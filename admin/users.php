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

// Получаем список всех пользователей
$users = $pdo->query("SELECT id, email, full_name, balance, is_admin, user_type, created_at FROM users ORDER BY id DESC")->fetchAll();

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $userId = (int)$_POST['user_id'];
        try {
            $pdo->beginTransaction();

            // Проверяем существование пользователя
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userExists = $stmt->fetch();

            if (!$userExists) {
                throw new Exception("Пользователь не найден");
            }

            // Сначала удаляем все связанные данные, проверяя существование столбцов

            // Таблица vms (виртуальные машины)
            try {
                $pdo->exec("DELETE FROM vms WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из vms: " . $e->getMessage());
            }

            // Таблица user_quotas
            try {
                $pdo->exec("DELETE FROM user_quotas WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из user_quotas: " . $e->getMessage());
            }

            // Таблица api_tokens
            try {
                $pdo->exec("DELETE FROM api_tokens WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из api_tokens: " . $e->getMessage());
            }

            // Таблица user_sessions
            try {
                $pdo->exec("DELETE FROM user_sessions WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удаления из user_sessions: " . $e->getMessage());
            }

            // Таблица payments (критически важная - вызывает ошибку внешнего ключа)
            try {
                $pdo->exec("DELETE FROM payments WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из payments: " . $e->getMessage());
            }

            // Проверяем существование таблицы balance_history
            $checkBalanceHistory = $pdo->query("SHOW TABLES LIKE 'balance_history'")->fetch();
            if ($checkBalanceHistory) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM balance_history LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    $pdo->exec("DELETE FROM balance_history WHERE user_id = $userId");
                }
            }

            // Проверяем существование таблицы payment_info
            $checkPaymentInfo = $pdo->query("SHOW TABLES LIKE 'payment_info'")->fetch();
            if ($checkPaymentInfo) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM payment_info LIKE 'user_id'")->fetch();
                if (!$checkColumn) {
                    $checkColumn = $pdo->query("SHOW COLUMNS FROM payment_info LIKE 'customer_id'")->fetch();
                    if ($checkColumn) {
                        $pdo->exec("DELETE FROM payment_info WHERE customer_id = $userId");
                    }
                } else {
                    $pdo->exec("DELETE FROM payment_info WHERE user_id = $userId");
                }
            }

            // Проверяем существование таблицы tickets
            $checkTickets = $pdo->query("SHOW TABLES LIKE 'tickets'")->fetch();
            if ($checkTickets) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    // Сначала получаем ID тикетов пользователя
                    $ticketIds = $pdo->query("SELECT id FROM tickets WHERE user_id = $userId")->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($ticketIds)) {
                        $ticketIdsStr = implode(',', $ticketIds);

                        // Удаляем вложения тикетов
                        $checkAttachments = $pdo->query("SHOW TABLES LIKE 'ticket_attachments'")->fetch();
                        if ($checkAttachments) {
                            $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_attachments LIKE 'ticket_id'")->fetch();
                            if ($checkColumn) {
                                $pdo->exec("DELETE FROM ticket_attachments WHERE ticket_id IN ($ticketIdsStr)");
                            }
                        }

                        // Удаляем ответы тикетов
                        $checkReplies = $pdo->query("SHOW TABLES LIKE 'ticket_replies'")->fetch();
                        if ($checkReplies) {
                            $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_replies LIKE 'ticket_id'")->fetch();
                            if ($checkColumn) {
                                $pdo->exec("DELETE FROM ticket_replies WHERE ticket_id IN ($ticketIdsStr)");
                            }
                        }

                        // Удаляем сами тикеты
                        $pdo->exec("DELETE FROM tickets WHERE user_id = $userId");
                    }
                }
            }

            // Также удаляем связанные записи из ticket_replies, если они ссылаются на пользователя напрямую
            $checkTicketRepliesUser = $pdo->query("SHOW TABLES LIKE 'ticket_replies'")->fetch();
            if ($checkTicketRepliesUser) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_replies LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    $pdo->exec("DELETE FROM ticket_replies WHERE user_id = $userId");
                }
            }

            // Дополнительные таблицы, которые могут быть связаны с пользователем
            $additionalTables = [
                'invoices',
                'invoices_history',
                'refunds',
                'transactions',
                'user_logs',
                'user_activity',
                'notifications',
                'user_settings'
            ];

            foreach ($additionalTables as $table) {
                $checkTable = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
                if ($checkTable) {
                    $checkColumn = $pdo->query("SHOW COLUMNS FROM $table LIKE 'user_id'")->fetch();
                    if (!$checkColumn) {
                        $checkColumn = $pdo->query("SHOW COLUMNS FROM $table LIKE 'customer_id'")->fetch();
                        if ($checkColumn) {
                            try {
                                $pdo->exec("DELETE FROM $table WHERE customer_id = $userId");
                            } catch (Exception $e) {
                                error_log("Ошибка при удалении из $table: " . $e->getMessage());
                            }
                        }
                    } else {
                        try {
                            $pdo->exec("DELETE FROM $table WHERE user_id = $userId");
                        } catch (Exception $e) {
                            error_log("Ошибка при удалении из $table: " . $e->getMessage());
                        }
                    }
                }
            }

            // Затем удаляем самого пользователя
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            $affectedRows = $stmt->rowCount();

            if ($affectedRows > 0) {
                $pdo->commit();
                $_SESSION['success'] = "Пользователь успешно удален";
            } else {
                throw new Exception("Пользователь не найден или не удален");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Ошибка при удалении пользователя: " . $e->getMessage();
        }
        header("Location: users.php");
        exit;
    }
}

