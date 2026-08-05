import Foundation

enum Fmt {
    // Foundation date formatters are documented as thread-safe for formatting and
    // parsing; these two are configured once and never mutated afterwards.

    /// `kickoff_at` / `captured_at` are `2026-08-08T19:35:00+10:00` — internet date time, no fractional seconds.
    nonisolated(unsafe) private static let iso: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        return formatter
    }()

    /// Round `start_date` / `end_date` are plain `2026-08-06`.
    private static let dayOnly: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = sydney
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter
    }()

    static let sydney = TimeZone(identifier: "Australia/Sydney") ?? .current

    static func date(_ iso8601: String?) -> Date? {
        guard let iso8601 else { return nil }
        return iso.date(from: iso8601)
    }

    static func day(_ isoDay: String?) -> Date? {
        guard let isoDay else { return nil }
        return dayOnly.date(from: isoDay)
    }

    private static func formatter(_ format: String) -> DateFormatter {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_AU")
        formatter.timeZone = sydney
        formatter.dateFormat = format
        return formatter
    }

    /// "Fri 15 May · 08:00" — the dashboard match-card format.
    static func kickoffShort(_ iso8601: String?) -> String? {
        guard let date = date(iso8601) else { return nil }
        return formatter("EEE d MMM · HH:mm").string(from: date)
    }

    /// "Thursday 7 May · 09:50" — the match-detail format.
    static func kickoffLong(_ iso8601: String?) -> String? {
        guard let date = date(iso8601) else { return nil }
        return formatter("EEEE d MMMM · HH:mm").string(from: date)
    }

    /// "Sat 14:05" — the compact multi-leg format.
    static func kickoffCompact(_ iso8601: String?) -> String? {
        guard let date = date(iso8601) else { return nil }
        return formatter("EEE HH:mm").string(from: date)
    }

    /// "Fri 15 May — Sun 17 May" round window.
    static func roundWindow(start: String?, end: String?) -> String? {
        let short = formatter("EEE d MMM")
        switch (day(start), day(end)) {
        case let (start?, end?): return "\(short.string(from: start)) — \(short.string(from: end))"
        case let (start?, nil): return short.string(from: start)
        case let (nil, end?): return short.string(from: end)
        default: return nil
        }
    }

    static func relative(_ iso8601: String?) -> String? {
        guard let date = date(iso8601) else { return nil }
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .full
        return formatter.localizedString(for: date, relativeTo: Date())
    }

    /// The API returns implied probabilities as percent-suffixed strings ("42.5%").
    static func percentValue(_ text: String?) -> Double? {
        guard let text else { return nil }
        return Double(text.replacingOccurrences(of: "%", with: "").trimmingCharacters(in: .whitespaces))
    }

    static func odds(_ value: Double?) -> String {
        guard let value else { return "—" }
        return String(format: "%.2f", value)
    }

    static func money(_ value: Double) -> String {
        String(format: "$%.2f", value)
    }

    /// Signal keys are snake_case machine names; the web renders them space-separated.
    static func signalName(_ raw: String) -> String {
        raw.replacingOccurrences(of: "_", with: " ")
    }

    static func position(_ raw: String?) -> String? {
        guard let raw, !raw.isEmpty else { return nil }
        return raw.prefix(1).uppercased() + raw.dropFirst()
    }
}
