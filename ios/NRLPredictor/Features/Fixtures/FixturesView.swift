import SwiftUI

struct FixturesView: View {
    @State private var rounds = Loadable<ListResponse<RoundInfo>>()
    @State private var matches = Loadable<RoundScopedResponse<Match>>()
    @State private var leaderboard = Loadable<RoundScopedResponse<PredictionRow>>()
    @State private var selectedRound: RoundInfo?
    @State private var showSettings = false

    private var roundList: [RoundInfo] { rounds.value?.data ?? [] }

    private var roundLabel: String {
        if let selectedRound { return "Round \(selectedRound.roundNumber)" }
        if let number = matches.value?.round { return "Round \(number)" }
        return "Current round"
    }

    private var seasonLabel: String? {
        if let selectedRound { return String(selectedRound.season) }
        if let season = matches.value?.season { return String(season) }
        return nil
    }

    /// Fastest-approaching kickoff still in the future — the "what's on next" line.
    private var nextKickoff: Match? {
        (matches.value?.data ?? [])
            .filter { ($0.status != "completed") && (Fmt.date($0.kickoffAt) ?? .distantPast) > Date() }
            .min { (Fmt.date($0.kickoffAt) ?? .distantFuture) < (Fmt.date($1.kickoffAt) ?? .distantFuture) }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 28) {
                header
                matchesSection
                leaderboardSection
                ResponsibleGamblingFooter()
            }
            .padding(16)
        }
        .nrlPage("Round")
        .toolbar {
            ToolbarItem(placement: .principal) { MastheadTitle(title: "NRL Try Predictor") }
            ToolbarItem(placement: .topBarTrailing) {
                Button { showSettings = true } label: { Image(systemName: "gearshape") }
                    .tint(Palette.muted)
            }
        }
        .sheet(isPresented: $showSettings) { SettingsView() }
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    // MARK: - Header

    private var header: some View {
        VStack(alignment: .leading, spacing: 10) {
            Eyebrow(selectedRound == nil ? "Current round" : "Selected round")

            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(roundLabel)
                    .displayStyle(32, weight: .bold)
                    .foregroundStyle(Palette.heading)
                if let seasonLabel {
                    Text("— \(seasonLabel)")
                        .font(.display(20))
                        .foregroundStyle(Palette.muted)
                }
            }

            if let window = Fmt.roundWindow(start: selectedRound?.startDate, end: selectedRound?.endDate) {
                Text(window)
                    .font(.system(size: 13))
                    .foregroundStyle(Palette.muted)
            }

            HStack(spacing: 8) {
                Chip("\(matches.value?.data.count ?? 0) matches")
                Chip("\(leaderboard.value?.data.count ?? 0) predictions", tone: .gold)
                Spacer()
                roundPicker
            }

            if let next = nextKickoff, let relative = Fmt.relative(next.kickoffAt) {
                Card(tint: Palette.accent) {
                    VStack(alignment: .leading, spacing: 4) {
                        Eyebrow("Next kickoff", tint: Palette.accentBright)
                        Text(next.title)
                            .font(.system(size: 13, weight: .medium))
                            .foregroundStyle(Palette.body)
                        Text("\(Fmt.kickoffShort(next.kickoffAt) ?? "") AEST · \(relative)")
                            .font(.numeric(11))
                            .foregroundStyle(Palette.muted)
                    }
                }
            }
        }
    }

    private var roundPicker: some View {
        Menu {
            Button("Current round") {
                selectedRound = nil
                Task { await reloadMatches(force: true) }
            }
            ForEach(roundList) { round in
                Button("Round \(round.roundNumber) · \(round.season)") {
                    selectedRound = round
                    Task { await reloadMatches(force: true) }
                }
            }
        } label: {
            HStack(spacing: 6) {
                Text("Change round")
                Image(systemName: "chevron.down").font(.system(size: 10, weight: .bold))
            }
            .font(.system(size: 12, weight: .semibold))
            .tracking(0.6)
            .textCase(.uppercase)
            .foregroundStyle(Palette.body)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Palette.surface, in: .rect(cornerRadius: 6))
            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }
        }
    }

    // MARK: - Sections

    private var matchesSection: some View {
        SectionBlock(title: "Matches") {
            AsyncContent(loadable: matches, retry: { Task { await reloadMatches(force: true) } }) { payload in
                if payload.data.isEmpty {
                    EmptyCard(message: "No matches scheduled for this round.")
                } else {
                    LazyVStack(spacing: 12) {
                        ForEach(payload.data) { match in
                            NavigationLink(value: Route.match(match.id)) {
                                MatchCard(match: match, topPick: topPick(for: match.id))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
    }

    private var leaderboardSection: some View {
        SectionBlock(title: "Round leaderboard", trailing: "top 10") {
            AsyncContent(loadable: leaderboard, retry: { Task { await reloadLeaderboard(force: true) } }) { payload in
                if payload.data.isEmpty {
                    EmptyCard(message: "Predictions will populate after the next analysis run.")
                } else {
                    Card(padding: 0) {
                        VStack(spacing: 0) {
                            ForEach(Array(payload.data.prefix(10).enumerated()), id: \.element.id) { index, row in
                                LeaderboardRow(position: index + 1, row: row)
                                if index < min(10, payload.data.count) - 1 {
                                    Divider().overlay(Palette.track)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private func topPick(for matchId: Int) -> PredictionRow? {
        leaderboard.value?.data.first { $0.matchId == matchId && $0.rank == 1 }
    }

    // MARK: - Loading

    private func reload(force: Bool) async {
        // Concurrent: the round list is only needed by the picker, so making the
        // fixtures wait behind it just delays the first paint.
        async let roundsTask: Void = rounds.load(force: force) {
            try await APIClient.shared.get("/api/v1/rounds")
        }
        async let matchesTask: Void = reloadMatches(force: force)
        async let leaderboardTask: Void = reloadLeaderboard(force: force)

        _ = await (roundsTask, matchesTask, leaderboardTask)
    }

    private func reloadMatches(force: Bool) async {
        await matches.load(force: force) {
            if let selectedRound {
                return try await APIClient.shared.get(
                    "/api/v1/matches",
                    query: [
                        URLQueryItem(name: "round", value: String(selectedRound.roundNumber)),
                        URLQueryItem(name: "season", value: String(selectedRound.season)),
                    ]
                )
            }
            return try await APIClient.shared.get("/api/v1/matches/current")
        }
    }

    private func reloadLeaderboard(force: Bool) async {
        await leaderboard.load(force: force) {
            try await APIClient.shared.get("/api/v1/predictions/leaderboard")
        }
    }
}

/// One row of the round leaderboard: rank, player, score bar, score, advantage chips.
struct LeaderboardRow: View {
    let position: Int
    let row: PredictionRow

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 12) {
                Text("\(position)")
                    .font(.numeric(14, .medium))
                    .foregroundStyle(position <= 3 ? Palette.accentBright : Palette.muted)
                    .frame(width: 20, alignment: .trailing)

                VStack(alignment: .leading, spacing: 2) {
                    Text(row.player.name ?? "—")
                        .font(.system(size: 14, weight: .medium))
                        .foregroundStyle(Palette.heading)
                    Text([row.player.team, row.match].compactMap { $0 }.joined(separator: " · "))
                        .font(.system(size: 11))
                        .foregroundStyle(Palette.muted)
                }

                Spacer(minLength: 8)

                ScoreBar(value: Double(row.score) / 100, tint: Palette.scoreTier(row.score))
                    .frame(width: 60)

                Text("\(row.score)")
                    .font(.numeric(14, .medium))
                    .foregroundStyle(Palette.body)
                    .frame(width: 30, alignment: .trailing)
            }

            if !row.advantageTags.isEmpty {
                HStack(spacing: 4) {
                    ForEach(row.advantageTags.prefix(3), id: \.label) { tag in
                        Chip(tag.label, tone: tag.tone)
                    }
                }
                .padding(.leading, 32)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
    }
}
