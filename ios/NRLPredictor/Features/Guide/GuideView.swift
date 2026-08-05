import SwiftUI

/// "How to use" — plain-language orientation, with the model's live numbers
/// (weights, tiers, refresh cadence) pulled from /api/v1/methodology so the
/// copy cannot drift from what the server actually does.
struct GuideView: View {
    @State private var methodology = Loadable<Methodology>()

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 26) {
                header
                startHere
                matchCardAnatomy
                scoreMeaning
                chipLegend
                signalsSection
                multiSection
                oddsSection
                dataSection
                aiSection
                ResponsibleGamblingFooter()
            }
            .padding(16)
        }
        .nrlPage("How to use")
        .task { await methodology.load { try await APIClient.shared.get("/api/v1/methodology") } }
    }

    // MARK: - Intro

    private var header: some View {
        VStack(alignment: .leading, spacing: 8) {
            Eyebrow("Guide")
            Text("How to use this app")
                .displayStyle(30, weight: .bold)
                .foregroundStyle(Palette.heading)
            Text("Every player named in a team list is scored against dozens of weighted signals — form, position, who they are up against, venue history, market prices. A separate model picks match winners. The app turns both into fixtures you can read at a glance and multis you can adjust.")
                .font(.system(size: 14))
                .foregroundStyle(Palette.secondary)
            Text("Model output, not betting advice.")
                .font(.system(size: 12, weight: .medium))
                .foregroundStyle(Palette.orange)
        }
    }

    private var startHere: some View {
        SectionBlock(title: "Start here") {
            Card {
                VStack(alignment: .leading, spacing: 14) {
                    step(1, "Round", "The round tab lists this week's fixtures with each side's win probability and the top predicted try scorer. Use Change round to look back or ahead.")
                    step(2, "Match", "Tap any match for the signals behind the win call, ranked try scorers, bookmaker prices and both team lists. Tap Detail on a player to see every signal that moved them.")
                    step(3, "Multi", "The multi tab builds a suggested multi. Pick a risk profile and leg count, then tap legs off to reshape it — the odds and returns recalculate as you go.")
                }
            }
        }
    }

    private func step(_ number: Int, _ title: String, _ body: String) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Text("\(number)")
                .font(.numeric(13, .bold))
                .foregroundStyle(Palette.accentBright)
                .frame(width: 26, height: 26)
                .background(Palette.accent.opacity(0.18), in: .circle)

            VStack(alignment: .leading, spacing: 3) {
                Text(title)
                    .displayStyle(16)
                    .foregroundStyle(Palette.heading)
                Text(body)
                    .font(.system(size: 13))
                    .foregroundStyle(Palette.secondary)
            }
        }
    }

    // MARK: - Reading the UI

    private var matchCardAnatomy: some View {
        SectionBlock(title: "Reading a match card") {
            Card {
                VStack(alignment: .leading, spacing: 12) {
                    anatomyRow("Status", "Upcoming, live or final. Live cards update as scores land.")
                    anatomyRow("Kickoff", "Always shown in Sydney time (AEST/AEDT), wherever you are.")
                    anatomyRow("Win %", "The two percentages always add to 100. The green bar is the home side's share, and the greener team name is the model's pick.")
                    anatomyRow("Top pick", "The highest ranked try scorer for that match, with its score out of 100.")
                    anatomyRow("Chips", "Shorthand for the strongest signals behind a pick — see the legend below.")
                }
            }
        }
    }

    private func anatomyRow(_ label: String, _ body: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Eyebrow(label)
            Text(body)
                .font(.system(size: 13))
                .foregroundStyle(Palette.secondary)
        }
    }

    // MARK: - Scores

    private var scoreMeaning: some View {
        SectionBlock(title: "What a try scorer score means") {
            Card {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Scores are normalised **within a match**, so the top pick in every game sits near 100. Compare players inside a match, not across matches — a 90 in one game is not stronger than an 80 in another.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)

                    if let tiers = methodology.value?.tryScorerPrediction.scoreTiers {
                        VStack(alignment: .leading, spacing: 8) {
                            ForEach(tiers) { tier in
                                HStack(spacing: 10) {
                                    RoundedRectangle(cornerRadius: 2)
                                        .fill(tierColor(tier.range))
                                        .frame(width: 22, height: 8)
                                    Text(tier.range)
                                        .font(.numeric(12))
                                        .foregroundStyle(Palette.body)
                                        .frame(width: 56, alignment: .leading)
                                    Text(tier.label)
                                        .font(.system(size: 13))
                                        .foregroundStyle(Palette.muted)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private func tierColor(_ range: String) -> Color {
        Palette.scoreTier(Int(range.prefix(while: \.isNumber)) ?? 0)
    }

    private var chipLegend: some View {
        SectionBlock(title: "Advantage chips") {
            Card {
                VStack(alignment: .leading, spacing: 10) {
                    Text("A chip appears when that signal is firing strongly for a player.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)
                    legendRow("Hot form", .red, "Scoring in recent games.")
                    legendRow("Milestone", .gold, "Approaching a games or tries milestone.")
                    legendRow("Venue", .green, "Strong record at this ground.")
                    legendRow("Matchup", .blue, "Good history against this opponent.")
                    legendRow("Returning", .orange, "Back from a lay-off.")
                    legendRow("Opp. weak", .purple, "The opposition edge or defence is exposed.")
                }
            }
        }
    }

    private func legendRow(_ label: String, _ tone: ChipTone, _ body: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Chip(label, tone: tone)
                .frame(width: 96, alignment: .leading)
            Text(body)
                .font(.system(size: 12))
                .foregroundStyle(Palette.muted)
        }
    }

    // MARK: - Signals

    private var signalsSection: some View {
        SectionBlock(title: "Signals") {
            Card {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Each signal has a **weight** (how much it can ever matter) and a **strength** (how hard it is firing for this player right now). The bars on a player's detail view show strength; the ×number is weight. Weights retune themselves after each completed round.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)

                    if let method = methodology.value {
                        Text("Try scorers: \(method.tryScorerPrediction.signals.count) signals, \(method.tryScorerPrediction.maxRawScore) points at maximum. Winners: \(method.winPrediction.signals.count) signals, \(method.winPrediction.maxRawScore) points.")
                            .font(.system(size: 12))
                            .foregroundStyle(Palette.muted)

                        weightList(title: "Heaviest try scorer signals", signals: Array(method.tryScorerPrediction.signals.prefix(6)))
                        weightList(title: "Heaviest winner signals", signals: Array(method.winPrediction.signals.prefix(5)))
                        positionWeights(method.tryScorerPrediction.positionWeights)
                    } else if methodology.error != nil {
                        Text("Signal weights are unavailable — the API could not be reached.")
                            .font(.system(size: 12))
                            .foregroundStyle(Palette.faint)
                    }
                }
            }
        }
    }

    private func weightList(title: String, signals: [Methodology.WeightedSignal]) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow(title)
            let maxWeight = signals.map(\.weight).max() ?? 1
            ForEach(signals) { signal in
                HStack(spacing: 8) {
                    Text(Fmt.signalName(signal.type))
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                        .frame(width: 140, alignment: .leading)
                        .lineLimit(1)
                    ScoreBar(value: Double(signal.weight) / Double(maxWeight), tint: Palette.accent.opacity(0.7), height: 6)
                    Text("×\(signal.weight)")
                        .font(.numeric(11))
                        .foregroundStyle(Palette.faint)
                        .frame(width: 30, alignment: .trailing)
                }
            }
        }
    }

    private func positionWeights(_ weights: [String: Double]) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow("Position base weight")
            Text("Where a player packs down changes their baseline chance before any form is considered.")
                .font(.system(size: 12))
                .foregroundStyle(Palette.muted)
            ForEach(weights.sorted { $0.value > $1.value }, id: \.key) { position, weight in
                HStack(spacing: 8) {
                    Text(Fmt.position(position) ?? position)
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                        .frame(width: 100, alignment: .leading)
                    ScoreBar(value: weight, tint: Palette.accent.opacity(0.7), height: 6)
                    Text(String(format: "%.2f", weight))
                        .font(.numeric(11))
                        .foregroundStyle(Palette.faint)
                        .frame(width: 34, alignment: .trailing)
                }
            }
        }
    }

    // MARK: - Multi

    private var multiSection: some View {
        SectionBlock(title: "Building a multi") {
            Card {
                VStack(alignment: .leading, spacing: 12) {
                    Text("The builder mixes match winners and try scorers, reserving roughly a third of the slots for winners and never taking more than two legs from one match.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)

                    if let profiles = methodology.value?.multiBet.riskProfiles {
                        VStack(alignment: .leading, spacing: 8) {
                            ForEach(RiskProfile.allCases) { profile in
                                if let blurb = profiles[profile.rawValue] {
                                    HStack(alignment: .top, spacing: 10) {
                                        Chip(profile.label, tone: .gold)
                                            .frame(width: 84, alignment: .leading)
                                        Text(blurb)
                                            .font(.system(size: 12))
                                            .foregroundStyle(Palette.muted)
                                    }
                                }
                            }
                        }
                    }

                    Divider().overlay(Palette.track)

                    anatomyRow("Your slip", "Tap In slip on any leg to drop it. Probability, combined odds and the return update immediately.")
                    anatomyRow("Combined odds", "The product of each leg's best bookmaker price. If any leg in the slip has no stored price, no payout is shown rather than a misleading one.")
                    anatomyRow("Model probability", "Leg probabilities multiplied together, which assumes the legs are independent. Two legs from the same match are not, so treat it as an upper bound.")
                }
            }
        }
    }

    private var oddsSection: some View {
        SectionBlock(title: "Odds") {
            Card {
                VStack(alignment: .leading, spacing: 10) {
                    Text("Prices come from Australian bookmakers via The Odds API and are captured periodically, not streamed — treat them as indicative and check the book before betting.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)
                    anatomyRow("Best price", "Try scorer markets show the longest price across the books that were captured, with the implied probability beneath it.")
                    anatomyRow("Line and totals", "Shown under All markets on the odds tab, with the handicap or total beneath each price.")
                }
            }
        }
    }

    // MARK: - Data

    private var dataSection: some View {
        SectionBlock(title: "Where the data comes from") {
            Card {
                VStack(alignment: .leading, spacing: 10) {
                    Text("Everything is scraped from public sources on a schedule, so a fixture that has just been named may take a cycle to appear.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)

                    if let sources = methodology.value?.dataSources {
                        ForEach(sources) { source in
                            HStack(alignment: .top, spacing: 8) {
                                VStack(alignment: .leading, spacing: 1) {
                                    Text(source.name)
                                        .font(.system(size: 13, weight: .medium))
                                        .foregroundStyle(Palette.body)
                                    Text(source.url)
                                        .font(.system(size: 11))
                                        .foregroundStyle(Palette.faint)
                                }
                                Spacer()
                                Text(source.refresh)
                                    .font(.numeric(11))
                                    .foregroundStyle(Palette.accentBright)
                            }
                        }
                    }

                    Text("Pull down on any screen to refresh.")
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                }
            }
        }
    }

    private var aiSection: some View {
        SectionBlock(title: "The AI pass") {
            Card {
                VStack(alignment: .leading, spacing: 8) {
                    Text(methodology.value?.aiReview.description
                        ?? "An optional AI review nudges scores using team lists, injuries, venue history and news context.")
                        .font(.system(size: 13))
                        .foregroundStyle(Palette.secondary)
                    if let model = methodology.value?.aiReview.model, !model.isEmpty {
                        Text("Model: \(model)")
                            .font(.numeric(11))
                            .foregroundStyle(Palette.faint)
                    }
                    Text("Where it has an opinion, you'll see it as AI reasoning on a player's detail view.")
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                }
            }
        }
    }
}
