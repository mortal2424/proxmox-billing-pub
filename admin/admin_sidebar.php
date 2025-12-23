
<?php
require_once '../includes/db.php';
$db = new Database();
$pdo = $db->getConnection();

// Проверка существования таблиц
$payments_exists = safeQuery($pdo, "SHOW TABLES LIKE 'payments'")->rowCount() > 0;
$nodes_exists = safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0;
$legal_exists = safeQuery($pdo, "SHOW TABLES LIKE 'legal_entity_info'")->rowCount() > 0;

// Получаем текущую версию системы из базы данных
$current_version = '2.5.1'; // Версия по умолчанию
try {
    // Проверяем существование таблицы system_versions
    $table_exists = safeQuery($pdo, "SHOW TABLES LIKE 'system_versions'")->rowCount() > 0;

    if ($table_exists) {
        // Получаем последнюю версию из таблицы
        $stmt = $pdo->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1");
        if ($stmt->rowCount() > 0) {
            $current_version = $stmt->fetchColumn();
        }
    }
} catch (Exception $e) {
    error_log("Error getting system version: " . $e->getMessage());
}

// Определяем текущую страницу
$current_page = basename($_SERVER['PHP_SELF']);

// Получаем статистику системы
$system_stats = [];
try {
    // Общая статистика
    $system_stats['total_users'] = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_stats['active_vms'] = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status = 'running'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_stats['total_nodes'] = $pdo->query("SELECT COUNT(*) as count FROM proxmox_nodes WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_stats['open_tickets'] = $pdo->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_stats['today_payments'] = $pdo->query("SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_stats['monthly_income'] = $pdo->query("SELECT SUM(amount) as sum FROM payments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetch(PDO::FETCH_ASSOC)['sum'] ?? 0;

    // Новые пользователи сегодня
    $system_stats['new_users_today'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (Exception $e) {
    error_log("Error loading system stats: " . $e->getMessage());
}
?>

<style>
/* ========== ОСНОВНЫЕ ПЕРЕМЕННЫЕ (СИНХРОНИЗИРОВАНЫ С ШАПКОЙ) ========== */
:root {
    --sidebar-bg: #f8fafc;
    --sidebar-bg-gradient: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
    --sidebar-accent: #0ea5e9;
    --sidebar-accent-hover: #0284c7;
    --sidebar-accent-light: rgba(14, 165, 233, 0.15);
    --sidebar-text: #1e293b;
    --sidebar-text-secondary: #475569;
    --sidebar-border: #cbd5e1;
    --sidebar-hover: rgba(14, 165, 233, 0.08);
    --sidebar-active: rgba(14, 165, 233, 0.15);
    --sidebar-card-bg: #ffffff;
    --sidebar-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    --sidebar-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

[data-theme="dark"] {
    --sidebar-bg: #1e293b;
    --sidebar-bg-gradient: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    --sidebar-accent: #38bdf8;
    --sidebar-accent-hover: #0ea5e9;
    --sidebar-accent-light: rgba(56, 189, 248, 0.15);
    --sidebar-text: #f1f5f9;
    --sidebar-text-secondary: #cbd5e1;
    --sidebar-border: #334155;
    --sidebar-hover: rgba(56, 189, 248, 0.08);
    --sidebar-active: rgba(56, 189, 248, 0.15);
    --sidebar-card-bg: #1e293b;
    --sidebar-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    --sidebar-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

/* ========== ОСНОВНОЙ КОНТЕЙНЕР ========== */
.admin-sidebar {
    position: absolute;
    left: 0;
    top: 70px;
    width: 280px;
    height: auto;
    min-height: calc(100vh - 70px);
    background: var(--sidebar-bg-gradient);
    border-right: 1px solid var(--sidebar-border);
    z-index: 900;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    box-shadow: var(--sidebar-shadow);
}

.sidebar-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding-bottom: 20px;
}

/* ========== ЗАГОЛОВОК САЙДБАРА ========== */
.sidebar-header {
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--sidebar-border);
    flex-shrink: 0;
    background: var(--sidebar-bg);
}

.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sidebar-logo-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--sidebar-accent), var(--sidebar-accent-hover));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.sidebar-title {
    color: var(--sidebar-text);
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}

.sidebar-toggle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: var(--sidebar-accent-light);
    border: 1px solid rgba(14, 165, 233, 0.3);
    color: var(--sidebar-accent);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.25s ease;
}

.sidebar-toggle:hover {
    background: rgba(14, 165, 233, 0.2);
    color: var(--sidebar-accent-hover);
    transform: translateY(-2px);
    box-shadow: var(--sidebar-hover-shadow);
}

/* ========== КОМПАКТНЫЕ КАРТОЧКИ СТАТИСТИКИ ========== */
.sidebar-stats {
    padding: 15px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    flex-shrink: 0;
    background: var(--sidebar-bg);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.stat-card {
    background: var(--sidebar-card-bg);
    border: 1px solid var(--sidebar-border);
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.stat-card:hover {
    border-color: var(--sidebar-accent);
    transform: translateY(-1px);
    box-shadow: var(--sidebar-hover-shadow);
}

.stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

.stat-icon.users { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.stat-icon.vms { background: rgba(16, 185, 129, 0.15); color: #10b981; }
.stat-icon.nodes { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
.stat-icon.income { background: rgba(14, 165, 233, 0.15); color: #0ea5e9; }

.stat-info {
    flex: 1;
    min-width: 0;
}

.stat-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--sidebar-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--sidebar-text);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.stat-trend {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 4px;
    border-radius: 4px;
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.stat-trend.negative {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

/* ========== СИСТЕМНАЯ ИНФОРМАЦИЯ ========== */
.system-info {
    padding: 15px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    flex-shrink: 0;
    background: var(--sidebar-bg);
}

.info-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--sidebar-text-secondary);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-title i {
    color: var(--sidebar-accent);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}

.info-icon.success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
.info-icon.warning { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.info-icon.danger { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

.info-content {
    flex: 1;
    min-width: 0;
}

.info-label {
    font-size: 11px;
    color: var(--sidebar-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.info-value {
    font-size: 13px;
    font-weight: 600;
    color: var(--sidebar-text);
    margin-top: 1px;
}

/* ========== МЕНЮ ========== */
.sidebar-nav {
    flex: 1;
    padding: 15px 0;
    min-height: 200px;
    background: var(--sidebar-bg);
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.menu-section {
    padding: 0 20px;
    margin: 15px 0 8px 0;
}

.menu-section-title {
    font-size: 11px;
    font-weight: 600;
    color: var(--sidebar-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    opacity: 0.7;
    display: flex;
    align-items: center;
    gap: 6px;
}

.menu-section-title i {
    font-size: 10px;
    opacity: 0.5;
}

.sidebar-menu-item {
    margin: 2px 15px;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.sidebar-menu-item:hover {
    background: var(--sidebar-hover);
    transform: translateX(4px);
}

.sidebar-menu-item.active {
    background: var(--sidebar-active);
    position: relative;
}

.sidebar-menu-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, var(--sidebar-accent), var(--sidebar-accent-hover));
    border-radius: 0 3px 3px 0;
}

.sidebar-menu-link {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
    opacity: 0.9;
}

.sidebar-menu-item:hover .sidebar-menu-link {
    color: var(--sidebar-accent);
    opacity: 1;
}

.sidebar-menu-item.active .sidebar-menu-link {
    color: var(--sidebar-accent);
    font-weight: 600;
    opacity: 1;
}

.menu-icon {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 16px;
    color: var(--sidebar-text-secondary);
    transition: all 0.2s ease;
}

.sidebar-menu-item:hover .menu-icon,
.sidebar-menu-item.active .menu-icon {
    color: var(--sidebar-accent);
    transform: scale(1.1);
}

.menu-text {
    flex: 1;
}

.menu-badge {
    margin-left: 8px;
    padding: 3px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    min-width: 20px;
    text-align: center;
}

.menu-badge.new {
    background: linear-gradient(135deg, #ff4757, #ff3838);
    color: white;
    animation: pulse 2s infinite;
}

.menu-badge.count {
    background: var(--sidebar-accent-light);
    color: var(--sidebar-accent);
    border: 1px solid rgba(14, 165, 233, 0.3);
}

.menu-badge.warning {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.menu-badge.success {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 71, 87, 0.4);
    }
    70% {
        box-shadow: 0 0 0 4px rgba(255, 71, 87, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 71, 87, 0);
    }
}

/* ========== БЫСТРЫЕ ДЕЙСТВИЯ ========== */
.quick-actions {
    padding: 15px 20px;
    border-top: 1px solid var(--sidebar-border);
    background: var(--sidebar-bg);
    flex-shrink: 0;
}

.quick-actions-title {
    color: var(--sidebar-text);
    font-size: 12px;
    font-weight: 600;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.quick-actions-title i {
    color: var(--sidebar-accent);
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    text-align: center;
    position: relative;
    overflow: hidden;
    background: var(--sidebar-card-bg);
    border-color: var(--sidebar-border);
    color: var(--sidebar-text-secondary);
}

.quick-action-btn:hover {
    transform: translateY(-1px);
    box-shadow: var(--sidebar-hover-shadow);
}

.quick-action-btn.primary {
    background: var(--sidebar-accent-light);
    border-color: rgba(14, 165, 233, 0.3);
    color: var(--sidebar-accent);
}

.quick-action-btn.success {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
    color: #10b981;
}

.quick-action-btn.warning {
    background: rgba(245, 158, 11, 0.15);
    border-color: rgba(245, 158, 11, 0.3);
    color: #f59e0b;
}

.quick-action-btn.danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

/* ========== ФУТЕР САЙДБАРА ========== */
.sidebar-footer {
    padding: 12px 20px;
    border-top: 1px solid var(--sidebar-border);
    background: var(--sidebar-bg);
    flex-shrink: 0;
}

.footer-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.version-info {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    color: var(--sidebar-text-secondary);
}

.version-status {
    width: 5px;
    height: 5px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 4px rgba(16, 185, 129, 0.8);
    animation: pulse-green 2s infinite;
}

@keyframes pulse-green {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
    }
    70% {
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
}

.system-status {
    font-size: 10px;
    color: var(--sidebar-text-secondary);
    display: flex;
    align-items: center;
    gap: 4px;
}

.system-status i {
    color: #10b981;
    font-size: 6px;
}

/* ========== СОСТОЯНИЕ СВЕРНУТО ========== */
.admin-sidebar.compact {
    width: 70px;
}

.admin-sidebar.compact .sidebar-title,
.admin-sidebar.compact .stat-label,
.admin-sidebar.compact .stat-value span:not(.stat-icon),
.admin-sidebar.compact .stat-trend,
.admin-sidebar.compact .info-title span,
.admin-sidebar.compact .info-content,
.admin-sidebar.compact .menu-section-title,
.admin-sidebar.compact .menu-text,
.admin-sidebar.compact .menu-badge,
.admin-sidebar.compact .quick-actions-title span,
.admin-sidebar.compact .quick-action-btn span,
.admin-sidebar.compact .footer-info {
    display: none;
}

.admin-sidebar.compact .sidebar-logo {
    justify-content: center;
}

.admin-sidebar.compact .sidebar-stats,
.admin-sidebar.compact .system-info {
    padding: 10px;
}

.admin-sidebar.compact .stats-grid,
.admin-sidebar.compact .info-grid,
.admin-sidebar.compact .quick-actions-grid {
    grid-template-columns: 1fr;
}

.admin-sidebar.compact .sidebar-menu-item {
    margin: 2px 10px;
}

.admin-sidebar.compact .sidebar-menu-link {
    justify-content: center;
    padding: 10px;
}

.admin-sidebar.compact .menu-icon {
    margin-right: 0;
    font-size: 16px;
}

.admin-sidebar.compact .quick-action-btn {
    justify-content: center;
    padding: 10px;
}

.admin-sidebar.compact .sidebar-toggle i {
    transform: rotate(180deg);
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .admin-sidebar {
        width: 260px;
    }
}

@media (max-width: 992px) {
    .admin-sidebar {
        position: fixed;
        transform: translateX(-100%);
        top: 0;
        height: 100vh;
        z-index: 1002;
        box-shadow: 0 0 40px rgba(0, 0, 0, 0.5);
        transition: transform 0.3s ease;
    }

    .admin-sidebar.mobile-open {
        transform: translateX(0);
    }

    .sidebar-toggle {
        display: none;
    }

    .mobile-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        backdrop-filter: blur(5px);
    }

    .mobile-overlay.show {
        display: block;
    }
}

/* Иконки для разных разделов */
.menu-icon.dashboard { color: #3b82f6; }
.menu-icon.users { color: #10b981; }
.menu-icon.vms { color: #f59e0b; }
.menu-icon.nodes { color: #8b5cf6; }
.menu-icon.management { color: #6366f1; }
.menu-icon.payments { color: #ef4444; }
.menu-icon.settings { color: #6b7280; }
.menu-icon.quotas { color: #8b5cf6; }
.menu-icon.tickets { color: #f59e0b; }
.menu-icon.images { color: #10b981; }
.menu-icon.legal { color: #3b82f6; }
.menu-icon.logs { color: #ff6347; }
</style>

<!-- Боковое меню -->
<aside class="admin-sidebar" id="adminSidebar">
    <!-- Заголовок -->
    <div class="sidebar-header">
        <div class="sidebar-logo">
        <h3 class="sidebar-title">Свернуть/Развернуть</h3>
            <!--<div class="sidebar-logo-icon">
               <i class="fas fa-shield-alt"></i>
            </div>-->
            <!--<h3 class="sidebar-title">Админ панель</h3>-->
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" title="Свернуть/Развернуть">
            <i class="fas fa-chevron-left"></i>
        </button>
    </div>

    <!-- Содержимое сайдбара -->
    <div class="sidebar-content">
        <!-- Компактные карточки статистики -->
        <!--<div class="sidebar-stats">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Пользователи</div>
                        <div class="stat-value">
                            <?= number_format($system_stats['total_users']) ?>
                            <?php if ($system_stats['new_users_today'] > 0): ?>
                                <span class="stat-trend">+<?= $system_stats['new_users_today'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon vms">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Вирт. машины</div>
                        <div class="stat-value"><?= number_format($system_stats['active_vms']) ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon nodes">
                        <i class="fas fa-network-wired"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Ноды</div>
                        <div class="stat-value"><?= number_format($system_stats['total_nodes']) ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon income">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Доход</div>
                        <div class="stat-value"><?= number_format($system_stats['monthly_income'] ?? 0, 0) ?> ₽</div>
                    </div>
                </div>
            </div>
        </div>-->

        <!-- Системная информация -->
        <div class="system-info">
            <div class="info-title">
                <i class="fas fa-info-circle"></i>
                <span>Система</span>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon <?= $system_stats['open_tickets'] > 5 ? 'warning' : 'success' ?>">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Тикеты</div>
                        <div class="info-value"><?= $system_stats['open_tickets'] ?> открыто</div>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-icon success">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Платежи</div>
                        <div class="info-value"><?= $system_stats['today_payments'] ?> сегодня</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Навигационное меню -->
        <nav class="sidebar-nav">
            <ul class="sidebar-menu">
                <!-- Основной раздел -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-home"></i>
                        <span>Основное</span>
                    </div>
                </div>

                <li class="sidebar-menu-item <?= $current_page === 'index.php' || $current_page === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="/admin/" class="sidebar-menu-link">
                        <div class="menu-icon dashboard">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <span class="menu-text">Дашборд</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $current_page === 'users.php' ? 'active' : '' ?>">
                    <a href="/admin/users.php" class="sidebar-menu-link">
                        <div class="menu-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                        <span class="menu-text">Пользователи</span>
                        <?php if ($system_stats['new_users_today'] > 0): ?>
                            <span class="menu-badge new">+<?= $system_stats['new_users_today'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $current_page === 'vms.php' ? 'active' : '' ?>">
                    <a href="/admin/vms.php" class="sidebar-menu-link">
                        <div class="menu-icon vms">
                            <i class="fas fa-server"></i>
                        </div>
                        <span class="menu-text">Виртуальные машины</span>
                        <?php if ($system_stats['active_vms'] > 0): ?>
                            <span class="menu-badge count"><?= $system_stats['active_vms'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Системный раздел -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-cogs"></i>
                        <span>Система</span>
                    </div>
                </div>

                <?php if ($nodes_exists): ?>
                <li class="sidebar-menu-item <?= $current_page === 'nodes.php' || $current_page === 'add_nodes.php' || $current_page === 'edit_node.php' ? 'active' : '' ?>">
                    <a href="/admin/nodes.php" class="sidebar-menu-link">
                        <div class="menu-icon nodes">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <span class="menu-text">Настройки Proxmox</span>
                        <?php if ($system_stats['total_nodes'] > 0): ?>
                            <span class="menu-badge count"><?= $system_stats['total_nodes'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $current_page === 'proxmox.php' ? 'active' : '' ?>">
                    <a href="/admin/proxmox.php" class="sidebar-menu-link">
                        <div class="menu-icon management">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <span class="menu-text">Управление ВМ</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Финансы -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Финансы</span>
                    </div>
                </div>

                <?php if ($payments_exists): ?>
                <li class="sidebar-menu-item <?= $current_page === 'payments.php' ? 'active' : '' ?>">
                    <a href="/admin/payments.php" class="sidebar-menu-link">
                        <div class="menu-icon payments">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <span class="menu-text">Платежи</span>
                        <?php if ($system_stats['today_payments'] > 0): ?>
                            <span class="menu-badge success">+<?= $system_stats['today_payments'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Настройки -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-sliders-h"></i>
                        <span>Настройки</span>
                    </div>
                </div>

                <li class="sidebar-menu-item <?= $current_page === 'settings.php' ? 'active' : '' ?>">
                    <a href="/admin/settings.php" class="sidebar-menu-link">
                        <div class="menu-icon settings">
                            <i class="fas fa-cog"></i>
                        </div>
                        <span class="menu-text">Системные настройки</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $current_page === 'quotas.php' ? 'active' : '' ?>">
                    <a href="/admin/quotas.php" class="sidebar-menu-link">
                        <div class="menu-icon quotas">
                            <i class='fas fa-chart-pie'></i>
                        </div>
                        <span class="menu-text">Квоты и лимиты</span>
                    </a>
                </li>

                <!-- Поддержка -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-headset"></i>
                        <span>Поддержка</span>
                    </div>
                </div>

                <li class="sidebar-menu-item <?= $current_page === 'ticket.php' ? 'active' : '' ?>">
                    <a href="/admin/ticket.php" class="sidebar-menu-link">
                        <div class="menu-icon tickets">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <span class="menu-text">Тикеты</span>
                        <?php if ($system_stats['open_tickets'] > 0): ?>
                            <span class="menu-badge warning"><?= $system_stats['open_tickets'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Дополнительно -->
                <div class="menu-section">
                    <div class="menu-section-title">
                        <i class="fas fa-ellipsis-h"></i>
                        <span>Дополнительно</span>
                    </div>
                </div>

                <li class="sidebar-menu-item <?= $current_page === 'image.php' ? 'active' : '' ?>">
                    <a href="/admin/image.php" class="sidebar-menu-link">
                        <div class="menu-icon images">
                            <i class="fas fa-compact-disc"></i>
                        </div>
                        <span class="menu-text">Образы ОС</span>
                    </a>
                </li>

                <?php if ($legal_exists): ?>
                <li class="sidebar-menu-item <?= $current_page === 'legal_info.php' ? 'active' : '' ?>">
                    <a href="/admin/legal_info.php" class="sidebar-menu-link">
                        <div class="menu-icon legal">
                            <i class="fas fa-building"></i>
                        </div>
                        <span class="menu-text">Юридическая информация</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-menu-item <?= $current_page === 'logs.php' ? 'active' : '' ?>">
                    <a href="/admin/logs.php" class="sidebar-menu-link">
                        <div class="menu-icon logs">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <span class="menu-text">Логи системы</span>
                    </a>
                </li>
                <li class="sidebar-menu-item <?= $current_page === 'backup.php' ? 'active' : '' ?>">
                    <a href="/admin/backup.php" class="sidebar-menu-link">
                        <div class="menu-icon settings">
                            <i class="fas fa-database"></i>
                        </div>
                        <span class="menu-text">Резервное копирование</span>
                    </a>
                </li>

            </ul>
        </nav>

        <!-- Быстрые действия -->
        <div class="quick-actions">
            <h5 class="quick-actions-title">
                <i class="fas fa-bolt"></i>
                <span>Быстрые действия</span>
            </h5>
            <div class="quick-actions-grid">
                <a href="/admin/users.php?action=add" class="quick-action-btn primary">
                    <i class="fas fa-user-plus"></i>
                    <span>Добавить пользователя</span>
                </a>
                <a href="/admin/vms.php?action=add" class="quick-action-btn success">
                    <i class="fas fa-plus-circle"></i>
                    <span>Создать ВМ</span>
                </a>
                <a href="/admin/ticket.php" class="quick-action-btn warning">
                    <i class="fas fa-headset"></i>
                    <span>Поддержка</span>
                </a>
                <a href="/admin/settings.php" class="quick-action-btn danger">
                    <i class="fas fa-cogs"></i>
                    <span>Настройки</span>
                </a>
            </div>
        </div>

        <!-- Футер -->
        <div class="sidebar-footer">
            <div class="footer-info">
                <div class="version-info">
                    <i class="fas fa-code-branch"></i>
                    <span>v<?= htmlspecialchars($current_version) ?></span>
                    <span class="version-status"></span>
                </div>
                <div class="system-status">
                    <i class="fas fa-circle"></i>
                    <span>Система онлайн</span>
                </div>
            </div>
        </div>
    </div>
</aside>

<!-- Мобильный оверлей -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const mainContent = document.querySelector('.main-content');

    // Проверяем сохраненное состояние сайдбара
    const isCompact = localStorage.getItem('adminSidebarCompact') === 'true';
    const isMobile = window.innerWidth <= 992;

    // Инициализация состояния
    if (isCompact && !isMobile) {
        sidebar.classList.add('compact');
        sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        updateMainContentMargin();
    }

    // Функция для обновления отступа основного контента
    function updateMainContentMargin() {
        if (!mainContent || isMobile) return;

        if (sidebar.classList.contains('compact')) {
            mainContent.style.marginLeft = '70px';
        } else {
            mainContent.style.marginLeft = '280px';
        }
    }

    // Переключение сайдбара (десктоп)
    sidebarToggle.addEventListener('click', function() {
        if (window.innerWidth > 992) {
            sidebar.classList.toggle('compact');
            const isNowCompact = sidebar.classList.contains('compact');
            localStorage.setItem('adminSidebarCompact', isNowCompact);

            // Обновляем иконку
            const icon = this.querySelector('i');
            if (isNowCompact) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }

            updateMainContentMargin();
        }
    });

    // Мобильное меню
    function initMobileMenu() {
        if (window.innerWidth <= 992) {
            // Добавляем кнопку меню в хедер если её нет
            if (!document.getElementById('mobileMenuBtn')) {
                const mobileMenuBtn = document.createElement('button');
                mobileMenuBtn.id = 'mobileMenuBtn';
                mobileMenuBtn.className = 'mobile-menu-btn';
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                mobileMenuBtn.style.cssText = `
                    position: fixed;
                    top: 15px;
                    left: 15px;
                    width: 40px;
                    height: 40px;
                    border-radius: 10px;
                    background: rgba(14, 165, 233, 0.9);
                    border: none;
                    color: white;
                    font-size: 16px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 1000;
                    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
                    transition: all 0.3s ease;
                `;

                document.body.appendChild(mobileMenuBtn);

                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.add('mobile-open');
                    mobileOverlay.classList.add('show');
                    document.body.style.overflow = 'hidden';
                });

                mobileOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    mobileOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                });

                // Закрытие сайдбара при клике на ссылку
                document.querySelectorAll('.sidebar-menu-link, .quick-action-btn').forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992) {
                            sidebar.classList.remove('mobile-open');
                            mobileOverlay.classList.remove('show');
                            document.body.style.overflow = '';
                        }
                    });
                });
            }
        } else {
            // На десктопе убираем оверлей и кнопку
            mobileOverlay.classList.remove('show');
            sidebar.classList.remove('mobile-open');
            document.body.style.overflow = '';

            const mobileBtn = document.getElementById('mobileMenuBtn');
            if (mobileBtn) mobileBtn.remove();
        }
    }

    // Анимация карточек при загрузке
    function animateCards() {
        const cards = document.querySelectorAll('.stat-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';

            setTimeout(() => {
                card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 50);
        });
    }

    // Обновление статистики в реальном времени
    function updateStats() {
        fetch('/admin/ajax/get_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем значения статистики
                    const stats = {
                        'users': data.total_users,
                        'vms': data.active_vms,
                        'nodes': data.total_nodes,
                        'income': data.monthly_income + ' ₽'
                    };

                    Object.keys(stats).forEach((key, index) => {
                        const card = document.querySelectorAll('.stat-card')[index];
                        if (card) {
                            const valueSpan = card.querySelector('.stat-value');
                            if (valueSpan) {
                                const oldText = valueSpan.textContent;
                                valueSpan.innerHTML = stats[key];

                                if (key === 'users' && data.new_users_today > 0) {
                                    const trendSpan = document.createElement('span');
                                    trendSpan.className = 'stat-trend';
                                    trendSpan.textContent = '+' + data.new_users_today;
                                    valueSpan.appendChild(trendSpan);
                                }

                                if (oldText !== valueSpan.textContent) {
                                    card.style.animation = 'none';
                                    setTimeout(() => {
                                        card.style.animation = 'pulse 0.5s';
                                    }, 10);
                                }
                            }
                        }
                    });

                    // Обновляем бейджи
                    updateBadge('users', data.new_users_today);
                    updateBadge('vms', data.active_vms);
                    updateBadge('tickets', data.open_tickets);
                    updateBadge('payments', data.today_payments);
                }
            })
            .catch(error => console.error('Ошибка загрузки статистики:', error));
    }

    function updateBadge(type, count) {
        let selector, badgeClass;
        switch(type) {
            case 'users':
                selector = 'a[href="/admin/users.php"]';
                badgeClass = 'new';
                break;
            case 'vms':
                selector = 'a[href="/admin/vms.php"]';
                badgeClass = 'count';
                break;
            case 'tickets':
                selector = 'a[href="/admin/ticket.php"]';
                badgeClass = 'warning';
                break;
            case 'payments':
                selector = 'a[href="/admin/payments.php"]';
                badgeClass = 'success';
                break;
        }

        if (selector && count > 0) {
            const link = document.querySelector(selector);
            if (link) {
                let badge = link.querySelector('.menu-badge');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = `menu-badge ${badgeClass}`;
                    link.appendChild(badge);
                }
                badge.textContent = type === 'users' || type === 'payments' ? '+' + count : count;
            }
        } else if (selector && count === 0) {
            const link = document.querySelector(selector);
            if (link) {
                const badge = link.querySelector('.menu-badge');
                if (badge) {
                    badge.remove();
                }
            }
        }
    }

    // Инициализация
    initMobileMenu();
    animateCards();
    updateMainContentMargin();

    // Загружаем статистику каждые 30 секунд
    setInterval(updateStats, 30000);

    // Обработка изменения размера окна
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            initMobileMenu();

            if (window.innerWidth > 992) {
                updateMainContentMargin();
            } else {
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }, 250);
    });

    // Добавляем класс активной странице
    const currentPath = window.location.pathname;
    const menuItems = document.querySelectorAll('.sidebar-menu-item');

    menuItems.forEach(item => {
        item.classList.remove('active');
        const link = item.querySelector('a');
        if (link) {
            const href = link.getAttribute('href');
            if (href === '/admin/' && (currentPath === '/admin/' || currentPath === '/admin/index.php')) {
                item.classList.add('active');
            } else if (href && currentPath.includes(href.replace('/admin/', ''))) {
                item.classList.add('active');
            }
        }
    });
});
</script>
