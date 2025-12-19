<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

checkAuth();
$user_id = $_SESSION['user']['id'];

// Инициализируем подключение ОДИН раз
$db = new Database();
$pdo = $db->getConnection();

// Получаем данные пользователя с подготовленным запросом
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем текущую версию системы из базы данных
$current_version = '2.5.1'; // Версия по умолчанию
try {
    // Проверяем существование таблицы system_versions
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = DATABASE()
                         AND table_name = 'system_versions'");
    $table_exists = $stmt->fetchColumn() > 0;

    if ($table_exists) {
        // Получаем последнюю версию из таблицы
        $stmt = $pdo->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1");
        if ($stmt && $stmt->rowCount() > 0) {
            $current_version = $stmt->fetchColumn();
        }
    }
} catch (Exception $e) {
    error_log("Error getting system version: " . $e->getMessage());
}

// Определяем активную страницу
$currentPage = basename($_SERVER['PHP_SELF']);

// Получаем статистику ВМ для сайдбара (используем подготовленные запросы)
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_vms,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_vms
    FROM vms
    WHERE user_id = ? AND status != 'deleted'
");
$stmt->execute([$user_id]);
$vm_stats = $stmt->fetch();

// Получаем квоты пользователя
$stmt = $pdo->prepare("SELECT * FROM user_quotas WHERE user_id = ?");
$stmt->execute([$user_id]);
$quota = $stmt->fetch();

