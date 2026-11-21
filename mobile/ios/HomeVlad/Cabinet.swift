import SwiftUI

struct Cabinet: View {
    @ObservedObject var session: CloudSession
    @State private var selectedTab = "overview"
    @State private var ordering = false
    @State private var adminMode = false
    @State private var adminTab = "admin"

    var body: some View {
        Group {
            if let snapshot = session.snapshot {
                if snapshot.isAdmin && adminMode {
                    TabView(selection: $adminTab) {
                        NavigationStack {
                            NativeAdminHome(session: session).modifier(modeControl(true))
                        }.tabItem { Label("Сводка", systemImage: "chart.bar") }.tag("admin")
                        NavigationStack {
                            AdminGuestList(session: session).modifier(modeControl(true))
                        }.tabItem { Label("Машины", systemImage: "server.rack") }.tag("guests")
                        NavigationStack {
                            AdminInfrastructure(session: session).modifier(modeControl(true))
                        }.tabItem { Label("Ноды и зоны", systemImage: "point.3.connected.trianglepath.dotted") }.tag("infrastructure")
                    }
                } else {
                    TabView(selection: $selectedTab) {
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
                                Section("Безопасность") {
                                    if session.biometricEnabled {
                                        Button("Отключить биометрический вход") { Task { await session.disableBiometrics() } }
                                    } else if let label = session.biometricLabel, session.canEnrollBiometrics {
                                        Button("Включить вход по " + label) { Task { await session.enrollBiometrics() } }
                                    }
                                    Button("Выйти", role: .destructive) {
                                        Task { await session.logout(); selectedTab = "overview" }
                                    }
                                    if let error = session.error { Text(error).foregroundStyle(.secondary) }
                                }
                            }
                            .navigationTitle("HomeVlad")
                            .toolbar {
                                ToolbarItem(placement: .topBarLeading) {
                                    Button { ordering = true } label: { Label("Создать ВМ или LXC", systemImage: "plus") }
                                }
                            }
                            .modifier(modeControl(snapshot.isAdmin))
                            .sheet(isPresented: $ordering) {
                                NavigationStack { NativeOrder(session: session) }
                            }
                            .scrollContentBackground(.hidden)
                            .background(HomeVladMintBackground())
                            .refreshable { await session.refresh() }
                        }.tabItem { Label("Обзор", systemImage: "cloud") }.tag("overview")
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
                                        if let rx = node.networkRx, let tx = node.networkTx {
                                            LabeledContent("Сеть RX / TX", value: String(format: "%.2f / %.2f Мбит/с", rx, tx))
                                        }
                                        if let internet = node.guestInternet {
                                            LabeledContent("Интернет из гостя", value: internet)
                                        }
                                    }
                                }
                                if snapshot.nodes.isEmpty { Text("Доступных узлов пока нет") }
                            }
                            .scrollContentBackground(.hidden).background(HomeVladMintBackground())
                            .navigationTitle("Мониторинг")
                            .modifier(modeControl(snapshot.isAdmin))
                            .refreshable { await session.refresh() }
                        }.tabItem { Label("Мониторинг", systemImage: "chart.bar") }.tag("monitor")
                        NavigationStack {
                            NativeTickets(session: session, admin: false).modifier(modeControl(snapshot.isAdmin))
                        }.tabItem { Label("Поддержка", systemImage: "bubble.left.and.bubble.right") }.tag("tickets")
                        NavigationStack {
                            NativePayments(session: session, admin: false).modifier(modeControl(snapshot.isAdmin))
                        }.tabItem { Label("Финансы", systemImage: "creditcard") }.tag("payments")
                    }
                }
            } else {
                NativeLogin(session: session)
            }
        }.task { await session.start() }
         .onChange(of: session.snapshot?.isAdmin) { _, isAdmin in
             if isAdmin != true && adminMode {
                 adminMode = false
                 selectedTab = "overview"
             }
         }
    }

    private func modeControl(_ available: Bool) -> AdminModeToolbar {
        AdminModeToolbar(available: available, active: Binding(get: { adminMode }, set: { value in
            adminMode = value
            selectedTab = "overview"
            adminTab = "admin"
            if value { Task { await session.refresh() } }
        }))
    }
}

