<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем ноды с количеством ВМ (административных)
$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name,
           (SELECT COUNT(*) FROM vms_admin WHERE node_id = n.id AND status = 'running') as running_vms,
           (SELECT COUNT(*) FROM vms_admin WHERE node_id = n.id AND status = 'stopped') as stopped_vms,
           (SELECT COUNT(*) FROM vms_admin WHERE node_id = n.id AND status = 'error') as error_vms
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    WHERE n.is_active = 1
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$total_running_vms = array_sum(array_column($nodes, 'running_vms'));
$total_stopped_vms = array_sum(array_column($nodes, 'stopped_vms'));
$total_error_vms = array_sum(array_column($nodes, 'error_vms'));
$total_vms = $total_running_vms + $total_stopped_vms + $total_error_vms;

$title = "Управление Proxmox | HomeVlad Cloud";
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

/* ========== КАРТОЧКИ СТАТИСТИКИ ========== */
.dashboard-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.dashboard-stat-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: var(--admin-shadow);
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
    border-color: var(--admin-accent);
    box-shadow: var(--admin-hover-shadow);
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
    color: var(--admin-success);
}

.dashboard-stat-trend-warning {
    background: rgba(245, 158, 11, 0.2);
    color: var(--admin-warning);
}

.dashboard-stat-trend-danger {
    background: rgba(239, 68, 68, 0.2);
    color: var(--admin-danger);
}

.dashboard-stat-content h3 {
    color: var(--admin-text-secondary);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.dashboard-stat-value {
    color: var(--admin-text);
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 4px 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.dashboard-stat-subtext {
    color: var(--admin-text-muted);
    font-size: 12px;
    margin: 0;
}

/* Цвета для карточек */
.dashboard-stat-card-vms { --stat-color: var(--admin-warning); }
.dashboard-stat-card-nodes { --stat-color: var(--admin-purple); }
.dashboard-stat-card-resources { --stat-color: var(--admin-accent); }
.dashboard-stat-card-clusters { --stat-color: var(--admin-success); }

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
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--admin-shadow);
}

.dashboard-widget-header {
    padding: 20px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-widget-header h3 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-widget-header h3 i {
    color: var(--admin-accent);
}

.dashboard-widget-link {
    color: var(--admin-accent);
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

/* ========== АККОРДЕОН НОД ========== */
.proxmox-nodes-grid {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.proxmox-node-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: var(--admin-shadow);
}

.proxmox-node-card:hover {
    box-shadow: var(--admin-hover-shadow);
    border-color: var(--admin-accent);
}

.node-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    cursor: pointer;
    user-select: none;
    transition: background 0.3s ease;
}

.node-card-header:hover {
    background: var(--admin-accent-light);
}

.node-header-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.node-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--admin-purple), #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.node-info {
    display: flex;
    flex-direction: column;
}

.node-name {
    color: var(--admin-text);
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
}

.node-cluster {
    color: var(--admin-text-secondary);
    font-size: 13px;
}

.node-header-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.node-stats {
    display: flex;
    gap: 15px;
}

.node-stat {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--admin-text-secondary);
}

.node-stat i {
    font-size: 16px;
}

.node-stat span {
    font-weight: 600;
    color: var(--admin-text);
}

.node-chevron {
    color: var(--admin-text-muted);
    transition: transform 0.3s ease;
}