$title = "Управление пользователями | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ОСНОВНЫЕ ПЕРЕМЕННЫЕ (СИНХРОНИЗИРОВАНЫ С ШАПКОЙ И САЙДБАРОМ) */
:root {
    --admin-bg: #f8fafc;
    --admin-card-bg: #ffffff;
    --admin-text: #1e293b;
    --admin-text-secondary: #475569;
    --admin-border: #cbd5e1;
    --admin-accent: #0ea5e9;
    --admin-accent-hover: #0284c7;
    --admin-accent-light: rgba(14, 165, 233, 0.15);
    --admin-danger: #ef4444;
    --admin-success: #10b981;
    --admin-warning: #f59e0b;
    --admin-info: #3b82f6;
    --admin-purple: #8b5cf6;
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

[data-theme="dark"] {
    --admin-bg: #1e293b;
    --admin-card-bg: #1e293b;
    --admin-text: #f1f5f9;
    --admin-text-secondary: #cbd5e1;
    --admin-border: #334155;
    --admin-accent: #38bdf8;
    --admin-accent-hover: #0ea5e9;
    --admin-accent-light: rgba(56, 189, 248, 0.15);
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

/* ========== ОСНОВНОЙ МАКЕТ ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--admin-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

/* ========== ШАПКА СТРАНИЦЫ ========== */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    box-shadow: var(--admin-shadow);
}

.header-left h1 {
    color: var(--admin-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--admin-accent);
}

.header-left p {
    color: var(--admin-text-secondary);
    font-size: 14px;
    margin: 0;
}

.dashboard-quick-actions {
    display: flex;
    gap: 12px;
}

.dashboard-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.dashboard-action-btn-primary {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
}

.dashboard-action-btn-secondary {
    background: var(--admin-card-bg);
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
    border: 1px solid transparent;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
    color: #047857;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.alert i {
    font-size: 18px;
}

.alert-success i {
    color: #10b981;
}

.alert-danger i {
    color: #ef4444;
}

/* ========== ТАБЛИЦА ПОЛЬЗОВАТЕЛЕЙ ========== */
.users-table-container {
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    overflow: hidden;
    box-shadow: var(--admin-shadow);
}

.users-table-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header-left h3 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header-left h3 i {
    color: var(--admin-accent);
}

.table-search {
    position: relative;
}

.table-search input {
    padding: 10px 40px 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--admin-border);
    background: var(--admin-card-bg);
    color: var(--admin-text);
    font-size: 14px;
    width: 200px;
    transition: all 0.3s ease;
}

.table-search input:focus {
    outline: none;
    border-color: var(--admin-accent);
    box-shadow: 0 0 0 3px var(--admin-accent-light);
}

.table-search i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--admin-text-secondary);
}

/* ========== СТИЛИ ТАБЛИЦЫ ========== */
.users-table {
    width: 100%;
    border-collapse: collapse;
}

.users-table thead th {
    color: var(--admin-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--admin-border);
    background: rgba(14, 165, 233, 0.05);
}

.users-table tbody tr {
    border-bottom: 1px solid var(--admin-border);
    transition: all 0.3s ease;
}

.users-table tbody tr:hover {
    background: var(--admin-accent-light);
}

