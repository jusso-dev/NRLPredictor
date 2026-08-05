#!/usr/bin/env swift

// Renders the app icon from the same tokens the app uses, so the artwork can be
// regenerated rather than living in the repo as an unexplained binary.
//
//   swift ios/Tools/GenerateAppIcon.swift ios/NRLPredictor/Assets.xcassets/AppIcon.appiconset
//
// Produces the light, dark and tinted variants iOS 18+ asks for. The mark is the
// masthead lockup: a condensed "N" over the win-probability bar that runs through
// every match card.

import AppKit
import CoreGraphics
import Foundation

let size = 1024.0

struct Variant {
    let name: String
    let background: (top: NSColor, bottom: NSColor)?
    let glyph: NSColor
    let barFilled: NSColor
    let barTrack: NSColor
}

// Palette lifted from Design/Palette.swift.
func rgb(_ hex: UInt32, _ alpha: CGFloat = 1) -> NSColor {
    NSColor(
        srgbRed: CGFloat((hex >> 16) & 0xFF) / 255,
        green: CGFloat((hex >> 8) & 0xFF) / 255,
        blue: CGFloat(hex & 0xFF) / 255,
        alpha: alpha
    )
}

let variants = [
    // Default: the green tile from the masthead, black mark. Highest contrast at
    // 60pt, and instantly recognisable next to the app's own header.
    Variant(
        name: "icon-light",
        background: (rgb(0x00C258), rgb(0x00933F)),
        glyph: rgb(0x000000),
        barFilled: rgb(0x000000),
        barTrack: rgb(0x000000, 0.22)
    ),
    // Dark: the app's canvas, accent mark.
    Variant(
        name: "icon-dark",
        background: (rgb(0x111111), rgb(0x000000)),
        glyph: rgb(0x1FD46B),
        barFilled: rgb(0x00B852),
        barTrack: rgb(0xFFFFFF, 0.14)
    ),
    // Tinted: grayscale on transparency; the system supplies the tint and backdrop.
    Variant(
        name: "icon-tinted",
        background: nil,
        glyph: rgb(0xFFFFFF),
        barFilled: rgb(0xFFFFFF),
        barTrack: rgb(0xFFFFFF, 0.25)
    ),
]

func render(_ variant: Variant, to url: URL) throws {
    guard let context = CGContext(
        data: nil,
        width: Int(size),
        height: Int(size),
        bitsPerComponent: 8,
        bytesPerRow: 0,
        space: CGColorSpace(name: CGColorSpace.sRGB)!,
        bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue
    ) else {
        throw NSError(domain: "icon", code: 1)
    }

    let graphics = NSGraphicsContext(cgContext: context, flipped: false)
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = graphics

    let rect = CGRect(x: 0, y: 0, width: size, height: size)

    if let background = variant.background {
        let gradient = NSGradient(starting: background.top, ending: background.bottom)!
        gradient.draw(in: rect, angle: -90)
    }

    // The "N", in the condensed weight the app uses for every heading.
    let pointSize = size * 0.52
    let descriptor = NSFont.systemFont(ofSize: pointSize, weight: .black)
        .fontDescriptor
        .addingAttributes([
            .traits: [NSFontDescriptor.TraitKey.width: -0.3],
        ])
    let font = NSFont(descriptor: descriptor, size: pointSize)
        ?? NSFont.systemFont(ofSize: pointSize, weight: .black)

    let glyph = NSAttributedString(
        string: "N",
        attributes: [.font: font, .foregroundColor: variant.glyph]
    )

    // The win-probability bar: 62% filled, the split the model shows most often.
    let barWidth = size * 0.42
    let barHeight = size * 0.05
    let barX = (size - barWidth) / 2
    let radius = barHeight / 2

    // Compose the mark from real metrics rather than guessed offsets: cap height
    // plus gap plus bar, centred as one group and nudged up a touch, because a
    // mark sitting on the geometric centre reads as low once the corners are masked.
    let gap = size * 0.06
    let groupHeight = font.capHeight + gap + barHeight
    let groupBottom = (size - groupHeight) / 2 + size * 0.015
    let barY = groupBottom
    let baseline = groupBottom + barHeight + gap

    // draw(at:) places the line box, whose bottom sits a descender below the baseline.
    glyph.draw(at: CGPoint(
        x: (size - glyph.size().width) / 2,
        y: baseline + font.descender
    ))

    let track = NSBezierPath(
        roundedRect: CGRect(x: barX, y: barY, width: barWidth, height: barHeight),
        xRadius: radius,
        yRadius: radius
    )
    variant.barTrack.setFill()
    track.fill()

    let filled = NSBezierPath(
        roundedRect: CGRect(x: barX, y: barY, width: barWidth * 0.62, height: barHeight),
        xRadius: radius,
        yRadius: radius
    )
    variant.barFilled.setFill()
    filled.fill()

    NSGraphicsContext.restoreGraphicsState()

    guard let image = context.makeImage() else { throw NSError(domain: "icon", code: 2) }
    let rep = NSBitmapImageRep(cgImage: image)
    guard let png = rep.representation(using: .png, properties: [:]) else {
        throw NSError(domain: "icon", code: 3)
    }
    try png.write(to: url)
    print("wrote \(url.lastPathComponent) (\(png.count / 1024) KB)")
}

let output = URL(fileURLWithPath: CommandLine.arguments.count > 1
    ? CommandLine.arguments[1]
    : FileManager.default.currentDirectoryPath)

for variant in variants {
    try render(variant, to: output.appendingPathComponent("\(variant.name).png"))
}
