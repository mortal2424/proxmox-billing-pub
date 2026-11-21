import SwiftUI
import Charts

struct NativeGuestDetail: View {
    @ObservedObject var session: CloudSession
    let guest: CloudGuest
    @State private var live: CloudGuestLive?
    @State private var busy = false
    @State private var message: String?
    @State private var pending: String?

    var body: some View {
        List {
            Section("Конфигурация") {
                LabeledContent("Тип", value: guest.type.uppercased())
                LabeledContent("Идентификатор", value: String(guest.vmId))
                LabeledContent("Последний известный IP", value: guest.ipAddress ?? "Пока не определён")
                LabeledContent("CPU", value: "\(guest.cpu) vCPU")
                LabeledContent("Память", value: "\(guest.ramMiB) МБ")
                LabeledContent("Диск", value: "\(guest.diskGiB) ГБ")
            }
            Section("Текущее состояние") {
                Text(cloudStatus(live?.status ?? guest.status))
                if let cpu = live?.cpu { LabeledContent("CPU", value: String(format: "%.1f%%", cpu)) }
                if let memory = live?.memory { LabeledContent("Память", value: String(format: "%.1f%%", memory)) }
            }
            NativeGuestGraphs(session: session, guestId: guest.id)
            Section("Управление") {
                ForEach(["start", "shutdown", "reboot", "stop"], id: \.self) { command in
                    Button(label(command), role: command == "stop" ? .destructive : nil) { pending = command }
                        .disabled(busy)
                }
            }
            if busy { ProgressView() }
            if let message { Text(message).font(.footnote) }
        }
        .scrollContentBackground(.hidden).background(HomeVladMintBackground())
        .navigationTitle(guest.name)
        .task { await load() }
        .refreshable { await load() }
        .alert("Управление машиной", isPresented: Binding(get: { pending != nil }, set: { if !$0 { pending = nil } })) {
            Button("Отмена", role: .cancel) { pending = nil }
            Button("Выполнить", role: pending == "stop" ? .destructive : nil) {
                guard let command = pending else { return }
                pending = nil
                Task {
                    busy = true
                    defer { busy = false }
                    do {
                        _ = try await session.post("/api/mobile_resources.php",
                            ["action": "guest_power", "id": guest.id, "command": command])
                        message = "Команда принята. Обновите состояние через несколько секунд."
                        await session.refresh()
                        await load()
                    } catch { message = error.localizedDescription }
                }
            }
        } message: {
            Text(pending == "stop"
                 ? "Принудительно остановить машину? Несохранённые данные могут быть потеряны."
                 : label(pending ?? "start") + " машину?")
        }
    }
    private func label(_ command: String) -> String {
        switch command {
        case "start": "Запустить"
        case "shutdown": "Выключить ОС"
        case "reboot": "Перезагрузить"
        default: "Остановить принудительно"
        }
    }
    private func load() async {
        do { live = try await session.get("/api/mobile_resources.php?resource=guest&id=\(guest.id)") }
        catch { message = error.localizedDescription }
    }
}

struct NativePayments: View {
    @ObservedObject var session: CloudSession
    let admin: Bool
    @State private var payments: [CloudPayment] = []
    @State private var busy = false
    @State private var error: String?
    @State private var pending: CloudPayment?
    @State private var approve = true

