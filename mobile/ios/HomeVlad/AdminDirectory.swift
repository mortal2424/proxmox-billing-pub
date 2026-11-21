import SwiftUI

private let directoryAPI = "/api/mobile_admin.php"

private func directoryPath(_ resource: String, _ parameters: [URLQueryItem] = []) throws -> String {
    var url = URLComponents()
    url.path = directoryAPI
    url.queryItems = [URLQueryItem(name: "resource", value: resource)] + parameters
    guard let path = url.string else { throw CloudError.unavailable }
    return path.replacingOccurrences(of: "+", with: "%2B")
}

private struct DirectoryPage<Item: Decodable>: Decodable {
    let items: [Item]
    let next: Int?
}

private struct DirectoryFilter: Equatable {
    var query = ""
    var status = ""

    func parameters(before: Int?) -> [URLQueryItem] {
        var values = [URLQueryItem(name: "q", value: query), URLQueryItem(name: "status", value: status)]
        if let before { values.append(URLQueryItem(name: "before", value: String(before))) }
        return values
    }
}

private struct DirectoryList<Item: Decodable & Identifiable, Controls: View, Row: View>: View {
    @ObservedObject var session: CloudSession
    let resource: String
    let filter: DirectoryFilter
    var parameters: [URLQueryItem] = []
    let revision: Int
    let emptyText: String
    @ViewBuilder let controls: () -> Controls
    @ViewBuilder let row: (Item) -> Row
    @State private var items: [Item] = []
    @State private var next: Int?
    @State private var loading = true
    @State private var error: String?
    @State private var generation = 0

    var body: some View {
        List {
            controls()
            ForEach(items) { row($0) }
            if loading { ProgressView() }
            else if let error {
                Text(error).foregroundStyle(.red)
                Button("Повторить") { Task { await load() } }
            } else if items.isEmpty { Text(emptyText).foregroundStyle(.secondary) }
            if let next, !loading, error == nil {
                Button("Загрузить ещё") { Task { await load(before: next) } }
            }
        }
        .task(id: filter) {
            generation += 1
            items = []
            next = nil
            loading = true
            error = nil
            try? await Task.sleep(for: .milliseconds(250))
            if !Task.isCancelled { await load() }
        }
        .onChange(of: revision) { _, _ in Task { await load() } }
        .refreshable { await load() }
    }

    private func load(before: Int? = nil) async {
        generation += 1
        let current = generation
        loading = true
        error = nil
        defer { if generation == current { loading = false } }
        do {
            let path = try directoryPath(resource, parameters + filter.parameters(before: before))
            let page: DirectoryPage<Item> = try await session.get(path)
            guard generation == current, !Task.isCancelled else { return }
            items = before == nil ? page.items : items + page.items
            next = page.next
        } catch {
            if generation == current, !Task.isCancelled { self.error = error.localizedDescription }
        }
    }
}

private func directoryMoney(_ value: String) -> String {
    let locale = Locale(identifier: "en_US_POSIX")
    return Decimal(string: value, locale: locale)?.formatted(.currency(code: "RUB")) ?? value
}

private struct DirectoryAccount: Decodable, Identifiable {
    let id: Int
    let name: String
    let email: String
    let isAdmin: Bool
    let isActive: Bool
    let balance: String
}

struct NativeAdminAccounts: View {
    @ObservedObject var session: CloudSession
    @State private var filter = DirectoryFilter()
    @State private var creating = false
    @State private var revision = 0

    var body: some View {
        DirectoryList(session: session, resource: "accounts", filter: filter, revision: revision,
                      emptyText: "Пользователи не найдены") {
            Picker("Статус", selection: $filter.status) {
                Text("Все").tag("")
                Text("Активные").tag("active")
                Text("Неактивные").tag("inactive")
            }.pickerStyle(.segmented)
        } row: { (user: DirectoryAccount) in
            NavigationLink {
                AdminAccountEditor(session: session, id: user.id) { revision += 1 }
            } label: {
                VStack(alignment: .leading, spacing: 4) {
                    Text(user.name).font(.headline)
                    if user.name != user.email { Text(user.email).font(.subheadline) }
                    Text([user.isAdmin ? "Администратор" : "Пользователь",
                          user.isActive ? "Активен" : "Неактивен", directoryMoney(user.balance)].joined(separator: " · "))
                        .font(.caption).foregroundStyle(.secondary)
                }
            }
        }
        .searchable(text: $filter.query, prompt: "Имя, email или ID")
        .navigationTitle("Пользователи")
        .toolbar {
            Button { creating = true } label: { Label("Добавить пользователя", systemImage: "person.badge.plus") }
        }
        .sheet(isPresented: $creating) {
            NavigationStack {
                AdminAccountEditor(session: session, id: 0) { revision += 1 }
                    .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Отмена") { creating = false } } }
            }
        }
    }
}

