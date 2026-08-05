import SwiftUI

struct MatchDetailView: View {
    let matchId: Int

    @State private var match = Loadable<ItemResponse<Match>>()
    @State private var predictions = Loadable<ListResponse<PredictionRow>>()
    @State private var odds = Loadable<MatchOddsResponse>()
    @State private var expanded: Set<String> = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 26) {
                AsyncContent(loadable: match, retry: { Task { await reload(force: true) } }) { payload in
                    if let match = payload.data {
                        header(match)
                        lateChanges(match)
                        winPrediction(match)
                        bookmakerOdds(match)
                    } else {
                        EmptyCard(message: "Match not found.")
                    }
                }

                predictionsSection
                tryScorerOddsSection
                teamListsSection
                ResponsibleGamblingFooter()
            }
            .padding(16)
        }
        .nrlPage(match.value?.data?.title ?? "Match")
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    // MARK: - Header

    private func header(_ match: Match) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Chip(match.statusBadge, tone: match.statusTone)
                Text([Fmt.kickoffLong(match.kickoffAt).map { "\($0) AEST" }, match.venue]
                    .compactMap { $0 }
                    .joined(separator: " · "))
                    .font(.system(size: 12))
                    .foregroundStyle(Palette.muted)
            }

            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(match.homeTeam.name ?? match.homeTeam.label)
                    .displayStyle(26, weight: .bold)
                    .foregroundStyle(Palette.heading)
                Text("v")
                    .font(.display(20))
                    .foregroundStyle(Palette.muted)
                Text(match.awayTeam.name ?? match.awayTeam.label)
                    .displayStyle(26, weight: .bold)
                    .foregroundStyle(Palette.heading)
            }

            if match.hasScore {
                Text("\(match.homeScore ?? 0) — \(match.awayScore ?? 0)")
                    .font(.numeric(24, .medium))
                    .foregroundStyle(Palette.accentBright)
            }
        }
    }

    // MARK: - Late mail

    @ViewBuilder
    private func lateChanges(_ match: Match) -> some View {
        let changes = match.lateChanges ?? []
        if !changes.isEmpty {
            SectionBlock(title: "Late mail", trailing: "\(changes.count) change\(changes.count == 1 ? "" : "s")") {
                Card(tint: Palette.orange) {
                    VStack(alignment: .leading, spacing: 12) {
                        ForEach(changes) { change in
                            HStack(alignment: .top, spacing: 10) {
                                Chip(change.label, tone: change.tone)
                                    .fixedSize()
                                    .frame(width: 84, alignment: .leading)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text(change.summary)
                                        .font(.system(size: 13))
                                        .foregroundStyle(Palette.body)
                                    Text([change.timing, change.isOdds ? change.source?.capitalized : nil]
                                        .compactMap { $0 }
                                        .joined(separator: " · "))
                                        .font(.system(size: 11))
                                        .foregroundStyle(Palette.faint)
                                }

                                Spacer(minLength: 0)

                                if let playerId = change.playerId {
                                    NavigationLink(value: Route.player(playerId)) {
                                        Image(systemName: "chevron.forward")
                                            .font(.system(size: 11, weight: .semibold))
                                            .foregroundStyle(Palette.muted)
                                    }
                                }
                            }
                        }

                        Text("Detected by diffing the team list each poll and watching for hard price moves. Scores re-run as soon as a change lands.")
                            .font(.system(size: 11))
                            .foregroundStyle(Palette.muted)
                    }
                }
            }
        }
    }

    // MARK: - Win prediction

    @ViewBuilder
    private func winPrediction(_ match: Match) -> some View {
        if match.hasWinPrediction {
            SectionBlock(title: "Win prediction") {
                Card {
                    VStack(alignment: .leading, spacing: 16) {
                        HStack(alignment: .center) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(match.homeTeam.label)
                                    .displayStyle(18)
                                    .foregroundStyle(match.homeIsPredictedWinner ? Palette.accentBright : Palette.secondary)
                                Text("\(match.homeWinPct)%")
                                    .font(.numeric(24, .medium))
                                    .foregroundStyle(match.homeIsPredictedWinner ? Palette.accentBright : Palette.body)
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 2) {
                                Text(match.awayTeam.label)
                                    .displayStyle(18)
                                    .foregroundStyle(match.awayIsPredictedWinner ? Palette.accentBright : Palette.secondary)
                                Text("\(match.awayWinPct)%")
                                    .font(.numeric(24, .medium))
                                    .foregroundStyle(match.awayIsPredictedWinner ? Palette.accentBright : Palette.body)
                            }
                        }

                        WinSplitBar(homePct: match.homeWinPct, height: 10)

                        signalColumn(title: match.homeTeam.label, signals: match.signals(side: "home"))
                        signalColumn(title: match.awayTeam.label, signals: match.signals(side: "away"))
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func signalColumn(title: String, signals: [Signal]) -> some View {
        if !signals.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Eyebrow("\(title) signals")
                ForEach(signals.prefix(6)) { signal in
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(spacing: 8) {
                            Text(Fmt.signalName(signal.type))
                                .font(.system(size: 12))
                                .foregroundStyle(Palette.muted)
                                .frame(width: 120, alignment: .leading)
                                .lineLimit(1)
                            ScoreBar(
                                value: signal.strength,
                                tint: signal.strength >= 0.6 ? Palette.accent.opacity(0.7) : Palette.faint.opacity(0.5),
                                height: 6
                            )
                        }
                        Text(signal.detail)
                            .font(.system(size: 11))
                            .foregroundStyle(Palette.faint)
                            .padding(.leading, 128)
                    }
                }
            }
        }
    }

    // MARK: - Bookmaker odds

    @ViewBuilder
    private func bookmakerOdds(_ match: Match) -> some View {
        if let book = match.bookmakerOdds,
           !(book.matchWinner ?? []).isEmpty || !(book.spreads ?? []).isEmpty {
            SectionBlock(title: "Bookmaker odds") {
                Card {
                    VStack(alignment: .leading, spacing: 14) {
                        if let h2h = book.matchWinner, !h2h.isEmpty {
                            VStack(alignment: .leading, spacing: 6) {
                                Eyebrow("Head to head")
                                ForEach(h2h) { entry in
                                    HStack {
                                        Text(entry.bookmaker.capitalized)
                                            .font(.system(size: 12))
                                            .foregroundStyle(Palette.secondary)
                                        Spacer()
                                        ForEach(Array(entry.odds.enumerated()), id: \.offset) { _, price in
                                            Text(Fmt.odds(price))
                                                .font(.numeric(13, .medium))
                                                .foregroundStyle(Palette.body)
                                                .frame(width: 52, alignment: .trailing)
                                        }
                                    }
                                }
                            }
                        }

                        if let spreads = book.spreads, !spreads.isEmpty {
                            VStack(alignment: .leading, spacing: 6) {
                                Eyebrow("Line")
                                ForEach(spreads) { entry in
                                    HStack {
                                        Text(entry.bookmaker.capitalized)
                                            .font(.system(size: 12))
                                            .foregroundStyle(Palette.secondary)
                                        Spacer()
                                        ForEach(Array(entry.odds.enumerated()), id: \.offset) { _, price in
                                            VStack(alignment: .trailing, spacing: 1) {
                                                Text(Fmt.odds(price.price))
                                                    .font(.numeric(13, .medium))
                                                    .foregroundStyle(Palette.body)
                                                if let point = price.point?.value {
                                                    Text(String(format: "%+.1f", point))
                                                        .font(.numeric(10))
                                                        .foregroundStyle(Palette.faint)
                                                }
                                            }
                                            .frame(width: 52, alignment: .trailing)
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // MARK: - Predicted try scorers

    private var predictionsSection: some View {
        SectionBlock(title: "Predicted try scorers") {
            AsyncContent(loadable: predictions, retry: { Task { await reload(force: true) } }) { payload in
                if payload.data.isEmpty {
                    EmptyCard(message: "No predictions yet. Run an analysis pass from the web app.")
                } else {
                    LazyVStack(spacing: 8) {
                        ForEach(payload.data) { row in
                            predictionRow(row)
                        }
                    }
                }
            }
        }
    }

    private func predictionRow(_ row: PredictionRow) -> some View {
        Card(padding: 14) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 12) {
                    Text("\(row.rank)")
                        .font(.numeric(18, .medium))
                        .foregroundStyle(row.rank <= 3 ? Palette.accentBright : Palette.muted)
                        .frame(width: 26, alignment: .trailing)

                    VStack(alignment: .leading, spacing: 2) {
                        Text(row.player.name ?? "—")
                            .font(.system(size: 14, weight: .medium))
                            .foregroundStyle(Palette.heading)
                        Text([row.player.team, Fmt.position(row.player.position)]
                            .compactMap { $0 }
                            .joined(separator: " · "))
                            .font(.system(size: 11))
                            .foregroundStyle(Palette.muted)
                    }

                    Spacer(minLength: 6)

                    VStack(alignment: .trailing, spacing: 4) {
                        Text("\(row.score)")
                            .font(.numeric(16, .medium))
                            .foregroundStyle(Palette.body)
                        ScoreBar(value: Double(row.score) / 100, tint: Palette.scoreTier(row.score), height: 6)
                            .frame(width: 64)
                    }
                }

                if !row.advantageTags.isEmpty {
                    HStack(spacing: 4) {
                        ForEach(row.advantageTags.prefix(4), id: \.label) { tag in
                            Chip(tag.label, tone: tag.tone)
                        }
                    }
                    .padding(.leading, 38)
                }

                if isExpanded(row) {
                    Divider().overlay(Palette.track)
                    VStack(alignment: .leading, spacing: 10) {
                        if let reasoning = row.aiReasoning, !reasoning.isEmpty {
                            VStack(alignment: .leading, spacing: 4) {
                                Eyebrow("AI reasoning")
                                Text(reasoning)
                                    .font(.system(size: 13))
                                    .foregroundStyle(Palette.secondary)
                            }
                        }
                        VStack(alignment: .leading, spacing: 6) {
                            Eyebrow("Signals")
                            ForEach(row.topSignals.prefix(8)) { signal in
                                HStack(spacing: 8) {
                                    Text(Fmt.signalName(signal.type))
                                        .font(.system(size: 11))
                                        .foregroundStyle(Palette.muted)
                                        .frame(width: 130, alignment: .leading)
                                        .lineLimit(1)
                                    ScoreBar(
                                        value: signal.strength,
                                        tint: signal.strength >= 0.7 ? Palette.accent : Palette.faint.opacity(0.6),
                                        height: 5
                                    )
                                    Text("×\(signal.weight ?? 0)")
                                        .font(.numeric(10))
                                        .foregroundStyle(Palette.faint)
                                        .frame(width: 28, alignment: .trailing)
                                }
                            }
                        }
                    }
                }

                Button(isExpanded(row) ? "Hide" : "Detail") { toggle(row) }
                    .buttonStyle(GhostButtonStyle())
            }
        }
    }

    private func isExpanded(_ row: PredictionRow) -> Bool { expanded.contains(row.id) }

    private func toggle(_ row: PredictionRow) {
        withAnimation(.snappy(duration: 0.18)) {
            if expanded.contains(row.id) { expanded.remove(row.id) } else { expanded.insert(row.id) }
        }
    }

    // MARK: - Anytime try scorer market

    private var tryScorerOddsSection: some View {
        Group {
            if let entries = odds.value?.data.anytimeTryScorer, !entries.isEmpty {
                SectionBlock(title: "Anytime try scorer market", trailing: "best price") {
                    Card(padding: 0) {
                        VStack(spacing: 0) {
                            ForEach(Array(entries.prefix(12).enumerated()), id: \.element.id) { index, entry in
                                HStack(spacing: 10) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(entry.playerName ?? "—")
                                            .font(.system(size: 13, weight: .medium))
                                            .foregroundStyle(Palette.heading)
                                        Text([entry.team, Fmt.position(entry.position)]
                                            .compactMap { $0 }
                                            .joined(separator: " · "))
                                            .font(.system(size: 11))
                                            .foregroundStyle(Palette.muted)
                                    }
                                    Spacer()
                                    VStack(alignment: .trailing, spacing: 2) {
                                        Text(Fmt.odds(entry.bestOdds))
                                            .font(.numeric(14, .medium))
                                            .foregroundStyle(Palette.accentBright)
                                        Text(entry.avgImpliedProbability)
                                            .font(.numeric(10))
                                            .foregroundStyle(Palette.faint)
                                    }
                                }
                                .padding(.horizontal, 14)
                                .padding(.vertical, 10)
                                if index < min(12, entries.count) - 1 {
                                    Divider().overlay(Palette.track)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // MARK: - Team lists

    private var teamListsSection: some View {
        Group {
            if let lists = match.value?.data?.teamLists,
               !lists.home.isEmpty || !lists.away.isEmpty,
               let match = match.value?.data {
                SectionBlock(title: "Team lists") {
                    VStack(spacing: 12) {
                        teamListCard(title: match.homeTeam.label, entries: lists.home)
                        teamListCard(title: match.awayTeam.label, entries: lists.away)
                    }
                }
            }
        }
    }

    private func teamListCard(title: String, entries: [TeamListEntry]) -> some View {
        Card {
            VStack(alignment: .leading, spacing: 8) {
                Eyebrow(title)
                ForEach(entries) { entry in
                    HStack(spacing: 10) {
                        Text("\(entry.positionNumber)")
                            .font(.numeric(11))
                            .foregroundStyle(Palette.faint)
                            .frame(width: 18, alignment: .trailing)
                        NavigationLink(value: Route.player(entry.playerId)) {
                            Text(entry.name ?? "—")
                                .font(.system(size: 13))
                                .foregroundStyle(Palette.body)
                        }
                        Spacer()
                        if let position = Fmt.position(entry.position) {
                            Text(position)
                                .font(.system(size: 11))
                                .foregroundStyle(Palette.muted)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Loading

    private func reload(force: Bool) async {
        async let matchTask: Void = match.load(force: force) {
            try await APIClient.shared.get("/api/v1/matches/\(matchId)")
        }
        async let predictionTask: Void = predictions.load(force: force) {
            try await APIClient.shared.get("/api/v1/matches/\(matchId)/predictions")
        }
        async let oddsTask: Void = odds.load(force: force) {
            try await APIClient.shared.get("/api/v1/matches/\(matchId)/odds")
        }
        _ = await (matchTask, predictionTask, oddsTask)
    }
}
