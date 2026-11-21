import SwiftUI

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
            Section("Управление") {
                ForEach(["start", "shutdown", "reboot", "stop"], id: \.self) { command in
                    Button(label(command), role: command == "stop" ? .destructive : nil) { pending = command }
                        .disabled(busy)
                }
            }
            if busy { ProgressView() }
            if let message { Text(message).font(.footnote) }
        }
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
