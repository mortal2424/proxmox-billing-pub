import SwiftUI

struct Cabinet: View {
    @ObservedObject var session: CloudSession

    var body: some View {
        Group {
            if let snapshot = session.snapshot {
                TabView {
                    NavigationStack {
                        List {
                            Section("Личный кабинет") {
                                Text(snapshot.name).font(.headline)
                                LabeledContent("Баланс", value: snapshot.balance.formatted(.currency(code: "RUB")))
                                LabeledContent("Бонусы", value: snapshot.bonusBalance.formatted(.currency(code: "RUB")))
                            }
                            Section("Машины") {
                                if snapshot.guests.isEmpty { Text("Серверов пока нет").foregroundStyle(.secondary) }
                                ForEach(snapshot.guests) { guest in
                                    NavigationLink {
                                        NativeGuestDetail(session: session, guest: guest)
                                    } label: {
                                        VStack(alignment: .leading, spacing: 5) {
                                            Text(guest.name).font(.headline)
                                            Text("\(guest.type.uppercased()) · №\(guest.vmId) · \(cloudStatus(guest.status))")
                                                .font(.caption).foregroundStyle(.secondary)
                                            Text("\(guest.cpu) vCPU · \(guest.ramMiB / 1024) ГБ RAM · \(guest.diskGiB) ГБ")
                                                .font(.caption)
                                        }
                                    }
                                }
                            }
                            Section { Button("Выйти", role: .destructive) { Task { await session.logout() } } }
                        }
                        .navigationTitle("HomeVlad")
                        .refreshable { await session.refresh() }
                    }.tabItem { Label("Обзор", systemImage: "cloud") }
                    NavigationStack {
                        List {
                            ForEach(snapshot.nodes) { node in
                                Section(node.zone + " / " + node.name) {
                                    Text(cloudStatus(node.status))
                                    if let cpu = node.cpu, let memory = node.memory {
                                        LabeledContent("CPU", value: String(format: "%.1f%%", cpu))
                                        ProgressView(value: min(100, max(0, cpu)), total: 100)
                                        LabeledContent("Память", value: String(format: "%.1f%%", memory))
                                        ProgressView(value: min(100, max(0, memory)), total: 100)
                                    } else {
                                        Text("Нет свежих замеров").foregroundStyle(.secondary)
                                    }
                                }
                            }
                            if snapshot.nodes.isEmpty { Text("Доступных узлов пока нет") }
                        }
                        .navigationTitle("Мониторинг")
                        .refreshable { await session.refresh() }
                    }.tabItem { Label("Мониторинг", systemImage: "chart.bar") }
                    NavigationStack {
                        NativeTickets(session: session, admin: false)
                    }.tabItem { Label("Поддержка", systemImage: "bubble.left.and.bubble.right") }
                    NavigationStack {
                        NativePayments(session: session, admin: false)
                    }.tabItem { Label("Финансы", systemImage: "creditcard") }
                    if snapshot.isAdmin {
                        NavigationStack {
                            List {
                                Section("Администрирование") {
                                    NavigationLink("Платежи пользователей") { NativePayments(session: session, admin: true) }
                                    NavigationLink("Все обращения") { NativeTickets(session: session, admin: true) }
                                }
                            }.navigationTitle("Администратор")
                        }.tabItem { Label("Админ", systemImage: "person.badge.shield.checkmark") }
                    }
                }
            } else {
                NativeLogin(session: session)
            }
        }.task { await session.start() }
    }
}

struct NativeLogin: View {
    @ObservedObject var session: CloudSession
    @State private var email = ""
    @State private var password = ""
    @State private var answer = ""

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Image(systemName: "cloud.fill").font(.system(size: 48)).foregroundStyle(.tint)
                        .frame(maxWidth: .infinity).padding()
                    Text("HomeVlad").font(.largeTitle.bold()).frame(maxWidth: .infinity)
                    TextField("Email", text: $email).keyboardType(.emailAddress)
                        .textContentType(.username).textInputAutocapitalization(.never).autocorrectionDisabled()
                    SecureField("Пароль", text: $password).textContentType(.password)
                    if let challenge = session.challenge {
                        TextField("CAPTCHA · \(challenge.captcha) = ?", text: $answer).keyboardType(.numberPad)
                    }
                    Button("Обновить CAPTCHA") {
                        answer = ""
                        Task { await session.prepareLogin() }
                    }.disabled(session.loading)
                    Button("Войти") {
                        Task {
                            await session.login(email: email, password: password, answer: answer)
                            password = ""
                            answer = ""
                        }
                    }.disabled(session.loading || session.challenge == nil || email.isEmpty || password.isEmpty || answer.isEmpty)
                    if session.loading { ProgressView() }
                }
                if let error = session.error { Section { Text(error).foregroundStyle(.red) } }
            }.navigationTitle("Вход")
        }
    }
}