.users-table tbody td {
    color: var(--admin-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

/* Стили для ячеек таблицы */
.user-id {
    font-weight: 600;
    color: var(--admin-accent);
    font-family: 'Monaco', 'Consolas', monospace;
}

.user-email {
    color: var(--admin-text);
    font-weight: 500;
}

.user-balance {
    font-weight: 600;
    color: var(--admin-success);
}

.user-balance.negative {
    color: var(--admin-danger);
}

.user-date {
    color: var(--admin-text-secondary);
    font-size: 13px;
}

/* ========== БЕЙДЖИ И СТАТУСЫ ========== */
.user-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.user-role-admin {
    background: linear-gradient(135deg, var(--admin-danger), #dc2626);
    color: white;
}

.user-role-user {
    background: rgba(16, 185, 129, 0.2);
    color: #047857;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.user-type-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.user-type-individual {
    background: rgba(14, 165, 233, 0.15);
    color: var(--admin-accent);
    border: 1px solid rgba(14, 165, 233, 0.3);
}

.user-type-entrepreneur {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.user-type-legal {
    background: rgba(139, 92, 246, 0.15);
    color: #8b5cf6;
    border: 1px solid rgba(139, 92, 246, 0.3);
}

/* ========== ДЕЙСТВИЯ ========== */
.user-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.user-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s ease;
    background: transparent;
    color: var(--admin-text-secondary);
}

.user-action-btn:hover {
    transform: translateY(-2px);
}

.user-action-edit {
    background: rgba(14, 165, 233, 0.15);
    border: 1px solid rgba(14, 165, 233, 0.3);
    color: var(--admin-accent);
}

.user-action-edit:hover {
    background: rgba(14, 165, 233, 0.25);
    border-color: var(--admin-accent);
}

.user-action-delete {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--admin-danger);
}

.user-action-delete:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: var(--admin-danger);
}

.user-action-form {
    display: inline;
    margin: 0;
    padding: 0;
}

/* ========== ПУСТАЯ ТАБЛИЦА ========== */
.table-empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--admin-text-secondary);
}

.table-empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.table-empty-state h4 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0 0 8px 0;
}

.table-empty-state p {
    margin: 0;
    font-size: 14px;
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .users-table-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }

    .table-search input {
        width: 100%;
    }

    .users-table {
        display: block;
        overflow-x: auto;
    }

    .users-table thead th,
    .users-table tbody td {
        white-space: nowrap;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }

    .header-left h1 {
        font-size: 20px;
    }

    .users-table-header {
        padding: 15px;
    }

    .users-table tbody td {
        padding: 12px;
    }

    .user-actions {
        flex-direction: column;
        gap: 6px;
    }
}

/* ========== СТАТИСТИКА ПОЛЬЗОВАТЕЛЕЙ ========== */
.users-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.user-stat-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s ease;
    box-shadow: var(--admin-shadow);
}

.user-stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--admin-accent);
    box-shadow: var(--admin-hover-shadow);
}