private struct DirectoryAccountForm: Decodable {
    var email: String
    var fullName: String
    var type: String
    var company: String
    var inn: String
    var kpp: String
    var isActive: Bool
    var isAdmin: Bool
    let balance: String
    let createdAt: String
    let isSelf: Bool

    static let blank = Self(email: "", fullName: "", type: "individual", company: "", inn: "", kpp: "",
                            isActive: false, isAdmin: false, balance: "", createdAt: "", isSelf: false)
}

private struct AdminAccountEditor: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject var session: CloudSession
    let id: Int
    let saved: () -> Void
    @State private var record = DirectoryAccountForm.blank
    @State private var originalAdmin = false
    @State private var password = ""
    @State private var repeatedPassword = ""
    @State private var loaded = false
    @State private var busy = false
    @State private var confirmRole = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { Text(error).foregroundStyle(.red) }
            if loaded {
                Section("Профиль") {
                    field("Имя", text: $record.fullName)
                    field("Email", text: $record.email).keyboardType(.emailAddress)
                    Picker("Тип клиента", selection: $record.type) {
                        Text("Физическое лицо").tag("individual")
                        Text("ИП").tag("entrepreneur")
                        Text("Организация").tag("legal")
                    }
                    if record.type != "individual" {
                        field("Компания", text: $record.company)
                        field("ИНН", text: $record.inn).keyboardType(.numberPad)
                        if record.type == "legal" { field("КПП", text: $record.kpp).keyboardType(.numberPad) }
                    }
                }
                Section("Доступ") {
                    Toggle("Учётная запись активна", isOn: $record.isActive).disabled(record.isSelf)
                    Toggle("Администратор", isOn: $record.isAdmin).disabled(record.isSelf)
                    if id == 0 {
                        SecureField("Пароль", text: $password).textContentType(.newPassword)
                        SecureField("Повторите пароль", text: $repeatedPassword).textContentType(.newPassword)
                    }
                }
                if id != 0 {
                    Section("Баланс и платежи") {
                        LabeledContent("Текущий баланс", value: directoryMoney(record.balance))
                        if !record.createdAt.isEmpty { LabeledContent("Регистрация", value: record.createdAt) }
                        NavigationLink("Платежи пользователя") {
                            NativeAdminPayments(session: session, userId: id)
                        }
                    }
                }
                if busy { ProgressView() }
                Button("Сохранить") {
                    if originalAdmin != record.isAdmin { confirmRole = true }
                    else { Task { await save() } }
                }.disabled(busy || record.email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ||
                           (id == 0 && (password.count < 8 || password.utf8.count > 72 || password != repeatedPassword)))
            } else if error == nil { ProgressView() }
            else { Button("Повторить") { Task { await load() } } }
        }
        .navigationTitle(id == 0 ? "Новый пользователь" : "Пользователь №\(id)")
        .task { await load() }
        .alert(record.isAdmin ? "Выдать права администратора?" : "Снять права администратора?", isPresented: $confirmRole) {
            Button("Отмена", role: .cancel) {}
            Button("Сохранить") { Task { await save() } }
        } message: { Text(record.email) }
        .interactiveDismissDisabled(busy)
    }

    private func field(_ title: String, text: Binding<String>) -> some View {
        LabeledContent(title) {
            TextField(title, text: text)
                .autocorrectionDisabled().textInputAutocapitalization(.never)
                .multilineTextAlignment(.trailing).accessibilityLabel(title)
        }
    }

    private func load() async {
        if id == 0 { loaded = true; return }
        error = nil
        do {
            record = try await session.get(directoryPath("account", [.init(name: "id", value: String(id))]))
            originalAdmin = record.isAdmin
            loaded = true
        } catch { self.error = error.localizedDescription }
    }

    private func save() async {
        guard !busy else { return }
        busy = true
        error = nil
        defer { busy = false }
        var body: [String: Any] = ["action": "account_save", "id": id, "email": record.email,
            "fullName": record.fullName, "type": record.type, "company": record.company,
            "inn": record.inn, "kpp": record.kpp, "isActive": record.isActive, "isAdmin": record.isAdmin]
        if id == 0 { body["password"] = password }
        do {
            _ = try await session.post(directoryAPI, body)
            password = ""; repeatedPassword = ""
            saved()
            dismiss()
        } catch { self.error = error.localizedDescription }
    }
}

