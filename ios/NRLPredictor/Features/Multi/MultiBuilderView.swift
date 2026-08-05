import SwiftUI

struct MultiBuilderView: View {
    @State private var risk: RiskProfile = .balanced
    @State private var legCount = 6
    @State private var result = Loadable<MultiBetResponse>()
    /// Legs the user has tapped off the slip — the model still returns them.
    @State private var excluded: Set<String> = []
    @State private var stake = "10"

    private var legs: [MultiLeg] { result.value?.legs ?? [] }
    private var includedLegs: [MultiLeg] { legs.filter { !excluded.contains($0.id) } }

    /// Naive independence, the same assumption `MultiBetBuilder` makes.
    private var modelProbability: Double {
        includedLegs.reduce(1.0) { $0 * (Double($1.probability) / 100) }
    }

    private var legsWithoutOdds: Int {
        includedLegs.filter { $0.bookmakerOdds == nil }.count
    }

    /// Product of each leg's best bookmaker price. Nil when any included leg has no market.
    private var combinedOdds: Double? {
        guard !includedLegs.isEmpty, legsWithoutOdds == 0 else { return nil }
        return includedLegs.reduce(1.0) { $0 * ($1.bookmakerOdds?.bestDecimalOdds ?? 1) }
    }

    private var stakeValue: Double { Double(stake) ?? 0 }

