# NRL Predictor — iOS app

Native SwiftUI client for the NRL Try Predictor API. Read-only: it renders fixtures,
predictions, market odds, clubs and players, and builds multis from
`GET /api/v1/multi-bet`.

## Requirements

- Xcode 26 (iOS 17.0 deployment target)
- [XcodeGen](https://github.com/yonaskolb/XcodeGen) — `brew install xcodegen`
- The Laravel app running and reachable from the device/simulator

## Build

```bash
cd ios
xcodegen generate          # regenerate after adding/removing source files
open NRLPredictor.xcodeproj
```

Or from the command line:

```bash
xcodebuild -project NRLPredictor.xcodeproj -scheme NRLPredictor \
  -destination 'generic/platform=iOS Simulator' build
```

The project is signed ad-hoc (`CODE_SIGN_IDENTITY = "-"`), which is what the
simulator needs for Keychain access. Set `DEVELOPMENT_TEAM` in Xcode before
running on a device.

## Connecting to the API

Open **Settings** (gear icon, Round tab):

- **API base URL** — `http://localhost:8000` on the simulator; on a device use the
  Mac's LAN address, e.g. `http://192.168.0.10:8000`. Stored in `UserDefaults`.
- **API key** — must match `API_KEY` on the server. Stored in the **Keychain**
  (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`), never in preferences, and
  sent as `X-API-Key` on every request. Leave empty if the server has no key set.
- **Test connection** — hits `/api/v1/rounds/current` and reports what came back.

A 401 anywhere in the app surfaces as "API key missing or rejected".

`Info.plist` allows arbitrary loads because the API is normally served over plain
HTTP on localhost/LAN. Remove `NSAppTransportSecurity` once you front it with HTTPS.

## Screens

| Tab | Contents |
|---|---|
| **Round** | Round header with counts and next kickoff, match cards (status, kickoff, win %, split bar, top pick), round leaderboard, round picker. Tap a match for win-prediction signals, bookmaker odds, ranked try scorers with expandable signal breakdowns and AI reasoning, the anytime try scorer market, and both team lists. |
| **Multi** | Risk profile (safe/balanced/value) + leg count → `GET /api/v1/multi-bet`. Summary card, then a slip: toggle legs in or out, enter a stake, and see combined bookmaker odds, model probability and potential return recalculated locally. Share the slip as text. |
| **Odds** | Per-match head-to-head, line, totals and anytime try scorer prices by bookmaker. |
| **Teams** | All 17 clubs in club colours → squad (sortable) → player career/season splits, injury status, venue and opponent records. |
| **How to use** | Orientation guide reached from the `?` in the Round tab, a first-run card, and a link on the Multi tab. Plain-language sections (start here, reading a match card, chip legend, slip mechanics) are combined with live numbers from `/api/v1/methodology` — score tiers, signal weights, position weights, risk profiles, refresh cadence — so the copy cannot drift from the model. |

## Design

Ported from the web app's tokens (`tailwind.config.js`): `#0A0A0A` canvas,
`#141414` cards, `#3A3A3A` hairlines, `#00B852` / `#1FD46B` accent, and the
signal colours used for score tiers and advantage chips. Barlow Condensed maps to
SF Pro at `.width(.condensed)`; JetBrains Mono maps to SF Mono for every numeral.
Dark only, matching the canonical look of the web UI.

## Layout

```
NRLPredictor/
├── App/           # entry point, tab shell, shared routes
├── Core/          # APIClient, Keychain, Loadable, formatters
├── Design/        # palette, typography, components, club colours
├── Models/        # Codable mirrors of the /api/v1 payloads
└── Features/      # Fixtures, Multi, Odds, Teams, Settings
```

## Debug helpers

Debug builds read launch arguments so screens can be driven from the CLI:

```bash
xcrun simctl launch <device> au.com.yarndigi.nrlpredictor \
  -startTab 1 \
  -startRoute match:187 \
  -api_base_url "http://localhost:8000" \
  -debugAPIKey "your-key"
```

These are compiled out of release builds.
