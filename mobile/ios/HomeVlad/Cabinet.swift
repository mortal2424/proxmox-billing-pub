import SwiftUI

struct Cabinet: View {
    @ObservedObject var session: CloudSession
    @State private var selectedTab = "overview"
    @State private var ordering = false

    var body: some View {
        Group {
            if let snapshot = session.snapshot {
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
                            if snapshot.isAdmin {
                                ToolbarItem(placement: .topBarTrailing) {
                                    Button { selectedTab = "admin" } label: {
                                        Label("Админка", systemImage: "person.badge.shield.checkmark")
                                    }
                                }
                            }
                        }
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
                        .refreshable { await session.refresh() }
                    }.tabItem { Label("Мониторинг", systemImage: "chart.bar") }.tag("monitor")
                    NavigationStack {
                        NativeTickets(session: session, admin: false)
                    }.tabItem { Label("Поддержка", systemImage: "bubble.left.and.bubble.right") }.tag("tickets")
                    NavigationStack {
                        NativePayments(session: session, admin: false)
                    }.tabItem { Label("Финансы", systemImage: "creditcard") }.tag("payments")
                    if snapshot.isAdmin {
                        NavigationStack {
                            NativeAdminHome(session: session)
                        }.tabItem { Label("Админ", systemImage: "person.badge.shield.checkmark") }.tag("admin")
                    }
                }
            } else {
                NativeLogin(session: session)
            }
        }.task { await session.start() }
         .onChange(of: session.snapshot?.isAdmin) { _, isAdmin in
             if isAdmin != true && selectedTab == "admin" { selectedTab = "overview" }
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
