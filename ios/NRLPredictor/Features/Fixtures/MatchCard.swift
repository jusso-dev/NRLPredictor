import SwiftUI

/// The dashboard match card: status chip + kickoff, two team lines with win %,
/// the split probability bar, then venue and the top predicted try scorer.
struct MatchCard: View {
    let match: Match
    var topPick: PredictionRow? = nil

    var body: some View {
        Card(padding: 16) {
            VStack(alignment: .leading, spacing: 14) {
                header
                teamRow(
                    name: match.homeTeam.label,
                    pct: match.homeWinPct,
                    score: match.homeScore,
                    isWinner: match.homeIsPredictedWinner
                )
                teamRow(
                    name: match.awayTeam.label,
                    pct: match.awayWinPct,
                    score: match.awayScore,
                    isWinner: match.awayIsPredictedWinner
                )
                if match.hasWinPrediction {
                    WinSplitBar(homePct: match.homeWinPct)
                }
                footer
            }
        }
    }

    private var header: some View {
        HStack {
            Chip(match.statusBadge, tone: match.statusTone)
            if let count = match.lateChangeCount, count > 0 {
                Chip("Late mail \(count)", tone: .orange)
            }
            Spacer()
            Text(Fmt.kickoffShort(match.kickoffAt).map { "\($0) AEST" } ?? (match.kickoffAest ?? "TBC"))
                .font(.numeric(11))
                .foregroundStyle(Palette.muted)
        }
    }

    private func teamRow(name: String, pct: Int, score: Int?, isWinner: Bool) -> some View {
        HStack(spacing: 10) {
            RoundedRectangle(cornerRadius: 2)
                .fill(TeamColors.primary(name))
                .frame(width: 3, height: 18)
            Text(name)
                .displayStyle(18)
                .foregroundStyle(isWinner ? Palette.accentBright : Palette.heading)
            Spacer(minLength: 8)
            if match.hasScore, let score {
                Text("\(score)")
                    .font(.numeric(20, .medium))
                    .foregroundStyle(Palette.heading)
            }
            if match.hasWinPrediction {
                Text("\(pct)%")
                    .font(.numeric(12))
                    .foregroundStyle(isWinner ? Palette.accentBright : Palette.muted)
            }
        }
    }

    private var footer: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(match.venue ?? "Venue TBC")
                    .font(.system(size: 12))
                    .foregroundStyle(Palette.muted)
                    .lineLimit(1)
                Spacer()
            }

            VStack(alignment: .leading, spacing: 6) {
                if let topPick {
                    Eyebrow("Top pick")
                    HStack(spacing: 8) {
                        Text(topPick.player.name ?? "—")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundStyle(Palette.heading)
                            .lineLimit(1)
                        Spacer(minLength: 4)
                        Text("\(topPick.score)")
                            .font(.numeric(13, .medium))
                            .foregroundStyle(Palette.accentBright)
                    }
                    if !topPick.advantageTags.isEmpty {
                        HStack(spacing: 4) {
                            ForEach(topPick.advantageTags.prefix(3), id: \.label) { tag in
                                Chip(tag.label, tone: tag.tone)
                            }
                        }
                    }
                } else {
                    Text("No predictions yet")
                        .font(.system(size: 12))
                        .foregroundStyle(Palette.faint)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(10)
            .background(Palette.bg, in: .rect(cornerRadius: 6))
            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }
        }
    }
}
