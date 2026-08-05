import SwiftUI

/// Colour tokens lifted from `tailwind.config.js` (dark mode is the canonical look).
enum Palette {
    // Surfaces — `ink-*`
    static let bg = Color(hex: 0x0A0A0A)          // ink-950, page background
    static let surface = Color(hex: 0x141414)     // ink-900, card
    static let surfaceAlt = Color(hex: 0x1E1E1E)  // ink-800, inset panel
    static let track = Color(hex: 0x2A2A2A)       // ink-700, divider / bar track
    static let border = Color(hex: 0x3A3A3A)      // ink-600

    // Text — `bone-*`
    static let heading = Color(hex: 0xF4F5F7)     // bone-50
    static let body = Color(hex: 0xE0E2E7)        // bone-100
    static let secondary = Color(hex: 0xBFC4D0)   // bone-200
    static let muted = Color(hex: 0x8A8F9E)       // bone-400
    static let faint = Color(hex: 0x5F6475)       // bone-500

    // Accent — `gold-*` (a misnomer; it is NRL green)
    static let accent = Color(hex: 0x00B852)      // gold-500
    static let accentBright = Color(hex: 0x1FD46B) // gold-400, accent text in dark

    // Signal colours
    static let red = Color(hex: 0xD21F2E)
    static let orange = Color(hex: 0xE8843C)
    static let yellow = Color(hex: 0xE6B41F)
    static let green = Color(hex: 0x2F8F4F)
    static let blue = Color(hex: 0x1F5BB8)
    static let purple = Color(hex: 0x6F3FB0)

    /// Try-scorer score tiers — `Prediction::tierClass()`.
    static func scoreTier(_ score: Int) -> Color {
        switch score {
        case 80...: red
        case 65...: orange
        case 50...: yellow
        default: Color.white.opacity(0.15)
        }
    }

    static func scoreTierLabel(_ score: Int) -> String {
        switch score {
        case 80...: "Elite tier"
        case 65...: "Strong pick"
        case 50...: "Decent chance"
        default: "Outside shot"
        }
    }
}

extension Color {
    init(hex: UInt32, opacity: Double = 1) {
        self.init(
            .sRGB,
            red: Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >> 8) & 0xFF) / 255,
            blue: Double(hex & 0xFF) / 255,
            opacity: opacity
        )
    }

    /// Parses `#RRGGBB` / `#RRGGBBAA` strings as stored on `teams.color_primary`.
    init?(webHex: String?) {
        guard var raw = webHex?.trimmingCharacters(in: .whitespaces), !raw.isEmpty else { return nil }
        if raw.hasPrefix("#") { raw.removeFirst() }
        guard raw.count == 6 || raw.count == 8, let value = UInt32(raw, radix: 16) else { return nil }
        if raw.count == 6 {
            self.init(hex: value)
        } else {
            self.init(hex: value >> 8, opacity: Double(value & 0xFF) / 255)
        }
    }
}
