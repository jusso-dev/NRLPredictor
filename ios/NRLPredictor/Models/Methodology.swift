import Foundation

/// `GET /api/v1/methodology` — static model documentation, safe to cache.
/// Weights here are live: AutoTune rewrites them after each completed round.
struct Methodology: Decodable, Hashable, Sendable {
    struct WeightedSignal: Decodable, Hashable, Sendable, Identifiable {
        var id: String { type }
        let type: String
        let weight: Int
        let pctOfTotal: Double
    }

    struct ScoreTier: Decodable, Hashable, Sendable, Identifiable {
        var id: String { range }
        let range: String
        let label: String
    }

    struct TryScorer: Decodable, Hashable, Sendable {
        let description: String
        let maxRawScore: Int
        let signals: [WeightedSignal]
        let positionWeights: [String: Double]
        let scoreTiers: [ScoreTier]
    }

    struct WinPrediction: Decodable, Hashable, Sendable {
        let description: String
        let maxRawScore: Int
        let signals: [WeightedSignal]
    }

    struct MultiBet: Decodable, Hashable, Sendable {
        let description: String
        let riskProfiles: [String: String]
    }

    struct DataSource: Decodable, Hashable, Sendable, Identifiable {
        var id: String { name }
        let name: String
        let url: String
        let refresh: String
    }

    struct AIReview: Decodable, Hashable, Sendable {
        let description: String
        let model: String
    }

    let tryScorerPrediction: TryScorer
    let winPrediction: WinPrediction
    let multiBet: MultiBet
    let dataSources: [DataSource]
    let aiReview: AIReview
}
