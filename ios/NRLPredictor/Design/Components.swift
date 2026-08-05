import SwiftUI

// MARK: - Card

/// `.card` — 8px radius, 1px ink-600 border, ink-900 fill.
struct Card<Content: View>: View {
    var padding: CGFloat = 16
    var tint: Color? = nil
    @ViewBuilder var content: Content

    var body: some View {
        content
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(tint?.opacity(0.05) ?? Palette.surface, in: .rect(cornerRadius: 10))
            .overlay {
                RoundedRectangle(cornerRadius: 10)
                    .strokeBorder(tint?.opacity(0.35) ?? Palette.border, lineWidth: 1)
            }
    }
}

// MARK: - Eyebrow label

/// `.lbl` — 11px, 600, uppercase, 0.18em tracking, bone-400.
struct Eyebrow: View {
    let text: String
    var tint: Color = Palette.muted

    init(_ text: String, tint: Color = Palette.muted) {
        self.text = text
        self.tint = tint
    }

    var body: some View {
        Text(text.uppercased())
            .font(.system(size: 11, weight: .semibold))
            .tracking(1.8)
            .foregroundStyle(tint)
    }
}

// MARK: - Chip

enum ChipTone {
    case gold, muted, red, orange, yellow, green, blue, purple

    var color: Color {
        switch self {
        case .gold: Palette.accent
        case .muted: Palette.muted
        case .red: Palette.red
        case .orange: Palette.orange
        case .yellow: Palette.yellow
        case .green: Palette.green
        case .blue: Palette.blue
        case .purple: Palette.purple
        }
    }

    var textColor: Color { self == .muted ? Palette.secondary : color }
}

/// `.chip` — 10px/600/uppercase on a tinted pill with a matching hairline ring.
struct Chip: View {
    let text: String
    var tone: ChipTone = .muted

    init(_ text: String, tone: ChipTone = .muted) {
        self.text = text
        self.tone = tone
    }

    var body: some View {
        Text(text.uppercased())
            .font(.system(size: 10, weight: .semibold))
            .tracking(0.8)
            .foregroundStyle(tone.textColor)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(tone.color.opacity(0.14), in: .rect(cornerRadius: 4))
            .overlay {
                RoundedRectangle(cornerRadius: 4)
                    .strokeBorder(tone.color.opacity(0.35), lineWidth: 1)
            }
    }
}

// MARK: - Bars

/// `.score-bar` — pill track with a tinted fill.
struct ScoreBar: View {
    /// 0...1
    let value: Double
    var tint: Color = Palette.accent
    var height: CGFloat = 8

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(Palette.track)
                Capsule()
                    .fill(tint)
                    .frame(width: geo.size.width * min(max(value, 0), 1))
            }
        }
        .frame(height: height)
    }
}

/// The dashboard win-probability bar: green home segment on an empty track.
struct WinSplitBar: View {
    let homePct: Int
    var height: CGFloat = 6

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(Palette.track.opacity(0.6))
                Capsule()
                    .fill(Palette.accent)
                    .frame(width: geo.size.width * (Double(min(max(homePct, 0), 100)) / 100))
            }
        }
        .frame(height: height)
    }
}

// MARK: - Buttons

struct PrimaryButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 12, weight: .semibold))
            .tracking(0.6)
            .textCase(.uppercase)
            .foregroundStyle(Color.black)
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .background(configuration.isPressed ? Palette.accentBright : Palette.accent, in: .rect(cornerRadius: 6))
    }
}

struct GhostButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 12, weight: .semibold))
            .tracking(0.6)
            .textCase(.uppercase)
            .foregroundStyle(Palette.body)
            .padding(.horizontal, 12)
            .padding(.vertical, 9)
            .background(configuration.isPressed ? Palette.surfaceAlt : Palette.surface, in: .rect(cornerRadius: 6))
            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }
    }
}

// MARK: - Section scaffold

struct SectionBlock<Content: View>: View {
    let title: String
    var trailing: String? = nil
    @ViewBuilder var content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Eyebrow(title)
                Spacer()
                if let trailing {
                    Text(trailing)
                        .font(.numeric(11))
                        .foregroundStyle(Palette.faint)
                }
            }
            content
        }
    }
}

// MARK: - State views

struct LoadingCard: View {
    var body: some View {
        HStack(spacing: 10) {
            ProgressView().tint(Palette.accent)
            Text("Loading…")
                .font(.system(size: 13))
                .foregroundStyle(Palette.muted)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 40)
    }
}

struct ErrorCard: View {
    let message: String
    let retry: () -> Void

    var body: some View {
        Card(tint: Palette.red) {
            VStack(alignment: .leading, spacing: 10) {
                Eyebrow("Could not reach the API", tint: Palette.red.opacity(0.9))
                Text(message)
                    .font(.system(size: 13))
                    .foregroundStyle(Palette.body)
                Text("Check the API base URL in Settings, and that the Laravel app is running.")
                    .font(.system(size: 12))
                    .foregroundStyle(Palette.muted)
                Button("Retry", action: retry).buttonStyle(GhostButtonStyle())
            }
        }
    }
}

struct EmptyCard: View {
    let message: String

    var body: some View {
        Card {
            Text(message)
                .font(.system(size: 13))
                .foregroundStyle(Palette.faint)
                .frame(maxWidth: .infinity, alignment: .center)
                .padding(.vertical, 16)
        }
    }
}

/// Renders a `Loadable` through its loading / error / loaded states.
struct AsyncContent<Value: Sendable, Content: View>: View {
    let loadable: Loadable<Value>
    let retry: () -> Void
    @ViewBuilder let content: (Value) -> Content

    var body: some View {
        if let value = loadable.value {
            content(value)
        } else if let error = loadable.error {
            ErrorCard(message: error, retry: retry)
        } else {
            LoadingCard()
        }
    }
}

// MARK: - Responsible gambling footer

/// Mirrors the mandatory footer in `layouts/app.blade.php`.
struct ResponsibleGamblingFooter: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Divider().overlay(Palette.track)
            Text("Data scraped from public sources. Predictions are model-driven, not betting advice. Unofficial — not affiliated with the NRL.")
                .font(.system(size: 11))
                .foregroundStyle(Palette.muted)
            Text("For informational use only. Gambling involves real financial risk. If you need support, call 1800 858 858 or visit www.betstop.gov.au")
                .font(.system(size: 11))
                .foregroundStyle(Palette.faint)
        }
        .padding(.top, 12)
    }
}