    var body: some View {
        List {
            Section("Последние 100 платежей") {
                ForEach(payments) { payment in
                    VStack(alignment: .leading, spacing: 7) {
                        Text(payment.amount.formatted(.currency(code: "RUB"))).font(.headline)
                        Text("#\(payment.id) · \(cloudStatus(payment.status))")
                        Text(payment.createdAt).font(.caption).foregroundStyle(.secondary)
                        if admin { Text(payment.userName).font(.caption) }
                        if admin && payment.status == "pending" {
                            HStack {
                                Button("Подтвердить") { approve = true; pending = payment }
                                Spacer()
                                Button("Отклонить", role: .destructive) { approve = false; pending = payment }
                            }.buttonStyle(.borderless).disabled(busy)
                        }
                    }.padding(.vertical, 4)
                }
                if payments.isEmpty { Text("Платежей пока нет").foregroundStyle(.secondary) }
            }
            if let error { Text(error).foregroundStyle(.red) }
        }
        .navigationTitle(admin ? "Платежи пользователей" : "Финансы")
        .task { await load() }.refreshable { await load() }
        .alert(approve ? "Подтвердить платёж?" : "Отклонить платёж?",
               isPresented: Binding(get: { pending != nil }, set: { if !$0 { pending = nil } })) {
            Button("Отмена", role: .cancel) { pending = nil }
            Button("Подтвердить действие", role: approve ? nil : .destructive) {
                guard let payment = pending else { return }
                let action = approve ? "payment_approve" : "payment_reject"
                pending = nil
                Task {
                    busy = true
                    defer { busy = false }
                    do {
                        _ = try await session.post("/api/mobile_resources.php", ["action": action, "id": payment.id])
                        await load()
                        await session.refresh()
                    } catch { self.error = error.localizedDescription }
                }
            }
        } message: {
            if let pending {
                Text("#\(pending.id) · \(pending.userName) · \(pending.amount.formatted(.currency(code: "RUB")))")
            }
        }
    }
    private func load() async {
        do {
            let result: CloudItems<CloudPayment> = try await session.get("/api/mobile_resources.php?resource=payments&admin=\(admin ? 1 : 0)")
            payments = result.items
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct NativeTickets: View {
    @ObservedObject var session: CloudSession
    let admin: Bool
    @State private var tickets: [CloudTicket] = []
    @State private var closed = false
    @State private var creating = false
    @State private var error: String?

    var body: some View {
        List {
            Picker("Состояние", selection: $closed) {
                Text("Активные").tag(false)
                Text("Закрытые").tag(true)
            }.pickerStyle(.segmented)
            Section("Последние 100 обращений") {
                ForEach(tickets) { ticket in
                    NavigationLink {
                        NativeTicketDetail(session: session, id: ticket.id, admin: admin)
                    } label: {
                        VStack(alignment: .leading, spacing: 5) {
                            Text(ticket.subject).font(.headline)
                            Text("#\(ticket.id) · \(cloudStatus(ticket.status))").font(.caption)
                            if admin { Text(ticket.userName).font(.caption).foregroundStyle(.secondary) }
                        }
                    }
                }
            }
            if let error { Text(error).foregroundStyle(.red) }
        }
        .navigationTitle(admin ? "Все обращения" : "Поддержка")
        .toolbar { Button { creating = true } label: { Image(systemName: "plus") } }
        .task(id: closed) { await load() }.refreshable { await load() }
        .sheet(isPresented: $creating, onDismiss: { Task { await load() } }) {
            NativeNewTicket(session: session)
        }
    }
    private func load() async {
        do {
            let requestedClosed = closed
            let result: CloudItems<CloudTicket> = try await session.get("/api/mobile_resources.php?resource=tickets&admin=\(admin ? 1 : 0)&closed=\(requestedClosed ? 1 : 0)")
            guard requestedClosed == closed, !Task.isCancelled else { return }
            tickets = result.items
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct NativeNewTicket: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject var session: CloudSession
    @State private var subject = ""
    @State private var message = ""
    @State private var department = "general"
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                TextField("Тема", text: $subject)
                Picker("Отдел", selection: $department) {
                    Text("Общие вопросы").tag("general")
                    Text("Техническая поддержка").tag("technical")
                    Text("Оплата").tag("billing")
                }
                Section("Сообщение · 20–5000 символов") { TextEditor(text: $message).frame(minHeight: 180) }
                if let error { Text(error).foregroundStyle(.red) }
                if busy { ProgressView() }
            }
            .navigationTitle("Новое обращение")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Отмена") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Отправить") {
                        Task {
                            busy = true
                            defer { busy = false }
                            do {
                                _ = try await session.post("/api/mobile_resources.php",
                                    ["action": "ticket_create", "subject": subject, "message": message, "department": department])
                                dismiss()
                            } catch { self.error = error.localizedDescription }
                        }
                    }.disabled(busy || subject.count < 5 || subject.count > 200 || message.count < 20 || message.count > 5000)
                }
            }
        }
    }
}

