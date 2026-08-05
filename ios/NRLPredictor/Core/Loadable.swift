import Foundation
import Observation

/// Minimal async state holder: one per resource a screen needs.
@MainActor
@Observable
final class Loadable<Value: Sendable> {
    private(set) var value: Value?
    private(set) var error: String?
    private(set) var isLoading = false

    func load(force: Bool = false, _ fetch: () async throws -> Value) async {
        if value != nil && !force { return }
        isLoading = true
        if force { error = nil }
        do {
            value = try await fetch()
            error = nil
        } catch is CancellationError {
            // Superseded by a newer load; keep whatever we already had.
        } catch {
            self.error = (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
        }
        isLoading = false
    }

    func reset() {
        value = nil
        error = nil
    }
}
