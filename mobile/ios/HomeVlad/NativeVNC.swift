import CommonCrypto
import CoreGraphics
import Foundation

struct ConsoleLaunchTicket: Decodable {
    let ticket: String
    let socketPath: String
}

private enum ConsoleError: LocalizedError {
    case protocolMismatch, authentication, unsupportedConsole, frameSize

    var errorDescription: String? {
        switch self {
        case .protocolMismatch: "Сервер прервал обмен данными VNC."
        case .authentication: "Proxmox отклонил билет консоли. Подключитесь снова."
        case .unsupportedConsole: "Для нативной консоли LXC подключите ноду к HomeVlad через API-токен."
        case .frameSize: "Консоль передала неподдерживаемый размер экрана."
        }
    }
}

actor NativeVNCConnection {
    private struct Setup: Decodable {
        let type: String
        let password: String?
    }

    private let socket: URLSessionWebSocketTask
    private var incoming = Data()
    private var offset = 0
    private var pixels = Data()
    private var width = 0
    private var height = 0
    private var ready = false
    private var sentInitialFrame = false

    init(socket: URLSessionWebSocketTask) {
        self.socket = socket
    }

    func run(ticket: String, onFrame: @Sendable (CGImage) async -> Void) async throws {
        socket.resume()
        defer { socket.cancel(with: .normalClosure, reason: nil); ready = false }
        try await socket.send(.string(ticket))
        guard case .string(let setup) = try await socket.receive() else { throw ConsoleError.protocolMismatch }
        let grant = try JSONDecoder().decode(Setup.self, from: Data(setup.utf8))
        guard grant.type == "qemu" else { throw ConsoleError.unsupportedConsole }
        try await negotiate(password: grant.password)
        ready = true
        try await requestFrame(incremental: false)

        while !Task.isCancelled {
            switch try await read(1)[0] {
            case 0:
                let update = try await read(3)
                let changed = try await readRectangles(update.u16(at: 1))
                if changed, let frame = frameImage() {
                    sentInitialFrame = true
                    await onFrame(frame)
                }
                try await requestFrame(incremental: sentInitialFrame)
            case 1:
                let colorMap = try await read(5)
                _ = try await read(colorMap.u16(at: 3) * 6)
            case 2: break
            case 3:
                let clipboard = try await read(7)
                let length = Int(clipboard.u32(at: 3))
                guard length <= 1_048_576 else { throw ConsoleError.protocolMismatch }
                _ = try await read(length)
            default:
                throw ConsoleError.protocolMismatch
            }
        }
    }

    func close() {
        ready = false
        socket.cancel(with: .normalClosure, reason: nil)
    }

    func type(_ text: String, enter: Bool = false) async throws {
        var bytes = Data()
        for scalar in text.unicodeScalars.prefix(1024) {
            switch scalar.value {
            case 10, 13: bytes.append(keySequence(0xff0d))
            case 32...126, 160...255: bytes.append(keySequence(scalar.value))
            default: bytes.append(keySequence(0x01000000 | scalar.value))
            }
        }
        if enter { bytes.append(keySequence(0xff0d)) }
        try await sendInput(bytes)
    }

    func press(_ key: UInt32, modifiers: [UInt32] = []) async throws {
        var bytes = Data()
        for modifier in modifiers { bytes.append(keyEvent(modifier, down: true)) }
        bytes.append(keySequence(key))
        for modifier in modifiers.reversed() { bytes.append(keyEvent(modifier, down: false)) }
        try await sendInput(bytes)
    }

    func click(x: Double, y: Double, right: Bool = false) async throws {
        guard ready else { return }
        let x = UInt16(min(width - 1, max(0, Int(x * Double(width)))))
        let y = UInt16(min(height - 1, max(0, Int(y * Double(height)))))
        var point = Data()
        point.appendNetwork(x)
        point.appendNetwork(y)
        var bytes = Data([5, right ? 4 : 1])
        bytes.append(point)
        bytes.append(contentsOf: [5, 0])
        bytes.append(point)
        try await sendInput(bytes)
    }

    private func sendInput(_ bytes: Data) async throws {
        guard ready, !bytes.isEmpty else { return }
        try await socket.send(.data(bytes))
    }

    private func keyEvent(_ key: UInt32, down: Bool) -> Data {
        var bytes = Data([4, down ? 1 : 0, 0, 0])
        bytes.appendNetwork(key)
        return bytes
    }

    private func keySequence(_ key: UInt32) -> Data {
        keyEvent(key, down: true) + keyEvent(key, down: false)
    }

    private func negotiate(password: String?) async throws {
        let version = try await read(12)
        guard let banner = String(data: version, encoding: .ascii),
              banner.hasPrefix("RFB 003."), banner.hasSuffix("\n"),
              let minor = Int(banner.dropFirst(8).prefix(3)), minor >= 3 else {
            throw ConsoleError.protocolMismatch
        }
        let protocolMinor = minor >= 8 ? 8 : (minor >= 7 ? 7 : 3)
        try await socket.send(.data(Data("RFB 003.00\(protocolMinor)\n".utf8)))

        let security: UInt8
        if protocolMinor == 3 {
            let method = try await read(4).u32(at: 0)
            guard method == 1 || method == 2 else { throw ConsoleError.authentication }
            security = UInt8(method)
        } else {
            let count = Int(try await read(1)[0])
            guard count > 0 else { throw ConsoleError.authentication }
            let options = try await read(count)
            if password != nil && options.contains(2) { security = 2 }
            else if options.contains(1) { security = 1 }
            else { throw ConsoleError.authentication }
            try await socket.send(.data(Data([security])))
        }

        if security == 2 {
            guard let password else { throw ConsoleError.authentication }
            let challenge = try await read(16)
            try await socket.send(.data(try challengeResponse(challenge, password: password)))
        }
        if security == 2 || protocolMinor == 8 {
            guard try await read(4).u32(at: 0) == 0 else { throw ConsoleError.authentication }
        }

        try await socket.send(.data(Data([1])))
        let server = try await read(24)
        try resize(width: server.u16(at: 0), height: server.u16(at: 2))
        let nameLength = Int(server.u32(at: 20))
        guard nameLength <= 65_536 else { throw ConsoleError.protocolMismatch }
        _ = try await read(nameLength)

        // 32-bit little-endian BGRX pixels can be drawn directly with Core Graphics.
        var commands = Data([0, 0, 0, 0, 32, 24, 0, 1, 0, 255, 0, 255, 0, 255, 16, 8, 0, 0, 0, 0])
        commands.append(contentsOf: [2, 0, 0, 2])
        commands.appendNetwork(UInt32(0)) // Raw
        commands.appendNetwork(UInt32(bitPattern: -223)) // DesktopSize
        try await socket.send(.data(commands))
    }

    private func challengeResponse(_ challenge: Data, password: String) throws -> Data {
        // VNC uses a one-time challenge and reverses the bits of each DES key byte.
        var key = Array(password.utf8.prefix(8)).map { byte -> UInt8 in
            var result: UInt8 = 0
            for bit in 0..<8 { result |= ((byte >> bit) & 1) << (7 - bit) }
            return result
        }
        key.append(contentsOf: repeatElement(0, count: 8 - key.count))
        var result = Data(count: challenge.count)
        let resultSize = result.count
        var written = 0
        let status = result.withUnsafeMutableBytes { output in
            challenge.withUnsafeBytes { input in
                key.withUnsafeBytes { secret in
                    CCCrypt(CCOperation(kCCEncrypt), CCAlgorithm(kCCAlgorithmDES), CCOptions(kCCOptionECBMode),
                            secret.baseAddress, secret.count, nil, input.baseAddress, input.count,
                            output.baseAddress, resultSize, &written)
                }
            }
        }
        guard status == kCCSuccess, written == resultSize else { throw ConsoleError.authentication }
        return result
    }

    private func read(_ count: Int) async throws -> Data {
        guard (0...35_389_440).contains(count) else { throw ConsoleError.frameSize }
        while incoming.count - offset < count {
            if offset > 0 {
                incoming = Data(incoming[offset...])
                offset = 0
            }
            guard case .data(let chunk) = try await socket.receive(), !chunk.isEmpty else {
                throw ConsoleError.protocolMismatch
            }
            incoming.append(chunk)
        }
        let data = incoming.subdata(in: offset..<(offset + count))
        offset += count
        return data
    }

    private func resize(width: Int, height: Int) throws {
        guard (1...4096).contains(width), (1...4096).contains(height), width * height <= 8_847_360 else {
            throw ConsoleError.frameSize
        }
        self.width = width
        self.height = height
        sentInitialFrame = false
        pixels = Data(count: width * height * 4)
    }

    private func readRectangles(_ count: Int) async throws -> Bool {
        var changed = false
        for _ in 0..<count {
            let header = try await read(12)
            let x = header.u16(at: 0), y = header.u16(at: 2)
            let w = header.u16(at: 4), h = header.u16(at: 6)
            switch Int32(bitPattern: header.u32(at: 8)) {
            case -223:
                try resize(width: w, height: h)
            case -224:
                return changed
            case 0:
                guard w > 0, h > 0, x + w <= width, y + h <= height else { throw ConsoleError.frameSize }
                let rowSize = w * 4
                let rectangle = try await read(rowSize * h)
                for row in 0..<h {
                    let start = ((y + row) * width + x) * 4
                    let source = row * rowSize
                    pixels.replaceSubrange(start..<(start + rowSize), with: rectangle[source..<(source + rowSize)])
                }
                changed = true
            default:
                throw ConsoleError.protocolMismatch
            }
        }
        return changed
    }

    private func requestFrame(incremental: Bool) async throws {
        var bytes = Data([3, incremental ? 1 : 0, 0, 0, 0, 0])
        bytes.appendNetwork(UInt16(width))
        bytes.appendNetwork(UInt16(height))
        try await socket.send(.data(bytes))
    }

    private func frameImage() -> CGImage? {
        guard let provider = CGDataProvider(data: pixels as CFData) else { return nil }
        return CGImage(width: width, height: height, bitsPerComponent: 8, bitsPerPixel: 32,
                       bytesPerRow: width * 4, space: CGColorSpaceCreateDeviceRGB(),
                       bitmapInfo: [.byteOrder32Little, CGBitmapInfo(rawValue: CGImageAlphaInfo.noneSkipFirst.rawValue)],
                       provider: provider, decode: nil, shouldInterpolate: true, intent: .defaultIntent)
    }
}

private extension Data {
    func u16(at offset: Int) -> Int {
        Int(self[offset]) << 8 | Int(self[offset + 1])
    }

    func u32(at offset: Int) -> UInt32 {
        UInt32(u16(at: offset)) << 16 | UInt32(u16(at: offset + 2))
    }

    mutating func appendNetwork<T: FixedWidthInteger>(_ value: T) {
        var bytes = value.bigEndian
        Swift.withUnsafeBytes(of: &bytes) { append(contentsOf: $0) }
    }
}