struct NativeTicketDetail: View {
    @ObservedObject var session: CloudSession
    let id: Int
    let admin: Bool
    @State private var thread: CloudTicketThread?
    @State private var reply = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        List {
            if let thread {
                Section(thread.ticket.subject) {
                    Text(cloudStatus(thread.ticket.status)).font(.caption).foregroundStyle(.secondary)
                    Text(thread.ticket.message).textSelection(.enabled)
                }
                Section("Последние 200 ответов") {
                    ForEach(thread.replies) { item in
                        VStack(alignment: .leading, spacing: 6) {
                            Text(item.isAdmin ? "Поддержка · \(item.userName)" : item.userName).font(.caption.bold())
                            Text(item.message).textSelection(.enabled)
                            Text(item.createdAt).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                if thread.ticket.status != "closed" {
                    Section("Ответ · 10–2000 символов") {
                        TextEditor(text: $reply).frame(minHeight: 110)
                        Button("Отправить") { Task { await action("ticket_reply") } }
                            .disabled(busy || reply.count < 10 || reply.count > 2000)
                        Button("Закрыть обращение", role: .destructive) { Task { await action("ticket_close") } }.disabled(busy)
                    }
                } else if admin {
                    Button("Открыть обращение") { Task { await action("ticket_reopen") } }.disabled(busy)
                }
            }
            if let error { Text(error).foregroundStyle(.red) }
            if busy { ProgressView() }
        }
        .navigationTitle("Обращение №\(id)")
        .task { await load() }.refreshable { await load() }
    }
    private func action(_ action: String) async {
        busy = true
        defer { busy = false }
        do {
            _ = try await session.post("/api/mobile_resources.php", ["action": action, "id": id, "message": reply])
            reply = ""
            await load()
        } catch { self.error = error.localizedDescription }
    }
    private func load() async {
        do {
            thread = try await session.get("/api/mobile_resources.php?resource=ticket&id=\(id)&admin=\(admin ? 1 : 0)")
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

private struct AdminOverview: Decodable {
    let users: Int
    let active_users: Int
    let zones: Int
    let nodes: Int
    let pending_payments: Int
    let open_tickets: Int
}

private struct AdminAccount: Decodable, Identifiable {
    let id: Int
    let name: String
    let email: String
    let isAdmin: Bool
    let isActive: Bool
}

private struct AdminAccountPage: Decodable {
    let items: [AdminAccount]
    let next: Int?
}

struct NativeAdminHome: View {
    @ObservedObject var session: CloudSession
    @State private var overview: AdminOverview?
    @State private var error: String?

    var body: some View {
        List {
            if let overview {
                Section("Система") {
                    LabeledContent("Пользователи", value: "\(overview.active_users) активных из \(overview.users)")
                    LabeledContent("Зоны доступности", value: "\(overview.zones)")
                    LabeledContent("Активные ноды", value: "\(overview.nodes)")
                    LabeledContent("Платежи к проверке", value: "\(overview.pending_payments)")
                    LabeledContent("Открытые обращения", value: "\(overview.open_tickets)")
                }
            } else if let error {
                Text(error).foregroundStyle(.secondary)
            } else { ProgressView() }
            Section("Управление") {
                NavigationLink("Пользователи") { NativeAdminAccounts(session: session) }
                NavigationLink("Платежи пользователей") { NativePayments(session: session, admin: true) }
                NavigationLink("Все обращения") { NativeTickets(session: session, admin: true) }
            }
        }
        .navigationTitle("Администрирование")
        .task { await refresh() }
        .refreshable { await refresh() }
    }

    private func refresh() async {
        do {
            overview = try await session.get("/api/mobile_resources.php?resource=admin_overview")
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

private struct NativeAdminAccounts: View {
    @ObservedObject var session: CloudSession
    @State private var users: [AdminAccount] = []
    @State private var next: Int?
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        List {
            ForEach(users) { user in
                VStack(alignment: .leading, spacing: 4) {
                    Text(user.name).font(.headline)
                    Text(user.email)
                    Text((user.isAdmin ? "Администратор" : "Пользователь") + (user.isActive ? " · Активен" : " · Неактивен"))
                        .font(.caption).foregroundStyle(.secondary)
                }
            }
            if busy { ProgressView() }
            if let next { Button("Загрузить ещё") { Task { await load(before: next) } }.disabled(busy) }
            if let error { Text(error).foregroundStyle(.secondary) }
        }
        .navigationTitle("Пользователи")
        .task { await load() }
        .refreshable { await load() }
    }

    private func load(before: Int? = nil) async {
        guard !busy else { return }
        busy = true
        defer { busy = false }
        do {
            let suffix = before.map { "&before=\($0)" } ?? ""
            let page: AdminAccountPage = try await session.get("/api/mobile_resources.php?resource=admin_users" + suffix)
            users = before == nil ? page.items : users + page.items
            next = page.next
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct NativeOrder: View {
    @ObservedObject var session: CloudSession
    @Environment(\.dismiss) private var dismiss
    @State private var type = "qemu"
    @State private var catalogue: OrderCatalogue?
    @State private var resources: OrderResources?
    @State private var zone = ""
    @State private var network = ""
    @State private var storage = ""
    @State private var image = ""
    @State private var tariff = ""
    @State private var hostname = ""
    @State private var message: String?
    @State private var loading = false
    @State private var submitting = false
    @State private var confirm = false
    @State private var created: CloudGuest?

    private var images: [OrderOption] { (type == "qemu" ? resources?.cloud : resources?.media) ?? [] }
    private var tariffs: [OrderOption] {
        let os = images.first(where: { $0.id == image })?.os_type
        return (catalogue?.tariffs ?? []).filter { os == nil || $0.os_type == nil || $0.os_type == os }
    }
    private var ready: Bool {
        !loading && !submitting && !hostname.trimmingCharacters(in: .whitespaces).isEmpty &&
        !zone.isEmpty && !network.isEmpty && !storage.isEmpty && !image.isEmpty && tariffs.contains { $0.id == tariff }
    }

    var body: some View {
        Form {
            if let created {
                Section("Гость создан") {
                    NavigationLink("Открыть карточку") { NativeGuestDetail(session: session, guest: created) }
                    if let message { Text(message).foregroundStyle(.secondary) }
                }
            } else {
                Section("Машина") {
                    Picker("Тип", selection: $type) { Text("ВМ").tag("qemu"); Text("LXC").tag("lxc") }.pickerStyle(.segmented)
                    TextField("Имя хоста", text: $hostname).textInputAutocapitalization(.never).autocorrectionDisabled()
                    options("Зона доступности", $zone, catalogue?.zones ?? [])
                    if let catalogue, catalogue.zones.isEmpty { Text("Нет доступных зон").foregroundStyle(.secondary) }
                }.disabled(submitting)
                if !zone.isEmpty {
                    Section("Размещение и образ") {
                        if loading { ProgressView("Загрузка ресурсов зоны") }
                        options("Пул сети", $network, resources?.network ?? [])
                        options("Пул хранилищ", $storage, resources?.storage ?? [])
                        options(type == "qemu" ? "Cloud-образ" : "Шаблон LXC", $image, images)
                        options("Тариф", $tariff, tariffs)
                        if resources != nil && (images.isEmpty || resources?.network.isEmpty == true || resources?.storage.isEmpty == true) {
                            Text("Для этого типа гостя в зоне доступны не все ресурсы. Выберите другую зону.")
                                .foregroundStyle(.secondary)
                        }
                    }.disabled(submitting)
                }
                Section {
                    Text("Пароль будет сгенерирован сервером. Его можно посмотреть в веб-кабинете в течение 24 часов.")
                        .font(.footnote).foregroundStyle(.secondary)
                    Button("Создать \(type == "qemu" ? "ВМ" : "контейнер")") { confirm = true }
                        .disabled(!ready)
                    if submitting { ProgressView("Создаём гостя…") }
                    if let message { Text(message).foregroundStyle(.secondary) }
                }
            }
        }
        .scrollContentBackground(.hidden).background(HomeVladMintBackground())
        .navigationTitle("Создание машины")
        .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Закрыть") { dismiss() }.disabled(submitting) } }
        .interactiveDismissDisabled(submitting)
        .task(id: type) {
            let selectedType = type
            do {
                let response: OrderCatalogue = try await session.get("/api/mobile_order.php?vm_type=\(selectedType)")
                if !Task.isCancelled && type == selectedType { catalogue = response }
            } catch { if !Task.isCancelled { message = error.localizedDescription } }
        }
        .task(id: zone) {
            guard !zone.isEmpty else { return }
            let selectedZone = zone
            let selectedType = type
            loading = true
            defer { if zone == selectedZone && type == selectedType { loading = false } }
            do {
                let response: OrderResources = try await session.get("/templates/get_zone_placement.php?zone_id=\(selectedZone)&vm_type=\(selectedType)")
                if !Task.isCancelled && zone == selectedZone && type == selectedType { resources = response }
            } catch { if !Task.isCancelled { message = error.localizedDescription } }
        }
        .onChange(of: type) { _, _ in catalogue = nil; zone = ""; loading = false; message = nil }
        .onChange(of: zone) { _, _ in resources = nil; network = ""; storage = ""; image = ""; tariff = ""; message = nil }
        .onChange(of: image) { _, _ in tariff = "" }
        .confirmationDialog("Подтвердить заказ", isPresented: $confirm, titleVisibility: .visible) {
            Button("Создать") { Task { await submit() } }
            Button("Отмена", role: .cancel) {}
        } message: { Text(tariffs.first(where: { $0.id == tariff })?.label ?? "") }
    }

    private func options(_ label: String, _ value: Binding<String>, _ choices: [OrderOption]) -> some View {
        Picker(label, selection: value) {
            Text("Выберите").tag("")
            ForEach(choices) { Text($0.label).tag($0.id) }
        }.disabled(choices.isEmpty)
    }

    private func submit() async {
        guard ready else { return }
        submitting = true
        message = nil
        defer { submitting = false }
        do {
            let result = try await session.post("/api/mobile_order.php", [
                "vm_type": type, "hostname": hostname, "zone_id": zone, "network_pool": network,
                "storage_pool": storage, "tariff_id": tariff, (type == "qemu" ? "cloud_image" : "template"): image
            ])
            await session.refresh()
            created = session.snapshot?.guests.first { $0.id == result.guestId }
            message = result.warning
            if created == nil { dismiss() }
        } catch {
            if (error as? CloudRequestError)?.status == 422 {
                message = error.localizedDescription
            } else {
                message = "Заказ не подтверждён. Проверьте список машин перед повтором."
            }
        }
    }
}

private struct NativeGuestGraphs: View {
    @ObservedObject var session: CloudSession
    let guestId: Int
    @State private var period = "hour"
    @State private var points: [GuestMetric] = []
    @State private var loading = true
    @State private var failure: String?
    private let periods = [("hour", "Час"), ("day", "День"), ("week", "Неделя"), ("month", "Месяц"), ("year", "Год")]

    var body: some View {
        Section("Графики") {
            Picker("Период", selection: $period) { ForEach(periods, id: \.0) { Text($0.1).tag($0.0) } }.pickerStyle(.segmented)
            if loading { ProgressView() }
            else if let failure { Text(failure).foregroundStyle(.secondary) }
            else {
                graph("CPU и память · %", [("CPU", \.cpu), ("Память", \.memory)])
                graph("Сеть · Мбит/с", [("Вход", \.netIn), ("Выход", \.netOut)])
                graph("Диск · МиБ/с", [("Чтение", \.diskRead), ("Запись", \.diskWrite)])
            }
        }
        .task(id: period) {
            loading = true
            failure = nil
            do {
                let result: CloudItems<GuestMetric> = try await session.get("/api/mobile_resources.php?resource=guest_metrics&id=\(guestId)&period=\(period)")
                if !Task.isCancelled { points = result.items; loading = false }
            } catch {
                if !Task.isCancelled { failure = error.localizedDescription; loading = false }
            }
        }
    }

    @ViewBuilder private func graph(_ title: String, _ series: [(String, KeyPath<GuestMetric, Double?>)]) -> some View {
        Text(title).font(.subheadline.bold())
        if series.contains(where: { series in points.contains { $0[keyPath: series.1] != nil } }) {
            Chart {
                ForEach(series, id: \.0) { item in
                    ForEach(points) { point in
                        if let value = point[keyPath: item.1] {
                            LineMark(x: .value("Время", Date(timeIntervalSince1970: Double(point.time))),
                                     y: .value("Значение", value), series: .value("Ряд", item.0))
                                .foregroundStyle(by: .value("Ряд", item.0))
                        }
                    }
                }
            }.frame(height: 150)
        } else { Text("Нет замеров за этот период").font(.caption).foregroundStyle(.secondary) }
    }
}
