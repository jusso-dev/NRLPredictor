import SwiftUI

// The web app pairs Barlow Condensed (headings) with JetBrains Mono (every numeral).
// We get the same texture from the system faces: SF Pro at condensed width, SF Mono for digits.
extension Font {
    /// Barlow Condensed stand-in — used for every heading, team name and hero stat.
    static func display(_ size: CGFloat, _ weight: Font.Weight = .semibold) -> Font {
        .system(size: size, weight: weight).width(.condensed)
    }

    /// JetBrains Mono stand-in — used for every number in the UI.
    static func numeric(_ size: CGFloat, _ weight: Font.Weight = .regular) -> Font {
        .system(size: size, weight: weight, design: .monospaced)
    }
}

extension View {
    /// `.h-display` — condensed, uppercase, tight tracking.
    func displayStyle(_ size: CGFloat, weight: Font.Weight = .semibold) -> some View {
        font(.display(size, weight)).tracking(-0.3).textCase(.uppercase)
    }
}