.node-vms-container {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.node-vms-container.expanded {
    max-height: 800px;
}

.vm-table-container {
    padding: 20px;
    border-top: 1px solid var(--admin-border);
    background: var(--admin-bg);
}

/* ========== ТАБЛИЦА ВМ ========== */
.dashboard-table {
    width: 100%;
    border-collapse: collapse;
}

.dashboard-table thead th {
    color: var(--admin-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--admin-border);
}

.dashboard-table tbody tr {
    border-bottom: 1px solid var(--admin-border);
    transition: all 0.3s ease;
}

.dashboard-table tbody tr:hover {
    background: var(--admin-accent-light);
}

.dashboard-table tbody td {
    color: var(--admin-text);
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
    background: linear-gradient(135deg, var(--admin-purple), #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

.dashboard-user-info {
    display: flex;
    flex-direction: column;
}

.dashboard-user-name {
    color: var(--admin-text);
    font-size: 14px;
    font-weight: 500;
}

.dashboard-user-email {
    color: var(--admin-text-secondary);
    font-size: 12px;
}

.dashboard-item-status {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}

.dashboard-item-status-running { background: rgba(16, 185, 129, 0.2); color: var(--admin-success); }
.dashboard-item-status-stopped { background: rgba(148, 163, 184, 0.2); color: var(--admin-text-muted); }
.dashboard-item-status-error { background: rgba(239, 68, 68, 0.2); color: var(--admin-danger); }

.vm-actions {
    display: flex;
    gap: 8px;
    flex-wrap: nowrap;
}

.dashboard-mini-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 1px solid var(--admin-border);
    background: var(--admin-card-bg);
    color: var(--admin-text);
    cursor: pointer;
    min-width: 36px;
    min-height: 36px;
}

.dashboard-mini-action-btn:hover {
    background: var(--admin-accent-light);
    border-color: var(--admin-accent);
    color: var(--admin-accent);
    transform: translateY(-2px);
}

/* ========== СТАТУС КЛАСТЕРОВ ========== */
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
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
}

.dashboard-status-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--admin-success);
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
    background: var(--admin-warning);
}

.dashboard-status-indicator-warning::after {
    background: rgba(245, 158, 11, 0.3);
}

.dashboard-status-indicator-danger {
    background: var(--admin-danger);
}

.dashboard-status-indicator-danger::after {
    background: rgba(239, 68, 68, 0.3);
}

.dashboard-status-content {
    flex: 1;
}

.dashboard-status-label {
    color: var(--admin-text-secondary);
    font-size: 12px;
    margin: 0 0 2px 0;
}

.dashboard-status-value {
    color: var(--admin-text);
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

/* ========== НЕТ ДАННЫХ ========== */
.dashboard-no-data {
    text-align: center;
    padding: 40px 20px;
    color: var(--admin-text-secondary);
    font-size: 14px;
}

.dashboard-no-data i {
    font-size: 32px;
    margin-bottom: 10px;
    opacity: 0.5;
}

/* ========== ЗАГРУЗКА И ОШИБКИ ========== */
.loading-spinner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 20px;
    color: var(--admin-text-secondary);
}

