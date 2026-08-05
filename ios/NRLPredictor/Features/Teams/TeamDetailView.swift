import SwiftUI

struct TeamDetailView: View {
    let teamId: Int

    @State private var team = Loadable<ItemResponse<Team>>()
    @State private var sort: Sort = .tries

    enum Sort: String, CaseIterable, Identifiable {
        case tries = "Season tries"
        case rate = "Try rate"
        case name = "Name"

        var id: String { rawValue }
    }

    private func sorted(_ players: [TeamPlayer]) -> [TeamPlayer] {
        switch sort {
        case .tries: players.sorted { $0.currentSeasonTries > $1.currentSeasonTries }
        case .rate: players.sorted { $0.currentSeasonTryRate > $1.currentSeasonTryRate }
        case .name: players.sorted { $0.name < $1.name }
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 22) {
                AsyncContent(loadable: team, retry: { Task { await reload(force: true) } }) { payload in
                    if let team = payload.data {
                        header(team)
                        squad(team)
                    } else {
                        EmptyCard(message: "Team not found.")
                    }
                }
            }
            .padding(16)
        }
        .nrlPage(team.value?.data?.label ?? "Team")
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    private func header(_ team: Team) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                RoundedRectangle(cornerRadius: 4)
                    .fill(Color(webHex: team.colorPrimary) ?? TeamColors.primary(team.nrlSlug))
                    .frame(width: 34, height: 34)
                    .overlay {
                        RoundedRectangle(cornerRadius: 4)
                            .strokeBorder(Color(webHex: team.colorSecondary) ?? TeamColors.secondary(team.nrlSlug), lineWidth: 2)
                    }
                VStack(alignment: .leading, spacing: 2) {
                    Text(team.label)
                        .displayStyle(28, weight: .bold)
                        .foregroundStyle(Palette.heading)
                    Text(team.name)
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                }
            }

            HStack(spacing: 8) {
                Chip("\(team.players?.count ?? 0) players")
                Chip("\(team.players?.reduce(0) { $0 + $1.currentSeasonTries } ?? 0) season tries", tone: .gold)
            }
        }
    }

    private func squad(_ team: Team) -> some View {
        SectionBlock(title: "Squad") {
            VStack(alignment: .leading, spacing: 10) {
                Picker("Sort", selection: $sort) {
                    ForEach(Sort.allCases) { option in
                        Text(option.rawValue).tag(option)
                    }
                }
                .pickerStyle(.segmented)

                let players = sorted(team.players ?? [])
                if players.isEmpty {
                    EmptyCard(message: "No players stored for this club yet.")
                } else {
                    Card(padding: 0) {
                        VStack(spacing: 0) {
                            ForEach(Array(players.enumerated()), id: \.element.id) { index, player in
                                NavigationLink(value: Route.player(player.id)) {
                                    playerRow(player)
                                }
                                .buttonStyle(.plain)
                                if index < players.count - 1 {
                                    Divider().overlay(Palette.track)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private func playerRow(_ player: TeamPlayer) -> some View {
        HStack(spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(player.name)
                    .font(.system(size: 14, weight: .medium))
                    .foregroundStyle(Palette.heading)
                Text([Fmt.position(player.position), "\(player.currentSeasonGames) games"]
                    .compactMap { $0 }
                    .joined(separator: " · "))
                    .font(.system(size: 11))
                    .foregroundStyle(Palette.muted)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text("\(player.currentSeasonTries)")
                    .font(.numeric(14, .medium))
                    .foregroundStyle(Palette.accentBright)
                Text(String(format: "%.2f/gm", player.currentSeasonTryRate))
                    .font(.numeric(10))
                    .foregroundStyle(Palette.faint)
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 11)
    }

    private func reload(force: Bool) async {
        await team.load(force: force) {
            try await APIClient.shared.get("/api/v1/teams/\(teamId)")
        }
    }
}
