import SwiftUI

struct Cabinet: View {
    @ObservedObject var session: CloudSession

    var body: some View {
        NavigationStack {
            Group {
                if let snapshot = session.snapshot {
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
                                    CabinetDestination(session: session, title: guest.name, path: guest.managementPath)
                                } label: {
                                    VStack(alignment: .leading, spacing: 5) {
                                        Text(guest.name).font(.headline)
                                        Text("\(guest.type.uppercased()) · №\(guest.vmId) · \(status(guest.status))")
                                        Text("\(guest.cpu) vCPU · \(guest.ramMiB / 1024) ГБ RAM · \(guest.diskGiB) ГБ")
                                            .font(.caption).foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                        Section("Инфраструктура") {
                            if snapshot.nodes.isEmpty { Text("Доступных узлов пока нет") }
                            ForEach(snapshot.nodes) { node in
                                VStack(alignment: .leading, spacing: 5) {
                                    Text("\(node.zone) / \(node.name)").font(.headline)
                                    Text(status(node.status))
                                    if let cpu = node.cpu, let memory = node.memory {
                                        Text("CPU \(cpu, specifier: "%.1f")% · RAM \(memory, specifier: "%.1f")%").font(.caption)
                                    } else {
                                        Text("Нет свежих замеров").font(.caption).foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                        Section("Управление") {
                            destination("Заказать сервер", "/templates/order_vm.php")
                            destination("Финансы", "/templates/billing.php")
                            destination("Поддержка", "/templates/support.php")
                            destination("Профиль", "/templates/settings.php")
                        }
                        if snapshot.isAdmin {
                            Section("Администрирование") {
                                destination("Панель администратора", "/admin/")
                                destination("Платежи", "/admin/payments.php")
                                destination("Инфраструктура", "/admin/nodes.php")
                                destination("Обновления", "/admin/update.php")
                            }
                        }
                        Section { Button("Выйти", role: .destructive) { Task { await session.logout() } } }
                    }
                    .refreshable { await session.refresh() }
                } else {
                    VStack(spacing: 20) {
                        Image(systemName: "cloud.fill").font(.system(size: 60)).foregroundStyle(.tint)
                        Text("HomeVlad Cloud").font(.largeTitle.bold())
                        Text("Серверы и инфраструктура").foregroundStyle(.secondary)
                        Button("Войти в кабинет") { session.signingIn = true }.buttonStyle(.borderedProminent).disabled(session.loading)
                        if session.loading { ProgressView() }
                    }.padding()
                }
            }
            .navigationTitle("HomeVlad")
            .safeAreaInset(edge: .bottom) {
                if let error = session.error {
                    HStack {
                        Text(error).font(.caption)
                        Spacer()
                        Button("Повторить") { Task { await session.refresh() } }
                    }.padding().background(.regularMaterial)
                }
            }
            .sheet(isPresented: $session.signingIn) {
                NavigationStack {
                    WebCabinet(session: session, path: "/login/login.php")
                        .navigationTitle("Вход")
                        .toolbar { Button("Закрыть") { session.signingIn = false } }
                }
            }
            .task { await session.refresh() }
        }
    }

    private func destination(_ title: String, _ path: String) -> some View {
        NavigationLink(title) { CabinetDestination(session: session, title: title, path: path) }
    }

    private func status(_ value: String) -> String {
        switch value {
        case "online", "running": "Работает"
        case "offline": "Нет ответа"
        case "stopped": "Остановлен"
        case "suspended": "Приостановлен"
        case "creating": "Создаётся"
        default: "Неизвестно"
        }
    }
}
