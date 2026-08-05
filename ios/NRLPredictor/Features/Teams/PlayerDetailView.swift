import SwiftUI

struct PlayerDetailView: View {
    let playerId: Int

    @State private var player = Loadable<ItemResponse<PlayerDetail>>()

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 22) {
                AsyncContent(loadable: player, retry: { Task { await reload(force: true) } }) { payload in
                    if let player = payload.data {
                        header(player)
                        seasonCard(player)
                        careerCard(player)
                        venueCard(player)
                        opponentCard(player)
                    } else {
                        EmptyCard(message: "Player not found.")
                    }
                }
            }
            .padding(16)
        }
        .nrlPage(player.value?.data?.name ?? "Player")
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    private func header(_ player: PlayerDetail) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(player.name)
                .displayStyle(28, weight: .bold)
                .foregroundStyle(Palette.heading)

            HStack(spacing: 8) {
                if let position = Fmt.position(player.position) {
                    Chip(position, tone: .muted)
                }
                if let team = player.team {
                    NavigationLink(value: Route.team(team.id)) {
                        Chip(team.label, tone: .gold)
                    }
                }
                if let injury = player.injury {
                    Chip(injury.status, tone: injury.status == "out" ? .red : .orange)
                }
            }

            if let injury = player.injury, let type = injury.type {
                Text("Injury: \(type)")
                    .font(.system(size: 12))
                    .foregroundStyle(Palette.red)
            }
        }
    }

    private func seasonCard(_ player: PlayerDetail) -> some View {
        SectionBlock(title: "This season") {
            Card {
                HStack(spacing: 12) {
                    stat("Games", "\(player.season.games)")
                    stat("Tries", "\(player.season.tries)", tint: Palette.accentBright)
                    stat("Try rate", String(format: "%.2f", player.season.tryRate))
                }
            }
        }
    }

    private func careerCard(_ player: PlayerDetail) -> some View {
        SectionBlock(title: "Career") {
            Card {
                VStack(spacing: 14) {
                    HStack(spacing: 12) {
                        stat("Games", "\(player.career.games)")
                        stat("Tries", "\(player.career.tries)")
                        stat("Try rate", String(format: "%.2f", player.career.tryRate))
                    }
                    HStack(spacing: 12) {
                        stat("Try assists", "\(player.career.tryAssists)")
                        stat("Line breaks", "\(player.career.lineBreaks)")
                        Spacer().frame(maxWidth: .infinity)
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func venueCard(_ player: PlayerDetail) -> some View {
        if !player.venueStats.isEmpty {
            SectionBlock(title: "By venue") {
                Card(padding: 0) {
                    VStack(spacing: 0) {
                        ForEach(Array(player.venueStats.prefix(10).enumerated()), id: \.element.id) { index, stat in
                            statRow(label: stat.venue, games: stat.games, tries: stat.tries, rate: stat.tryRate)
                            if index < min(10, player.venueStats.count) - 1 {
                                Divider().overlay(Palette.track)
                            }
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func opponentCard(_ player: PlayerDetail) -> some View {
        if !player.opponentStats.isEmpty {
            SectionBlock(title: "By opponent") {
                Card(padding: 0) {
                    VStack(spacing: 0) {
                        ForEach(Array(player.opponentStats.prefix(12).enumerated()), id: \.element.id) { index, stat in
                            statRow(label: stat.opponent ?? "—", games: stat.games, tries: stat.tries, rate: stat.tryRate)
                            if index < min(12, player.opponentStats.count) - 1 {
                                Divider().overlay(Palette.track)
                            }
                        }
                    }
                }
            }
        }
    }

    private func statRow(label: String, games: Int, tries: Int, rate: Double) -> some View {
        HStack(spacing: 10) {
            Text(label)
                .font(.system(size: 13))
                .foregroundStyle(Palette.body)
                .lineLimit(1)
            Spacer()
            Text("\(games) gm")
                .font(.numeric(11))
                .foregroundStyle(Palette.muted)
            Text("\(tries)")
                .font(.numeric(13, .medium))
                .foregroundStyle(Palette.accentBright)
                .frame(width: 26, alignment: .trailing)
            Text(String(format: "%.2f", rate))
                .font(.numeric(11))
                .foregroundStyle(Palette.faint)
                .frame(width: 38, alignment: .trailing)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
    }

    private func stat(_ label: String, _ value: String, tint: Color = Palette.heading) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Eyebrow(label)
            Text(value)
                .font(.display(22, .semibold))
                .foregroundStyle(tint)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func reload(force: Bool) async {
        await player.load(force: force) {
            try await APIClient.shared.get("/api/v1/players/\(playerId)")
        }
    }
}
