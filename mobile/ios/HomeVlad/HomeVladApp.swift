import SwiftUI

@main struct HomeVladApp: App {
    @StateObject private var session = CloudSession()
    var body: some Scene {
        WindowGroup { Cabinet(session: session).tint(Color(red: 0.12, green: 0.45, blue: 0.35)).preferredColorScheme(.light) }
    }
}

struct HomeVladMintBackground: View {
    var body: some View {
        LinearGradient(colors: [Color(red: 0.84, green: 0.96, blue: 0.91),
                                Color(red: 0.97, green: 0.99, blue: 0.98)],
                       startPoint: .topLeading, endPoint: .bottomTrailing)
            .ignoresSafeArea()
    }
}
