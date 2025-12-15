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

// Обработка подтверждения платежа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id']) && isset($_POST['action'])) {
    $payment_id = (int)$_POST['payment_id'];
    $action = $_POST['action'];

    try {
        // Получаем данные платежа
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();

        if (!$payment) {
            throw new Exception('Платеж не найден');
        }

        if ($action === 'approve') {
            // Подтверждаем платеж и пополняем баланс
            $pdo->beginTransaction();

            // Обновляем статус платежа
            $stmt = $pdo->prepare("UPDATE payments SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payment_id]);

            // Пополняем баланс пользователя
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$payment['amount'], $payment['user_id']]);

            // Добавляем запись в историю баланса
            if (safeQuery($pdo, "SHOW TABLES LIKE 'balance_history'")->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO balance_history (user_id, amount, type, description, created_at)
                    VALUES (?, ?, 'deposit', ?, NOW())
                ");
                $stmt->execute([
                    $payment['user_id'],
                    $payment['amount'],
                    "Пополнение через платеж #{$payment['id']}"
                ]);
            }

            $pdo->commit();

            $_SESSION['admin_message'] = ['type' => 'success', 'text' => 'Платеж успешно подтвержден и баланс пополнен'];

        } elseif ($action === 'reject') {
            // Отклоняем платеж
            $stmt = $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payment_id]);

            $_SESSION['admin_message'] = ['type' => 'success', 'text' => 'Платеж отклонен'];
        }

        header("Location: payments.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка: ' . $e->getMessage()];
        header("Location: payments.php");
        exit;
    }
}

// Фильтрация и поиск
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

// Получение списка платежей
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(u.email LIKE ? OR p.control_word LIKE ? OR p.id = ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
    $params[] = "%$search%";
}

if ($status !== 'all') {
    $where[] = "p.status = ?";
    $params[] = $status;
}

if (!empty($date_from)) {
    $where[] = "p.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where[] = "p.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Общее количество платежей для пагинации
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN users u ON p.user_id = u.id $where_clause");
$total_stmt->execute($params);
$total_payments = $total_stmt->fetchColumn();
$total_pages = ceil($total_payments / $per_page);

// Получение платежей для текущей страницы
$payments = [];
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
    "SELECT p.*, u.email, u.full_name, u.balance as user_balance
     FROM payments p
     JOIN users u ON p.user_id = u.id
     $where_clause
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);

$stmt->execute(array_merge($params, [$per_page, $offset]));
$payments = $stmt->fetchAll() ?: [];

