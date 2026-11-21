import SwiftUI

struct NativeConsoleScreen: View {
    @ObservedObject var session: CloudSession
    let guest: CloudGuest
    @Environment(\.dismiss) private var dismiss
    @Environment(\.scenePhase) private var scenePhase
    @FocusState private var keyboardFocused: Bool
    @State private var connection: NativeVNCConnection?
    @State private var frame: CGImage?
    @State private var error: String?
    @State private var attempt = UUID()
    @State private var keyboardVisible = false
    @State private var privateInput = false
    @State private var rightClick = false
    @State private var draft = ""

    var body: some View {
        VStack(spacing: 0) {
            GeometryReader { geometry in
                ZStack {
                    Color.black
                    if let frame {
                        let size = fitted(frame, in: geometry.size)
                        Image(decorative: frame, scale: 1, orientation: .up)
                            .resizable()
                            .interpolation(.medium)
                            .frame(width: size.width, height: size.height)
                            .contentShape(Rectangle())
                            .gesture(SpatialTapGesture().onEnded { tap in
                                let right = rightClick
                                rightClick = false
                                send { try await $0.click(x: tap.location.x / size.width,
                                                         y: tap.location.y / size.height, right: right) }
                            })
                            .accessibilityLabel("Экран гостевой системы")
                    } else if error == nil {
                        ProgressView("Подключение к консоли…").tint(.white).foregroundStyle(.white)
                    }
                    if let error {
                        VStack(spacing: 14) {
                            Text(error).multilineTextAlignment(.center)
                            Button("Подключиться снова") { attempt = UUID(); self.error = nil }
                                .buttonStyle(.borderedProminent)
                        }
                        .padding().foregroundStyle(.white)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .background(.black.opacity(0.85))
                    }
                }
            }
            if error == nil && frame != nil {
                controls
                if keyboardVisible { inputBar }
            }
        }
        .background(HomeVladMintBackground())
        .navigationTitle(guest.name)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) { Button("Закрыть") { dismiss() } }
        }
        .task(id: attempt) { await connect() }
        .onChange(of: scenePhase) { _, phase in
            if phase == .background { attempt = UUID(); disconnect(); error = "Сеанс консоли приостановлен." }
        }
        .onDisappear { disconnect() }
    }

    private var controls: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 9) {
                Button {
                    keyboardVisible.toggle()
                    keyboardFocused = keyboardVisible
                } label: { Label("Клавиатура", systemImage: "keyboard") }
                .tint(keyboardVisible ? Color.accentColor : .secondary)
                Button {
                    rightClick.toggle()
                } label: { Label("Правый клик", systemImage: "computermouse") }
                .tint(rightClick ? Color.accentColor : .secondary)
                specialKey("Enter", 0xff0d)
                specialKey("Tab", 0xff09)
                specialKey("Esc", 0xff1b)
                specialKey("⌫", 0xff08, accessibility: "Backspace")
                specialKey("←", 0xff51, accessibility: "Стрелка влево")
                specialKey("↑", 0xff52, accessibility: "Стрелка вверх")
                specialKey("→", 0xff53, accessibility: "Стрелка вправо")
                specialKey("↓", 0xff54, accessibility: "Стрелка вниз")
                specialKey("Ctrl+C", 0x63, modifiers: [0xffe3])
                if guest.type == "qemu" {
                    specialKey("Ctrl+Alt+Del", 0xffff, modifiers: [0xffe3, 0xffe9])
                }
            }
            .buttonStyle(.bordered)
            .padding(10)
        }
    }

    private var inputBar: some View {
        HStack(spacing: 8) {
            Button {
                privateInput.toggle()
                keyboardFocused = true
            } label: { Image(systemName: privateInput ? "lock.fill" : "lock.open") }
            .accessibilityLabel(privateInput ? "Показать вводимый текст" : "Скрыть вводимый текст")
            Group {
                if privateInput { SecureField("Текст для гостя", text: $draft) }
                else { TextField("Текст для гостя", text: $draft) }
            }
            .textFieldStyle(.roundedBorder)
            .focused($keyboardFocused)
            .textInputAutocapitalization(.never)
            .autocorrectionDisabled()
            .submitLabel(.go)
            .onSubmit { submit(enter: true) }
            Button("Ввести") { submit(enter: false) }.disabled(draft.isEmpty)
        }
        .padding(.horizontal, 12)
        .padding(.bottom, 10)
    }

    private func submit(enter: Bool) {
        let text = draft
        draft = ""
        send { try await $0.type(text, enter: enter) }
    }

    private func specialKey(_ label: String, _ key: UInt32, modifiers: [UInt32] = [], accessibility: String? = nil) -> some View {
        Button(label) { send { try await $0.press(key, modifiers: modifiers) } }
            .accessibilityLabel(accessibility ?? label)
    }

    private func fitted(_ frame: CGImage, in size: CGSize) -> CGSize {
        let scale = min(size.width / CGFloat(frame.width), size.height / CGFloat(frame.height))
        return CGSize(width: CGFloat(frame.width) * scale, height: CGFloat(frame.height) * scale)
    }

    @MainActor private func connect() async {
        guard scenePhase != .background else { return }
        let currentAttempt = attempt
        frame = nil
        error = nil
        do {
            let ticket = try await session.consoleTicket(guestId: guest.id)
            try Task.checkCancellation()
            let active = try session.consoleConnection(path: ticket.socketPath)
            connection = active
            try await active.run(ticket: ticket.ticket) { image in
                await MainActor.run {
                    if currentAttempt == attempt { frame = image }
                }
            }
        } catch {
            if !Task.isCancelled && currentAttempt == attempt { self.error = error.localizedDescription }
        }
    }

    private func disconnect() {
        draft = ""
        if let connection { Task { await connection.close() } }
        connection = nil
    }

    private func send(_ operation: @escaping @Sendable (NativeVNCConnection) async throws -> Void) {
        guard let connection else { return }
        let currentAttempt = attempt
        Task {
            do { try await operation(connection) }
            catch {
                guard currentAttempt == attempt else { return }
                self.error = error.localizedDescription
                await connection.close()
            }
        }
    }
}
