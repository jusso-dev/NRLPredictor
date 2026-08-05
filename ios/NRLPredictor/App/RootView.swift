import SwiftUI

/// Shared push destinations. Every tab owns its own `NavigationStack`.
enum Route: Hashable {
    case match(Int)
    case team(Int)
    case player(Int)
}

extension View {
    /// Applies the app's page chrome: black canvas, condensed nav title, shared routes.
    func nrlPage(_ title: String) -> some View {
        self
            .background(Palette.bg)
            .scrollContentBackground(.hidden)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(Color.black, for: .navigationBar)
            .toolbarBackground(.visible, for: .navigationBar)
            .navigationDestination(for: Route.self) { route in
                switch route {
                case .match(let id): MatchDetailView(matchId: id)
                case .team(let id): TeamDetailView(teamId: id)
                case .player(let id): PlayerDetailView(playerId: id)
                }
            }
    }
}

struct RootView: View {
    @State private var selection = RootView.initialTab
    @State private var roundPath = NavigationPath()

    /// Debug builds honour `-startTab <n>` so a tab can be screenshotted straight from the CLI.
    static var initialTab: Int {
        #if DEBUG
        UserDefaults.standard.integer(forKey: "startTab")
        #else
        0
        #endif
    }

    /// Debug builds also honour `-startRoute match:187` / `team:1` / `player:402`.
    static var debugRoute: Route? {
        #if DEBUG
        guard let raw = UserDefaults.standard.string(forKey: "startRoute") else { return nil }
        let parts = raw.split(separator: ":")
        guard parts.count == 2, let id = Int(parts[1]) else { return nil }
        switch parts[0] {
        case "match": return .match(id)
        case "team": return .team(id)
        case "player": return .player(id)
        default: return nil
        }
        #else
        return nil
        #endif
    }

    var body: some View {
        TabView(selection: $selection) {
            NavigationStack(path: $roundPath) {
                FixturesView()
                    .task {
                        if let route = RootView.debugRoute { roundPath.append(route) }
                    }
            }
                .tabItem { Label("Round", systemImage: "sportscourt") }
                .tag(0)

            NavigationStack { MultiBuilderView() }
                .tabItem { Label("Multi", systemImage: "square.stack.3d.up") }
                .tag(1)

            NavigationStack { OddsView() }
                .tabItem { Label("Odds", systemImage: "chart.line.uptrend.xyaxis") }
                .tag(2)

            NavigationStack { TeamsView() }
                .tabItem { Label("Teams", systemImage: "person.3") }
                .tag(3)
        }
    }
}

/// The masthead lockup from `layouts/app.blade.php`, reused as a nav-bar title.
struct MastheadTitle: View {
    let title: String

    var body: some View {
        HStack(spacing: 8) {
            Text("N")
                .font(.display(15, .bold))
                .foregroundStyle(Color.black)
                .frame(width: 22, height: 22)
                .background(Palette.accent, in: .rect(cornerRadius: 3))
            Text(title)
                .displayStyle(16)
                .tracking(0.5)
                .foregroundStyle(Palette.heading)
        }
    }
}
