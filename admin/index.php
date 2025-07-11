<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем последние тикеты для виджета
$recent_tickets = $db->getConnection()->query("
    SELECT t.id, t.subject, t.status, t.created_at, u.email 
    FROM tickets t
    JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
    LIMIT 5")->fetchAll();

// Получаем статистику
$stats = [
    'total_users' => 0,
    'total_vms' => 0,
    'active_vms' => 0,
    'total_nodes' => 0,
    'active_nodes' => 0,
    'total_income' => 0,
    'open_tickets' => 0
];

// Основная статистика
$stats['total_users'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users")->fetchColumn();
$stats['total_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms")->fetchColumn();
$stats['active_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'running'")->fetchColumn();

// Статистика по тикетам (если таблица существует)
if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
    $stats['open_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
}

// Статистика по нодам (если таблица существует)
if (safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0) {
    $stats['total_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes")->fetchColumn();
    $stats['active_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes WHERE is_active = 1")->fetchColumn();
}

// Статистика по платежам (если таблица существует)
if (safeQuery($pdo, "SHOW TABLES LIKE 'payments'")->rowCount() > 0) {
    $stats['total_income'] = (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE amount > 0")->fetchColumn();
}

// Последние пользователи
$recent_users = safeQuery($pdo, 
    "SELECT id, email, balance, " . 
    (columnExists($pdo, 'users', 'created_at') ? "created_at" : "NULL as created_at") . 
    " FROM users ORDER BY id DESC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Последние ВМ
$recent_vms = safeQuery($pdo, 
    "SELECT vms.vm_id, vms.hostname, vms.status, users.email,vms.created_at " .     
    " FROM vms JOIN users ON vms.user_id = users.id ORDER BY vms.id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = "Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-tachometer-alt"></i> Админ панель
            </h1>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Пользователи</h3>
                    <p class="stat-value"><?= $stats['total_users'] ?></p>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-server"></i>
                    </div>
                    <h3>Всего ВМ</h3>
                    <p class="stat-value"><?= $stats['total_vms'] ?></p>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <h3>Активные ВМ</h3>
                    <p class="stat-value"><?= $stats['active_vms'] ?></p>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-network-wired"></i>
                    </div>
                    <h3>Ноды</h3>
                    <p class="stat-value"><?= $stats['active_nodes'] ?>/<?= $stats['total_nodes'] ?></p>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3>Открытые тикеты</h3>
                    <p class="stat-value"><?= $stats['open_tickets'] ?></p>
                    <a href="ticket.php" class="btn btn-small" style="margin-top: 10px; display: inline-block;">
                        <i class="fas fa-eye"></i> Посмотреть
                    </a>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-ruble-sign"></i>
                    </div>
                    <h3>Общий доход</h3>
                    <p class="stat-value"><?= number_format($stats['total_income'], 2) ?> руб.</p>
                </div>
            </div>

            <!-- Виджет последних тикетов -->
            <div class="admin-widget">
                <h3 class="widget-title">
                    <i class="fas fa-ticket-alt"></i> Последние тикеты
                    <a href="ticket.php" class="widget-link">Все тикеты</a>
                </h3>
                
                <?php if (empty($recent_tickets)): ?>
                    <div class="no-data">Нет новых тикетов</div>
                <?php else: ?>
                    <ul class="widget-list">
                        <?php foreach ($recent_tickets as $ticket): ?>
                            <li>
                                <a href="ticket.php?ticket_id=<?= $ticket['id'] ?>">
                                    <span class="ticket-id">#<?= $ticket['id'] ?></span>
                                    <span class="ticket-subject"><?= htmlspecialchars($ticket['subject']) ?></span>
                                    <span class="ticket-meta">
                                        <span class="status <?= $ticket['status'] ?>"><?= getStatusText($ticket['status']) ?></span>
                                        <span class="date"><?= date('d.m H:i', strtotime($ticket['created_at'])) ?></span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Последние пользователи -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-user-clock"></i> Последние пользователи
                </h2>
                <?php if (!empty($recent_users)): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Баланс</th>
                                <?php if (columnExists($pdo, 'users', 'created_at')): ?>
                                <th>Дата регистрации</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= number_format($user['balance'], 2) ?> руб.</td>
                                <?php if (columnExists($pdo, 'users', 'created_at') && !empty($user['created_at'])): ?>
                                <td><?= date('d.m.Y', strtotime($user['created_at'])) ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Нет данных о пользователях
                    </div>
                <?php endif; ?>
            </section>

            <!-- Последние ВМ -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-server"></i> Последние виртуальные машины
                </h2>
                <?php if (!empty($recent_vms)): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>VMID</th>
                                <th>Имя</th>
                                <th>Пользователь</th>
                                <th>Статус</th>
                                <th>Дата создания</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_vms as $vm): ?>
                            <tr>
                                <td><?= $vm['vm_id'] ?></td>
                                <td><?= htmlspecialchars($vm['hostname']) ?></td>
                                <td><?= htmlspecialchars($vm['email']) ?></td>
                                <td>
                                    <span class="status-badge <?= $vm['status'] === 'running' ? 'status-active' : 'status-inactive' ?>">
                                        <?= $vm['status'] === 'running' ? 'Активна' : 'Остановлена' ?>
                                    </span>
                                </td>
                                <td><?= $vm['created_at'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Нет данных о виртуальных машинах
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/index_styles.css'; ?>
</style>

<?php
require 'admin_footer.php';
?>