private struct AdminModeToolbar: ViewModifier {
    let available: Bool
    @Binding var active: Bool

    func body(content: Content) -> some View {
        content.toolbar {
            if available {
                ToolbarItem(placement: .topBarTrailing) {
                    HStack(spacing: 4) {
                        Image(systemName: "person.badge.shield.checkmark").accessibilityHidden(true)
                        Toggle("Режим администратора", isOn: $active).labelsHidden().fixedSize()
                    }
                }
            }
        }
    }
}

struct NativeLogin: View {
    @ObservedObject var session: CloudSession
    @State private var email = ""
    @State private var password = ""
    @State private var answer = ""

    var body: some View {
        ScrollView {
            VStack(spacing: 24) {
                VStack(spacing: 12) {
                    Image(systemName: "cloud.fill")
                        .font(.system(size: 42, weight: .medium))
                        .foregroundStyle(Color(red: 0.10, green: 0.45, blue: 0.35))
                        .frame(width: 84, height: 84)
                        .background(.white.opacity(0.85), in: RoundedRectangle(cornerRadius: 24))
                    Text("HomeVlad").font(.system(.largeTitle, design: .rounded).bold())
                    Text("Вход в облачный кабинет").foregroundStyle(.secondary)
                }
                VStack(spacing: 14) {
                    TextField("Email", text: $email).keyboardType(.emailAddress)
                        .textContentType(.username).textInputAutocapitalization(.never).autocorrectionDisabled()
                        .padding(14).background(Color(red: 0.93, green: 0.98, blue: 0.96), in: RoundedRectangle(cornerRadius: 14))
                    SecureField("Пароль", text: $password).textContentType(.password)
                        .padding(14).background(Color(red: 0.93, green: 0.98, blue: 0.96), in: RoundedRectangle(cornerRadius: 14))
                    HStack {
                        if let challenge = session.challenge {
                            TextField("\(challenge.captcha) = ?", text: $answer).keyboardType(.numberPad)
                                .accessibilityLabel("Ответ на проверочный вопрос")
                                .padding(14).background(Color(red: 0.93, green: 0.98, blue: 0.96), in: RoundedRectangle(cornerRadius: 14))
                        }
                        Button {
                            answer = ""
                            Task { await session.prepareLogin() }
                        } label: {
                            Image(systemName: "arrow.clockwise").padding(8)
                        }.accessibilityLabel("Обновить CAPTCHA").disabled(session.loading)
                    }
                    Button {
                        Task {
                            await session.login(email: email, password: password, answer: answer)
                            password = ""
                            answer = ""
                        }
                    } label: { Text("Войти").frame(maxWidth: .infinity) }
                    .buttonStyle(.borderedProminent).controlSize(.large)
                    .disabled(session.loading || session.challenge == nil || email.isEmpty || password.isEmpty || answer.isEmpty)
                    if session.biometricEnabled, let label = session.biometricLabel {
                        Button { Task { await session.biometricLogin() } } label: {
                            Label("Войти по " + label, systemImage: label == "Face ID" ? "faceid" : "touchid")
                        }.disabled(session.loading || session.challenge == nil)
                    }
                    if session.loading { ProgressView() }
                    if let error = session.error { Text(error).font(.footnote).foregroundStyle(.red) }
                }
                .padding(22)
                .background(.white, in: RoundedRectangle(cornerRadius: 26))
            }
            .frame(maxWidth: 420)
            .padding(.horizontal, 24)
            .padding(.vertical, 38)
            .frame(maxWidth: .infinity)
        }.background(HomeVladMintBackground())
    }
}