.loading-spinner i {
    color: var(--admin-accent);
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.error-message {
    padding: 20px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 8px;
    color: #b91c1c;
    text-align: center;
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
    color: #065f46;
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
    color: var(--admin-success);
}

.alert-danger i {
    color: var(--admin-danger);
}

/* ========== VNC КОНСОЛЬ МОДАЛЬНОЕ ОКНО ========== */
.vnc-console-modal {
    width: 90vw !important;
    max-width: 1200px !important;
    height: 85vh !important;
    padding: 0 !important;
}

.vnc-console-modal .swal2-html-container {
    padding: 0;
    height: 100%;
    margin: 0;
    overflow: hidden;
}

.vnc-console-iframe {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: 8px;
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
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка дашборда -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-network-wired"></i> Управление Proxmox</h1>
            <p>Мониторинг и управление виртуальными машинами</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="javascript:void(0)" onclick="refreshAllNodes()" class="dashboard-action-btn dashboard-action-btn-primary">
                <i class="fas fa-sync-alt"></i> Обновить все
            </a>
            <a href="/admin/nodes.php?action=add" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-plus-circle"></i> Добавить ноду
            </a>
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

    <!-- Карточки статистики -->
    <div class="dashboard-stats-grid">
        <!-- Всего ВМ -->
        <div class="dashboard-stat-card dashboard-stat-card-vms">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-server"></i>
                </div>
                <span class="dashboard-stat-trend <?= $total_running_vms > 0 ? 'dashboard-stat-trend-positive' : 'dashboard-stat-trend-warning' ?>">
                    <?= $total_running_vms ?> запущено
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Виртуальные машины</h3>
                <div class="dashboard-stat-value"><?= number_format($total_vms) ?></div>
                <p class="dashboard-stat-subtext">
                    <?= $total_running_vms ?> запущено / <?= $total_stopped_vms ?> остановлено
                    <?php if ($total_error_vms > 0): ?>
                    <br><span style="color: var(--admin-danger);"><?= $total_error_vms ?> с ошибками</span>
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
                <span class="dashboard-stat-trend <?= count($nodes) > 0 ? 'dashboard-stat-trend-positive' : 'dashboard-stat-trend-warning' ?>">
                    <?= count($nodes) ?> активно
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Ноды Proxmox</h3>
                <div class="dashboard-stat-value"><?= count($nodes) ?></div>
                <p class="dashboard-stat-subtext">
                    Все ноды в рабочем состоянии
                </p>
            </div>
        </div>

        <!-- Ресурсы -->
        <div class="dashboard-stat-card dashboard-stat-card-resources">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-microchip"></i>
                </div>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive">
                    <i class="fas fa-chart-line"></i> Активно
                </span>
            </div>
            <div class="dashboard-stat-content">
                <h3>Ресурсы</h3>
                <div class="dashboard-stat-value">-</div>
                <p class="dashboard-stat-subtext">Загрузка CPU и памяти</p>
            </div>
        </div>

        <!-- Кластеры -->
        <?php
        $clusters = array_unique(array_column($nodes, 'cluster_name'));
        ?>
        <div class="dashboard-stat-card dashboard-stat-card-clusters">
            <div class="dashboard-stat-header">
                <div class="dashboard-stat-icon">
                    <i class="fas fa-sitemap"></i>
                </div>
                <?php if (count($clusters) > 1): ?>
                <span class="dashboard-stat-trend dashboard-stat-trend-positive"><?= count($clusters) ?> кластера</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-stat-content">
                <h3>Кластеры</h3>
                <div class="dashboard-stat-value"><?= count($clusters) ?></div>
                <p class="dashboard-stat-subtext">
                    <?= !empty($clusters) ? htmlspecialchars(implode(', ', $clusters)) : 'Нет кластеров' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Основная сетка -->
    <div class="dashboard-main-grid">
        <!-- Левая колонка - Ноды -->
        <div class="left-column">
            <div class="dashboard-widget">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-server"></i> Ноды Proxmox</h3>
                    <a href="/admin/nodes.php" class="dashboard-widget-link">Управление нодами →</a>
                </div>
                <div class="dashboard-widget-body">
                    <?php if (!empty($nodes)): ?>
                        <div class="proxmox-nodes-grid">
                            <?php foreach ($nodes as $node): ?>
                            <div class="proxmox-node-card" data-node-id="<?= $node['id'] ?>">
                                <div class="node-card-header" onclick="loadNodeVms(<?= $node['id'] ?>, this)">
                                    <div class="node-header-left">
                                        <div class="node-avatar">
                                            <i class="fas fa-server"></i>
                                        </div>
                                        <div class="node-info">
                                            <div class="node-name"><?= htmlspecialchars($node['node_name']) ?></div>
                                            <div class="node-cluster"><?= htmlspecialchars($node['cluster_name']) ?></div>
                                        </div>
                                    </div>
                                    <div class="node-header-right">
                                        <div class="node-stats">
                                            <div class="node-stat">
                                                <i class="fas fa-play-circle" style="color: var(--admin-success);"></i>
                                                <span><?= $node['running_vms'] ?></span>
                                            </div>
                                            <div class="node-stat">
                                                <i class="fas fa-stop-circle" style="color: var(--admin-warning);"></i>
                                                <span><?= $node['stopped_vms'] ?></span>
                                            </div>
                                            <?php if ($node['error_vms'] > 0): ?>
                                            <div class="node-stat">
                                                <i class="fas fa-exclamation-triangle" style="color: var(--admin-danger);"></i>
                                                <span><?= $node['error_vms'] ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <i class="fas fa-chevron-down node-chevron"></i>
                                    </div>
                                </div>
                                <div class="node-vms-container" id="node-vms-<?= $node['id'] ?>">
                                    <div class="loading-spinner">
                                        <i class="fas fa-spinner fa-spin"></i> Загрузка виртуальных машин...
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-no-data">
                            <i class="fas fa-info-circle"></i>
                            <p>Нет активных нод Proxmox</p>
                            <a href="/admin/nodes.php?action=add" class="dashboard-mini-action-btn" style="margin-top: 15px; display: inline-flex;">
                                <i class="fas fa-plus-circle"></i> Добавить ноду
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Правая колонка - Быстрые действия и информация -->
        <div class="right-column">
            <!-- Быстрые действия -->
            <div class="dashboard-widget dashboard-quick-actions-widget">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-bolt"></i> Быстрые действия</h3>
                </div>
                <div class="dashboard-widget-body">
                    <a href="javascript:void(0)" onclick="showAllVms()" class="dashboard-mini-action-btn">
                        <i class="fas fa-list"></i> Все ВМ
                    </a>
                    <a href="javascript:void(0)" onclick="showRunningVms()" class="dashboard-mini-action-btn">
                        <i class="fas fa-play-circle"></i> Запущенные
                    </a>
                    <a href="javascript:void(0)" onclick="showStoppedVms()" class="dashboard-mini-action-btn">
                        <i class="fas fa-stop-circle"></i> Остановленные
                    </a>
                    <a href="/admin/vm_add.php" class="dashboard-mini-action-btn">
                        <i class="fas fa-plus-circle"></i> Новая ВМ
                    </a>
                </div>
            </div>

            <!-- Статус кластеров -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-sitemap"></i> Статус кластеров</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="dashboard-status-grid">
                        <?php foreach ($clusters as $cluster):
                            $cluster_nodes = array_filter($nodes, function($n) use ($cluster) {
                                return $n['cluster_name'] === $cluster;
                            });
                            $cluster_running = array_sum(array_column($cluster_nodes, 'running_vms'));
                            $cluster_total = array_sum(array_column($cluster_nodes, 'running_vms')) +
                                            array_sum(array_column($cluster_nodes, 'stopped_vms')) +
                                            array_sum(array_column($cluster_nodes, 'error_vms'));
                        ?>
                        <div class="dashboard-status-item">
                            <div class="dashboard-status-indicator <?= $cluster_total > 0 ? '' : 'dashboard-status-indicator-warning' ?>"></div>
                            <div class="dashboard-status-content">
                                <div class="dashboard-status-label"><?= htmlspecialchars($cluster) ?></div>
                                <div class="dashboard-status-value"><?= count($cluster_nodes) ?> нод, <?= $cluster_running ?>/<?= $cluster_total ?> ВМ</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Глобальные переменные для управления состоянием
const vmActions = {
    pending: {},

    start: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;

        Swal.fire({
            title: 'Запуск VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });

        fetch(`vm_action.php?action=start&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно запущена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        const header = document.querySelector(`[data-node-id="${nodeId}"] .node-card-header`);
                        if (header) {
                            loadNodeVms(nodeId, header);
                        }
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось запустить VM: ' + error.message,
                    icon: 'error'
                });
            });
    },

    stop: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;

        Swal.fire({
            title: 'Остановка VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });

        fetch(`vm_action.php?action=stop&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно остановлена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        const header = document.querySelector(`[data-node-id="${nodeId}"] .node-card-header`);
                        if (header) {
                            loadNodeVms(nodeId, header);
                        }
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось остановить VM: ' + error.message,
                    icon: 'error'
                });
            });
    },

    reboot: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;

        Swal.fire({
            title: 'Перезагрузка VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });

        fetch(`vm_action.php?action=reboot&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно перезагружена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        const header = document.querySelector(`[data-node-id="${nodeId}"] .node-card-header`);
                        if (header) {
                            loadNodeVms(nodeId, header);
                        }
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось перезагрузить VM: ' + error.message,
                    icon: 'error'
                });
            });
    }
};

function loadNodeVms(nodeId, headerElement) {
    const container = document.getElementById(`node-vms-${nodeId}`);
    const chevron = headerElement.querySelector('.node-chevron');

    // Если уже открыто - закрываем
    if (container.classList.contains('expanded')) {
        container.classList.remove('expanded');
        chevron.classList.remove('fa-rotate-180');
        return;
    }

    // Скрыть другие открытые ноды
    document.querySelectorAll('.node-vms-container.expanded').forEach(el => {
        el.classList.remove('expanded');
        el.closest('.proxmox-node-card').querySelector('.node-chevron').classList.remove('fa-rotate-180');
    });

    // Если уже загружено, просто показываем
    if (container.dataset.loaded === 'true') {
        container.classList.add('expanded');
        chevron.classList.add('fa-rotate-180');
        return;
    }

    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка виртуальных машин...</div>';
    container.classList.add('expanded');
    chevron.classList.add('fa-rotate-180');

    fetch(`get_node_vms.php?node_id=${nodeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<div class="error-message">${data.error}</div>`;
                return;
            }

            let html = `
                <div class="vm-table-container">
                    <div class="table-responsive">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Имя</th>
                                    <th>Статус</th>
                                    <th>CPU</th>
                                    <th>RAM</th>
                                    <th>Диск</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            data.vms.forEach(vm => {
                html += `
                    <tr>
                        <td><strong>${vm.vmid}</strong></td>
                        <td>
                            <div class="dashboard-table-user">
                                <div class="dashboard-user-avatar">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="dashboard-user-info">
                                    <div class="dashboard-user-name">${escapeHtml(vm.name)}</div>
                                    <div class="dashboard-user-email">ID: ${vm.vmid}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="dashboard-item-status ${vm.status === 'running' ? 'dashboard-item-status-running' : 'dashboard-item-status-stopped'}">
                                ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                            </span>
                        </td>
                        <td>${vm.cpus} ядер</td>
                        <td>${vm.mem} GB</td>
                        <td>${vm.disk} GB</td>
                        <td>
                            <div class="vm-actions">
                                <button class="dashboard-mini-action-btn" onclick="showVmInfo(${nodeId}, ${vm.vmid})" title="Информация">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <button class="dashboard-mini-action-btn" onclick="openVncConsoleProxmox(${nodeId}, ${vm.vmid}, '${escapeHtml(vm.name)}')" title="Консоль">
                                    <i class="fas fa-terminal"></i>
                                </button>
                                ${vm.status === 'running' ?
                                    `<button class="dashboard-mini-action-btn" onclick="vmActions.stop(${nodeId}, ${vm.vmid})" style="background: rgba(239, 68, 68, 0.1); color: var(--admin-danger);" title="Остановить">
                                        <i class="fas fa-stop"></i>
                                    </button>` :
                                    `<button class="dashboard-mini-action-btn" onclick="vmActions.start(${nodeId}, ${vm.vmid})" style="background: rgba(16, 185, 129, 0.1); color: var(--admin-success);" title="Запустить">
                                        <i class="fas fa-play"></i>
                                    </button>`
                                }
                                <button class="dashboard-mini-action-btn" onclick="vmActions.reboot(${nodeId}, ${vm.vmid})" style="background: rgba(245, 158, 11, 0.1); color: var(--admin-warning);" title="Перезагрузить">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div>`;
            container.innerHTML = html;
            container.dataset.loaded = 'true';
        })
        .catch(error => {
            container.innerHTML = `<div class="error-message">Ошибка загрузки: ${error.message}</div>`;
        });
}

function refreshAllNodes() {
    Swal.fire({
        title: 'Обновление данных...',
        html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Обновление информации о нодах...</div>',
        showConfirmButton: false,
        allowOutsideClick: false
    });

    // Закрыть все открытые панели ВМ
    document.querySelectorAll('.node-vms-container.expanded').forEach(el => {
        el.classList.remove('expanded');
        el.closest('.proxmox-node-card').querySelector('.node-chevron').classList.remove('fa-rotate-180');
    });

    // Сбросить загруженные данные
    document.querySelectorAll('.node-vms-container').forEach(el => {
        el.dataset.loaded = 'false';
    });

    // Перезагрузить страницу через 1 секунду
    setTimeout(() => {
        Swal.close();
        location.reload();
    }, 1000);
}

function showAllVms() {
    // Открыть все ноды
    document.querySelectorAll('.proxmox-node-card').forEach(card => {
        const nodeId = card.dataset.nodeId;
        const header = card.querySelector('.node-card-header');
        loadNodeVms(nodeId, header);
    });
}

function showRunningVms() {
    // Открыть только ноды с запущенными ВМ
    document.querySelectorAll('.proxmox-node-card').forEach(card => {
        const runningVms = card.querySelector('.node-stat:nth-child(1) span').textContent;
        if (parseInt(runningVms) > 0) {
            const nodeId = card.dataset.nodeId;
            const header = card.querySelector('.node-card-header');
            loadNodeVms(nodeId, header);
        }
    });
}

function showStoppedVms() {
    // Открыть только ноды с остановленными ВМ
    document.querySelectorAll('.proxmox-node-card').forEach(card => {
        const stoppedVms = card.querySelector('.node-stat:nth-child(2) span').textContent;
        if (parseInt(stoppedVms) > 0) {
            const nodeId = card.dataset.nodeId;
            const header = card.querySelector('.node-card-header');
            loadNodeVms(nodeId, header);
        }
    });
}

function showVmInfo(nodeId, vmid) {
    Swal.fire({
        title: 'Получение информации о VM...',
        html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка данных...</div>',
        showConfirmButton: false,
        allowOutsideClick: false,
        didOpen: () => {
            fetch(`get_vm_info.php?node_id=${nodeId}&vmid=${vmid}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        Swal.fire({
                            title: 'Ошибка',
                            text: data.error,
                            icon: 'error'
                        });
                        return;
                    }

                    let html = `
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">ID VM:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${data.vmid}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">Имя:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${escapeHtml(data.name)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">Статус:</span>
                                <span class="dashboard-item-status ${data.status === 'running' ? 'dashboard-item-status-running' : 'dashboard-item-status-stopped'}">
                                    ${data.status === 'running' ? 'Запущена' : 'Остановлена'}
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">CPU:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${data.cpus} ядер</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">Память:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${data.mem} GB</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">Диск:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${data.disk} GB</span>
                            </div>
                    `;

                    if (data.ip) {
                        html += `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--admin-border);">
                                <span style="color: var(--admin-text-secondary); font-weight: 500;">IP-адрес:</span>
                                <span style="color: var(--admin-text); font-weight: 600;">${data.ip}</span>
                            </div>
                        `;
                    }

                    html += `</div>`;

                    Swal.fire({
                        title: `Информация о VM ${data.vmid}`,
                        html: html,
                        confirmButtonText: 'Закрыть',
                        width: 500
                    });
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Ошибка',
                        text: 'Не удалось получить информацию: ' + error.message,
                        icon: 'error'
                    });
                });
        }
    });
}

// Функция для открытия VNC консоли для ВМ из Proxmox API
let vncActionInProgress = false;

async function openVncConsoleProxmox(nodeId, vmId, vmName) {
    if (vncActionInProgress) return;
    vncActionInProgress = true;

    try {
        // Показываем загрузку
        const swalInstance = Swal.fire({
            title: 'Подготовка консоли...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся к ВМ Proxmox...</div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Формируем URL для ВМ из Proxmox API (используем vnc_console_prox.php)
        const url = new URL('vnc_console_prox.php', window.location.href);
        url.searchParams.append('node_id', nodeId);
        url.searchParams.append('vm_id', vmId);

        console.log('Fetching VNC console URL for Proxmox VM:', url.toString());

        const response = await fetch(url);

        // Проверяем ответ
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Server response:', errorText);
            throw new Error(`HTTP error! status: ${response.status}, response: ${errorText.substring(0, 500)}`);
        }

        const text = await response.text();
        console.log('Raw response:', text.substring(0, 500));

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text);
            throw new Error('Неверный формат ответа от сервера: ' + e.message);
        }

        console.log('Parsed data:', data);

        if (!data.success) {
            throw new Error(data.error || 'Не удалось подключиться к VNC консоли');
        }

        // Устанавливаем cookie если она есть
        if (data.data.cookie) {
            const cookie = data.data.cookie;
            // Формируем строку cookie
            let cookieStr = `${cookie.name}=${encodeURIComponent(cookie.value)}; `;

            // Добавляем domain если он есть
            if (cookie.domain) {
                cookieStr += `domain=${cookie.domain}; `;
            }

            cookieStr += `path=${cookie.path || '/'}; `;
            cookieStr += `secure=${cookie.secure !== false}; `;
            cookieStr += `samesite=${cookie.samesite || 'None'}`;

            console.log('Setting cookie for Proxmox VNC:', cookieStr);
            document.cookie = cookieStr;
        }

        // Проверка URL консоли
        if (!data.data.url) {
            throw new Error('Не получен URL консоли');
        }

        // Закрываем окно загрузки
        swalInstance.close();

        // Открываем VNC консоль в новом окне
        const vncWindow = window.open(
            data.data.url,
            `vnc_proxmox_${nodeId}_${vmId}`,
            'width=1024,height=768,scrollbars=yes,resizable=yes,location=yes'
        );

        if (!vncWindow || vncWindow.closed) {
            // Если не удалось открыть окно, показываем в модальном окне
            Swal.fire({
                title: 'VNC Консоль Proxmox',
                html: `<div style="width: 100%; height: 600px;">
                          <iframe src="${data.data.url}"
                                  style="width: 100%; height: 100%; border: none; border-radius: 8px;"
                                  class="vnc-console-iframe"></iframe>
                       </div>`,
                showConfirmButton: false,
                showCloseButton: true,
                width: 1200,
                customClass: {
                    popup: 'vnc-console-modal'
                }
            });
        } else {
            // Показываем успешное сообщение
            Swal.fire({
                title: 'Консоль открыта',
                text: `VNC консоль для ВМ #${vmId} (${vmName || ''}) открыта в новом окне`,
                icon: 'success',
                timer: 3000,
                showConfirmButton: false
            });
        }

    } catch (error) {
        // Закрываем загрузку если открыто
        if (Swal.isVisible()) {
            Swal.close();
        }

        // Показываем ошибку
        Swal.fire({
            title: 'Ошибка подключения к ВМ Proxmox',
            text: error.message,
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: '#ef4444'
        });

        console.error('Proxmox VNC Error:', error);
    } finally {
        vncActionInProgress = false;
    }
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

// Обновление отступа при сворачивании сайдбара
document.addEventListener('DOMContentLoaded', function() {
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
</script>

<?php require 'admin_footer.php'; ?>
