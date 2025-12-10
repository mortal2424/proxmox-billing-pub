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

// Получаем статистику для карточек
$stats = getDashboardStats($pdo);

// Получаем последние тикеты
$recent_tickets = getRecentTickets($pdo, 5);

// Получаем последних пользователей
$recent_users = getRecentUsers($pdo, 6);

// Получаем последние ВМ
$recent_vms = getRecentVMs($pdo, 6);

// Получаем уведомления
$notifications = getAdminNotifications($pdo);

// Функции для получения данных
function getDashboardStats($pdo) {
    $stats = [];

    // Пользователи
    $stats['total_users'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['new_users_today'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    // ВМ - ИСПРАВЛЕНО: получаем все статусы отдельно
    $stats['total_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms")->fetchColumn();
    $stats['running_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'running'")->fetchColumn();
    $stats['stopped_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'stopped'")->fetchColumn();
    $stats['error_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'error'")->fetchColumn();
    $stats['other_vms'] = $stats['total_vms'] - $stats['running_vms'] - $stats['stopped_vms'] - $stats['error_vms'];

    // Ноды
    if (safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0) {
        $stats['total_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes")->fetchColumn();
        $stats['active_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes WHERE is_active = 1")->fetchColumn();
        $stats['inactive_nodes'] = $stats['total_nodes'] - $stats['active_nodes'];
    } else {
        $stats['total_nodes'] = 0;
        $stats['active_nodes'] = 0;
        $stats['inactive_nodes'] = 0;
    }

    // Тикеты
    if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
        $stats['total_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets")->fetchColumn();
        $stats['open_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
        $stats['pending_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'pending'")->fetchColumn();
        $stats['closed_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'closed'")->fetchColumn();
    } else {
        $stats['total_tickets'] = 0;
        $stats['open_tickets'] = 0;
        $stats['pending_tickets'] = 0;
        $stats['closed_tickets'] = 0;
    }

    // Платежи - ИСПРАВЛЕНО: получаем общий доход и месячный доход
    if (safeQuery($pdo, "SHOW TABLES LIKE 'payments'")->rowCount() > 0) {
        $stats['total_income'] = (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'")->fetchColumn();
        $stats['today_income'] = (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn();
        $stats['monthly_income'] = (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetchColumn();
        $stats['yearly_income'] = (float)safeQuery($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetchColumn();
    } else {
        $stats['total_income'] = 0;
        $stats['today_income'] = 0;
        $stats['monthly_income'] = 0;
        $stats['yearly_income'] = 0;
    }

    return $stats;
}

function getRecentTickets($pdo, $limit = 5) {
    $tickets = [];
    if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
        $query = safeQuery($pdo, "
            SELECT t.*, u.email, u.full_name
            FROM tickets t
            JOIN users u ON t.user_id = u.id
            ORDER BY t.created_at DESC
            LIMIT ?
        ", [$limit]);

        $tickets = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    return $tickets;
}

function getRecentUsers($pdo, $limit = 6) {
    $query = safeQuery($pdo, "
        SELECT id, email, balance, created_at
        FROM users
        ORDER BY id DESC
        LIMIT ?
    ", [$limit]);

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentVMs($pdo, $limit = 6) {
    $query = safeQuery($pdo, "
        SELECT vms.*, users.email, users.full_name
        FROM vms
        JOIN users ON vms.user_id = users.id
        ORDER BY vms.created_at DESC
        LIMIT ?
    ", [$limit]);

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function getAdminNotifications($pdo) {
    $notifications = [];

    // Новые пользователи сегодня
    $new_users_today = (int)safeQuery($pdo, "
        SELECT COUNT(*) FROM users
        WHERE DATE(created_at) = CURDATE()
    ")->fetchColumn();

    if ($new_users_today > 0) {
        $notifications[] = [
            'type' => 'success',
            'icon' => 'fa-user-plus',
            'title' => 'Новые пользователи',
            'message' => $new_users_today . ' новых пользователей сегодня',
            'link' => '/admin/users.php'
        ];
    }

    // Открытые тикеты
    if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
        $open_tickets = (int)safeQuery($pdo, "
            SELECT COUNT(*) FROM tickets
            WHERE status = 'open'
        ")->fetchColumn();

        if ($open_tickets > 0) {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'fa-ticket-alt',
                'title' => 'Открытые тикеты',
                'message' => $open_tickets . ' тикетов требуют внимания',
                'link' => '/admin/ticket.php'
            ];
        }
    }

    // ВМ с ошибками
    $error_vms = (int)safeQuery($pdo, "
        SELECT COUNT(*) FROM vms
        WHERE status = 'error'
    ")->fetchColumn();

    if ($error_vms > 0) {
        $notifications[] = [
            'type' => 'danger',
            'icon' => 'fa-exclamation-triangle',
            'title' => 'Ошибки ВМ',
            'message' => $error_vms . ' виртуальных машин с ошибками',
            'link' => '/admin/vms.php?status=error'
        ];
    }

    // Неактивные ноды
    if (safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0) {
        $inactive_nodes = (int)safeQuery($pdo, "
            SELECT COUNT(*) FROM proxmox_nodes
            WHERE is_active = 0
        ")->fetchColumn();

        if ($inactive_nodes > 0) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'fa-server',
                'title' => 'Неактивные ноды',
                'message' => $inactive_nodes . ' серверов недоступно',
                'link' => '/admin/nodes.php'
            ];
        }
    }

    return $notifications;
}

$title = "Дашборд | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    /* Светлая тема по умолчанию */
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

/* ========== СТАТИСТИЧЕСКИЕ КАРТОЧКИ ========== */
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

/* Цвета для карточек */
.dashboard-stat-card-users { --stat-color: var(--db-success); }
.dashboard-stat-card-vms { --stat-color: var(--db-warning); }
.dashboard-stat-card-nodes { --stat-color: var(--db-purple); }
.dashboard-stat-card-tickets { --stat-color: var(--db-danger); }
.dashboard-stat-card-income { --stat-color: var(--db-accent); }

/* ========== ОСНОВНАЯ СЕТКА ========== */
.dashboard-main-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 25px;
    margin-bottom: 30px;
}

@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }

    .dashboard-main-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
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
}

@media (max-width: 480px) {
    .dashboard-stats-grid {
        grid-template-columns: 1fr;
    }
}

/* ========== ВИДЖЕТЫ ========== */
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

.dashboard-widget-link {
    color: var(--db-accent);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.dashboard-widget-link:hover {
    color: #0097a7;
    text-decoration: underline;
}

.dashboard-widget-body {
    padding: 20px;
}

/* ========== СПИСОК ВИДЖЕТ ========== */
.dashboard-list-widget {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.dashboard-list-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.dashboard-list-item:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
    transform: translateX(5px);
}

.dashboard-item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.dashboard-item-avatar-users { background: rgba(16, 185, 129, 0.1); color: var(--db-success); }
.dashboard-item-avatar-vms { background: rgba(245, 158, 11, 0.1); color: var(--db-warning); }
.dashboard-item-avatar-tickets { background: rgba(239, 68, 68, 0.1); color: var(--db-danger); }

.dashboard-item-content {
    flex: 1;
    min-width: 0;
}

.dashboard-item-title {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dashboard-item-subtitle {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin: 0;
}

.dashboard-item-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
}

.dashboard-item-status {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}

.dashboard-item-status-running { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }
.dashboard-item-status-stopped { background: rgba(148, 163, 184, 0.2); color: var(--db-text-muted); }
.dashboard-item-status-error { background: rgba(239, 68, 68, 0.2); color: var(--db-danger); }
.dashboard-item-status-open { background: rgba(245, 158, 11, 0.2); color: var(--db-warning); }
.dashboard-item-status-closed { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }
.dashboard-item-status-pending { background: rgba(59, 130, 246, 0.2); color: var(--db-info); }

.dashboard-item-time {
    color: var(--db-text-muted);
    font-size: 11px;
    white-space: nowrap;
}

/* ========== ТАБЛИЦЫ ========== */
.dashboard-table {
    width: 100%;
    border-collapse: collapse;
}

.dashboard-table thead th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
}

.dashboard-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.dashboard-table tbody tr:hover {
    background: var(--db-hover);
}

.dashboard-table tbody td {
    color: var(--db-text);
    font-size: 14px;
    padding: 12px;
    vertical-align: middle;
}

.dashboard-table-user {
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--db-purple), #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

.dashboard-user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 8px;
    object-fit: cover;
}

.dashboard-user-info {
    display: flex;
    flex-direction: column;
}

.dashboard-user-name {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

.dashboard-user-email {
    color: var(--db-text-secondary);
    font-size: 12px;
}

.dashboard-table-balance {
    font-weight: 600;
    color: var(--db-success);
}

.dashboard-table-date {
    color: var(--db-text-secondary);
    font-size: 12px;
    white-space: nowrap;
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.dashboard-notifications-widget {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    border: none;
    color: white;
}

.dashboard-notifications-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.dashboard-notification-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}

.dashboard-notification-item:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateX(5px);
}

.dashboard-notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: rgba(255, 255, 255, 0.2);
}

.dashboard-notification-content {
    flex: 1;
}

.dashboard-notification-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 4px 0;
}

.dashboard-notification-message {
    font-size: 12px;
    opacity: 0.9;
    margin: 0;
}

.dashboard-notification-link {
    color: white;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
}

.dashboard-notification-link:hover {
    text-decoration: underline;
}

/* ========== СИСТЕМНЫЕ СТАТУСЫ ========== */
.dashboard-status-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.dashboard-status-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
}

.dashboard-status-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--db-success);
    position: relative;
}

.dashboard-status-indicator::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: rgba(16, 185, 129, 0.3);
    animation: pulse 2s infinite;
}

.dashboard-status-indicator-warning {
    background: var(--db-warning);
}

.dashboard-status-indicator-warning::after {
    background: rgba(245, 158, 11, 0.3);
}

.dashboard-status-indicator-danger {
    background: var(--db-danger);
}

.dashboard-status-indicator-danger::after {
    background: rgba(239, 68, 68, 0.3);
}

.dashboard-status-content {
    flex: 1;
}

.dashboard-status-label {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin: 0 0 2px 0;
}

.dashboard-status-value {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 600;
    margin: 0;
}

/* ========== БЫСТРЫЕ ДЕЙСТВИЯ ========== */
.dashboard-quick-actions-widget .dashboard-widget-body {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.dashboard-mini-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 11px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    color: var(--db-text);
}

.dashboard-mini-action-btn:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
    color: var(--db-accent);
    transform: translateY(-2px);
}

/* ========== НЕТ ДАННЫХ ========== */
.dashboard-no-data {
    text-align: center;
    padding: 40px 20px;
    color: var(--db-text-secondary);
    font-size: 14px;
}

.dashboard-no-data i {
    font-size: 32px;
    margin-bottom: 10px;
    opacity: 0.5;
}

@keyframes pulse {
    0% {
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
    }
    100% {
        transform: translate(-50%, -50%) scale(2);
        opacity: 0;
    }
}
.dashboard-stat-income-total {
    font-size: 14px;
    margin-top: 5px;
    color: var(--db-text-secondary);
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка дашборда -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-tachometer-alt"></i> Админ дашборд</h1>
            <p>Обзор системы и управление ресурсами</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="/admin/users.php?action=add" class="dashboard-action-btn dashboard-action-btn-primary">
                <i class="fas fa-user-plus"></i> Добавить пользователя
            </a>
            <a href="/admin/vms.php?action=add" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-plus-circle"></i> Создать ВМ
            </a>
        </div>
    </div>

    <!-- Карточки статистики -->
    <div class="dashboard-stats-grid">
        <!-- Пользователи -->
        <div class="dashboard-stat-card dashboard-stat-card-users">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <?php if ($stats['new_users_today'] > 0): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive">+<?= $stats['new_users_today'] ?> сегодня</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Пользователи</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['total_users']) ?></div>
                <p class="dashboard-stat-subtext">Всего зарегистрировано</p>
            </div>
        </div>

        <!-- Виртуальные машины -->
        <div class="dashboard-stat-card dashboard-stat-card-vms">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-server"></i>
                </div>
                <span class="dashboard-stat-trend <?= $stats['running_vms'] > 0 ? 'dashboard-stat-trend-positive' : 'dashboard-stat-trend-warning' ?>">
                    <?= $stats['running_vms'] ?> запущено
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Виртуальные машины</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['total_vms']) ?></div>
                <p class="dashboard-stat-subtext">
                    <?= $stats['running_vms'] ?> запущено / <?= $stats['stopped_vms'] ?> остановлено
                    <?php if ($stats['error_vms'] > 0): ?>
                    <br><span style="color: var(--db-danger);"><?= $stats['error_vms'] ?> с ошибками</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Ноды -->
        <div class="dashboard-stat-card dashboard-stat-card-nodes">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-network-wired"></i>
                </div>
                <span class="dashboard-stat-trend <?= $stats['active_nodes'] == $stats['total_nodes'] && $stats['total_nodes'] > 0 ? 'dashboard-stat-trend-positive' : 'dashboard-stat-trend-warning' ?>">
                    <?= $stats['active_nodes'] ?> активных
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Ноды</h3>
                <div class="dashboard-stat-value"><?= $stats['total_nodes'] ?></div>
                <p class="dashboard-stat-subtext">
                    <?= $stats['active_nodes'] ?> активно / <?= $stats['inactive_nodes'] ?> неактивно
                </p>
            </div>
        </div>

        <!-- Тикеты -->
        <div class="dashboard-stat-card dashboard-stat-card-tickets">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <?php if ($stats['open_tickets'] > 0): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-warning"><?= $stats['open_tickets'] ?> открыто</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Тикеты</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['total_tickets']) ?></div>
                <p class="dashboard-stat-subtext">
                    <?= $stats['open_tickets'] ?> открыто / <?= $stats['pending_tickets'] ?> в работе
                    <?php if ($stats['closed_tickets'] > 0): ?>
                    <br><?= $stats['closed_tickets'] ?> закрыто
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Доход -->
        <div class="dashboard-stat-card dashboard-stat-card-income">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <?php if ($stats['today_income'] > 0): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive">+<?= number_format($stats['today_income'], 0) ?> ₽</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Доход</h3>
                <div class="dashboard-stat-value"><?= number_format($stats['total_income'], 0) ?> <span>₽</span></div>
                <p class="dashboard-stat-subtext">Общий доход</p>
                <div class="dashboard-stat-income-total">
                    Месяц: <?= number_format($stats['monthly_income'], 0) ?> ₽ |
                    Год: <?= number_format($stats['yearly_income'], 0) ?> ₽
                </div>
            </div>
        </div>
    </div>

    <!-- Основная сетка -->
    <div class="dashboard-main-grid">
        <!-- Левая колонка -->
        <div class="left-column">
            <!-- Последние пользователи -->
            <div class="dashboard-widget">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-users"></i> Последние пользователи</h3>
                    <a href="/admin/users.php" class="dashboard-widget-link">Все пользователи →</a>
                </div>
                <div class="dashboard-widget-body">
                    <?php if (!empty($recent_users)): ?>
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Пользователь</th>
                                <th>Баланс</th>
                                <th>Дата регистрации</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $user): ?>
                            <tr>
                                <td>
                                    <div class="dashboard-table-user">
                                        <div class="dashboard-user-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="dashboard-user-info">
                                            <div class="dashboard-user-name"><?= htmlspecialchars($user['email']) ?></div>
                                            <div class="dashboard-user-email">ID: <?= $user['id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="dashboard-table-balance"><?= number_format($user['balance'], 2) ?> ₽</td>
                                <td class="dashboard-table-date"><?= date('d.m.Y', strtotime($user['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="dashboard-no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>Нет данных о пользователях</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Последние ВМ -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-server"></i> Последние ВМ</h3>
                    <a href="/admin/vms.php" class="dashboard-widget-link">Все ВМ →</a>
                </div>
                <div class="dashboard-widget-body">
                    <?php if (!empty($recent_vms)): ?>
                    <div class="dashboard-list-widget">
                        <?php foreach ($recent_vms as $vm): ?>
                        <a href="/admin/vms.php?vm_id=<?= $vm['vm_id'] ?>" class="dashboard-list-item">
                            <div class="dashboard-item-avatar dashboard-item-avatar-vms">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="dashboard-item-content">
                                <div class="dashboard-item-title"><?= htmlspecialchars($vm['hostname'] ?? 'Без имени') ?></div>
                                <div class="dashboard-item-subtitle"><?= htmlspecialchars($vm['email']) ?></div>
                            </div>
                            <div class="dashboard-item-meta">
                                <span class="dashboard-item-status dashboard-item-status-<?= $vm['status'] ?>">
                                    <?= 
                                        $vm['status'] === 'running' ? 'Запущена' : 
                                        ($vm['status'] === 'stopped' ? 'Остановлена' : 
                                        ($vm['status'] === 'error' ? 'Ошибка' : $vm['status'])) 
                                    ?>
                                </span>
                                <span class="dashboard-item-time"><?= date('d.m.Y', strtotime($vm['created_at'])) ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="dashboard-no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>Нет данных о виртуальных машинах</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Правая колонка -->
        <div class="right-column">
            <!-- Уведомления -->
            <?php if (!empty($notifications)): ?>
            <div class="dashboard-widget dashboard-notifications-widget">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-bell"></i> Уведомления</h3>
                    <a href="#" class="dashboard-widget-link" style="color: white;" onclick="hideNotifications(this); return false;">Скрыть</a>
                </div>
                <div class="dashboard-widget-body">
                    <div class="dashboard-notifications-list">
                        <?php foreach ($notifications as $notification): ?>
                        <div class="dashboard-notification-item">
                            <div class="dashboard-notification-icon">
                                <i class="fas <?= $notification['icon'] ?>"></i>
                            </div>
                            <div class="dashboard-notification-content">
                                <div class="dashboard-notification-title"><?= $notification['title'] ?></div>
                                <div class="dashboard-notification-message"><?= $notification['message'] ?></div>
                            </div>
                            <a href="<?= $notification['link'] ?>" class="dashboard-notification-link">Посмотреть →</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Системный статус -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-chart-pie"></i> Системный статус</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="dashboard-status-grid">
                        <div class="dashboard-status-item">
                            <div class="dashboard-status-indicator <?= $stats['running_vms'] > 0 ? '' : 'dashboard-status-indicator-warning' ?>"></div>
                            <div class="dashboard-status-content">
                                <div class="dashboard-status-label">Вирт. машины</div>
                                <div class="dashboard-status-value"><?= $stats['running_vms'] ?>/<?= $stats['total_vms'] ?></div>
                            </div>
                        </div>
                        <div class="dashboard-status-item">
                            <div class="dashboard-status-indicator <?= $stats['active_nodes'] > 0 ? '' : 'dashboard-status-indicator-danger' ?>"></div>
                            <div class="dashboard-status-content">
                                <div class="dashboard-status-label">Ноды</div>
                                <div class="dashboard-status-value"><?= $stats['active_nodes'] ?>/<?= $stats['total_nodes'] ?></div>
                            </div>
                        </div>
                        <div class="dashboard-status-item">
                            <div class="dashboard-status-indicator <?= $stats['open_tickets'] === 0 ? '' : 'dashboard-status-indicator-warning' ?>"></div>
                            <div class="dashboard-status-content">
                                <div class="dashboard-status-label">Тикеты</div>
                                <div class="dashboard-status-value"><?= $stats['open_tickets'] ?> открыто</div>
                            </div>
                        </div>
                        <div class="dashboard-status-item">
                            <div class="dashboard-status-indicator <?= $stats['total_income'] > 0 ? '' : 'dashboard-status-indicator-warning' ?>"></div>
                            <div class="dashboard-status-content">
                                <div class="dashboard-status-label">Общий доход</div>
                                <div class="dashboard-status-value"><?= number_format($stats['total_income'], 0) ?> ₽</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Последние тикеты -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-ticket-alt"></i> Последние тикеты</h3>
                    <a href="/admin/ticket.php" class="dashboard-widget-link">Все тикеты →</a>
                </div>
                <div class="dashboard-widget-body">
                    <?php if (!empty($recent_tickets)): ?>
                    <div class="dashboard-list-widget">
                        <?php foreach ($recent_tickets as $ticket): ?>
                        <a href="/admin/ticket.php?ticket_id=<?= $ticket['id'] ?>" class="dashboard-list-item">
                            <div class="dashboard-item-avatar dashboard-item-avatar-tickets">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <div class="dashboard-item-content">
                                <div class="dashboard-item-title"><?= htmlspecialchars($ticket['subject']) ?></div>
                                <div class="dashboard-item-subtitle"><?= htmlspecialchars($ticket['email']) ?></div>
                            </div>
                            <div class="dashboard-item-meta">
                                <span class="dashboard-item-status dashboard-item-status-<?= $ticket['status'] ?>">
                                    <?= 
                                        $ticket['status'] === 'open' ? 'Открыт' : 
                                        ($ticket['status'] === 'closed' ? 'Закрыт' : 
                                        ($ticket['status'] === 'pending' ? 'В работе' : $ticket['status'])) 
                                    ?>
                                </span>
                                <span class="dashboard-item-time"><?= date('d.m H:i', strtotime($ticket['created_at'])) ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="dashboard-no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>Нет новых тикетов</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Быстрые действия -->
            <div class="dashboard-widget dashboard-quick-actions-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-bolt"></i> Быстрые действия</h3>
                </div>
                <div class="dashboard-widget-body">
                    <a href="/admin/users.php?action=add" class="dashboard-mini-action-btn">
                        <i class="fas fa-user-plus"></i> Пользователь
                    </a>
                    <a href="/admin/vms.php?action=add" class="dashboard-mini-action-btn">
                        <i class="fas fa-plus-circle"></i> ВМ
                    </a>
                    <a href="/admin/nodes.php?action=add" class="dashboard-mini-action-btn">
                        <i class="fas fa-network-wired"></i> Нода
                    </a>
                    <a href="/admin/settings.php" class="dashboard-mini-action-btn">
                        <i class="fas fa-cog"></i> Настройки
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек при загрузке
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

    // Анимация виджетов
    const widgets = document.querySelectorAll('.dashboard-widget');
    widgets.forEach((widget, index) => {
        widget.style.opacity = '0';
        widget.style.transform = 'translateY(20px)';

        setTimeout(() => {
            widget.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            widget.style.opacity = '1';
            widget.style.transform = 'translateY(0)';
        }, (statCards.length * 100) + (index * 100));
    });

    // Обновление статистики в реальном времени
    function updateDashboardStats() {
        fetch('/admin/ajax/dashboard_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем значения карточек с анимацией
                    updateStatCard('.dashboard-stat-card-users .dashboard-stat-value', data.total_users);
                    updateStatCard('.dashboard-stat-card-vms .dashboard-stat-value', data.total_vms);
                    updateStatCard('.dashboard-stat-card-nodes .dashboard-stat-value', data.total_nodes);
                    updateStatCard('.dashboard-stat-card-tickets .dashboard-stat-value', data.total_tickets);
                    updateStatCard('.dashboard-stat-card-income .dashboard-stat-value', data.total_income + ' ₽');

                    // Обновляем тренды
                    updateTrends(data);
                    
                    // Обновляем системный статус
                    updateSystemStatus(data);
                }
            })
            .catch(error => console.error('Ошибка обновления статистики:', error));
    }

    function updateStatCard(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            const oldValue = element.textContent;
            const newValue = value;

            if (oldValue !== newValue) {
                element.style.transform = 'scale(1.1)';
                element.style.color = 'var(--db-accent)';

                setTimeout(() => {
                    element.textContent = value;
                    element.style.transform = 'scale(1)';
                    element.style.color = '';
                }, 300);
            }
        }
    }

    function updateTrends(data) {
        // Новые пользователи сегодня
        const userTrend = document.querySelector('.dashboard-stat-card-users .dashboard-stat-trend');
        if (userTrend) {
            if (data.new_users_today > 0) {
                userTrend.textContent = '+' + data.new_users_today + ' сегодня';
                userTrend.style.display = 'block';
                userTrend.className = 'dashboard-stat-trend dashboard-stat-trend-positive';
            } else {
                userTrend.style.display = 'none';
            }
        }

        // Запущенные ВМ
        const vmTrend = document.querySelector('.dashboard-stat-card-vms .dashboard-stat-trend');
        if (vmTrend) {
            if (data.running_vms > 0) {
                vmTrend.textContent = data.running_vms + ' запущено';
                vmTrend.style.display = 'block';
                vmTrend.className = 'dashboard-stat-trend dashboard-stat-trend-positive';
            } else {
                vmTrend.textContent = '0 запущено';
                vmTrend.style.display = 'block';
                vmTrend.className = 'dashboard-stat-trend dashboard-stat-trend-warning';
            }
        }

        // Активные ноды
        const nodeTrend = document.querySelector('.dashboard-stat-card-nodes .dashboard-stat-trend');
        if (nodeTrend) {
            if (data.active_nodes > 0) {
                nodeTrend.textContent = data.active_nodes + ' активных';
                nodeTrend.style.display = 'block';
                const isAllActive = data.active_nodes === data.total_nodes && data.total_nodes > 0;
                nodeTrend.className = isAllActive ? 
                    'dashboard-stat-trend dashboard-stat-trend-positive' : 
                    'dashboard-stat-trend dashboard-stat-trend-warning';
            } else {
                nodeTrend.style.display = 'none';
            }
        }

        // Открытые тикеты
        const ticketTrend = document.querySelector('.dashboard-stat-card-tickets .dashboard-stat-trend');
        if (ticketTrend) {
            if (data.open_tickets > 0) {
                ticketTrend.textContent = data.open_tickets + ' открыто';
                ticketTrend.style.display = 'block';
                ticketTrend.className = 'dashboard-stat-trend dashboard-stat-trend-warning';
            } else {
                ticketTrend.style.display = 'none';
            }
        }

        // Доход сегодня
        const incomeTrend = document.querySelector('.dashboard-stat-card-income .dashboard-stat-trend');
        if (incomeTrend) {
            if (data.today_income > 0) {
                incomeTrend.textContent = '+' + data.today_income + ' ₽';
                incomeTrend.style.display = 'block';
                incomeTrend.className = 'dashboard-stat-trend dashboard-stat-trend-positive';
            } else {
                incomeTrend.style.display = 'none';
            }
        }
    }
    
    function updateSystemStatus(data) {
        // Обновляем индикаторы системного статуса
        const vmIndicator = document.querySelector('.dashboard-status-item:nth-child(1) .dashboard-status-indicator');
        const nodeIndicator = document.querySelector('.dashboard-status-item:nth-child(2) .dashboard-status-indicator');
        const ticketIndicator = document.querySelector('.dashboard-status-item:nth-child(3) .dashboard-status-indicator');
        const incomeIndicator = document.querySelector('.dashboard-status-item:nth-child(4) .dashboard-status-indicator');
        
        if (vmIndicator) {
            vmIndicator.className = 'dashboard-status-indicator ' + 
                (data.running_vms > 0 ? '' : 'dashboard-status-indicator-warning');
        }
        
        if (nodeIndicator) {
            nodeIndicator.className = 'dashboard-status-indicator ' + 
                (data.active_nodes > 0 ? '' : 'dashboard-status-indicator-danger');
        }
        
        if (ticketIndicator) {
            ticketIndicator.className = 'dashboard-status-indicator ' + 
                (data.open_tickets === 0 ? '' : 'dashboard-status-indicator-warning');
        }
        
        if (incomeIndicator) {
            incomeIndicator.className = 'dashboard-status-indicator ' + 
                (data.total_income > 0 ? '' : 'dashboard-status-indicator-warning');
        }
    }

    // Загружаем статистику при загрузке страницы
    updateDashboardStats();

    // Обновляем статистику каждые 30 секунд
    setInterval(updateDashboardStats, 30000);

    // Обновление отступа дашборда при сворачивании сайдбара
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

function hideNotifications(link) {
    const widget = link.closest('.dashboard-widget');
    widget.style.opacity = '0';
    widget.style.transform = 'translateY(-20px)';
    setTimeout(() => {
        widget.style.display = 'none';
    }, 300);
}
</script>

<?php
require 'admin_footer.php';
?>