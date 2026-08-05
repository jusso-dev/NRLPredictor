import Foundation

/// Where the Laravel app lives. `php artisan serve` in docker-compose binds 0.0.0.0:8000.
enum APIConfig {
    static let defaultBaseURL = "http://localhost:8000"
    static let storageKey = "api_base_url"
    /// Keychain account holding the shared secret checked by `ApiKeyAuth` on the server.
    static let apiKeyAccount = "api_key"

    static var baseURL: String {
        let stored = UserDefaults.standard.string(forKey: storageKey)
        guard let stored, !stored.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return defaultBaseURL
        }
        return normalize(stored)
    }

    /// Users paste all sorts of things. A trailing slash is the nasty one: it turns
    /// every request into `host//api/v1/...`, which Laravel answers with a 404.
    static func normalize(_ raw: String) -> String {
        var value = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !value.isEmpty else { return defaultBaseURL }

        if !value.contains("://") {
            value = "http://" + value
        }

        while value.hasSuffix("/") { value.removeLast() }

        // Strip an API path the user may have copied out of a browser or curl command.
        for suffix in ["/api/v1", "/api"] where value.hasSuffix(suffix) {
            value.removeLast(suffix.count)
            break
        }

        while value.hasSuffix("/") { value.removeLast() }

        return value.isEmpty ? defaultBaseURL : value
    }

    /// Reads straight from the Keychain — never cached in UserDefaults.
    static var apiKey: String? {
        guard let key = Keychain.read(apiKeyAccount)?.trimmingCharacters(in: .whitespacesAndNewlines),
              !key.isEmpty
        else { return nil }
        return key
    }

    static var hasAPIKey: Bool { apiKey != nil }

    @discardableResult
    static func setAPIKey(_ key: String?) -> Bool {
        guard let key, !key.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return Keychain.delete(apiKeyAccount)
        }
        return Keychain.write(key, account: apiKeyAccount)
    }
}

enum APIError: LocalizedError {
    case badURL
    case unauthorized
    case status(Int)
    case transport(String)
    case decoding(String)

    var errorDescription: String? {
        switch self {
        case .badURL: "The API base URL is not a valid URL."
        case .unauthorized: "API key missing or rejected. Add the key from your server's API_KEY in Settings."
        case .status(404): "Not found (404). The API base URL should be the host only, e.g. http://192.168.0.10:8001."
        case .status(let code): "Server returned HTTP \(code)."
        case .transport(let message): message
        case .decoding(let message): "Unexpected response shape: \(message)"
        }
    }
}

final class APIClient: Sendable {
    static let shared = APIClient()

    private let session: URLSession

    init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 20
        config.waitsForConnectivity = false
        session = URLSession(configuration: config)
    }

    static func makeDecoder() -> JSONDecoder {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return decoder
    }

    /// Total attempts for a request. A cold container or a sleeping Wi-Fi radio
    /// makes the first call of a session fail often enough to be worth absorbing.
    private static let maxAttempts = 3

    func get<T: Decodable & Sendable>(
        _ path: String,
        query: [URLQueryItem] = [],
        as type: T.Type = T.self
    ) async throws -> T {
        var lastError: any Error = APIError.transport("Request never ran.")

        for attempt in 1...Self.maxAttempts {
            do {
                return try await perform(path, query: query, as: T.self)
            } catch let error as APIError where Self.isRetryable(error) {
                lastError = error
                guard attempt < Self.maxAttempts else { break }
                try? await Task.sleep(for: .milliseconds(250 * attempt))
            }
        }

        throw lastError
    }

    /// Auth, decoding and 4xx failures are deterministic — retrying only delays the error.
    private static func isRetryable(_ error: APIError) -> Bool {
        switch error {
        case .transport: true
        case .status(let code): code >= 500
        case .badURL, .unauthorized, .decoding: false
        }
    }

    private func perform<T: Decodable & Sendable>(
        _ path: String,
        query: [URLQueryItem],
        as type: T.Type
    ) async throws -> T {
        guard var components = URLComponents(string: APIConfig.baseURL + path) else {
            throw APIError.badURL
        }
        if !query.isEmpty { components.queryItems = query }
        guard let url = components.url else { throw APIError.badURL }

        var request = URLRequest(url: url)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let key = APIConfig.apiKey {
            request.setValue(key, forHTTPHeaderField: "X-API-Key")
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch let error as URLError where error.code == .cancelled {
            throw CancellationError()
        } catch {
            throw APIError.transport(error.localizedDescription)
        }

        if let http = response as? HTTPURLResponse, !(200..<300).contains(http.statusCode) {
            throw http.statusCode == 401 ? APIError.unauthorized : APIError.status(http.statusCode)
        }

        do {
            return try Self.makeDecoder().decode(T.self, from: data)
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }
}