.user-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.user-stat-icon.total { background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover)); }
.user-stat-icon.active { background: linear-gradient(135deg, var(--admin-success), #059669); }
.user-stat-icon.admins { background: linear-gradient(135deg, var(--admin-danger), #dc2626); }
.user-stat-icon.balance { background: linear-gradient(135deg, var(--admin-purple), #7c3aed); }

.user-stat-content h4 {
    color: var(--admin-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 4px 0;
}

.user-stat-value {
    color: var(--admin-text);
    font-size: 24px;
    font-weight: 700;
    margin: 0;
}

.user-stat-subtext {
    color: var(--admin-text-secondary);
    font-size: 12px;
    margin: 4px 0 0 0;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-users"></i> Управление пользователями</h1>
            <p>Всего пользователей: <?= count($users) ?></p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="user_add.php" class="dashboard-action-btn dashboard-action-btn-primary">
                <i class="fas fa-user-plus"></i> Добавить пользователя
            </a>
            <a href="#" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-file-export"></i> Экспорт
            </a>
        </div>
    </div>

    <!-- Статистика пользователей -->
    <div class="users-stats">
        <div class="user-stat-card">
            <div class="user-stat-icon total">
                <i class="fas fa-users"></i>
            </div>
            <div class="user-stat-content">
                <h4>Всего пользователей</h4>
                <div class="user-stat-value"><?= count($users) ?></div>
            </div>
        </div>

        <?php
        $admin_count = 0;
        $total_balance = 0;
        
        foreach ($users as $user) {
            if ($user['is_admin']) {
                $admin_count++;
            }
            $total_balance += $user['balance'];
        }
        ?>

        <div class="user-stat-card">
            <div class="user-stat-icon active">
                <i class="fas fa-user-shield"></i>
            </div>
            <div class="user-stat-content">
                <h4>Администраторы</h4>
                <div class="user-stat-value"><?= $admin_count ?></div>
                <p class="user-stat-subtext"><?= $admin_count ?> из <?= count($users) ?></p>
            </div>
        </div>

        <div class="user-stat-card">
            <div class="user-stat-icon admins">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="user-stat-content">
                <h4>Общий баланс</h4>
                <div class="user-stat-value"><?= number_format($total_balance, 2) ?> ₽</div>
                <p class="user-stat-subtext">Средний: <?= count($users) > 0 ? number_format($total_balance / count($users), 2) : '0' ?> ₽</p>
            </div>
        </div>

        <div class="user-stat-card">
            <div class="user-stat-icon balance">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="user-stat-content">
                <?php
                $today_users = $pdo->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE()")->fetch();
                ?>
                <h4>Новые сегодня</h4>
                <div class="user-stat-value"><?= $today_users['count'] ?></div>
                <p class="user-stat-subtext">За текущий день</p>
            </div>
        </div>
    </div>

    <!-- Уведомления -->
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

    <!-- Таблица пользователей -->
    <div class="users-table-container">
        <!-- Заголовок таблицы -->
        <div class="users-table-header">
            <div class="table-header-left">
                <h3><i class="fas fa-list"></i> Список пользователей</h3>
            </div>
            <div class="table-search">
                <input type="text" id="userSearch" placeholder="Поиск пользователей..." onkeyup="searchUsers()">
                <i class="fas fa-search"></i>
            </div>
        </div>

        <!-- Таблица -->
        <?php if (!empty($users)): ?>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>ФИО</th>
                        <th>Тип</th>
                        <th>Баланс</th>
                        <th>Роль</th>
                        <th>Дата регистрации</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="user-id">#<?= $user['id'] ?></td>
                        <td class="user-email"><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['full_name'] ?? 'Не указано') ?></td>
                        <td>
                            <span class="user-type-badge user-type-<?= $user['user_type'] ?>">
                                <?= 
                                    $user['user_type'] === 'individual' ? 'Физ. лицо' :
                                    ($user['user_type'] === 'entrepreneur' ? 'ИП' : 'Юр. лицо')
                                ?>
                            </span>
                        </td>
                        <td class="user-balance <?= $user['balance'] < 0 ? 'negative' : '' ?>">
                            <?= number_format($user['balance'], 2) ?> ₽
                        </td>
                        <td>
                            <span class="user-badge user-role-<?= $user['is_admin'] ? 'admin' : 'user' ?>">
                                <?= $user['is_admin'] ? 'Администратор' : 'Пользователь' ?>
                            </span>
                        </td>
                        <td class="user-date"><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                        <td>
                            <div class="user-actions">
                                <a href="user_edit.php?id=<?= $user['id'] ?>" class="user-action-btn user-action-edit" title="Редактировать">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="user-action-form" onsubmit="return confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['email']) ?>')">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="delete_user" class="user-action-btn user-action-delete" title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="table-empty-state">
                <i class="fas fa-users"></i>
                <h4>Нет пользователей</h4>
                <p>В системе еще нет зарегистрированных пользователей</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек статистики
    const statCards = document.querySelectorAll('.user-stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Анимация строк таблицы
    const tableRows = document.querySelectorAll('.users-table tbody tr');
    tableRows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(10px)';

        setTimeout(() => {
            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, (statCards.length * 100) + (index * 50));
    });

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const dashboard = document.querySelector('.dashboard-wrapper');

    if (sidebar && dashboard) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        dashboard.style.marginLeft = '70px';
                    } else {
                        dashboard.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }
});

function confirmDelete(userId, userEmail) {
    return confirm('Вы уверены, что хотите удалить пользователя #' + userId + ' (' + userEmail + ')? Все связанные данные (платежи, тикеты, баланс, виртуальные машины и т.д.) также будут удалены. Это действие нельзя отменить!');
}

function searchUsers() {
    const input = document.getElementById('userSearch');
    const filter = input.value.toLowerCase();
    const table = document.querySelector('.users-table tbody');
    const rows = table.getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.getElementsByTagName('td');
        let show = false;

        for (let j = 0; j < cells.length; j++) {
            const cell = cells[j];
            if (cell) {
                const textValue = cell.textContent || cell.innerText;
                if (textValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
        }

        if (show) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}
</script>

<?php require 'admin_footer.php'; ?>