    private var potentialReturn: Double? {
        guard let combinedOdds else { return nil }
        return stakeValue * combinedOdds
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                header
                controls
                AsyncContent(loadable: result, retry: { Task { await build() } }) { payload in
                    VStack(alignment: .leading, spacing: 24) {
                        if let error = payload.summary.error {
                            EmptyCard(message: error)
                        } else {
                            summaryCard(payload)
                            slipCard(payload)
                            legsSection(payload)
                        }
                    }
                }
                ResponsibleGamblingFooter()
            }
            .padding(16)
        }
        .nrlPage("Multi")
        .toolbar {
            ToolbarItem(placement: .principal) { MastheadTitle(title: "Multi Builder") }
        }
        .refreshable { await build() }
        .task { await result.load { try await fetch() } }
    }

    // MARK: - Header & controls

    private var header: some View {
        VStack(alignment: .leading, spacing: 6) {
            Eyebrow("Multi builder")
            Text(result.value?.round.map { "Round \($0) Multi" } ?? "Build your multi")
                .displayStyle(30, weight: .bold)
                .foregroundStyle(Palette.heading)
            Text("Signal-driven multi-bet suggestions combining match winners and try scorers.")
                .font(.system(size: 13))
                .foregroundStyle(Palette.muted)

            NavigationLink(value: Route.guide) {
                HStack(spacing: 4) {
                    Image(systemName: "questionmark.circle")
                    Text("How multis are built")
                }
                .font(.system(size: 12, weight: .medium))
                .foregroundStyle(Palette.accentBright)
            }
        }
    }

    private var controls: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                ForEach(RiskProfile.allCases) { profile in
                    Button {
                        risk = profile
                        Task { await build() }
                    } label: {
                        Text(profile.label)
                            .font(.system(size: 12, weight: .semibold))
                            .tracking(0.8)
                            .textCase(.uppercase)
                            .foregroundStyle(risk == profile ? Color.black : Palette.body)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(risk == profile ? Palette.accent : Palette.surfaceAlt, in: .rect(cornerRadius: 8))
                            .overlay {
                                RoundedRectangle(cornerRadius: 8)
                                    .strokeBorder(risk == profile ? .clear : Palette.border, lineWidth: 1)
                            }
                    }
                    .buttonStyle(.plain)
                }
            }

            Text(risk.blurb)
                .font(.system(size: 12))
                .foregroundStyle(Palette.faint)

            HStack(spacing: 12) {
                Eyebrow("Legs")
                Stepper(value: $legCount, in: 2...10) {
                    Text("\(legCount)")
                        .font(.numeric(16, .medium))
                        .foregroundStyle(Palette.heading)
                }
                .fixedSize()

                Spacer()

                Button(result.isLoading ? "Building…" : "Build multi") {
                    Task { await build() }
                }
                .buttonStyle(PrimaryButtonStyle())
                .disabled(result.isLoading)
            }
        }
    }

    // MARK: - Summary

    private func summaryCard(_ payload: MultiBetResponse) -> some View {
        Card(tint: Palette.accent) {
            VStack(alignment: .leading, spacing: 14) {
                Grid(alignment: .leading, horizontalSpacing: 12, verticalSpacing: 14) {
                    GridRow {
                        stat("Legs", "\(payload.summary.totalLegs ?? payload.legs.count)")
                        stat(
                            "Combined probability",
                            payload.summary.combinedProbabilityPct.map { String(format: "%.2f%%", $0) } ?? "—",
                            tint: Palette.accentBright
                        )
                    }
                    GridRow {
                        stat("Confidence", payload.summary.overallConfidence.map { "\($0)" } ?? "—")
                        stat("Risk profile", (payload.riskProfile ?? risk.rawValue).capitalized)
                    }
                }

                if let label = payload.summary.confidenceLabel {
                    Text(label)
                        .font(.system(size: 13, weight: .medium))
                        .foregroundStyle(Palette.body)
                }
                if let recommendation = payload.summary.recommendation {
                    Text(recommendation)
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                }
            }
        }
    }

    private func stat(_ label: String, _ value: String, tint: Color = Palette.heading) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Eyebrow(label)
            Text(value)
                .font(.display(22, .semibold))
                .foregroundStyle(tint)
                .minimumScaleFactor(0.6)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    // MARK: - Slip

    private func slipCard(_ payload: MultiBetResponse) -> some View {
        SectionBlock(title: "Your slip", trailing: "\(includedLegs.count) of \(payload.legs.count) legs") {
            Card {
                VStack(alignment: .leading, spacing: 14) {
                    HStack(spacing: 12) {
                        VStack(alignment: .leading, spacing: 4) {
                            Eyebrow("Stake")
                            HStack(spacing: 4) {
                                Text("$")
                                    .font(.numeric(16))
                                    .foregroundStyle(Palette.muted)
                                TextField("10", text: $stake)
                                    .keyboardType(.decimalPad)
                                    .font(.numeric(16, .medium))
                                    .foregroundStyle(Palette.heading)
                                    .frame(width: 70)
                            }
                            .padding(.horizontal, 10)
                            .padding(.vertical, 8)
                            .background(Palette.surfaceAlt, in: .rect(cornerRadius: 6))
                            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }
                        }

                        Spacer()

                        VStack(alignment: .trailing, spacing: 4) {
                            Eyebrow("Combined odds")
                            Text(combinedOdds.map { String(format: "%.2f", $0) } ?? "—")
                                .font(.numeric(22, .medium))
                                .foregroundStyle(Palette.accentBright)
                        }
                    }

                    Divider().overlay(Palette.track)

                    HStack {
                        VStack(alignment: .leading, spacing: 4) {
                            Eyebrow("Model probability")
                            Text(String(format: "%.2f%%", modelProbability * 100))
                                .font(.numeric(16, .medium))
                                .foregroundStyle(Palette.body)
                        }
                        Spacer()
                        VStack(alignment: .trailing, spacing: 4) {
                            Eyebrow("Potential return")
                            Text(potentialReturn.map { Fmt.money($0) } ?? "—")
                                .font(.numeric(16, .medium))
                                .foregroundStyle(Palette.heading)
                        }
                    }

                    if legsWithoutOdds > 0 {
                        Text("\(legsWithoutOdds) leg\(legsWithoutOdds == 1 ? "" : "s") in the slip have no bookmaker price stored, so a combined payout cannot be calculated.")
                            .font(.system(size: 11))
                            .foregroundStyle(Palette.orange)
                    }

                    ShareLink(item: slipText(payload)) {
                        Label("Share slip", systemImage: "square.and.arrow.up")
                            .font(.system(size: 12, weight: .semibold))
                            .tracking(0.6)
                            .textCase(.uppercase)
                            .foregroundStyle(Palette.body)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 9)
                            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }
                    }
                }
            }
        }
    }

    private func slipText(_ payload: MultiBetResponse) -> String {
        var lines: [String] = []
        let round = payload.round.map { "Round \($0)" } ?? "NRL"
        lines.append("\(round) — \(includedLegs.count)-leg \(risk.label.lowercased()) multi")
        for (index, leg) in includedLegs.enumerated() {
            var line = "\(index + 1). \(leg.selection) — \(leg.kindLabel) (\(leg.match)) · \(leg.probability)%"
            if let odds = leg.bookmakerOdds {
                line += " · \(Fmt.odds(odds.bestDecimalOdds)) \(odds.bestBookmaker)"
            }
            lines.append(line)
        }
        lines.append(String(format: "Model probability: %.2f%%", modelProbability * 100))
        if let combinedOdds {
            lines.append("Combined odds: \(Fmt.odds(combinedOdds)) · \(Fmt.money(stakeValue)) returns \(Fmt.money(stakeValue * combinedOdds))")
        }
        lines.append("Model-driven, not betting advice. Gamble responsibly — 1800 858 858.")
        return lines.joined(separator: "\n")
    }

    // MARK: - Legs

    private func legsSection(_ payload: MultiBetResponse) -> some View {
        SectionBlock(title: "Legs") {
            if payload.legs.isEmpty {
                EmptyCard(message: "No suitable legs found for this round. Try a different risk profile or wait for more data.")
            } else {
                LazyVStack(spacing: 10) {
                    ForEach(Array(payload.legs.enumerated()), id: \.element.id) { index, leg in
                        legCard(index: index + 1, leg: leg)
                    }
                }
            }
        }
    }

    private func legCard(index: Int, leg: MultiLeg) -> some View {
        let isIncluded = !excluded.contains(leg.id)

        return Card(padding: 14, tint: leg.isValuePick ? Palette.orange : nil) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 12) {
                    Text("\(index)")
                        .font(.numeric(14, .bold))
                        .foregroundStyle(leg.isWinner ? Palette.accentBright : Palette.secondary)
                        .frame(width: 30, height: 30)
                        .background(leg.isWinner ? Palette.accent.opacity(0.2) : Palette.track, in: .circle)

                    VStack(alignment: .leading, spacing: 6) {
                        HStack(spacing: 6) {
                            Chip(leg.kindLabel, tone: leg.isWinner ? .gold : .muted)
                            if leg.isValuePick { Chip("Value pick", tone: .orange) }
                            Text(leg.match)
                                .font(.system(size: 11))
                                .foregroundStyle(Palette.muted)
                                .lineLimit(1)
                        }

                        Text(leg.selection)
                            .displayStyle(18)
                            .foregroundStyle(Palette.heading)

                        if let subtitle = leg.subtitle {
                            Text(subtitle)
                                .font(.system(size: 11))
                                .foregroundStyle(Palette.muted)
                        }
                    }

                    Spacer(minLength: 4)

                    VStack(alignment: .trailing, spacing: 2) {
                        Text("\(leg.probability)%")
                            .font(.numeric(22, .medium))
                            .foregroundStyle(leg.probability >= 55 ? Palette.accentBright : Palette.body)
                        Text("probability")
                            .font(.system(size: 10))
                            .foregroundStyle(Palette.faint)
                    }
                }

                HStack(spacing: 14) {
                    metric("Confidence", "\(leg.confidence)/100")
                    if let odds = leg.bookmakerOdds {
                        metric("Best", "\(Fmt.odds(odds.bestDecimalOdds)) · \(odds.bestBookmaker)")
                    }
                    if let kickoff = Fmt.kickoffCompact(leg.kickoffAt) {
                        metric("Kickoff", "\(kickoff) AEST")
                    }
                }

                Text(leg.reasoning)
                    .font(.system(size: 13))
                    .foregroundStyle(Palette.secondary)

                if let signals = leg.signals, !signals.isEmpty {
                    FlowRow(spacing: 6) {
                        ForEach(signals) { signal in
                            HStack(spacing: 4) {
                                Text(signal.type)
                                    .font(.system(size: 11))
                                    .foregroundStyle(Palette.muted)
                                Text("\(signal.strength)")
                                    .font(.numeric(11, .medium))
                                    .foregroundStyle(signal.strength >= 60 ? Palette.accentBright : Palette.faint)
                            }
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Palette.bg, in: .rect(cornerRadius: 4))
                            .overlay { RoundedRectangle(cornerRadius: 4).strokeBorder(Palette.border, lineWidth: 1) }
                        }
                    }
                }

                if let ai = leg.aiReasoning, !ai.isEmpty {
                    Text("AI: \(ai)")
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.muted)
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Palette.bg, in: .rect(cornerRadius: 6))
                }

                HStack(spacing: 10) {
                    Button(isIncluded ? "In slip" : "Add to slip") {
                        withAnimation(.snappy(duration: 0.15)) {
                            if isIncluded { excluded.insert(leg.id) } else { excluded.remove(leg.id) }
                        }
                    }
                    .buttonStyle(GhostButtonStyle())
                    .foregroundStyle(isIncluded ? Palette.accentBright : Palette.muted)

                    NavigationLink(value: Route.match(leg.matchId)) {
                        Text("Match")
                            .font(.system(size: 12, weight: .semibold))
                            .tracking(0.6)
                            .textCase(.uppercase)
                            .foregroundStyle(Palette.muted)
                    }
                }
            }
            .opacity(isIncluded ? 1 : 0.45)
        }
    }

    private func metric(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label.uppercased())
                .font(.system(size: 9, weight: .semibold))
                .tracking(1)
                .foregroundStyle(Palette.faint)
            Text(value)
                .font(.numeric(12))
                .foregroundStyle(Palette.body)
        }
    }

    // MARK: - Loading

    private func build() async {
        excluded.removeAll()
        await result.load(force: true) { try await fetch() }
    }

    private func fetch() async throws -> MultiBetResponse {
        try await APIClient.shared.get(
            "/api/v1/multi-bet",
            query: [
                URLQueryItem(name: "legs", value: String(legCount)),
                URLQueryItem(name: "risk", value: risk.rawValue),
            ]
        )
    }
}

/// Minimal wrapping HStack for signal tiles.
struct FlowRow: Layout {
    var spacing: CGFloat = 6

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let maxWidth = proposal.width ?? .infinity
        var x: CGFloat = 0
        var y: CGFloat = 0
        var rowHeight: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            if x + size.width > maxWidth, x > 0 {
                x = 0
                y += rowHeight + spacing
                rowHeight = 0
            }
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
        return CGSize(width: maxWidth == .infinity ? x : maxWidth, height: y + rowHeight)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX
        var y = bounds.minY
        var rowHeight: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            if x + size.width > bounds.maxX, x > bounds.minX {
                x = bounds.minX
                y += rowHeight + spacing
                rowHeight = 0
            }
            subview.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
    }
}
