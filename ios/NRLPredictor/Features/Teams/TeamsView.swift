import SwiftUI

struct TeamsView: View {
    @State private var teams = Loadable<ListResponse<Team>>()
    @State private var search = ""

    private var filtered: [Team] {
        let all = teams.value?.data ?? []
        guard !search.isEmpty else { return all }
        return all.filter { $0.name.localizedCaseInsensitiveContains(search) }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                VStack(alignment: .leading, spacing: 6) {
                    Eyebrow("Season")
                    Text("Clubs")
                        .displayStyle(30, weight: .bold)
                        .foregroundStyle(Palette.heading)
                }

                AsyncContent(loadable: teams, retry: { Task { await reload(force: true) } }) { payload in
                    if payload.data.isEmpty {
                        EmptyCard(message: "No teams seeded yet. Run TeamSeeder in the backend.")
                    } else {
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 150), spacing: 12)], spacing: 12) {
                            ForEach(filtered) { team in
                                NavigationLink(value: Route.team(team.id)) {
                                    teamTile(team)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
            .padding(16)
        }
        .nrlPage("Teams")
        .searchable(text: $search, prompt: "Search clubs")
        .toolbar {
            ToolbarItem(placement: .principal) { MastheadTitle(title: "Clubs") }
        }
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    private func teamTile(_ team: Team) -> some View {
        Card(padding: 14) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 8) {
                    RoundedRectangle(cornerRadius: 3)
                        .fill(Color(webHex: team.colorPrimary) ?? TeamColors.primary(team.nrlSlug))
                        .frame(width: 26, height: 26)
                        .overlay {
                            RoundedRectangle(cornerRadius: 3)
                                .strokeBorder(Color(webHex: team.colorSecondary) ?? TeamColors.secondary(team.nrlSlug), lineWidth: 2)
                        }
                    Spacer()
                }
                Text(team.label)
                    .displayStyle(18)
                    .foregroundStyle(Palette.heading)
                Text(team.name)
                    .font(.system(size: 11))
                    .foregroundStyle(Palette.muted)
                    .lineLimit(2)
            }
        }
    }

    private func reload(force: Bool) async {
        await teams.load(force: force) {
            try await APIClient.shared.get("/api/v1/teams")
        }
    }
}
