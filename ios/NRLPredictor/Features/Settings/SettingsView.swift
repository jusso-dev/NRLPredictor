import SwiftUI

struct SettingsView: View {
    @Environment(\.dismiss) private var dismiss
    @AppStorage(APIConfig.storageKey) private var baseURL = APIConfig.defaultBaseURL
    @State private var probe: String?
    @State private var isProbing = false
    @State private var apiKey = ""
    @State private var keyStored = APIConfig.hasAPIKey
    @State private var keyStatus: String?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    SectionBlock(title: "API base URL") {
                        Card {
                            VStack(alignment: .leading, spacing: 10) {
                                TextField(APIConfig.defaultBaseURL, text: $baseURL)
                                    .textInputAutocapitalization(.never)
                                    .autocorrectionDisabled()
                                    .keyboardType(.URL)
                                    .font(.numeric(13))
                                    .foregroundStyle(Palette.heading)
                                    .padding(10)
                                    .background(Palette.surfaceAlt, in: .rect(cornerRadius: 6))
                                    .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }

                                Text("Point this at the Laravel app — host and port only. On the simulator that is usually http://localhost:8000; on a device use the server's LAN address, e.g. http://192.168.0.10:8001.")
                                    .font(.system(size: 12))
                                    .foregroundStyle(Palette.muted)

                                if APIConfig.normalize(baseURL) != baseURL.trimmingCharacters(in: .whitespacesAndNewlines) {
                                    Text("Requests will use \(APIConfig.normalize(baseURL)) — a trailing slash or /api path would otherwise produce 404s.")
                                        .font(.system(size: 12))
                                        .foregroundStyle(Palette.orange)
                                }

                                HStack(spacing: 10) {
                                    Button(isProbing ? "Checking…" : "Test connection") {
                                        Task { await testConnection() }
                                    }
                                    .buttonStyle(PrimaryButtonStyle())
                                    .disabled(isProbing)

                                    Button("Reset") { baseURL = APIConfig.defaultBaseURL }
                                        .buttonStyle(GhostButtonStyle())
                                }

                                if let probe {
                                    Text(probe)
                                        .font(.system(size: 12))
                                        .foregroundStyle(probe.hasPrefix("Connected") ? Palette.accentBright : Palette.red)
                                }
                            }
                        }
                    }

                    SectionBlock(title: "API key") {
                        Card {
                            VStack(alignment: .leading, spacing: 10) {
                                HStack(spacing: 8) {
                                    Chip(keyStored ? "Stored in Keychain" : "Not set",
                                         tone: keyStored ? .green : .muted)
                                    Spacer()
                                }

                                SecureField(keyStored ? "Replace stored key" : "Paste API key", text: $apiKey)
                                    .textInputAutocapitalization(.never)
                                    .autocorrectionDisabled()
                                    .font(.numeric(13))
                                    .foregroundStyle(Palette.heading)
                                    .padding(10)
                                    .background(Palette.surfaceAlt, in: .rect(cornerRadius: 6))
                                    .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(Palette.border, lineWidth: 1) }

                                Text("Matches API_KEY on the server. Sent as X-API-Key on every request and held in the device Keychain — never in preferences or a backup-readable file.")
                                    .font(.system(size: 12))
                                    .foregroundStyle(Palette.muted)

                                HStack(spacing: 10) {
                                    Button("Save key") { saveKey() }
                                        .buttonStyle(PrimaryButtonStyle())
                                        .disabled(apiKey.trimmingCharacters(in: .whitespaces).isEmpty)

                                    if keyStored {
                                        Button("Remove") { removeKey() }
                                            .buttonStyle(GhostButtonStyle())
                                    }
                                }

                                if let keyStatus {
                                    Text(keyStatus)
                                        .font(.system(size: 12))
                                        .foregroundStyle(keyStatus.hasPrefix("Could not") ? Palette.red : Palette.accentBright)
                                }
                            }
                        }
                    }

                    SectionBlock(title: "About") {
                        Card {
                            VStack(alignment: .leading, spacing: 8) {
                                Text("NRL Try Predictor")
                                    .displayStyle(18)
                                    .foregroundStyle(Palette.heading)
                                Text("Native client for the signal-driven try scorer and match winner models. Data is read-only over the public /api/v1 endpoints — no auth required.")
                                    .font(.system(size: 12))
                                    .foregroundStyle(Palette.muted)
                            }
                        }
                    }

                    ResponsibleGamblingFooter()
                }
                .padding(16)
            }
            .nrlPage("Settings")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }

    private func saveKey() {
        if APIConfig.setAPIKey(apiKey) {
            apiKey = ""
            keyStored = APIConfig.hasAPIKey
            keyStatus = "Key saved to the Keychain."
        } else {
            keyStatus = "Could not write to the Keychain."
        }
        probe = nil
    }

    private func removeKey() {
        if APIConfig.setAPIKey(nil) {
            apiKey = ""
            keyStored = false
            keyStatus = "Key removed."
        } else {
            keyStatus = "Could not remove the stored key."
        }
        probe = nil
    }

    private func testConnection() async {
        isProbing = true
        probe = nil
        do {
            let response: ItemResponse<RoundInfo> = try await APIClient.shared.get("/api/v1/rounds/current")
            if let round = response.data {
                probe = "Connected — current round \(round.roundNumber), season \(round.season)."
            } else {
                probe = "Connected, but the API has no current round yet."
            }
        } catch {
            probe = (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
        }
        isProbing = false
    }
}
