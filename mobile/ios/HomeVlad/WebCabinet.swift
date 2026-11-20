import SwiftUI
import WebKit

struct WebCabinet: UIViewRepresentable {
    @ObservedObject var session: CloudSession
    let path: String

    func makeCoordinator() -> Coordinator { Coordinator(session) }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = session.webData
        let view = WKWebView(frame: .zero, configuration: config)
        view.navigationDelegate = context.coordinator
        view.uiDelegate = context.coordinator
        view.allowsBackForwardNavigationGestures = true
        view.load(URLRequest(url: URL(string: path, relativeTo: CloudEnvironment.origin)!.absoluteURL))
        return view
    }

    func updateUIView(_ view: WKWebView, context: Context) {}

    @MainActor final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {
        let session: CloudSession
        init(_ session: CloudSession) { self.session = session }

        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url else { decisionHandler(.cancel); return }
            let internalURL = url.scheme == "https" && url.host == CloudEnvironment.host &&
                (url.port == nil || url.port == 443)
            if internalURL {
                decisionHandler(.allow)
            } else {
                if navigationAction.navigationType == .linkActivated && ["https", "mailto", "tg"].contains(url.scheme ?? "") {
                    UIApplication.shared.open(url)
                }
                decisionHandler(.cancel)
            }
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            if webView.url?.path.hasPrefix("/templates/") == true && session.signingIn {
                Task { await session.refresh() }
            }
        }

        func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                     for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
            if let url = navigationAction.request.url, url.scheme == "https",
               url.host == CloudEnvironment.host, url.port == nil || url.port == 443 {
                webView.load(navigationAction.request)
            }
            return nil
        }
    }
}

struct CabinetDestination: View {
    @ObservedObject var session: CloudSession
    let title: String
    let path: String
    var body: some View {
        WebCabinet(session: session, path: path)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .onDisappear { Task { await session.refresh() } }
    }
}
