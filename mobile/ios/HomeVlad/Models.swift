import Foundation

enum CloudEnvironment {
    #if BILLING_DEV
    static let host = "dev.homevlad.ru"
    #else
    static let host = "homevlad.ru"
    #endif
    static let origin = URL(string: "https://" + host)!
}

struct CloudSnapshot: Decodable {
    let name: String
    let balance: Double
    let bonusBalance: Double
    let isAdmin: Bool
    let guests: [CloudGuest]
    let nodes: [CloudNode]
}

struct CloudGuest: Decodable, Identifiable {
    let id: Int
    let vmId: Int
    let nodeId: Int
    let name: String
    let type: String
    let status: String
    let cpu: Int
    let ramMiB: Int
    let diskGiB: Int

    var managementPath: String {
        "/templates/my_vms.php?guest=\(vmId)&type=\(type == "lxc" ? "lxc" : "qemu")"
    }
}

struct CloudNode: Decodable, Identifiable {
    let id: Int
    let name: String
    let zone: String
    let status: String
    let cpu: Double?
    let memory: Double?
}

enum CloudError: LocalizedError {
    case signIn
    case unavailable
    var errorDescription: String? {
        switch self {
        case .signIn: "Войдите в кабинет"
        case .unavailable: "Не удалось загрузить данные. Проверьте соединение."
        }
    }
}
