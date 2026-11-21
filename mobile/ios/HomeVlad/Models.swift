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
    let csrf: String
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


}

struct CloudNode: Decodable, Identifiable {
    let id: Int
    let name: String
    let zone: String
    let status: String
    let cpu: Double?
    let memory: Double?
    let networkRx: Double?
    let networkTx: Double?
    let guestInternet: String?
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


struct CloudPayment: Decodable, Identifiable {
    let id: Int
    let amount: Double
    let status: String
    let createdAt: String
    let method: String
    let userName: String
}
struct CloudTicket: Decodable, Identifiable {
    let id: Int
    let subject: String
    let message: String
    let status: String
    let createdAt: String
    let updatedAt: String
    let userName: String
}
struct CloudReply: Decodable, Identifiable {
    let id: Int
    let message: String
    let isAdmin: Bool
    let createdAt: String
    let userName: String
}
struct CloudTicketThread: Decodable {
    let ticket: CloudTicket
    let replies: [CloudReply]
}
struct CloudItems<T: Decodable>: Decodable { let items: [T] }
struct CloudGuestLive: Decodable {
    let status: String
    let cpu: Double?
    let memory: Double?
    let uptime: Int
}

func cloudStatus(_ value: String) -> String {
    switch value {
    case "online", "running": "Работает"
    case "offline": "Нет ответа"
    case "stopped": "Остановлен"
    case "suspended": "Приостановлен"
    case "creating": "Создаётся"
    case "pending": "Ожидает"
    case "completed": "Подтверждён"
    case "failed": "Отклонён"
    case "open": "Открыто"
    case "answered": "Есть ответ"
    case "closed": "Закрыто"
    default: "Неизвестно"
    }
}
