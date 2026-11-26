import Foundation
import LocalAuthentication
import Security

private final class SameHostRedirects: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask,
                    willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
                    completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

struct CloudChallenge: Decodable {
    let csrf: String
    let turnstile_required: Bool?
}
struct CloudAcknowledgement: Decodable {
    let success: Bool
    let csrf: String?
    let id: Int?
    let token: String?
    let guestId: Int?
    let warning: String?
    let notice: String?
}
private struct CloudServerError: Decodable { let error: String }
struct CloudRequestError: LocalizedError {
    let message: String
    var status: Int? = nil
    var errorDescription: String? { message }
}

private enum BiometricTokenVault {
    static let service = "ru.homevlad.cloud.device." + CloudEnvironment.host
    static var key: [String: Any] {
        [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
         kSecAttrAccount as String: "device-login"]
    }

    static func save(_ token: String) throws {
        guard let access = SecAccessControlCreateWithFlags(nil,
            kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly, .biometryCurrentSet, nil) else {
            throw CloudError.unavailable
        }
        remove()
        var attributes = key
        attributes[kSecAttrAccessControl as String] = access
        attributes[kSecValueData as String] = Data(token.utf8)
        guard SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess else {
            throw CloudRequestError(message: "Не удалось сохранить ключ входа")
        }
    }

    static func read() throws -> String {
        let context = LAContext()
        context.localizedReason = "Вход в HomeVlad"
        context.localizedFallbackTitle = ""
        var query = key
        query[kSecUseAuthenticationContext as String] = context
        query[kSecReturnData as String] = true
        var item: CFTypeRef?
        let result = SecItemCopyMatching(query as CFDictionary, &item)
        if result == errSecUserCanceled { throw CancellationError() }
        guard result == errSecSuccess, let data = item as? Data,
              let token = String(data: data, encoding: .utf8) else {
            throw CloudRequestError(message: "Войдите с паролем и проверьте настройки биометрии")
        }
        return token
    }

    static func remove() {
        SecItemDelete(key as CFDictionary)
    }
}

