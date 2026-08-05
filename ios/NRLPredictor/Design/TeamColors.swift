import SwiftUI

/// Club colours as seeded by `database/seeders/TeamSeeder.php`.
/// The web UI stores but never renders these; on mobile they carry team identity
/// in place of the logos the backend does not have.
enum TeamColors {
    private static let bySlug: [String: (primary: UInt32, secondary: UInt32)] = [
        "broncos": (0x6E0027, 0xFBBF15),
        "bulldogs": (0x003CB4, 0xFFFFFF),
        "raiders": (0x00703C, 0xFFFFFF),
        "cowboys": (0x002B5C, 0xFFC72C),
        "dolphins": (0xE4002B, 0xD4A843),
        "dragons": (0xE4002B, 0xFFFFFF),
        "eels": (0x002B5C, 0xFFC72C),
        "knights": (0x003CB4, 0xE4002B),
        "panthers": (0x2A2A2A, 0xFF0050),
        "rabbitohs": (0x003B28, 0xE4002B),
        "roosters": (0x003CB4, 0xE4002B),
        "sea-eagles": (0x6E0027, 0xFFFFFF),
        "sharks": (0x00B3E3, 0x2A2A2A),
        "storm": (0x582C83, 0xFFC72C),
        "titans": (0x003E7E, 0xFFC72C),
        "warriors": (0x2A2A2A, 0x8C8C8C),
        "wests-tigers": (0xF47920, 0x2A2A2A),
    ]

    private static func key(_ name: String?) -> String? {
        guard let name, !name.isEmpty else { return nil }
        let slug = name.lowercased()
            .replacingOccurrences(of: " ", with: "-")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        if bySlug[slug] != nil { return slug }
        // Fall back to matching on any word, e.g. "Manly Warringah Sea Eagles" -> "sea-eagles".
        return bySlug.keys.first { slug.contains($0) }
    }

    static func primary(_ name: String?) -> Color {
        guard let key = key(name), let entry = bySlug[key] else { return Palette.border }
        return Color(hex: entry.primary)
    }

    static func secondary(_ name: String?) -> Color {
        guard let key = key(name), let entry = bySlug[key] else { return Palette.faint }
        return Color(hex: entry.secondary)
    }
}
