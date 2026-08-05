import Foundation

// MARK: - Decoding helpers

/// `point` comes back as a String under MySQL and a number under SQLite.
struct LooseDouble: Decodable, Hashable, Sendable {
    let value: Double?

    init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let double = try? container.decode(Double.self) {
            value = double
        } else if let string = try? container.decode(String.self) {
            value = Double(string)
        } else {
            value = nil
        }
    }
}

// MARK: - Rounds

struct RoundInfo: Decodable, Identifiable, Hashable, Sendable {
    let id: Int
    let season: Int
    let roundNumber: Int
    let startDate: String?
    let endDate: String?
}

struct ListResponse<T: Decodable & Sendable>: Decodable, Sendable {
    let data: [T]
}

struct ItemResponse<T: Decodable & Sendable>: Decodable, Sendable {
    let data: T?
}

/// Endpoints scoped to a round add `round` / `season` siblings — both absent when
/// there is no current round.
struct RoundScopedResponse<T: Decodable & Sendable>: Decodable, Sendable {
    let round: Int?
    let season: Int?
    let data: [T]
}

// MARK: - Teams & players

struct TeamRef: Decodable, Hashable, Sendable {
    let id: Int
    let name: String?
    let shortName: String?

    var label: String { shortName ?? name ?? "—" }
}

struct Team: Decodable, Identifiable, Hashable, Sendable {
    let id: Int
    let name: String
    let shortName: String?
    let nrlSlug: String
    let colorPrimary: String?
    let colorSecondary: String?
    let players: [TeamPlayer]?

    var label: String { shortName ?? name }
}

struct TeamPlayer: Decodable, Identifiable, Hashable, Sendable {
    let id: Int
    let name: String
    let position: String?
    let careerGames: Int
    let careerTries: Int
    let currentSeasonGames: Int
    let currentSeasonTries: Int
    let currentSeasonTryRate: Double
}

struct PlayerDetail: Decodable, Identifiable, Hashable, Sendable {
    struct Career: Decodable, Hashable, Sendable {
        let games: Int
        let tries: Int
        let tryAssists: Int
        let lineBreaks: Int
        let tryRate: Double
    }

    struct Season: Decodable, Hashable, Sendable {
        let games: Int
        let tries: Int
        let tryRate: Double
    }

    struct Injury: Decodable, Hashable, Sendable {
        let type: String?
        let status: String
    }

    struct VenueStat: Decodable, Hashable, Sendable, Identifiable {
        var id: String { venue }
        let venue: String
        let games: Int
        let tries: Int
        let tryRate: Double
    }

    struct OpponentStat: Decodable, Hashable, Sendable, Identifiable {
        var id: String { opponent ?? "unknown" }
        let opponent: String?
        let games: Int
        let tries: Int
        let tryRate: Double
    }

    let id: Int
    let name: String
    let position: String?
    let team: TeamRef?
    let career: Career
    let season: Season
    let injury: Injury?
    let venueStats: [VenueStat]
    let opponentStats: [OpponentStat]
}

// MARK: - Matches

struct WinPrediction: Decodable, Hashable, Sendable {
    let homeWinPct: Int?
    let awayWinPct: Int?
    let predictedWinnerId: Int?
}

struct Signal: Decodable, Hashable, Sendable, Identifiable {
    var id: String { "\(type)-\(detail)-\(side ?? "")" }
    let type: String
    let weight: Int?
    /// 0...1
    let strength: Double
    let detail: String
    /// Only present on `win_signals`.
    let side: String?

    enum CodingKeys: String, CodingKey {
        case type, weight, strength, side
        case detail = "description"
    }
}

struct MatchBookmakerOdds: Decodable, Hashable, Sendable {
    struct HeadToHead: Decodable, Hashable, Sendable, Identifiable {
        var id: String { bookmaker }
        let bookmaker: String
        let odds: [Double]
    }

    struct SpreadPrice: Decodable, Hashable, Sendable {
        let price: Double?
        let point: LooseDouble?
    }