// Статистика платежей
$stats = [
    'total' => (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'")->fetchColumn(),
    'today' => (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn(),
    'month' => (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetchColumn(),
    'pending' => (int)safeQuery($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn(),
    'completed' => (int)safeQuery($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'completed'")->fetchColumn(),
    'failed' => (int)safeQuery($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'failed'")->fetchColumn()
];

$title = "Управление платежами | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

/* ========== ОСНОВНЫЕ СТИЛИ ДАШБОРДА ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--db-bg);
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
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--db-accent);
}

.header-left p {
    color: var(--db-text-secondary);
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
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.dashboard-action-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

/* ========== КАРТОЧКИ СТАТИСТИКИ ПЛАТЕЖЕЙ ========== */
.dashboard-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.dashboard-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.dashboard-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--stat-color);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
}

.dashboard-stat-card:hover::before {
    transform: translateX(0);
}

.dashboard-stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--db-accent);
    box-shadow: var(--db-shadow-hover);
}

.dashboard-stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.dashboard-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    background: var(--stat-color);
}

.dashboard-stat-trend {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.dashboard-stat-trend-positive {
    background: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.dashboard-stat-trend-warning {
    background: rgba(245, 158, 11, 0.2);
    color: var(--db-warning);
}

.dashboard-stat-trend-danger {
    background: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.dashboard-stat-content h3 {
    color: var(--db-text-secondary);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.dashboard-stat-value {
    color: var(--db-text);
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 4px 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.dashboard-stat-value span {
    font-size: 16px;
    font-weight: 500;
    color: var(--db-text-muted);
}

.dashboard-stat-subtext {
    color: var(--db-text-muted);
    font-size: 12px;
    margin: 0;
}

/* Цвета для карточек платежей */
.dashboard-stat-card-total { --stat-color: var(--db-success); }
.dashboard-stat-card-today { --stat-color: var(--db-accent); }
.dashboard-stat-card-month { --stat-color: var(--db-purple); }
.dashboard-stat-card-pending { --stat-color: var(--db-warning); }

/* ========== ФИЛЬТРЫ ПЛАТЕЖЕЙ ========== */
.dashboard-filters-widget {
    margin-bottom: 25px;
}

.dashboard-widget {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.dashboard-widget-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-widget-header h3 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-widget-header h3 i {
    color: var(--db-accent);
}

.dashboard-widget-body {
    padding: 20px;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    color: var(--db-text-secondary);
    font-size: 13px;
    font-weight: 500;
}

.filter-group input,
.filter-group select {
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px var(--db-accent-light);
}

.filter-group input[type="date"] {
    min-height: 42px;
}

.filter-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.filter-btn {
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.filter-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

/* ========== ТАБЛИЦА ПЛАТЕЖЕЙ ========== */
.dashboard-table-container {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    box-shadow: var(--db-shadow);
}

.dashboard-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.dashboard-table thead th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
    background: rgba(0, 188, 212, 0.05);
}

.dashboard-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.dashboard-table tbody tr:hover {
    background: var(--db-accent-light);
}

.dashboard-table tbody td {
    color: var(--db-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

/* Стили для ячеек таблицы */
.payment-id {
    font-weight: 600;
    color: var(--db-accent);
    font-family: 'Monaco', 'Consolas', monospace;
}

.payment-user {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.payment-user-email {
    color: var(--db-text);
    font-weight: 500;
}

.payment-user-name {
    color: var(--db-text-secondary);
    font-size: 12px;
}

.payment-amount {
    font-weight: 600;
    color: var(--db-success);
}

.payment-control-word {
    font-family: 'Monaco', 'Consolas', monospace;
    font-size: 13px;
    color: var(--db-text-secondary);
    background: var(--db-hover);
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-block;
}

.payment-description {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ========== СТАТУСЫ ПЛАТЕЖЕЙ ========== */
.payment-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.payment-status-pending {
    background: rgba(245, 158, 11, 0.15);
    color: var(--db-warning);
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.payment-status-completed {
    background: rgba(16, 185, 129, 0.15);
    color: var(--db-success);
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.payment-status-failed {
    background: rgba(239, 68, 68, 0.15);
    color: var(--db-danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* ========== ДЕЙСТВИЯ С ПЛАТЕЖАМИ ========== */
.payment-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.payment-action-btn {
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
    color: var(--db-text-secondary);
}

.payment-action-btn:hover {
    transform: translateY(-2px);
}

.payment-action-approve {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--db-success);
}

.payment-action-approve:hover {
    background: rgba(16, 185, 129, 0.25);
    border-color: var(--db-success);
}

.payment-action-reject {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--db-danger);
}

.payment-action-reject:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: var(--db-danger);
}

.payment-action-view {
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--db-info);
}

.payment-action-view:hover {
    background: rgba(59, 130, 246, 0.25);
    border-color: var(--db-info);
}

.payment-action-form {
    display: inline;
    margin: 0;
    padding: 0;
}

/* ========== ПАГИНАЦИЯ ========== */
.dashboard-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    padding: 20px;
    border-top: 1px solid var(--db-border);
}

.pagination-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    color: var(--db-text);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.pagination-btn:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
    color: var(--db-accent);
    transform: translateY(-2px);
}

.pagination-btn.active {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    border-color: transparent;
    color: white;
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.pagination-ellipsis {
    color: var(--db-text-secondary);
    padding: 0 8px;
}

/* ========== ПУСТАЯ ТАБЛИЦА ========== */
.dashboard-no-data {
    text-align: center;
    padding: 60px 20px;
    color: var(--db-text-secondary);
}

.dashboard-no-data i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.dashboard-no-data h4 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0 0 8px 0;
}

.dashboard-no-data p {
    margin: 0;
    font-size: 14px;
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.dashboard-alert {
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

    .dashboard-quick-actions {
        flex-direction: column;
    }

    .dashboard-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }

    .header-left h1 {
        font-size: 20px;
    }

    .dashboard-stats-grid {
        grid-template-columns: 1fr;
    }

    .payment-actions {
        flex-direction: column;
        gap: 4px;
    }

    .payment-action-btn {
        width: 32px;
        height: 32px;
    }

    .dashboard-pagination {
        flex-wrap: wrap;
    }
}

@media (max-width: 576px) {
    .filter-actions {
        flex-direction: column;
    }

    .filter-btn {
        width: 100%;
        justify-content: center;
    }
}

/* ========== АНИМАЦИИ ========== */
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.payment-processing {
    animation: pulse 1s infinite;
    pointer-events: none;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка дашборда -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-credit-card"></i> Управление платежами</h1>
            <p>Всего платежей: <?= $total_payments ?> (<?= $stats['pending'] ?> ожидают, <?= $stats['completed'] ?> завершено)</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="payment_add.php" class="dashboard-action-btn dashboard-action-btn-primary">
                <i class="fas fa-plus"></i> Создать платеж
            </a>
            <button class="dashboard-action-btn dashboard-action-btn-secondary" onclick="refreshPayments()">
                <i class="fas fa-sync-alt"></i> Обновить
            </button>
        </div>
    </div>

    <!-- Карточки статистики -->
    <div class="dashboard-stats-grid">
        <!-- Общий доход -->
        <div class="dashboard-stat-card dashboard-stat-card-total">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive"><?= number_format($stats['today'], 0) ?> ₽ сегодня</span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Общий доход</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['total'], 0) ?> <span>₽</span></div>
                <p class="dashboard-stat-subtext">За все время</p>
            </div>
        </div>

        <!-- Доход сегодня -->
        <div class="dashboard-stat-card dashboard-stat-card-today">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <?php if ($stats['today'] > 0): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive">+<?= number_format($stats['today'], 0) ?> ₽</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Сегодня</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['today'], 0) ?> <span>₽</span></div>
                <p class="dashboard-stat-subtext">Доход за сегодня</p>
            </div>
        </div>

        <!-- Доход за месяц -->
        <div class="dashboard-stat-card dashboard-stat-card-month">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <span class="dashboard-stat-trend <?= $stats['month'] > 0 ? 'dashboard-stat-trend-positive' : 'dashboard-stat-trend-warning' ?>">
                    <?= number_format($stats['month'], 0) ?> ₽
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Этот месяц</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['month'], 0) ?> <span>₽</span></div>
                <p class="dashboard-stat-subtext">Доход за текущий месяц</p>
            </div>
        </div>

        <!-- Ожидающие платежи -->
        <div class="dashboard-stat-card dashboard-stat-card-pending">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <?php if ($stats['pending'] > 0): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-warning"><?= $stats['pending'] ?> ожидают</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Ожидают подтверждения</h3>
                <div class="dashboard-stat-value"><?= $stats['pending'] ?></div>
                <p class="dashboard-stat-subtext">Требуют вашего внимания</p>
            </div>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if (isset($_SESSION['admin_message'])): ?>
        <div class="dashboard-alert alert-<?= $_SESSION['admin_message']['type'] ?>">
            <i class="fas fa-<?= $_SESSION['admin_message']['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= $_SESSION['admin_message']['text'] ?>
        </div>
        <?php unset($_SESSION['admin_message']); ?>
    <?php endif; ?>

    <!-- Виджет фильтров -->
    <div class="dashboard-filters-widget">
        <div class="dashboard-widget">
            <div class="dashboard-widget-header">
                <h3><i class="fas fa-filter"></i> Фильтры</h3>
            </div>
            <div class="dashboard-widget-body">
                <form method="GET" id="paymentsFilterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="status">Статус платежа</label>
                            <select name="status" id="status" onchange="this.form.submit()">
                                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все статусы</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Ожидает подтверждения</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Завершен</option>
                                <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Ошибка</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="date_from">Дата с</label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?= htmlspecialchars($date_from) ?>"
                                   onchange="this.form.submit()">
                        </div>

                        <div class="filter-group">
                            <label for="date_to">Дата по</label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?= htmlspecialchars($date_to) ?>"
                                   onchange="this.form.submit()">
                        </div>

                        <div class="filter-group">
                            <label for="search">Поиск</label>
                            <input type="text" name="search" id="search" 
                                   placeholder="Email, ФИО, ID платежа..."
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="filter-btn filter-btn-primary">
                            <i class="fas fa-search"></i> Применить фильтры
                        </button>
                        <a href="payments.php" class="filter-btn filter-btn-secondary">
                            <i class="fas fa-times"></i> Сбросить
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Таблица платежей -->
    <div class="dashboard-widget">
        <div class="dashboard-widget-header">
            <h3><i class="fas fa-list"></i> История платежей</h3>
            <div class="dashboard-widget-link">
                Страница <?= $page ?> из <?= $total_pages ?>
            </div>
        </div>
        <div class="dashboard-widget-body">
            <?php if (!empty($payments)): ?>
                <div class="dashboard-table-container">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Пользователь</th>
                                <th>Сумма</th>
                                <th>Контрольное слово</th>
                                <th>Описание</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="payment-id">#<?= $payment['id'] ?></td>
                                <td>
                                    <div class="payment-user">
                                        <div class="payment-user-email"><?= htmlspecialchars($payment['email']) ?></div>
                                        <?php if (!empty($payment['full_name'])): ?>
                                        <div class="payment-user-name"><?= htmlspecialchars($payment['full_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="payment-amount"><?= number_format($payment['amount'], 2) ?> ₽</td>
                                <td>
                                    <span class="payment-control-word"><?= htmlspecialchars($payment['control_word'] ?? '—') ?></span>
                                </td>
                                <td class="payment-description" title="<?= htmlspecialchars($payment['description']) ?>">
                                    <?= htmlspecialchars($payment['description']) ?>
                                </td>
                                <td>
                                    <span class="payment-status payment-status-<?= $payment['status'] ?>">
                                        <?= $payment['status'] === 'completed' ? 'Завершен' :
                                           ($payment['status'] === 'pending' ? 'Ожидает' : 'Ошибка') ?>
                                    </span>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?></td>
                                <td>
                                    <div class="payment-actions">
                                        <?php if ($payment['status'] === 'pending'): ?>
                                            <form method="POST" class="payment-action-form" 
                                                  onsubmit="return confirmApprovePayment(<?= $payment['id'] ?>, <?= $payment['amount'] ?>, '<?= htmlspecialchars(addslashes($payment['email'])) ?>')">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="payment-action-btn payment-action-approve" 
                                                        title="Подтвердить платеж">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="payment-action-form" 
                                                  onsubmit="return confirmRejectPayment(<?= $payment['id'] ?>, '<?= htmlspecialchars(addslashes($payment['email'])) ?>')">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="payment-action-btn payment-action-reject" 
                                                        title="Отклонить платеж">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="payment_details.php?id=<?= $payment['id'] ?>" 
                                           class="payment-action-btn payment-action-view" 
                                           title="Подробнее">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Пагинация -->
                <?php if ($total_pages > 1): ?>
                    <div class="dashboard-pagination">
                        <!-- Первая страница -->
                        <a href="?page=1&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                           class="pagination-btn <?= $page == 1 ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>

                        <!-- Предыдущая страница -->
                        <a href="?page=<?= $page-1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                           class="pagination-btn <?= $page == 1 ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>

                        <!-- Номера страниц -->
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);

                        if ($start > 1): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif;

                        for ($i = $start; $i <= $end; $i++): ?>
                            <a href="?page=<?= $i ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
                               class="pagination-btn <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor;

                        if ($end < $total_pages): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif; ?>

                        <!-- Следующая страница -->
                        <a href="?page=<?= $page+1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                           class="pagination-btn <?= $page == $total_pages ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>

                        <!-- Последняя страница -->
                        <a href="?page=<?= $total_pages ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                           class="pagination-btn <?= $page == $total_pages ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="dashboard-no-data">
                    <i class="fas fa-credit-card"></i>
                    <h4>Платежи не найдены</h4>
                    <p>Попробуйте изменить параметры фильтрации</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек статистики
    const statCards = document.querySelectorAll('.dashboard-stat-card');
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
    const tableRows = document.querySelectorAll('.dashboard-table tbody tr');
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

    // Авто-сабмит формы при изменении поиска
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('paymentsFilterForm').submit();
            }, 500);
        });
    }
});

// Подтверждение подтверждения платежа
function confirmApprovePayment(paymentId, amount, email) {
    return Swal.fire({
        title: 'Подтвердить платеж?',
        html: `Вы уверены, что хотите подтвердить платеж #${paymentId}?<br><br>
               <strong>Сумма:</strong> ${amount.toLocaleString('ru-RU')} ₽<br>
               <strong>Пользователь:</strong> ${email}<br><br>
               Баланс пользователя будет пополнен на указанную сумму.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Да, подтвердить',
        cancelButtonText: 'Отмена',
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#ef4444',
        reverseButtons: true
    }).then((result) => {
        return result.isConfirmed;
    });
}

// Подтверждение отклонения платежа
function confirmRejectPayment(paymentId, email) {
    return Swal.fire({
        title: 'Отклонить платеж?',
        html: `Вы уверены, что хотите отклонить платеж #${paymentId}?<br><br>
               <strong>Пользователь:</strong> ${email}<br><br>
               Платеж будет помечен как ошибочный.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Да, отклонить',
        cancelButtonText: 'Отмена',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        reverseButtons: true
    }).then((result) => {
        return result.isConfirmed;
    });
}

// Обновление списка платежей
function refreshPayments() {
    const refreshBtn = document.querySelector('.dashboard-action-btn-secondary');
    const originalHtml = refreshBtn.innerHTML;

    // Анимация вращения
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обновление...';
    refreshBtn.disabled = true;

    // Перезагрузка страницы через 1 секунду (для имитации)
    setTimeout(() => {
        location.reload();
    }, 1000);
}

// Экспорт платежей в CSV
function exportPaymentsToCSV() {
    const params = new URLSearchParams(window.location.search);
    params.append('export', 'csv');
    
    Swal.fire({
        title: 'Экспорт платежей',
        text: 'Файл CSV будет сгенерирован и загружен на ваше устройство.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Экспортировать',
        cancelButtonText: 'Отмена'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `payments_export.php?${params.toString()}`;
        }
    });
}

// Быстрый поиск при нажатии Enter
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.matches('input[name="search"]')) {
        e.preventDefault();
        document.getElementById('paymentsFilterForm').submit();
    }
});
</script>

<?php require 'admin_footer.php'; ?>