import SwiftUI

private let adminAPI = "/api/mobile_admin.php"

private struct AdminGuestPage: Decodable {
    let items: [CloudGuest]
    let next: Int?
}

struct AdminGuestList: View {
    @ObservedObject var session: CloudSession
    @State private var guests: [CloudGuest] = []
    @State private var next: Int?
    @State private var search = ""
    @State private var type = ""
    @State private var error: String?
    @State private var loading = false
    @State private var generation = 0

    var body: some View {
        List {
            Picker("Тип", selection: $type) {
                Text("Все").tag("")
                Text("ВМ").tag("qemu")
                Text("LXC").tag("lxc")
            }.pickerStyle(.segmented)
            ForEach(guests) { guest in
                NavigationLink {
                    NativeGuestDetail(session: session, guest: guest)
                } label: {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(guest.name).font(.headline)
                        Text("\(guest.type.uppercased()) · №\(guest.vmId) · \(cloudStatus(guest.status))")
                        Text([guest.owner, guest.zone, guest.node].compactMap { $0 }.joined(separator: " · "))
                    }.font(.subheadline)
                }
            }
            if loading { ProgressView() }
            else if let error { Text(error).foregroundStyle(.red) }
            else if guests.isEmpty { Text("Машины не найдены").foregroundStyle(.secondary) }
            if let next, !loading {
                Button("Загрузить ещё") { Task { await load(before: next) } }
            }
        }
        .searchable(text: $search, prompt: "Имя, VMID или пользователь")
        .navigationTitle("ВМ и LXC")
        .task(id: type + "|" + search) {
            try? await Task.sleep(for: .milliseconds(250))
            if !Task.isCancelled { await load() }
        }
        .refreshable { await load() }
    }

    private func load(before: Int? = nil) async {
        generation += 1
        let request = generation
        loading = true
        error = nil
        if before == nil { guests = []; next = nil }
        defer { if request == generation { loading = false } }
        var url = URLComponents()
        url.path = adminAPI
        url.queryItems = [URLQueryItem(name: "resource", value: "guests"),
                          URLQueryItem(name: "q", value: search), URLQueryItem(name: "type", value: type)]
        if let before { url.queryItems?.append(URLQueryItem(name: "before", value: String(before))) }
        do {
            guard let path = url.string else { throw CloudError.unavailable }
            let page: AdminGuestPage = try await session.get(path.replacingOccurrences(of: "+", with: "%2B"))
            guard request == generation, !Task.isCancelled else { return }
            guests += page.items
            next = page.next
        } catch {
            if request == generation, !Task.isCancelled { self.error = error.localizedDescription }
        }
    }
}

private struct AdminZone: Decodable, Identifiable {
    let id: Int
    var name: String
    var description: String
    var active: Bool
    var limit: Int
    var threshold: Int
    var maintenance: Bool

    static let blank = Self(id: 0, name: "", description: "", active: true, limit: 50, threshold: 80, maintenance: false)
}

private struct AdminNode: Decodable, Identifiable {
    let id: Int
    var zoneId: Int
    var name: String
    var host: String
    var port: Int
    var tokenId: String
    var active: Bool
    var orders: Bool
    var primary: Bool
    var description: String
    let status: String
    let lastCheck: String?

    static let blank = Self(id: 0, zoneId: 0, name: "", host: "", port: 8006, tokenId: "",
        active: true, orders: false, primary: false, description: "", status: "unknown", lastCheck: nil)
}

private struct AdminInfrastructureData: Decodable {
    let zones: [AdminZone]
    let nodes: [AdminNode]
}

struct AdminInfrastructure: View {
    @ObservedObject var session: CloudSession
    @State private var data: AdminInfrastructureData?
    @State private var error: String?
    @State private var notice: String?

