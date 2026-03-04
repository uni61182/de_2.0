import SwiftUI

struct ContentView: View {
    @State private var username = ""
    @State private var password = ""
    @State private var isLoggedIn = false
    @State private var dashboard: DashboardPayload?
    @State private var errorText = ""
    @State private var fleetSlot = "0"
    @State private var targetSector = "1"
    @State private var targetSystem = "1"

    var body: some View {
        NavigationView {
            if isLoggedIn {
                List {
                    if let dashboard {
                        Section("Spieler") {
                            Text(dashboard.overview.player.spielername)
                            Text("Score: \(dashboard.overview.player.score)")
                            Text("Position: \(dashboard.overview.player.sector):\(dashboard.overview.player.system)")
                        }

                        Section("Ressourcen") {
                            Text("Multiplex: \(dashboard.resources.resources.restyp01)")
                            Text("Dyharra: \(dashboard.resources.resources.restyp02)")
                            Text("Iradium: \(dashboard.resources.resources.restyp03)")
                            Text("Eternium: \(dashboard.resources.resources.restyp04)")
                            Text("Tronic: \(dashboard.resources.resources.restyp05)")
                        }

                        Section("Flotten") {
                            ForEach(dashboard.fleets.fleets) { fleet in
                                VStack(alignment: .leading) {
                                    Text("Fleet: \(fleet.user_id)")
                                    Text("Von \(fleet.hsec):\(fleet.hsys) nach \(fleet.zielsec):\(fleet.zielsys)")
                                    Text("Size: \(fleet.fleetsize)")
                                }
                            }
                        }

                        Section("Flotte bewegen") {
                            TextField("Slot (0-3)", text: $fleetSlot)
                            TextField("Sektor", text: $targetSector)
                            TextField("System", text: $targetSystem)
                            Button("Senden") {
                                Task { await moveFleet() }
                            }
                        }
                    }
                }
                .navigationTitle("DE2 Dashboard")
                .toolbar {
                    Button("Refresh") {
                        Task { await loadDashboard() }
                    }
                }
            } else {
                Form {
                    TextField("Username", text: $username)
                        .autocapitalization(.none)
                    SecureField("Passwort", text: $password)
                    Button("Login") {
                        Task { await login() }
                    }
                    if !errorText.isEmpty {
                        Text(errorText)
                            .foregroundColor(.red)
                    }
                }
                .navigationTitle("DE2 Login")
            }
        }
    }

    private func login() async {
        do {
            try await APIClient.shared.login(username: username, password: password)
            isLoggedIn = true
            await loadDashboard()
        } catch {
            errorText = "Login fehlgeschlagen"
        }
    }

    private func loadDashboard() async {
        do {
            dashboard = try await APIClient.shared.loadDashboard()
        } catch {
            errorText = "Daten konnten nicht geladen werden"
        }
    }

    private func moveFleet() async {
        guard let slot = Int(fleetSlot), let sector = Int(targetSector), let system = Int(targetSystem) else {
            errorText = "Ungültige Flotten-Eingabe"
            return
        }

        do {
            try await APIClient.shared.moveFleet(slot: slot, targetSector: sector, targetSystem: system)
            await loadDashboard()
        } catch {
            errorText = "Flotte konnte nicht bewegt werden"
        }
    }
}
