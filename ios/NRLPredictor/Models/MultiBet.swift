import Foundation

enum RiskProfile: String, CaseIterable, Identifiable, Sendable {
    case safe, balanced, value

    var id: String { rawValue }
    var label: String { rawValue.capitalized }

    var blurb: String {
        switch self {
        case .safe: "Fewer legs, higher individual probability, conservative picks only."
        case .balanced: "Mix of likely outcomes with decent return potential."
        case .value: "More legs, includes underdogs and tighter contests."
        }
    }
}

struct LegBookmakerOdds: Decodable, Hashable, Sendable {
    let bestDecimalOdds: Double
    let bestBookmaker: String
    /// Percent-suffixed string, e.g. "42.5%".
    let impliedProbability: String
    let bookmakerCount: Int
}

struct MultiSignal: Decodable, Hashable, Sendable, Identifiable {
    var id: String { "\(type)-\(detail)" }
    let type: String
    /// Already scaled 0...100 by MultiBetBuilder.
    let strength: Int
    let detail: String
    let impact: Double

    enum CodingKeys: String, CodingKey {
        case type, strength, impact
        case detail = "description"
    }
}

/// One struct covers both leg types — `MultiBetBuilder` strips null keys, so
/// everything type-specific is optional.
struct MultiLeg: Decodable, Hashable, Sendable, Identifiable {
    let type: String
    let matchId: Int
    let match: String
    let venue: String?
    let kickoffAt: String?
    let selection: String
    let selectionTeamId: Int?
    let selectionPlayerId: Int?
    let team: String?
    let position: String?
    let rankInMatch: Int?
    let predictionScore: Int?
    let probability: Int
    let confidence: Int
    let isValuePick: Bool
    let reasoning: String
    let signals: [MultiSignal]?
    let aiReasoning: String?
    let bookmakerOdds: LegBookmakerOdds?

    var id: String { "\(type)-\(matchId)-\(selectionPlayerId ?? selectionTeamId ?? 0)" }
    var isWinner: Bool { type == "match_winner" }
    var kindLabel: String { isWinner ? "Winner" : "Try scorer" }
    var subtitle: String? {
        let parts = [team, Fmt.position(position)].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}

struct MultiSummary: Decodable, Hashable, Sendable {
    let totalLegs: Int?
    let combinedProbabilityPct: Double?
    let overallConfidence: Int?
    let confidenceLabel: String?
    let recommendation: String?
    let error: String?
}

struct MultiBetResponse: Decodable, Hashable, Sendable {
    let round: Int?
    let season: Int?
    let riskProfile: String?
    let legs: [MultiLeg]
    let summary: MultiSummary
}