private struct DirectoryPayment: Decodable, Identifiable {
    let id: Int
    let amount: String
    let status: String
    let createdAt: String
    let userName: String
    let email: String
    let method: String
    let description: String?
    let invoice: String?
    let paidAt: String?
    let updatedAt: String?
    let controlWord: String?
}

struct NativeAdminPayments: View {
    @ObservedObject var session: CloudSession
    var userId: Int? = nil
    @State private var filter = DirectoryFilter()
    @State private var creating = false
    @State private var revision = 0

    var body: some View {
        DirectoryList(session: session, resource: "payments", filter: filter,
                      parameters: userId.map { [URLQueryItem(name: "user", value: String($0))] } ?? [],
                      revision: revision, emptyText: "Платежи не найдены") {
            Picker("Статус", selection: $filter.status) {
                Text("Все").tag("")
                Text("На проверке").tag("pending")
                Text("Подтверждены").tag("completed")
                Text("Отклонены").tag("failed")
            }
        } row: { (payment: DirectoryPayment) in
            NavigationLink {
                AdminPaymentDetail(session: session, payment: payment) { revision += 1 }
            } label: {
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(directoryMoney(payment.amount)).font(.headline)
                        Spacer()
                        Text(cloudStatus(payment.status)).font(.caption)
                    }
                    Text(payment.userName)
                    Text("№\(payment.id) · \(payment.createdAt)").font(.caption).foregroundStyle(.secondary)
                }
            }
        }
        .searchable(text: $filter.query, prompt: "Номер, клиент или описание")
        .navigationTitle("Платежи")
        .toolbar { Button { creating = true } label: { Label("Добавить платёж", systemImage: "plus") } }
        .sheet(isPresented: $creating) {
            NavigationStack {
                AdminNewPayment(session: session, fixedUserId: userId) { revision += 1 }
                    .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Отмена") { creating = false } } }
            }
        }
    }
}

private struct AdminPaymentDetail: View {
    @ObservedObject var session: CloudSession
    @State var payment: DirectoryPayment
    let changed: () -> Void
    @State private var action: String?
    @State private var loaded = false
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?

    var body: some View {
        List {
            Section("Платёж") {
                LabeledContent("Сумма", value: directoryMoney(payment.amount))
                LabeledContent("Статус", value: cloudStatus(payment.status))
                LabeledContent("Клиент", value: payment.userName)
                if payment.userName != payment.email { LabeledContent("Email", value: payment.email) }
                LabeledContent("Создан", value: payment.createdAt)
                if !payment.method.isEmpty {
                    LabeledContent("Способ", value: payment.method == "manual" ? "Ручной" : payment.method)
                }
                if let description = payment.description, !description.isEmpty { LabeledContent("Описание", value: description) }
                if let invoice = payment.invoice, !invoice.isEmpty { LabeledContent("Счёт", value: invoice) }
                if let updated = payment.updatedAt, !updated.isEmpty { LabeledContent("Обновлён", value: updated) }
                if let paid = payment.paidAt, !paid.isEmpty { LabeledContent("Оплачен", value: paid) }
                if let word = payment.controlWord, !word.isEmpty { LabeledContent("Контрольное слово", value: word) }
            }
            if loaded && payment.status == "pending" {
                Section("Обработка") {
                    Button("Подтвердить и зачислить") { action = "payment_approve" }.disabled(busy)
                    Button("Отклонить", role: .destructive) { action = "payment_reject" }.disabled(busy)
                }
            }
            if let notice { Text(notice) }
            if let error { Text(error).foregroundStyle(.red) }
            if busy { ProgressView() }
        }
        .navigationTitle("Платёж №\(payment.id)")
        .task { await load() }
        .refreshable { await load() }
        .alert(action == "payment_approve" ? "Подтвердить платёж?" : "Отклонить платёж?",
               isPresented: Binding(get: { action != nil }, set: { if !$0 { action = nil } })) {
            Button("Отмена", role: .cancel) { action = nil }
            Button("Подтвердить действие", role: action == "payment_reject" ? .destructive : nil) {
                guard let action else { return }
                self.action = nil
                Task { await process(action) }
            }
        } message: {
            Text("\(payment.userName) · \(directoryMoney(payment.amount))")
        }
    }

