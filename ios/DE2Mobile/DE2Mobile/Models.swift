import Foundation

struct TokenPayload: Decodable {
    let accessToken: String
    let refreshToken: String

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
    }
}

struct RefreshPayload: Decodable {
    let accessToken: String

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
    }
}

struct DashboardPayload {
    let overview: OverviewResponse
    let resources: ResourcesResponse
    let fleets: FleetsResponse
}

struct OverviewResponse: Decodable {
    let player: Player
    let server: Server
}

struct Player: Decodable {
    let user_id: String
    let spielername: String
    let score: String
    let sector: String
    let system: String
}

struct Server: Decodable {
    let wt: Int
    let kt: Int
    let server_time: String
}

struct ResourcesResponse: Decodable {
    let resources: Resources
}

struct Resources: Decodable {
    let restyp01: String
    let restyp02: String
    let restyp03: String
    let restyp04: String
    let restyp05: String
}

struct FleetsResponse: Decodable {
    let fleets: [Fleet]
}

struct Fleet: Decodable, Identifiable {
    let user_id: String
    let hsec: String
    let hsys: String
    let zielsec: String
    let zielsys: String
    let fleetsize: String

    var id: String { user_id }
}