if (!$quota) {
    $stmt = $pdo->prepare("INSERT INTO user_quotas (user_id) VALUES (?)");
    $stmt->execute([$user_id]);

    $stmt = $pdo->prepare("SELECT * FROM user_quotas WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $quota = $stmt->fetch();
}

// Получаем текущее использование ресурсов
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as vm_count,
        COALESCE(SUM(cpu), 0) as total_cpu,
        COALESCE(SUM(ram), 0) as total_ram,
        COALESCE(SUM(disk), 0) as total_disk
    FROM vms
    WHERE user_id = ? AND status != 'deleted'
");
$stmt->execute([$user_id]);
$usage = $stmt->fetch();

// Устанавливаем значения по умолчанию для массивов
$vm_stats = $vm_stats ?: ['total_vms' => 0, 'running_vms' => 0];
$quota = $quota ?: ['max_cpu' => 0, 'max_ram' => 0, 'max_disk' => 0, 'max_vms' => 0];
$usage = $usage ?: ['vm_count' => 0, 'total_cpu' => 0, 'total_ram' => 0, 'total_disk' => 0];

// Рассчитываем процент использования для мини-графиков
$cpu_percent = $quota['max_cpu'] > 0 ? round(($usage['total_cpu'] / $quota['max_cpu']) * 100) : 0;
$ram_percent = $quota['max_ram'] > 0 ? round(($usage['total_ram'] / $quota['max_ram']) * 100) : 0;
$disk_percent = $quota['max_disk'] > 0 ? round(($usage['total_disk'] / $quota['max_disk']) * 100) : 0;
$vms_percent = $quota['max_vms'] > 0 ? round(($usage['vm_count'] / $quota['max_vms']) * 100) : 0;
?>

<!-- Информативный сайдбар (скроллится с контентом) -->
<aside class="admin-sidebar">
    <div class="sidebar-content">
        <!-- Заголовок сайдбара -->
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <!--<div class="sidebar-logo-icon">
                    <i class="fas fa-server"></i>
                </div>-->
                <h3 class="sidebar-title">Свернуть/Развернуть</h3>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" title="Свернуть/Развернуть">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <!-- Профиль пользователя -->
        <div class="sidebar-profile">
            <div class="sidebar-avatar">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар" class="avatar-image">
                <?php else: ?>
                    <?php
                    $defaultIcons = ['fa-user-tie', 'fa-user-astronaut', 'fa-user-shield', 'fa-user-circle'];
                    $randomIcon = $defaultIcons[array_rand($defaultIcons)];
                    ?>
                    <i class="fas <?= $randomIcon ?>"></i>
                <?php endif; ?>
                <?php if ($user['is_admin']): ?>
                    <span class="admin-badge" title="Администратор">
                        <i class="fas fa-crown"></i>
                    </span>
                <?php endif; ?>
            </div>
            <div class="sidebar-user-info">
                <h4 class="sidebar-user-name"><?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></h4>
                <span class="sidebar-user-email"><?= htmlspecialchars($user['email']) ?></span>
                <div class="sidebar-user-stats">
                    <div class="user-stat">
                        <i class="fas fa-server"></i>
                        <span><?= $vm_stats['total_vms'] ?? 0 ?> ВМ</span>
                    </div>
                    <div class="user-stat">
                        <i class="fas fa-play-circle"></i>
                        <span><?= $vm_stats['running_vms'] ?? 0 ?> запущ.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Быстрые действия -->
        <div class="sidebar-quick-actions">
            <h5 class="quick-actions-title">Быстрые действия</h5>
            <div class="quick-actions-grid">
                <a href="/templates/order_vm.php" class="quick-action-btn primary">
                    <i class="fas fa-plus"></i>
                    <span>Создать ВМ</span>
                </a>
                <a href="/admin/billing.php?action=deposit" class="quick-action-btn success">
                    <i class="fas fa-wallet"></i>
                    <span>Пополнить</span>
                </a>
                <a href="/templates/support.php" class="quick-action-btn warning">
                    <i class="fas fa-headset"></i>
                    <span>Поддержка</span>
                </a>
                <a href="/templates/notifications.php" class="quick-action-btn info">
                    <i class="fas fa-bell"></i>
                    <span>Уведомления</span>
                    <?php if (isset($user['unread_notifications']) && $user['unread_notifications'] > 0): ?>
                        <span class="notification-badge"><?= $user['unread_notifications'] ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Использование ресурсов -->
        <div class="sidebar-resources">
            <h5 class="resources-title">
                <i class="fas fa-chart-pie"></i>
                Использование ресурсов
            </h5>

            <div class="resource-item">
                <div class="resource-header">
                    <div class="resource-label">
                        <i class="fas fa-microchip"></i>
                        <span>CPU</span>
                    </div>
                    <div class="resource-value">
                        <span class="used"><?= $usage['total_cpu'] ?? 0 ?></span>
                        <span class="separator">/</span>
                        <span class="total"><?= $quota['max_cpu'] ?></span>
                    </div>
                </div>
                <div class="resource-progress">
                    <div class="progress-bar">
                        <div class="progress-fill cpu" style="width: <?= min($cpu_percent, 100) ?>%"></div>
                    </div>
                    <span class="progress-percent"><?= $cpu_percent ?>%</span>
                </div>
            </div>

            <div class="resource-item">
                <div class="resource-header">
                    <div class="resource-label">
                        <i class="fas fa-memory"></i>
                        <span>RAM</span>
                    </div>
                    <div class="resource-value">
                        <span class="used"><?= ($usage['total_ram'] / 1024) ?? 0 ?> GB</span>
                        <span class="separator">/</span>
                        <span class="total"><?= ($quota['max_ram'] / 1024) ?> GB</span>
                    </div>
                </div>
                <div class="resource-progress">
                    <div class="progress-bar">
                        <div class="progress-fill ram" style="width: <?= min($ram_percent, 100) ?>%"></div>
                    </div>
                    <span class="progress-percent"><?= $ram_percent ?>%</span>
                </div>
            </div>

            <div class="resource-item">
                <div class="resource-header">
                    <div class="resource-label">
                        <i class="fas fa-hdd"></i>
                        <span>Диск</span>
                    </div>
                    <div class="resource-value">
                        <span class="used"><?= $usage['total_disk'] ?? 0 ?> GB</span>
                        <span class="separator">/</span>
                        <span class="total"><?= $quota['max_disk'] ?> GB</span>
                    </div>
                </div>
                <div class="resource-progress">
                    <div class="progress-bar">
                        <div class="progress-fill disk" style="width: <?= min($disk_percent, 100) ?>%"></div>
                    </div>
                    <span class="progress-percent"><?= $disk_percent ?>%</span>
                </div>
            </div>

            <div class="resource-item">
                <div class="resource-header">
                    <div class="resource-label">
                        <i class="fas fa-server"></i>
                        <span>Вирт. машины</span>
                    </div>
                    <div class="resource-value">
                        <span class="used"><?= $usage['vm_count'] ?? 0 ?></span>
                        <span class="separator">/</span>
                        <span class="total"><?= $quota['max_vms'] ?></span>
                    </div>
                </div>
                <div class="resource-progress">
                    <div class="progress-bar">
                        <div class="progress-fill vms" style="width: <?= min(($usage['vm_count'] / $quota['max_vms']) * 100, 100) ?>%"></div>
                    </div>
                    <span class="progress-percent"><?= round(($usage['vm_count'] / $quota['max_vms']) * 100) ?>%</span>
                </div>
            </div>
        </div>

        <!-- Основное меню -->
        <nav class="sidebar-nav">
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">
                    <a href="/templates/dashboard.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <span class="menu-text">Дашборд</span>
                        <?php if ($currentPage == 'dashboard.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $currentPage == 'order_vm.php' ? 'active' : '' ?>">
                    <a href="/templates/order_vm.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <span class="menu-text">Заказать ВМ</span>
                        <?php if ($currentPage == 'order_vm.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                        <span class="menu-badge new">Новый</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $currentPage == 'my_vms.php' ? 'active' : '' ?>">
                    <a href="/templates/my_vms.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <span class="menu-text">Мои ВМ</span>
                        <?php if ($currentPage == 'my_vms.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                        <?php if ($vm_stats['total_vms'] > 0): ?>
                            <span class="menu-badge count"><?= $vm_stats['total_vms'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $currentPage == 'billing.php' ? 'active' : '' ?>">
                    <a href="/templates/billing.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <span class="menu-text">Биллинг</span>
                        <?php if ($currentPage == 'billing.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                        <span class="menu-badge balance"><?= number_format($user['balance'], 0) ?> ₽</span>
                    </a>
                </li>

                <?php if ($user['bonus_balance'] > 0): ?>
                <li class="sidebar-menu-item">
                    <a href="/templates/billing.php#bonuses" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                        <span class="menu-text">Бонусы</span>
                        <span class="menu-badge bonus">+<?= number_format($user['bonus_balance'], 0) ?> ₽</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-menu-item <?= $currentPage == 'settings.php' ? 'active' : '' ?>">
                    <a href="/templates/settings.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <span class="menu-text">Настройки</span>
                        <?php if ($currentPage == 'settings.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $currentPage == 'telegram.php' ? 'active' : '' ?>">
                    <a href="/templates/telegram.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <span class="menu-text">Telegram Bot</span>
                        <?php if ($currentPage == 'telegram.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="sidebar-menu-item <?= $currentPage == 'support.php' ? 'active' : '' ?>">
                    <a href="/templates/support.php" class="sidebar-menu-link">
                        <div class="menu-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <span class="menu-text">Поддержка</span>
                        <?php if ($currentPage == 'support.php'): ?>
                            <span class="menu-active-indicator"></span>
                        <?php endif; ?>
                    </a>
                </li>

                <?php if ($user['is_admin']): ?>
                <li class="sidebar-menu-item">
                    <a href="/admin/" class="sidebar-menu-link admin">
                        <div class="menu-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <span class="menu-text">Админ панель</span>
                        <span class="menu-badge admin">Admin</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Баланс и статус -->
        <div class="sidebar-status">
            <div class="balance-info">
                <div class="balance-item">
                    <span class="balance-label">Основной баланс:</span>
                    <span class="balance-value primary"><?= number_format($user['balance'], 2) ?> ₽</span>
                </div>
                <?php if ($user['bonus_balance'] > 0): ?>
                <div class="balance-item">
                    <span class="balance-label">Бонусный баланс:</span>
                    <span class="balance-value bonus">+<?= number_format($user['bonus_balance'], 2) ?> ₽</span>
                </div>
                <?php endif; ?>
                <div class="balance-total">
                    <span class="total-label">Всего доступно:</span>
                    <span class="total-value"><?= number_format($user['balance'] + $user['bonus_balance'], 2) ?> ₽</span>
                </div>
            </div>

            <div class="system-status">
                <div class="status-item">
                    <span class="status-label">Система:</span>
                    <span class="status-value online">
                        <i class="fas fa-circle"></i> Онлайн
                    </span>
                </div>
                <div class="status-item">
                    <span class="status-label">Обновления:</span>
                    <span class="status-value updated">
                        <i class="fas fa-check-circle"></i> Актуально
                    </span>
                </div>
            </div>
        </div>

        <!-- Футер сайдбара -->
        <div class="sidebar-footer">
            <div class="footer-info">
                <div class="version-info">
                    <i class="fas fa-code-branch"></i>
                    <span>v<?= htmlspecialchars($current_version) ?></span>
                    <span class="version-status"></span>
                </div>
                <div class="footer-links">
                    <a href="/docs/" class="footer-link" title="Документация">
                        <i class="fas fa-book"></i>
                    </a>
                    <a href="/status/" class="footer-link" title="Статус системы">
                        <i class="fas fa-heartbeat"></i>
                    </a>
                </div>
            </div>
            <a href="/login/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Выйти</span>
            </a>
        </div>
    </div>
</aside>

<style>
/* ========== ИНФОРМАТИВНЫЙ САЙДБАР (СКРОЛЛИТСЯ С КОНТЕНТОМ) ========== */
.admin-sidebar {
    position: absolute; /* Меняем на absolute */
    left: 0;
    top: 70px;
    width: 280px;
    height: auto;
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
    border-right: 1px solid rgba(255, 255, 255, 0.05);
    z-index: 900;
    margin-bottom: 60px;
    border-bottom-left-radius: 20px;
    border-bottom-right-radius: 20px;
}

.sidebar-content {
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 70px - 60px);
    padding-bottom: 20px;
}

/* Заголовок сайдбара */
.sidebar-header {
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sidebar-logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}

.sidebar-title {
    color: white;
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}

.sidebar-toggle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.7);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.25s ease;
}

.sidebar-toggle:hover {
    background: rgba(255, 255, 255, 0.12);
    color: white;
    transform: translateY(-2px);
}

/* Профиль пользователя */
.sidebar-profile {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.sidebar-avatar {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    background: linear-gradient(135deg, #8a2be2, #4b0082);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 28px;
    overflow: hidden;
    border: 3px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    position: relative;
}

.sidebar-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.admin-badge {
    position: absolute;
    bottom: -5px;
    right: -5px;
    background: linear-gradient(135deg, #ffd700, #ffa500);
    color: #000;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    border: 2px solid #0f172a;
}

.sidebar-user-info {
    flex: 1;
    min-width: 0;
}

.sidebar-user-name {
    color: white;
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-user-email {
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    margin-bottom: 8px;
    display: block;
}

.sidebar-user-stats {
    display: flex;
    gap: 15px;
}

.user-stat {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.8);
}

.user-stat i {
    color: #00bcd4;
    font-size: 12px;
}

/* Быстрые действия */
.sidebar-quick-actions {
    padding: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.quick-actions-title {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.25s ease;
    position: relative;
    border: 1px solid transparent;
}

.quick-action-btn.primary {
    background: rgba(0, 188, 212, 0.15);
    border-color: rgba(0, 188, 212, 0.3);
    color: #00bcd4;
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

.quick-action-btn.info {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.3);
    color: #3b82f6;
}

.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: linear-gradient(135deg, #ff4757, #ff3838);
    color: white;
    font-size: 10px;
    font-weight: 700;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #0f172a;
}

/* Использование ресурсов */
.sidebar-resources {
    padding: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.resources-title {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.resource-item {
    margin-bottom: 15px;
}

.resource-item:last-child {
    margin-bottom: 0;
}

.resource-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.resource-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: rgba(255, 255, 255, 0.8);
}

.resource-label i {
    color: #00bcd4;
    font-size: 12px;
    width: 16px;
    text-align: center;
}

.resource-value {
    font-size: 12px;
    font-weight: 600;
}

.resource-value .used {
    color: white;
}

.resource-value .separator {
    color: rgba(255, 255, 255, 0.5);
    margin: 0 4px;
}

.resource-value .total {
    color: rgba(255, 255, 255, 0.7);
}

.resource-progress {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar {
    flex: 1;
    height: 6px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.5s ease;
}

.progress-fill.cpu {
    background: linear-gradient(90deg, #00bcd4, #0097a7);
}

.progress-fill.ram {
    background: linear-gradient(90deg, #10b981, #059669);
}

.progress-fill.disk {
    background: linear-gradient(90deg, #8b5cf6, #7c3aed);
}

.progress-fill.vms {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.progress-percent {
    font-size: 11px;
    font-weight: 700;
    color: white;
    min-width: 30px;
    text-align: right;
}

/* Основное меню */
.sidebar-nav {
    padding: 15px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu-item {
    margin: 2px 15px;
    border-radius: 10px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.sidebar-menu-item:hover {
    background: rgba(255, 255, 255, 0.05);
}

.sidebar-menu-item.active {
    background: rgba(255, 255, 255, 0.1);
    position: relative;
}

.sidebar-menu-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, #00bcd4, #0097a7);
    border-radius: 0 3px 3px 0;
}

.sidebar-menu-link {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
}

.sidebar-menu-item:hover .sidebar-menu-link {
    color: white;
    padding-left: 20px;
}

.sidebar-menu-item.active .sidebar-menu-link {
    color: white;
    font-weight: 600;
}

.sidebar-menu-link.admin {
    background: rgba(239, 68, 68, 0.1);
}

.menu-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 16px;
    color: rgba(255, 255, 255, 0.7);
    transition: all 0.2s ease;
}

.sidebar-menu-item:hover .menu-icon,
.sidebar-menu-item.active .menu-icon {
    color: white;
    transform: scale(1.1);
}

.menu-text {
    flex: 1;
}

.menu-active-indicator {
    width: 8px;
    height: 8px;
    background: #00bcd4;
    border-radius: 50%;
    margin-left: 12px;
    box-shadow: 0 0 8px rgba(0, 188, 212, 0.8);
}

.menu-badge {
    margin-left: 12px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.menu-badge.new {
    background: linear-gradient(135deg, #ff4757, #ff3838);
    color: white;
}

.menu-badge.count {
    background: rgba(0, 188, 212, 0.2);
    color: #00bcd4;
    border: 1px solid rgba(0, 188, 212, 0.3);
}

.menu-badge.balance {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.menu-badge.bonus {
    background: rgba(139, 92, 246, 0.2);
    color: #8b5cf6;
    border: 1px solid rgba(139, 92, 246, 0.3);
}

.menu-badge.admin {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* Баланс и статус */
.sidebar-status {
    padding: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.balance-info {
    margin-bottom: 20px;
}

.balance-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.balance-label {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.balance-value {
    font-size: 13px;
    font-weight: 700;
}

.balance-value.primary {
    color: #10b981;
}

.balance-value.bonus {
    color: #8b5cf6;
}

.balance-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 10px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 10px;
}

.total-label {
    font-size: 13px;
    font-weight: 600;
    color: white;
}

.total-value {
    font-size: 14px;
    font-weight: 700;
    color: #00bcd4;
}

.system-status {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.status-label {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.status-value {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
}

.status-value.online {
    color: #10b981;
}

.status-value.online i {
    color: #10b981;
    font-size: 8px;
}

.status-value.updated {
    color: #3b82f6;
}

.status-value.updated i {
    color: #3b82f6;
    font-size: 12px;
}

/* Футер сайдбара */
.sidebar-footer {
    padding: 20px;
}

.footer-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.version-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.6);
}

.version-status {
    width: 6px;
    height: 6px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 6px rgba(16, 185, 129, 0.8);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
}

.footer-links {
    display: flex;
    gap: 10px;
}

.footer-link {
    color: rgba(255, 255, 255, 0.5);
    font-size: 14px;
    transition: all 0.2s ease;
}

.footer-link:hover {
    color: #00bcd4;
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px;
    background: rgba(255, 71, 87, 0.1);
    border: 1px solid rgba(255, 71, 87, 0.2);
    border-radius: 10px;
    color: #ff4757;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.logout-btn:hover {
    background: rgba(255, 71, 87, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 71, 87, 0.1);
}

/* Адаптивность */
@media (max-width: 992px) {
    .admin-sidebar {
        position: fixed;
        width: 280px;
        transform: translateX(-100%);
        top: 70px;
        height: calc(100vh - 70px);
        overflow-y: auto;
        z-index: 1001;
        box-shadow: 0 0 40px rgba(0, 0, 0, 0.5);
        transition: transform 0.3s ease;
    }

    .admin-sidebar.mobile-open {
        transform: translateX(0);
    }

    .sidebar-toggle {
        display: none;
    }

    /* Затемнение фона при открытом сайдбаре */
    .sidebar-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        display: none;
    }

    .sidebar-backdrop.active {
        display: block;
    }
}

/* Для компактного режима */
.admin-sidebar.compact {
    width: 70px;
}

.admin-sidebar.compact .sidebar-title,
.admin-sidebar.compact .sidebar-user-info,
.admin-sidebar.compact .sidebar-user-stats,
.admin-sidebar.compact .quick-actions-title,
.admin-sidebar.compact .quick-action-btn span,
.admin-sidebar.compact .resources-title,
.admin-sidebar.compact .resource-header,
.admin-sidebar.compact .progress-percent,
.admin-sidebar.compact .menu-text,
.admin-sidebar.compact .menu-badge,
.admin-sidebar.compact .balance-info,
.admin-sidebar.compact .system-status,
.admin-sidebar.compact .footer-info,
.admin-sidebar.compact .logout-btn span {
    display: none;
}

.admin-sidebar.compact .sidebar-logo {
    justify-content: center;
}

.admin-sidebar.compact .sidebar-profile {
    justify-content: center;
    padding: 15px;
}

.admin-sidebar.compact .sidebar-avatar {
    width: 40px;
    height: 40px;
    font-size: 20px;
}

.admin-sidebar.compact .quick-actions-grid {
    grid-template-columns: 1fr;
}

.admin-sidebar.compact .quick-action-btn {
    justify-content: center;
    padding: 12px;
}

.admin-sidebar.compact .sidebar-menu-link {
    justify-content: center;
    padding: 12px;
}

.admin-sidebar.compact .menu-icon {
    margin-right: 0;
}

.admin-sidebar.compact .sidebar-toggle i {
    transform: rotate(180deg);
}

.admin-sidebar.compact .logout-btn {
    justify-content: center;
    padding: 12px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    // Функционал сворачивания/разворачивания сайдбара
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('compact');

            const icon = this.querySelector('i');
            if (sidebar.classList.contains('compact')) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');

                // Обновляем отступ для основного контента
                updateMainContentMargin(70);
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');

                // Обновляем отступ для основного контента
                updateMainContentMargin(280);
            }

            // Сохраняем состояние
            localStorage.setItem('sidebarCompact', sidebar.classList.contains('compact'));
        });

        // Восстанавливаем состояние
        const isCompact = localStorage.getItem('sidebarCompact') === 'true';
        if (isCompact) {
            sidebar.classList.add('compact');
            const icon = sidebarToggle.querySelector('i');
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
            updateMainContentMargin(70);
        } else {
            updateMainContentMargin(280);
        }
    }

    // Функция для обновления отступа основного контента
    function updateMainContentMargin(width) {
        const mainContent = document.querySelector('.main-content');
        if (mainContent && window.innerWidth > 992) {
            mainContent.style.marginLeft = width + 'px';
        }
    }

    // На мобильных добавляем кнопку меню в шапку
    if (window.innerWidth <= 992) {
        const headerCenter = document.querySelector('.header-center');
        if (headerCenter) {
            if (!document.getElementById('mobileMenuBtn')) {
                const mobileMenuBtn = document.createElement('button');
                mobileMenuBtn.id = 'mobileMenuBtn';
                mobileMenuBtn.className = 'mobile-menu-btn';
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                mobileMenuBtn.style.cssText = 'width: 40px; height: 40px; border-radius: 10px; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.15); color: white; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center;';

                headerCenter.insertBefore(mobileMenuBtn, headerCenter.firstChild);

                const backdrop = document.createElement('div');
                backdrop.className = 'sidebar-backdrop';
                document.body.appendChild(backdrop);

                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.add('mobile-open');
                    backdrop.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });

                backdrop.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    backdrop.classList.remove('active');
                    document.body.style.overflow = '';
                });

                document.querySelectorAll('.sidebar-menu-link, .quick-action-btn, .logout-btn').forEach(link => {
                    link.addEventListener('click', function() {
                        sidebar.classList.remove('mobile-open');
                        backdrop.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                });
            }
        }
    }

    // Добавляем активный класс к текущей странице
    const currentPath = window.location.pathname;
    const currentPage = currentPath.substring(currentPath.lastIndexOf('/') + 1);

    document.querySelectorAll('.sidebar-menu-item').forEach(item => {
        item.classList.remove('active');
    });

    const activeItem = document.querySelector(`.sidebar-menu-item a[href*="${currentPage}"]`);
    if (activeItem) {
        activeItem.closest('.sidebar-menu-item').classList.add('active');
    }

    // Анимация прогресс-баров
    setTimeout(() => {
        document.querySelectorAll('.progress-fill').forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => {
                bar.style.width = width;
            }, 100);
        });
    }, 500);

    // Обновление при изменении размера окна
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            sidebar.style.transform = 'translateX(0)';
            const backdrop = document.querySelector('.sidebar-backdrop');
            if (backdrop) backdrop.remove();

            // Восстанавливаем отступы
            if (sidebar.classList.contains('compact')) {
                updateMainContentMargin(70);
            } else {
                updateMainContentMargin(280);
            }
        } else {
            sidebar.style.transform = 'translateX(-100%)';
            sidebar.classList.remove('mobile-open');
            sidebar.style.position = 'fixed';
            sidebar.style.top = '70px';

            // Сбрасываем отступы
            const mainContent = document.querySelector('.main-content');
            if (mainContent) mainContent.style.marginLeft = '0';
        }
    });

    // На десктопе сайдбар скроллится с контентом автоматически (position: absolute)
    // На мобильных - fixed

    // Инициализация при загрузке
    if (window.innerWidth > 992) {
        if (sidebar.classList.contains('compact')) {
            updateMainContentMargin(70);
        } else {
            updateMainContentMargin(280);
        }
    }
});
</script>
