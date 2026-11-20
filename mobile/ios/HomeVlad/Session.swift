import Foundation

private final class SameHostRedirects: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask,
                    willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
                    completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

struct CloudChallenge: Decodable {
    let csrf: String
    let captcha: String
}
struct CloudAcknowledgement: Decodable {
    let success: Bool
    let csrf: String?
    let id: Int?
}
private struct CloudServerError: Decodable { let error: String }
struct CloudRequestError: LocalizedError {
    let message: String
    var errorDescription: String? { message }
}

@MainActor final class CloudSession: ObservableObject {
    @Published var snapshot: CloudSnapshot?
    @Published var error: String?
    @Published var loading = false
    @Published var challenge: CloudChallenge?
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
            throw CloudRequestError(message: message ?? "Сервис временно недоступен")
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

    func login(email: String, password: String, answer: String) async {
        guard !loading else { return }
        loading = true
        defer { loading = false }
        do {
            let result = try await post("/api/mobile_auth.php",
                ["email": email, "password": password, "captcha": answer])
            guard result.success else { throw CloudError.signIn }
            csrf = result.csrf ?? csrf
            let next: CloudSnapshot = try await get("/api/mobile_dashboard.php")
            snapshot = next
            csrf = next.csrf
            challenge = nil
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
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
        generation += 1
        loading = true
        _ = try? await post("/api/mobile_auth.php", ["action": "logout"])
        client.configuration.httpCookieStorage?.cookies?.forEach {
            client.configuration.httpCookieStorage?.deleteCookie($0)
        }
        snapshot = nil
        csrf = ""
        challenge = nil
        error = nil
        loading = false
        await prepareLogin()
    }

    deinit { client.invalidateAndCancel() }
}