    private func load() async {
        do {
            payment = try await session.get(directoryPath("payment", [.init(name: "id", value: String(payment.id))]))
            loaded = true
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func process(_ action: String) async {
        guard !busy else { return }
        busy = true
        defer { busy = false }
        do {
            notice = try await session.post(directoryAPI, ["action": action, "id": payment.id]).notice
            loaded = false
            changed()
            await load()
            await session.refresh()
        } catch {
            let message = error.localizedDescription
            await load()
            changed()
            self.error = message
        }
    }
}

private struct AdminNewPayment: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject var session: CloudSession
    let fixedUserId: Int?
    let saved: () -> Void
    @State private var payer: DirectoryAccount?
    @State private var selecting = false
    @State private var filter = DirectoryFilter()
    @State private var amount = ""
    @State private var description = ""
    @State private var busy = false
    @State private var error: String?
    @State private var requestId = UUID()
    @State private var attempted = false

    var body: some View {
        Form {
            if let error { Text(error).foregroundStyle(.red) }
            Section("Получатель") {
                if let payer { LabeledContent(payer.name, value: payer.email) }
                if let fixedUserId { LabeledContent("Клиент", value: "№\(fixedUserId)") }
                else { Button(payer == nil ? "Выбрать пользователя" : "Изменить пользователя") { selecting = true }.disabled(attempted) }
            }
            Section("Ручной платёж") {
                LabeledContent("Сумма, ₽") {
                    TextField("0,00", text: $amount).keyboardType(.decimalPad).multilineTextAlignment(.trailing)
                        .accessibilityLabel("Сумма в рублях")
                }.disabled(attempted)
                TextField("Описание", text: $description, axis: .vertical).disabled(attempted)
                Text("Платёж будет ожидать проверки. Баланс изменится после подтверждения.").font(.footnote)
            }
            if busy { ProgressView() }
            Button(attempted ? "Повторить отправку" : "Добавить платёж") {
                Task { await save() }
            }.disabled(busy || (payer == nil && fixedUserId == nil) ||
                        !validAmount || description.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || description.count > 255)
        }
        .navigationTitle("Новый платёж")
        .sheet(isPresented: $selecting) {
            NavigationStack {
                DirectoryList(session: session, resource: "accounts", filter: filter, revision: 0,
                              emptyText: "Пользователи не найдены") {
                    EmptyView()
                } row: { (user: DirectoryAccount) in
                    Button {
                        payer = user
                        selecting = false
                    } label: {
                        VStack(alignment: .leading) { Text(user.name); Text(user.email).font(.caption) }
                    }
                }
                .searchable(text: $filter.query, prompt: "Имя, email или ID")
                .navigationTitle("Получатель")
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Отмена") { selecting = false } } }
            }
        }
        .interactiveDismissDisabled(busy)
    }

    private var validAmount: Bool {
        let value = amount.trimmingCharacters(in: .whitespacesAndNewlines).replacingOccurrences(of: ",", with: ".")
        guard value.range(of: #"^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$"#, options: .regularExpression) != nil else { return false }
        return (Decimal(string: value, locale: Locale(identifier: "en_US_POSIX")) ?? 0) > 0
    }

    private func save() async {
        guard !busy, let user = fixedUserId ?? payer?.id else { return }
        busy = true
        attempted = true
        error = nil
        defer { busy = false }
        do {
            _ = try await session.post(directoryAPI, ["action": "payment_add", "userId": user, "amount": amount,
                "description": description, "requestId": requestId.uuidString])
            saved()
            dismiss()
        } catch {
            if let status = (error as? CloudRequestError)?.status, (400...499).contains(status) {
                attempted = false
            }
            self.error = error.localizedDescription
        }
    }
}
