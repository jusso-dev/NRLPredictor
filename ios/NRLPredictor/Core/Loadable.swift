import Foundation
import Observation

/// Minimal async state holder: one per resource a screen needs.
@MainActor
@Observable
final class Loadable<Value: Sendable> {
    private(set) var value: Value?
    private(set) var error: String?
    private(set) var isLoading = false
    /// Bumped per load so an older, slower response can't overwrite a newer one.
    @ObservationIgnored private var generation = 0

    func load(force: Bool = false, _ fetch: () async throws -> Value) async {
        if value != nil && !force { return }
        generation += 1
        let current = generation
        isLoading = true
        if force { error = nil }
        let result: Result<Value, any Error>
        do {
            result = .success(try await fetch())
        } catch {
            result = .failure(error)
        }
        // Superseded by a newer load; that one owns the state now.
        guard current == generation else { return }
        switch result {
        case .success(let fetched):
            value = fetched
            error = nil
        case .failure(is CancellationError):
            // Cancelled; keep whatever we already had.
            break
        case .failure(let error):
            self.error = (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
        }
        isLoading = false
    }

    func reset() {
        value = nil
        error = nil
    }
}
