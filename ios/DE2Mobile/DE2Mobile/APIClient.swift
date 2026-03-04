import Foundation

final class APIClient {
    static let shared = APIClient()

    private let baseURL = URL(string: "https://example.com/api/v1")!
    private var accessToken: String = ""
    private var refreshToken: String = ""

    func login(username: String, password: String) async throws {
        let url = baseURL.appendingPathComponent("auth/login")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONEncoder().encode(["username": username, "password": password])

        let (data, response) = try await URLSession.shared.data(for: request)
        try ensureHTTP(response)
        let payload = try JSONDecoder().decode(TokenPayload.self, from: data)
        accessToken = payload.accessToken
        refreshToken = payload.refreshToken
    }

    func loadDashboard() async throws -> DashboardPayload {
        async let overview: OverviewResponse = get("player/overview")
        async let resources: ResourcesResponse = get("player/resources")
        async let fleets: FleetsResponse = get("player/fleets")

        return try await DashboardPayload(
            overview: overview,
            resources: resources,
            fleets: fleets
        )
    }

    func moveFleet(slot: Int, targetSector: Int, targetSystem: Int) async throws {
        let url = baseURL.appendingPathComponent("fleet/move")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(accessToken)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONEncoder().encode([
            "fleet_slot": slot,
            "target_sector": targetSector,
            "target_system": targetSystem,
        ])

        let (_, response) = try await URLSession.shared.data(for: request)
        try ensureHTTP(response)
    }

    private func get<T: Decodable>(_ path: String) async throws -> T {
        do {
            return try await getWithAccessToken(path)
        } catch {
            try await refreshAccessToken()
            return try await getWithAccessToken(path)
        }
    }

    private func getWithAccessToken<T: Decodable>(_ path: String) async throws -> T {
        var request = URLRequest(url: baseURL.appendingPathComponent(path))
        request.httpMethod = "GET"
        request.setValue("Bearer \(accessToken)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await URLSession.shared.data(for: request)
        try ensureHTTP(response)
        return try JSONDecoder().decode(T.self, from: data)
    }

    private func refreshAccessToken() async throws {
        let url = baseURL.appendingPathComponent("auth/refresh")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONEncoder().encode(["refresh_token": refreshToken])

        let (data, response) = try await URLSession.shared.data(for: request)
        try ensureHTTP(response)
        let payload = try JSONDecoder().decode(RefreshPayload.self, from: data)
        accessToken = payload.accessToken
    }

    private func ensureHTTP(_ response: URLResponse) throws {
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw URLError(.badServerResponse)
        }
    }
}
