import SwiftUI

@main struct HomeVladApp: App {
    @StateObject private var session = CloudSession()
    var body: some Scene {
        WindowGroup { Cabinet(session: session).tint(Color(red: 0.12, green: 0.45, blue: 0.35)) }
    }
}