    struct Spread: Decodable, Hashable, Sendable, Identifiable {
        var id: String { bookmaker }
        let bookmaker: String
        let odds: [SpreadPrice]
    }

    let matchWinner: [HeadToHead]?
    let spreads: [Spread]?
}

struct TeamListEntry: Decodable, Hashable, Sendable, Identifiable {
    var id: Int { playerId }
    let playerId: Int
    let name: String?
    let position: String?
    let positionNumber: Int
}

struct TeamLists: Decodable, Hashable, Sendable {
    let home: [TeamListEntry]
    let away: [TeamListEntry]
}

/// One struct for both the summary and detail payloads — the detail-only keys are optional.
struct Match: Decodable, Identifiable, Hashable, Sendable {
    let id: Int
    let round: Int?
    let season: Int?
    let homeTeam: TeamRef
    let awayTeam: TeamRef
    let venue: String?
    let kickoffAt: String?
    let kickoffAest: String?
    let status: String
    let homeScore: Int?
    let awayScore: Int?
    let winPrediction: WinPrediction?
    let winSignals: [Signal]?
    let bookmakerOdds: MatchBookmakerOdds?
    let teamLists: TeamLists?

    /// `Matchup::statusBadge()`
    var statusBadge: String {
        switch status {
        case "live": "Live"
        case "completed": "Final"
        default: "Upcoming"
        }
    }

    var statusTone: ChipTone {
        switch status {
        case "live": .red
        case "completed": .muted
        default: .gold
        }
    }

    var homeWinPct: Int { winPrediction?.homeWinPct ?? 50 }
    var awayWinPct: Int { winPrediction?.awayWinPct ?? 50 }
    var hasWinPrediction: Bool { winPrediction?.homeWinPct != nil }
    var homeIsPredictedWinner: Bool { winPrediction?.predictedWinnerId == homeTeam.id }
    var awayIsPredictedWinner: Bool { winPrediction?.predictedWinnerId == awayTeam.id }
    var hasScore: Bool { homeScore != nil && awayScore != nil }
    var title: String { "\(homeTeam.label) v \(awayTeam.label)" }

    func signals(side: String) -> [Signal] {
        (winSignals ?? [])
            .filter { $0.side == side }
            .sorted { ($0.strength * Double($0.weight ?? 0)) > ($1.strength * Double($1.weight ?? 0)) }
    }
}

// MARK: - Predictions

struct PredictionPlayer: Decodable, Hashable, Sendable {
    let id: Int
    let name: String?
    let team: String?
    let position: String?
}

struct PredictionRow: Decodable, Hashable, Sendable, Identifiable {
    var id: String { "\(matchId ?? 0)-\(player.id)" }
    let rank: Int
    let player: PredictionPlayer
    let score: Int
    let signals: [Signal]?
    let aiReasoning: String?
    /// Leaderboard rows only.
    let match: String?
    let matchId: Int?

    var topSignals: [Signal] {
        (signals ?? [])
            .filter { $0.strength > 0 }
            .sorted { ($0.strength * Double($0.weight ?? 0)) > ($1.strength * Double($1.weight ?? 0)) }
    }

    /// `Prediction::advantageTags()` — signals at strength >= 0.6 become chips.
    var advantageTags: [(label: String, tone: ChipTone)] {
        var seen = Set<String>()
        var tags: [(String, ChipTone)] = []
        for signal in signals ?? [] where signal.strength >= 0.6 {
            let mapped: (String, ChipTone)?
            switch signal.type {
            case "recent_form": mapped = ("Hot form", .red)
            case "milestone_game": mapped = ("Milestone", .gold)
            case "venue_record": mapped = ("Venue", .green)
            case "head_to_head": mapped = ("Matchup", .blue)
            case "returning_player": mapped = ("Returning", .orange)
            case "opponent_edge_weakness", "opponent_missing_defenders": mapped = ("Opp. weak", .purple)
            default: mapped = nil
            }
            if let mapped, seen.insert(mapped.0).inserted {
                tags.append(mapped)
            }
        }
        return tags
    }
}