@MainActor final class CloudSession: ObservableObject {
    @Published var snapshot: CloudSnapshot?
    @Published var error: String?
    @Published var loading = false
    @Published var challenge: CloudChallenge?
    @Published private(set) var biometricEnabled = UserDefaults.standard.bool(forKey: BiometricTokenVault.service)
    @Published private(set) var canEnrollBiometrics = false
    var biometricLabel: String? {
        let context = LAContext()
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: nil) else { return nil }
        return context.biometryType == .faceID ? "Face ID" : "Touch ID"
    }
    private func setBiometricEnabled(_ enabled: Bool) {
        UserDefaults.standard.set(enabled, forKey: BiometricTokenVault.service)
        biometricEnabled = enabled
    }
    private var csrf = ""
    private var generation = 0
    private let client: URLSession

    init() {
        let config = URLSessionConfiguration.ephemeral
        config.httpShouldSetCookies = true
        config.httpCookieAcceptPolicy = .always
        config.timeoutIntervalForRequest = 20
        client = URLSession(configuration: config, delegate: SameHostRedirects(), delegateQueue: nil)
    }

    func get<T: Decodable>(_ path: String) async throws -> T {
        try await request(path, body: nil)
    }

    func post(_ path: String, _ body: [String: Any]) async throws -> CloudAcknowledgement {
        var payload = body
        payload["csrf"] = csrf
        return try await request(path, body: payload)
    }

    func consoleTicket(guestId: Int) async throws -> ConsoleLaunchTicket {
        let grant: ConsoleLaunchTicket = try await request("/api/mobile_console.php", body: ["csrf": csrf, "id": guestId])
        guard grant.ticket.utf8.count == 64,
              grant.ticket.utf8.allSatisfy({ (48...57).contains($0) || (97...102).contains($0) }) else {
            throw CloudError.unavailable
        }
        return grant
    }

    func consoleConnection(path: String) throws -> NativeVNCConnection {
        guard path == "/console/socket",
              var endpoint = URLComponents(url: CloudEnvironment.origin, resolvingAgainstBaseURL: false) else {
            throw CloudError.unavailable
        }
        endpoint.scheme = "wss"
        endpoint.path = path
        guard let url = endpoint.url else { throw CloudError.unavailable }
        var request = URLRequest(url: url)
        request.httpShouldHandleCookies = false
        request.setValue(CloudEnvironment.origin.absoluteString, forHTTPHeaderField: "Origin")
        let socket = client.webSocketTask(with: request)
        socket.maximumMessageSize = 4 * 1024 * 1024
        return NativeVNCConnection(socket: socket)
    }

    private func request<T: Decodable>(_ path: String, body: [String: Any]?) async throws -> T {
        guard let url = URL(string: path, relativeTo: CloudEnvironment.origin)?.absoluteURL,
              url.scheme == "https", url.host == CloudEnvironment.host, url.port == nil || url.port == 443 else {
            throw CloudError.unavailable
        }
        var request = URLRequest(url: url)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let body {
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        if body != nil {
            if path == "/api/mobile_order.php" { request.timeoutInterval = 680 }
            if path == "/api/mobile_admin.php" { request.timeoutInterval = 120 }
        }
        let (data, response) = try await client.data(for: request)
        guard let response = response as? HTTPURLResponse else { throw CloudError.unavailable }
        guard response.statusCode == 200 else {
            if let nextChallenge = try? JSONDecoder().decode(CloudChallenge.self, from: data) {
                challenge = nextChallenge
                csrf = nextChallenge.csrf
            }
            if response.statusCode == 401 && !path.contains("mobile_auth.php") {
                let wasSignedIn = snapshot != nil
                snapshot = nil
                if wasSignedIn { Task { await prepareLogin() } }
            }
            let message = (try? JSONDecoder().decode(CloudServerError.self, from: data))?.error
            throw CloudRequestError(message: message ?? "Сервис временно недоступен", status: response.statusCode)
        }
        return try JSONDecoder().decode(T.self, from: data)
    }

    func start() async {
        guard !loading else { return }
        await refresh()
        if snapshot == nil { await prepareLogin() }
    }

    func prepareLogin() async {
        loading = true
        defer { loading = false }
        do {
            let next: CloudChallenge = try await get("/api/mobile_auth.php")
            challenge = next
            csrf = next.csrf
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    func login(email: String, password: String, totp: String, turnstile: String) async {
        guard !loading else { return }
        loading = true
        defer { loading = false }
        do {
            let result = try await post("/api/mobile_auth.php",
                ["email": email, "password": password, "totp": totp, "turnstile_token": turnstile])
            guard result.success else { throw CloudError.signIn }
            if biometricEnabled {
                BiometricTokenVault.remove()
                setBiometricEnabled(false)
            }
            csrf = result.csrf ?? csrf
            let next: CloudSnapshot = try await get("/api/mobile_dashboard.php")
            snapshot = next
            csrf = next.csrf
            challenge = nil
            canEnrollBiometrics = true
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    func enrollBiometrics() async {
        guard !loading, canEnrollBiometrics, let label = biometricLabel else { return }
        loading = true
        defer { loading = false }
        do {
            let context = LAContext()
            context.localizedFallbackTitle = ""
            guard try await context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,
                localizedReason: "Включить вход в HomeVlad по " + label) else { return }
            let response = try await post("/api/mobile_auth.php", ["action": "device_enable"])
            guard let token = response.token else { throw CloudError.unavailable }
            do { try BiometricTokenVault.save(token) }
            catch {
                _ = try? await post("/api/mobile_auth.php", ["action": "device_disable"])
                throw error
            }
            setBiometricEnabled(true)
            error = nil
        } catch let failure as LAError where failure.code == .userCancel || failure.code == .systemCancel {
        } catch { self.error = error.localizedDescription }
    }

    func biometricLogin() async {
        guard !loading, biometricEnabled, challenge != nil else { return }
        loading = true
        defer { loading = false }
        do {
            let token = try await Task.detached { try BiometricTokenVault.read() }.value
            _ = try await post("/api/mobile_auth.php", ["action": "device_login", "token": token])
            let next: CloudSnapshot = try await get("/api/mobile_dashboard.php")
            snapshot = next
            csrf = next.csrf
            challenge = nil
            canEnrollBiometrics = false
            error = nil
        } catch is CancellationError {
        } catch {
            if (error as? CloudRequestError)?.status == 401 {
                BiometricTokenVault.remove()
                setBiometricEnabled(false)
            }
            self.error = error.localizedDescription
        }
    }

    func disableBiometrics() async {
        guard !loading else { return }
        loading = true
        defer { loading = false }
        do {
            _ = try await post("/api/mobile_auth.php", ["action": "device_disable"])
            BiometricTokenVault.remove()
            setBiometricEnabled(false)
            error = nil
        } catch { self.error = error.localizedDescription }
    }

    func refresh() async {
        guard !loading else { return }
        let requestGeneration = generation
        loading = true
        defer { if requestGeneration == generation { loading = false } }
        do {
            let next: CloudSnapshot = try await get("/api/mobile_dashboard.php")
            guard requestGeneration == generation else { return }
            snapshot = next
            csrf = next.csrf
            error = nil
        } catch {
            guard requestGeneration == generation else { return }
            self.error = error.localizedDescription
        }
    }

    func logout() async {
        guard !loading else { return }
        generation += 1
        loading = true
        _ = try? await post("/api/mobile_auth.php", ["action": "logout"])
        client.configuration.httpCookieStorage?.cookies?.forEach {
            client.configuration.httpCookieStorage?.deleteCookie($0)
        }
        BiometricTokenVault.remove()
        setBiometricEnabled(false)
        canEnrollBiometrics = false
        snapshot = nil
        csrf = ""
        challenge = nil
        error = nil
        loading = false
        await prepareLogin()
    }

    deinit { client.invalidateAndCancel() }
}
