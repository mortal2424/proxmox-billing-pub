import Foundation
import WebKit

private final class SameHostRedirects: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask,
                    willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
                    completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(request.url?.scheme == "https" && request.url?.host == CloudEnvironment.host &&
                          (request.url?.port == nil || request.url?.port == 443) ? request : nil)
    }
}

@MainActor final class CloudSession: ObservableObject {
    @Published var snapshot: CloudSnapshot?
    @Published var error: String?
    @Published var loading = false
    @Published var signingIn = false
    let webData = WKWebsiteDataStore.nonPersistent()
    private var generation = 0
    private let client: URLSession

    init() {
        let config = URLSessionConfiguration.ephemeral
        config.httpShouldSetCookies = false
        config.timeoutIntervalForRequest = 20
        client = URLSession(configuration: config, delegate: SameHostRedirects(), delegateQueue: nil)
    }

    func refresh() async {
        guard !loading else { return }
        let requestGeneration = generation
        loading = true
        defer { if requestGeneration == generation { loading = false } }
        do {
            let cookies = await webData.httpCookieStore.allCookies()
            let allowed = cookies.filter {
                let domain = $0.domain.hasPrefix(".") ? String($0.domain.dropFirst()) : $0.domain
                return domain == CloudEnvironment.host
            }
            var request = URLRequest(url: CloudEnvironment.origin.appendingPathComponent("api/mobile_dashboard.php"))
            request.allHTTPHeaderFields = HTTPCookie.requestHeaderFields(with: allowed)
            request.setValue("application/json", forHTTPHeaderField: "Accept")
            let (data, response) = try await client.data(for: request)
            guard requestGeneration == generation else { return }
            guard let response = response as? HTTPURLResponse else { throw CloudError.unavailable }
            if response.statusCode == 401 {
                snapshot = nil
                throw CloudError.signIn
            }
            guard response.statusCode == 200 else { throw CloudError.unavailable }
            snapshot = try JSONDecoder().decode(CloudSnapshot.self, from: data)
            error = nil
            signingIn = false
        } catch {
            guard requestGeneration == generation else { return }
            self.error = error.localizedDescription
        }
    }

    func logout() async {
        generation += 1
        loading = true
        defer { loading = false }
        snapshot = nil
        error = nil
        let cookies = await webData.httpCookieStore.allCookies()
        var request = URLRequest(url: CloudEnvironment.origin.appendingPathComponent("login/logout.php"))
        request.allHTTPHeaderFields = HTTPCookie.requestHeaderFields(with: cookies.filter {
            $0.domain.trimmingCharacters(in: CharacterSet(charactersIn: ".")) == CloudEnvironment.host
        })
        _ = try? await client.data(for: request)
        await webData.removeData(ofTypes: WKWebsiteDataStore.allWebsiteDataTypes(), modifiedSince: .distantPast)
        snapshot = nil
        error = nil
    }

    deinit { client.invalidateAndCancel() }
}