    var body: some View {
        List {
            if let notice { Text(notice) }
            if let error { Text(error).foregroundStyle(.red) }
            if let data {
                Section("Зоны доступности") {
                    NavigationLink("Добавить зону") { AdminZoneEditor(session: session, zone: .blank, saved: load) }
                    ForEach(data.zones) { zone in
                        NavigationLink {
                            AdminZoneEditor(session: session, zone: zone, saved: load)
                        } label: {
                            VStack(alignment: .leading) {
                                Text(zone.name)
                                Text(zone.maintenance ? "Обслуживание" : zone.active ? "Активна" : "Выключена").font(.caption)
                            }
                        }
                    }
                }
                Section("Ноды Proxmox") {
                    if data.zones.contains(where: \.active) {
                        NavigationLink("Добавить ноду") {
                            AdminNodeEditor(session: session, node: .blank, zones: data.zones, saved: update)
                        }
                    }
                    ForEach(data.nodes) { node in
                        NavigationLink {
                            AdminNodeEditor(session: session, node: node, zones: data.zones, saved: update)
                        } label: {
                            VStack(alignment: .leading, spacing: 4) {
                                Text(node.name)
                                Text("\(data.zones.first { $0.id == node.zoneId }?.name ?? "Без зоны") · \(node.active ? cloudStatus(node.status) : "Выключена")")
                                    .font(.caption)
                            }
                        }
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Ноды и зоны")
        .task { await load() }
        .refreshable { await load() }
    }

    private func update(_ message: String?) async {
        notice = message
        await load()
    }

    private func load() async {
        do {
            data = try await session.get(adminAPI + "?resource=infrastructure")
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

private struct AdminZoneEditor: View {
    @ObservedObject var session: CloudSession
    @State var zone: AdminZone
    let saved: () async -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        Form {
            Section("Доступность") {
                LabeledContent("Название") {
                    TextField("Введите название", text: $zone.name)
                        .multilineTextAlignment(.trailing)
                        .accessibilityLabel("Название")
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text("Описание").foregroundStyle(.secondary)
                    TextField("Добавьте описание", text: $zone.description, axis: .vertical)
                        .accessibilityLabel("Описание")
                }
                Toggle("Активна", isOn: $zone.active)
                Toggle("Режим обслуживания", isOn: $zone.maintenance)
            }
            Section("Размещение") {
                LabeledContent("Лимит ВМ и LXC на ноду") {
                    TextField("Лимит", value: $zone.limit, format: .number.grouping(.never))
                        .keyboardType(.numberPad)
                        .multilineTextAlignment(.trailing)
                        .accessibilityLabel("Лимит ВМ и LXC на ноду")
                }
                LabeledContent("Порог нагрузки, %") {
                    TextField("Порог", value: $zone.threshold, format: .number.grouping(.never))
                        .keyboardType(.numberPad)
                        .multilineTextAlignment(.trailing)
                        .accessibilityLabel("Порог нагрузки, в процентах")
                }
            }
            if let error { Text(error).foregroundStyle(.red) }
            if busy { ProgressView() }
            Button("Сохранить") { Task { await save() } }.disabled(busy || zone.name.trimmingCharacters(in: .whitespaces).isEmpty)
        }.navigationTitle(zone.id == 0 ? "Новая зона" : "Настройки зоны")
    }

    private func save() async {
        busy = true
        defer { busy = false }
        do {
            _ = try await session.post(adminAPI, ["action":"zone_save", "id":zone.id, "name":zone.name,
                "description":zone.description, "active":zone.active, "limit":zone.limit,
                "threshold":zone.threshold, "maintenance":zone.maintenance])
            await saved()
            dismiss()
        } catch { self.error = error.localizedDescription }
    }
}

private struct AdminNodeEditor: View {
    @ObservedObject var session: CloudSession
    @State var node: AdminNode
    let zones: [AdminZone]
    let saved: (String?) async -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var secret = ""
    @State private var message: String?
    @State private var failed = false
    @State private var busy = false

    var body: some View {
        Form {
            Section("Подключение") {
                Picker("Зона доступности", selection: $node.zoneId) {
                    Text("Выберите зону").tag(0)
                    ForEach(zones.filter { $0.active || $0.id == node.zoneId }) { Text($0.name).tag($0.id) }
                }
                TextField("Имя ноды", text: $node.name).textInputAutocapitalization(.never).autocorrectionDisabled()
                TextField("Адрес API", text: $node.host).textInputAutocapitalization(.never).autocorrectionDisabled()
                TextField("Порт API", value: $node.port, format: .number.grouping(.never)).keyboardType(.numberPad)
                TextField("ID API-токена", text: $node.tokenId).textInputAutocapitalization(.never).autocorrectionDisabled()
                SecureField(node.id == 0 ? "Секрет API-токена" : "Новый секрет API-токена", text: $secret)
            }
            Section("Размещение") {
                Toggle("Нода активна", isOn: $node.active)
                Toggle("Принимать новые заказы", isOn: $node.orders)
                Toggle("Главная нода", isOn: $node.primary)
                TextField("Описание", text: $node.description, axis: .vertical)
            }
            if node.id > 0 {
                Section("Диагностика") {
                    if let check = node.lastCheck { LabeledContent("Последняя проверка", value: check) }
                    Button("Проверить API") { Task { await perform("node_check") } }
                    Button("Обновить список нод кластера") { Task { await perform("node_import") } }
                }.disabled(busy)
            }
            if let message { Text(message).foregroundStyle(failed ? .red : .secondary) }
            if busy { ProgressView() }
            Button("Сохранить") { Task { await perform("node_save") } }
                .disabled(busy || node.zoneId == 0 || node.name.isEmpty || node.host.isEmpty || node.tokenId.isEmpty)
        }.navigationTitle(node.id == 0 ? "Новая нода" : "Настройки ноды")
    }

    private func perform(_ action: String) async {
        busy = true
        defer { busy = false }
        var body: [String: Any] = ["action":action, "id":node.id]
        if action == "node_save" {
            body.merge(["zoneId":node.zoneId, "name":node.name, "host":node.host, "port":node.port,
                "tokenId":node.tokenId, "secret":secret, "active":node.active, "orders":node.orders,
                "primary":node.primary, "description":node.description]) { _, new in new }
        }
        do {
            let response = try await session.post(adminAPI, body)
            failed = false
            message = response.notice
            await saved(response.notice)
            if action == "node_save" { secret = ""; dismiss() }
        } catch {
            failed = true
            message = error.localizedDescription
        }
    }
}
