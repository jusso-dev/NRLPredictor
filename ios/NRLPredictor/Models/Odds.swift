import Foundation

struct OddsOutcome: Decodable, Hashable, Sendable, Identifiable {
    var id: String { "\(decimalOdds ?? 0)-\(point?.value ?? 0)-\(impliedProbability ?? "")" }
    let decimalOdds: Double?
    /// Percent-suffixed string, e.g. "54.1%".
    let impliedProbability: String?
    let point: LooseDouble?
}

struct BookmakerMarket: Decodable, Hashable, Sendable, Identifiable {
    var id: String { bookmaker }
    let bookmaker: String
    let outcomes: [OddsOutcome]
    let capturedAt: String?
}

struct AtsPrice: Decodable, Hashable, Sendable, Identifiable {
    var id: String { bookmaker }
    let bookmaker: String
    let decimalOdds: Double
    let impliedProbability: String
}

struct AtsEntry: Decodable, Hashable, Sendable, Identifiable {
    var id: Int { playerId ?? 0 }
    let playerId: Int?
    let playerName: String?
    let position: String?
    let team: String?
    let bookmakers: [AtsPrice]
    let bestOdds: Double
    let avgImpliedProbability: String
}

struct MatchOdds: Decodable, Hashable, Sendable, Identifiable {
    var id: Int { matchId ?? 0 }
    let matchId: Int?
    let match: String?
    let kickoffAt: String?
    let matchWinner: [BookmakerMarket]?
    let spreads: [BookmakerMarket]?
    let totals: [BookmakerMarket]?
    let anytimeTryScorer: [AtsEntry]?

    var hasAnyMarket: Bool {
        !(matchWinner ?? []).isEmpty
            || !(spreads ?? []).isEmpty
            || !(totals ?? []).isEmpty
            || !(anytimeTryScorer ?? []).isEmpty
    }
}

/// `GET /api/v1/matches/{match}/odds` returns a single object under `data`.
struct MatchOddsResponse: Decodable, Sendable {
    let matchId: Int?
    let match: String?
    let data: MatchOdds
}
