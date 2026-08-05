import SwiftUI

struct OddsView: View {
    @State private var odds = Loadable<RoundScopedResponse<MatchOdds>>()
    @State private var expanded: Set<Int> = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                header
                AsyncContent(loadable: odds, retry: { Task { await reload(force: true) } }) { payload in
                    if payload.data.isEmpty {
                        EmptyCard(message: "No odds captured for this round yet. The scheduler pulls The Odds API every 4 hours.")
                    } else {
                        LazyVStack(spacing: 12) {
                            ForEach(payload.data) { entry in
                                matchOddsCard(entry)
                            }
                        }
                    }
                }
                ResponsibleGamblingFooter()
            }
            .padding(16)
        }
        .nrlPage("Odds")
        .toolbar {
            ToolbarItem(placement: .principal) { MastheadTitle(title: "Market Odds") }
        }
        .refreshable { await reload(force: true) }
        .task { await reload(force: false) }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow("Bookmaker markets")
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(odds.value?.round.map { "Round \($0)" } ?? "Current round")
                    .displayStyle(30, weight: .bold)
                    .foregroundStyle(Palette.heading)
                if let season = odds.value?.season {
                    Text("— \(String(season))")
                        .font(.display(18))
                        .foregroundStyle(Palette.muted)
                }
            }
            Text("Head to head, line, totals and anytime try scorer prices from AU bookmakers.")
                .font(.system(size: 13))
                .foregroundStyle(Palette.muted)
        }
    }

    private func matchOddsCard(_ entry: MatchOdds) -> some View {
        let isOpen = expanded.contains(entry.id)

        return Card(padding: 14) {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(entry.match ?? "—")
                            .displayStyle(18)
                            .foregroundStyle(Palette.heading)
                        if let kickoff = Fmt.kickoffShort(entry.kickoffAt) {
                            Text("\(kickoff) AEST")
                                .font(.numeric(11))
                                .foregroundStyle(Palette.muted)
                        }
                    }
                    Spacer()
                    if let matchId = entry.matchId {
                        NavigationLink(value: Route.match(matchId)) {
                            Image(systemName: "chevron.forward")
                                .font(.system(size: 12, weight: .semibold))
                                .foregroundStyle(Palette.muted)
                        }
                    }
                }

                if let h2h = entry.matchWinner, !h2h.isEmpty {
                    marketBlock(title: "Head to head", markets: h2h)
                }

                if isOpen {
                    if let spreads = entry.spreads, !spreads.isEmpty {
                        marketBlock(title: "Line", markets: spreads, showPoint: true)
                    }
                    if let totals = entry.totals, !totals.isEmpty {
                        marketBlock(title: "Totals", markets: totals, showPoint: true)
                    }
                    if let ats = entry.anytimeTryScorer, !ats.isEmpty {
                        atsBlock(ats)
                    }
                }

                if entry.hasAnyMarket {
                    Button(isOpen ? "Hide markets" : "All markets") {
                        withAnimation(.snappy(duration: 0.18)) {
                            if isOpen { expanded.remove(entry.id) } else { expanded.insert(entry.id) }
                        }
                    }
                    .buttonStyle(GhostButtonStyle())
                } else {
                    Text("No prices stored for this match.")
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.faint)
                }
            }
        }
    }

    private func marketBlock(title: String, markets: [BookmakerMarket], showPoint: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow(title)
            ForEach(markets) { market in
                HStack(spacing: 8) {
                    Text(market.bookmaker.capitalized)
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.secondary)
                    Spacer()
                    ForEach(market.outcomes) { outcome in
                        VStack(alignment: .trailing, spacing: 1) {
                            Text(Fmt.odds(outcome.decimalOdds))
                                .font(.numeric(13, .medium))
                                .foregroundStyle(Palette.body)
                            if showPoint, let point = outcome.point?.value {
                                Text(String(format: "%+.1f", point))
                                    .font(.numeric(10))
                                    .foregroundStyle(Palette.faint)
                            } else if let implied = outcome.impliedProbability {
                                Text(implied)
                                    .font(.numeric(10))
                                    .foregroundStyle(Palette.faint)
                            }
                        }
                        .frame(width: 54, alignment: .trailing)
                    }
                }
            }
        }
    }

    private func atsBlock(_ entries: [AtsEntry]) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow("Anytime try scorer")
            ForEach(entries.prefix(10)) { entry in
                HStack(spacing: 8) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(entry.playerName ?? "—")
                            .font(.system(size: 13))
                            .foregroundStyle(Palette.body)
                        Text([entry.team, Fmt.position(entry.position)]
                            .compactMap { $0 }
                            .joined(separator: " · "))
                            .font(.system(size: 10))
                            .foregroundStyle(Palette.muted)
                    }
                    Spacer()
                    Text("\(entry.bookmakers.count) books")
                        .font(.system(size: 10))
                        .foregroundStyle(Palette.faint)
                    Text(Fmt.odds(entry.bestOdds))
                        .font(.numeric(13, .medium))
                        .foregroundStyle(Palette.accentBright)
                        .frame(width: 48, alignment: .trailing)
                }
            }
        }
    }

    private func reload(force: Bool) async {
        await odds.load(force: force) {
            try await APIClient.shared.get("/api/v1/odds")
        }
    }
}
