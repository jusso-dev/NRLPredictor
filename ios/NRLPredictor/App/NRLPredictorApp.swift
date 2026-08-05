import SwiftUI

@main
struct NRLPredictorApp: App {
    init() {
        #if DEBUG
        // QA hook: `-debugAPIKey <key>` seeds the Keychain so a protected server
        // can be exercised from the command line.
        if let key = UserDefaults.standard.string(forKey: "debugAPIKey") {
            APIConfig.setAPIKey(key.isEmpty ? nil : key)
        }
        #endif
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .preferredColorScheme(.dark)
                .tint(Palette.accent)
        }
    }
}
