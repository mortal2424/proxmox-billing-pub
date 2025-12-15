<?php
if (!isset($title)) {
    $title = 'Админ панель | HomeVlad Cloud';
}

// Не начинаем сессию - она уже должна быть запущена в основном файле
// Просто используем данные из существующей сессии
$admin_name = $_SESSION['username'] ?? 'Администратор';
$admin_role = $_SESSION['role'] ?? 'admin';
$admin_email = $_SESSION['email'] ?? '';
$admin_id = $_SESSION['user_id'] ?? 0;

// Статистика для шапки
$header_stats = [
    'total_users' => 0,
    'total_vms' => 0,
    'active_vms' => 0,
    'open_tickets' => 0,
    'available_updates' => 0
];

// ДОБАВИМ: Подключаем функции для безопасных запросов
require_once '../includes/functions.php';

// Получаем статистику ТОЛЬКО если мы на странице, отличной от дашборда
// и у нас есть права администратора
if (($admin_role === 'admin' || $admin_role === 'superadmin') && !defined('ON_DASHBOARD')) {
    require_once '../includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();

    try {
        // Статистика пользователей
        $header_stats['total_users'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users")->fetchColumn();

        // Статистика ВМ
        $header_stats['total_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms")->fetchColumn();
        $header_stats['active_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'running'")->fetchColumn();

        // Статистика по тикетам
        if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
            $header_stats['open_tickets'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
        }
        
        // ДОБАВЛЕНО: Статистика по обновлениям
        $updates_path = dirname(__DIR__) . '/admin/updates';
        $header_stats['available_updates'] = 0;
        
        if (file_exists($updates_path)) {
            // Проверяем, существует ли таблица system_updates
            $table_exists = safeQuery($pdo, "SHOW TABLES LIKE 'system_updates'")->rowCount() > 0;
            $applied_versions = [];
            
            if ($table_exists) {
                // Получаем список примененных версий
                $applied_versions = safeQuery($pdo, "SELECT version FROM system_updates WHERE success = 1")->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Сканируем папку обновлений
            $folders = scandir($updates_path);
            foreach ($folders as $folder) {
                $folder_path = $updates_path . '/' . $folder;
                
                // Проверяем, что это папка и соответствует формату версии X.Y.Z
                if ($folder != '.' && $folder != '..' && is_dir($folder_path) && preg_match('/^\d+\.\d+\.\d+$/', $folder)) {
                    // Проверяем, применено ли обновление
                    if (!in_array($folder, $applied_versions)) {
                        $header_stats['available_updates']++;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        // В случае ошибки используем значения по умолчанию
        error_log("Ошибка получения статистики для шапки: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="/img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/admin/css/admin_style.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        /* ОСНОВНЫЕ ПЕРЕМЕННЫЕ - БОЛЕЕ КОНТРАСТНЫЕ ЦВЕТА */
        :root {
            --admin-header-bg: #f8fafc;
            --admin-header-bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            --admin-header-text: #1e293b;
            --admin-header-text-secondary: #475569;
            --admin-header-border: #cbd5e1;
            --admin-accent: #0ea5e9;
            --admin-accent-hover: #0284c7;
            --admin-accent-light: rgba(14, 165, 233, 0.15);
            --admin-danger: #ef4444;
            --admin-success: #10b981;
            --admin-warning: #f59e0b;
            --admin-info: #3b82f6;
            --admin-purple: #8b5cf6;
            --admin-update: #8b5cf6; /* Новый цвет для обновлений */
            --admin-card-bg: #ffffff;
            --admin-hover-bg: #f1f5f9;
        }

        [data-theme="dark"] {
            --admin-header-bg: #1e293b;
            --admin-header-bg-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --admin-header-text: #f1f5f9;
            --admin-header-text-secondary: #cbd5e1;
            --admin-header-border: #334155;
            --admin-accent: #38bdf8;
            --admin-accent-hover: #0ea5e9;
            --admin-accent-light: rgba(56, 189, 248, 0.15);
            --admin-card-bg: #1e293b;
            --admin-hover-bg: #2d3748;
            --admin-update: #a78bfa; /* Более светлый фиолетовый для темной темы */
        }

        /* ========== ШАПКА ========== */
        .admin-header.modern-header {
            background: var(--admin-header-bg-gradient);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 70px;
            width: 100%;
            border-bottom: 1px solid var(--admin-header-border);
        }

        .header-container {
            max-width: 100%;
            padding: 0 24px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ========== ЛОГОТИП ========== */
        .admin-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .admin-logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .admin-logo-text {
            display: flex;
            flex-direction: column;
        }

        .admin-logo-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--admin-header-text);
            line-height: 1.2;
        }

        .admin-logo-subtitle {
            font-size: 12px;
            color: var(--admin-header-text-secondary);
            font-weight: 400;
        }

        /* ========== ЦЕНТРАЛЬНАЯ ОБЛАСТЬ ========== */
        .header-center {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            gap: 12px;
        }

        /* ========== БЛОК СТАТИСТИКИ ========== */
        .admin-stats-block {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--admin-card-bg);
            border-radius: 12px;
            padding: 8px 20px;
            border: 1px solid var(--admin-header-border);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .admin-stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            min-width: 110px;
            cursor: default;
        }

        .admin-stat-icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: white;
        }

        .admin-stat-icon.users { background: linear-gradient(135deg, var(--admin-info), #2563eb); }
        .admin-stat-icon.vms { background: linear-gradient(135deg, var(--admin-success), #059669); }
        .admin-stat-icon.active { background: linear-gradient(135deg, var(--admin-warning), #d97706); }
        .admin-stat-icon.tickets { background: linear-gradient(135deg, var(--admin-danger), #dc2626); }

        .admin-stat-info {
            display: flex;
            flex-direction: column;
        }

        .admin-stat-value {
            font-weight: 700;
            font-size: 14px;
            color: var(--admin-header-text);
            line-height: 1.2;
        }

        .admin-stat-label {
            font-size: 10px;
            color: var(--admin-header-text-secondary);
            font-weight: 500;
            line-height: 1.2;
        }

        /* ========== БЛОК БЫСТРЫХ ДЕЙСТВИЙ ========== */
        .admin-quick-block {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--admin-card-bg);
            border-radius: 12px;
            padding: 8px 20px;
            border: 1px solid var(--admin-header-border);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .admin-quick-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-quick-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-header-border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--admin-header-text-secondary);
            font-size: 16px;
            transition: all 0.2s ease;
            text-decoration: none;
            position: relative; /* Для бейджа */
        }

        .admin-quick-btn:hover {
            background: var(--admin-hover-bg);
            color: var(--admin-accent);
            border-color: var(--admin-accent);
            transform: translateY(-1px);
        }

        /* ДОБАВЛЕНО: Стиль для бейджа обновлений */
        .admin-update-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, var(--admin-update), #7c3aed);
            color: white;
            font-size: 11px;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--admin-header-bg);
            z-index: 1;
        }

        /* ========== УВЕДОМЛЕНИЯ ========== */
        .admin-notifications {
            position: relative;
        }

        .admin-notification-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-header-border);
            color: var(--admin-header-text-secondary);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .admin-notification-btn:hover {
            background: var(--admin-hover-bg);
            color: var(--admin-accent);
            border-color: var(--admin-accent);
        }

        .admin-notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, var(--admin-danger), #dc2626);
            color: white;
            font-size: 11px;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--admin-header-bg);
        }

        /* ========== ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ ========== */
        .admin-theme-switcher {
            display: flex;
            align-items: center;
        }

        .admin-theme-label {
            width: 60px;
            height: 32px;
            background: var(--admin-hover-bg);
            border-radius: 20px;
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 0 2px;
            transition: all 0.3s ease;
            border: 1px solid var(--admin-header-border);
        }

        .admin-theme-label:hover {
            background: var(--admin-accent-light);
            border-color: var(--admin-accent);
        }

        .admin-theme-label i {
            position: absolute;
            font-size: 14px;
            color: var(--admin-header-text-secondary);
            z-index: 1;
            transition: all 0.3s ease;
        }

        .admin-theme-label .fa-sun {
            left: 10px;
        }

        .admin-theme-label .fa-moon {
            right: 10px;
        }

        .admin-theme-ball {
            width: 24px;
            height: 24px;
            background: var(--admin-accent);
            border-radius: 50%;
            position: absolute;
            left: 4px;
            transition: transform 0.4s ease;
            z-index: 2;
        }

        #adminThemeToggle:checked + .admin-theme-label .admin-theme-ball {
            transform: translateX(28px);
        }

        #adminThemeToggle:checked + .admin-theme-label .fa-sun {
            color: var(--admin-warning);
        }

        #adminThemeToggle:checked + .admin-theme-label .fa-moon {
            color: var(--admin-header-text);
        }

        /* ========== ПРОФИЛЬ АДМИНИСТРАТОРА ========== */
        .admin-profile {
            position: relative;
        }

        .admin-profile-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-header-border);
            border-radius: 12px;
            padding: 8px 16px 8px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 44px;
            min-width: 180px;
        }

        .admin-profile-btn:hover {
            background: var(--admin-hover-bg);
            border-color: var(--admin-accent);
        }

        .admin-avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .admin-info-short {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            flex: 1;
            min-width: 0;
        }

        .admin-name {
            color: var(--admin-header-text);
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .admin-role {
            color: var(--admin-header-text-secondary);
            font-size: 12px;
            font-weight: 500;
        }

        .admin-dropdown-arrow {
            color: var(--admin-header-text-secondary);
            font-size: 12px;
            transition: transform 0.3s ease;
            flex-shrink: 0;
        }

        .admin-profile:hover .admin-dropdown-arrow {
            transform: rotate(180deg);
            color: var(--admin-accent);
        }

        /* ========== ВЫПАДАЮЩЕЕ МЕНЮ ПРОФИЛЯ ========== */
        .admin-profile-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 320px;
            background: var(--admin-card-bg);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--admin-header-border);
            margin-top: 10px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
            max-height: 800px;
        }

        .admin-profile-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .admin-profile-header {
            padding: 20px;
            background: var(--admin-hover-bg);
            border-bottom: 1px solid var(--admin-header-border);
        }

        .admin-avatar-large {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .admin-profile-info h4 {
            margin: 0 0 4px 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--admin-header-text);
        }

        .admin-email {
            font-size: 13px;
            color: var(--admin-header-text-secondary);
            margin-bottom: 10px;
            display: block;
        }

        .admin-badges {
            display: flex;
            gap: 8px;
        }

        .admin-role-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            background: var(--admin-accent-light);
            color: var(--admin-accent);
        }

        .super-admin-badge {
            background: linear-gradient(135deg, var(--admin-purple), #7c3aed);
            color: white;
        }

        /* ========== СТАТИСТИКА В МЕНЮ ========== */
        .admin-stats-menu {
            padding: 15px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            background: var(--admin-card-bg);
        }

        .admin-stat-menu-item {
            text-align: center;
            padding: 12px;
            background: var(--admin-hover-bg);
            border-radius: 10px;
            border: 1px solid var(--admin-header-border);
        }

        .admin-stat-menu-value {
            font-weight: 700;
            font-size: 18px;
            color: var(--admin-accent);
            display: block;
            margin-bottom: 4px;
        }

        .admin-stat-menu-label {
            font-size: 10px;
            color: var(--admin-header-text-secondary);
            font-weight: 500;
        }

        /* ========== ССЫЛКИ В МЕНЮ ========== */
        .admin-menu-links {
            padding: 0;
            background: var(--admin-card-bg);
        }

        .admin-menu-divider {
            height: 1px;
            background: var(--admin-header-border);
            margin: 0;
        }

        .admin-menu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            text-decoration: none;
            color: var(--admin-header-text);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--admin-header-border);
        }

        .admin-menu-link:last-child {
            border-bottom: none;
        }

        .admin-menu-link:hover {
            background: var(--admin-hover-bg);
            color: var(--admin-accent);
        }

        .admin-menu-link i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            color: var(--admin-header-text-secondary);
        }

        .admin-menu-link:hover i {
            color: var(--admin-accent);
        }

        /* ========== ФУТЕР МЕНЮ ========== */
        .admin-menu-footer {
            padding: 15px 20px;
            background: var(--admin-card-bg);
            border-top: 1px solid var(--admin-header-border);
        }

        .admin-logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            /*width: 100%;*/
            padding: 10px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            color: var(--admin-danger);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .admin-logout-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: var(--admin-danger);
        }

        /* ========== МЕНЮ УВЕДОМЛЕНИЙ ========== */
        .admin-notifications-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 300px;
            background: var(--admin-card-bg);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--admin-header-border);
            margin-top: 10px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
            max-height: 400px;
            overflow-y: auto;
        }

        .admin-notifications-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .admin-notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--admin-header-border);
            background: var(--admin-card-bg);
        }

        .admin-notifications-header h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--admin-header-text);
        }

        .admin-notifications-view-all {
            font-size: 12px;
            color: var(--admin-accent);
            text-decoration: none;
            font-weight: 500;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 1400px) {
            .admin-stat-item {
                min-width: 100px;
                padding: 5px 10px;
            }
        }

        @media (max-width: 1200px) {
            .header-center {
                gap: 8px;
            }

            .admin-quick-block,
            .admin-stats-block {
                padding: 6px 15px;
            }

            .admin-stat-item {
                min-width: 90px;
                padding: 4px 8px;
            }
        }

        @media (max-width: 992px) {
            .admin-stats-block {
                display: none;
            }

            .admin-quick-block {
                gap: 10px;
                padding: 6px 12px;
            }

            .admin-quick-actions {
                gap: 10px;
            }
        }

        @media (max-width: 768px) {
            .admin-header.modern-header {
                height: 65px;
            }

            .header-container {
                padding: 0 20px;
            }

            .header-center {
                position: static;
                transform: none;
                margin-left: auto;
                margin-right: 10px;
            }

            .admin-quick-actions {
                display: none;
            }

            .admin-profile-btn {
                min-width: auto;
                padding: 6px 12px;
                gap: 10px;
                height: 40px;
            }

            .admin-info-short {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .header-container {
                padding: 0 16px;
            }

            .admin-logo-text {
                display: none;
            }

            .admin-theme-switcher {
                display: none;
            }

            .admin-profile-menu,
            .admin-notifications-menu {
                position: fixed;
                top: auto;
                bottom: 0;
                right: 0;
                left: 0;
                width: 100%;
                margin: 0;
                border-radius: 16px 16px 0 0;
                max-height: 80vh;
            }
        }
    </style>
</head>
<body>
    <!-- Шапка админки -->
    <header class="admin-header modern-header">
        <div class="header-container">
            <!-- Логотип -->
            <a href="/admin/" class="admin-logo">
                <div class="admin-logo-icon">
                    <i class="fas fa-cloud"></i>
                </div>
                <div class="admin-logo-text">
                    <span class="admin-logo-title">HomeVlad Админ Панель</span>
                    <span class="admin-logo-subtitle">Cloud Admin</span>
                </div>
            </a>

            <!-- Центральная область с двумя блоками -->
            <div class="header-center">
                <!-- Блок статистики (скрыт на дашборде через JS) -->
                <div class="admin-stats-block" id="headerStatsBlock">
                    <div class="admin-stat-item">
                        <div class="admin-stat-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="admin-stat-info">
                            <span class="admin-stat-value"><?= htmlspecialchars($header_stats['total_users']) ?></span>
                            <span class="admin-stat-label">Пользователи</span>
                        </div>
                    </div>

                    <div class="admin-stat-item">
                        <div class="admin-stat-icon vms">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="admin-stat-info">
                            <span class="admin-stat-value"><?= htmlspecialchars($header_stats['total_vms']) ?></span>
                            <span class="admin-stat-label">Всего ВМ</span>
                        </div>
                    </div>

                    <div class="admin-stat-item">
                        <div class="admin-stat-icon active">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <div class="admin-stat-info">
                            <span class="admin-stat-value"><?= htmlspecialchars($header_stats['active_vms']) ?></span>
                            <span class="admin-stat-label">Активные ВМ</span>
                        </div>
                    </div>

                    <div class="admin-stat-item">
                        <div class="admin-stat-icon tickets">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <div class="admin-stat-info">
                            <span class="admin-stat-value"><?= htmlspecialchars($header_stats['open_tickets']) ?></span>
                            <span class="admin-stat-label">Открытые тикеты</span>
                        </div>
                    </div>
                </div>

                <!-- Блок быстрых действий -->
                <div class="admin-quick-block">
                    <!-- Быстрые действия -->
                    <div class="admin-quick-actions">
                        <a href="/admin/index.php" class="admin-quick-btn" title="Дашборд">
                            <i class="fas fa-tachometer-alt"></i>
                        </a>
                        <a href="/admin/users.php" class="admin-quick-btn" title="Пользователи">
                            <i class="fas fa-users"></i>
                        </a>
                        <a href="/admin/vms.php" class="admin-quick-btn" title="Серверы">
                            <i class="fas fa-server"></i>
                        </a>
                        <a href="/admin/payments.php" class="admin-quick-btn" title="Биллинг">
                            <i class="fas fa-credit-card"></i>
                        </a>
                        <!-- ДОБАВЛЕНО: Бейдж обновлений на иконке -->
                        <a href="/admin/update.php" class="admin-quick-btn" title="Обновление">
                            <i class="fas fa-sync"></i>
                            <?php if ($header_stats['available_updates'] > 0): ?>
                                <span class="admin-update-badge"><?= htmlspecialchars($header_stats['available_updates']) ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <!-- Уведомления -->
                    <div class="admin-notifications">
                        <button class="admin-notification-btn" id="adminNotificationBtn">
                            <i class="fas fa-bell"></i>
                            <?php if ($header_stats['open_tickets'] > 0): ?>
                                <span class="admin-notification-badge"><?= htmlspecialchars($header_stats['open_tickets']) ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="admin-notifications-menu" id="adminNotificationsMenu">
                            <div class="admin-notifications-header">
                                <h4>Уведомления</h4>
                                <a href="/admin/ticket.php" class="admin-notifications-view-all">Все тикеты</a>
                            </div>
                            <div style="padding: 15px;">
                                <?php
                                // Получаем последние тикеты для меню уведомлений
                                if (isset($pdo) && $admin_role === 'admin') {
                                    try {
                                        $recent_tickets = $pdo->query("
                                            SELECT t.id, t.subject, t.status, t.created_at, u.email
                                            FROM tickets t
                                            JOIN users u ON t.user_id = u.id
                                            WHERE t.status IN ('open', 'pending')
                                            ORDER BY t.created_at DESC
                                            LIMIT 5
                                        ")->fetchAll();

                                        if (!empty($recent_tickets)) {
                                            foreach ($recent_tickets as $ticket): ?>
                                                <a href="/admin/ticket.php?ticket_id=<?= $ticket['id'] ?>" class="admin-menu-link" style="margin-bottom: 8px; border-radius: 8px; border: 1px solid var(--admin-header-border); padding: 10px;">
                                                    <div style="flex: 1; min-width: 0;">
                                                        <div style="font-weight: 500; color: var(--admin-header-text); font-size: 13px; margin-bottom: 4px;">
                                                            #<?= $ticket['id'] ?> <?= htmlspecialchars(mb_substr($ticket['subject'], 0, 40)) ?>...
                                                        </div>
                                                        <div style="font-size: 11px; color: var(--admin-header-text-secondary); margin-bottom: 2px;">
                                                            <?= htmlspecialchars($ticket['email']) ?>
                                                        </div>
                                                        <div style="font-size: 10px; color: var(--admin-header-text-secondary);">
                                                            <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
                                                        </div>
                                                    </div>
                                                    <span style="background: <?= $ticket['status'] === 'open' ? '#10b981' : '#f59e0b' ?>; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px;">
                                                        <?= $ticket['status'] === 'open' ? 'Открыт' : 'В работе' ?>
                                                    </span>
                                                </a>
                                            <?php endforeach;
                                        } else {
                                            echo '<div style="padding: 20px; text-align: center; color: var(--admin-header-text-secondary);">Нет открытых тикетов</div>';
                                        }
                                    } catch (Exception $e) {
                                        echo '<div style="padding: 20px; text-align: center; color: var(--admin-header-text-secondary);">Ошибка загрузки тикетов</div>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Переключатель темы -->
                    <div class="admin-theme-switcher">
                        <input type="checkbox" id="adminThemeToggle" class="theme-checkbox" hidden>
                        <label for="adminThemeToggle" class="admin-theme-label">
                            <i class="fas fa-sun"></i>
                            <i class="fas fa-moon"></i>
                            <span class="admin-theme-ball"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Профиль администратора -->
            <div class="admin-profile">
                <button class="admin-profile-btn" id="adminProfileBtn">
                    <div class="admin-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="admin-info-short">
                        <span class="admin-name"><?= htmlspecialchars($admin_name) ?></span>
                        <span class="admin-role"><?= htmlspecialchars($admin_role) ?></span>
                    </div>
                    <i class="fas fa-chevron-down admin-dropdown-arrow"></i>
                </button>

                <div class="admin-profile-menu" id="adminProfileMenu">
                    <div class="admin-profile-header">
                        <div class="admin-avatar-large">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="admin-profile-info">
                            <h4><?= htmlspecialchars($admin_name) ?></h4>
                            <span class="admin-email"><?= htmlspecialchars($admin_email) ?></span>
                            <div class="admin-badges">
                                <span class="admin-role-badge <?= $admin_role === 'superadmin' ? 'super-admin-badge' : '' ?>">
                                    <?= htmlspecialchars($admin_role) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Статистика в меню -->
                    <div class="admin-stats-menu">
                        <div class="admin-stat-menu-item">
                            <span class="admin-stat-menu-value"><?= htmlspecialchars($header_stats['total_users']) ?></span>
                            <span class="admin-stat-menu-label">Пользователей</span>
                        </div>
                        <div class="admin-stat-menu-item">
                            <span class="admin-stat-menu-value"><?= htmlspecialchars($header_stats['total_vms']) ?></span>
                            <span class="admin-stat-menu-label">Всего ВМ</span>
                        </div>
                        <div class="admin-stat-menu-item">
                            <span class="admin-stat-menu-value"><?= htmlspecialchars($header_stats['active_vms']) ?></span>
                            <span class="admin-stat-menu-label">Активных ВМ</span>
                        </div>
                        <div class="admin-stat-menu-item">
                            <span class="admin-stat-menu-value"><?= htmlspecialchars($header_stats['open_tickets']) ?></span>
                            <span class="admin-stat-menu-label">Открытых тикетов</span>
                        </div>
                    </div>

                    <div class="admin-menu-divider"></div>

                    <div class="admin-menu-links">
                        <a href="/admin/dashboard.php" class="admin-menu-link">
                            <i class="fas fa-tachometer-alt"></i> Дашборд
                        </a>
                        <a href="/admin/users.php" class="admin-menu-link">
                            <i class="fas fa-users"></i> Пользователи
                        </a>
                        <a href="/admin/vms.php" class="admin-menu-link">
                            <i class="fas fa-server"></i> Серверы
                        </a>
                        <a href="/admin/payments.php" class="admin-menu-link">
                            <i class="fas fa-credit-card"></i> Биллинг
                        </a>
                        <a href="/admin/ticket.php" class="admin-menu-link">
                            <i class="fas fa-ticket-alt"></i> Тикеты
                            <?php if ($header_stats['open_tickets'] > 0): ?>
                                <span style="margin-left: auto; background: var(--admin-danger); color: white; font-size: 10px; padding: 2px 5px; border-radius: 8px;">
                                    <?= htmlspecialchars($header_stats['open_tickets']) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <a href="/admin/settings.php" class="admin-menu-link">
                            <i class="fas fa-cog"></i> Настройки
                        </a>
                    </div>

                    <div class="admin-menu-divider"></div>

                    <div class="admin-menu-footer">
                        <a href="/templates/dashboard.php" class="admin-menu-link" style="border: none; padding: 8px 20px; margin-bottom: 10px;">
                            <i class="fas fa-external-link-alt"></i> Перейти в лк
                        </a>
                        <a href="/login/logout.php" class="admin-logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Выйти
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Элементы
        const themeToggle = document.getElementById('adminThemeToggle');
        const profileBtn = document.getElementById('adminProfileBtn');
        const profileMenu = document.getElementById('adminProfileMenu');
        const notificationBtn = document.getElementById('adminNotificationBtn');
        const notificationMenu = document.getElementById('adminNotificationsMenu');
        const headerStatsBlock = document.getElementById('headerStatsBlock');

        // Проверка сохраненной темы
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            themeToggle.checked = true;
            document.documentElement.setAttribute('data-theme', 'dark');
        }

        // Переключатель темы
        themeToggle.addEventListener('change', function() {
            if (this.checked) {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            }
        });

        // Скрыть блок статистики в шапке на странице дашборда
        if (window.location.pathname.includes('/admin/index.php') ||
            window.location.pathname.includes('/admin/dashboard.php') ||
            window.location.pathname === '/admin/' ||
            window.location.pathname === '/admin') {
            if (headerStatsBlock) {
                headerStatsBlock.style.display = 'none';
            }
        }

        // Управление меню профиля
        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isVisible = profileMenu.classList.contains('show');

            // Закрываем другие меню
            if (notificationMenu.classList.contains('show')) {
                notificationMenu.classList.remove('show');
            }

            // Переключаем текущее меню
            if (!isVisible) {
                profileMenu.classList.add('show');
            } else {
                profileMenu.classList.remove('show');
            }
        });

        // Управление меню уведомлений
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isVisible = notificationMenu.classList.contains('show');

            // Закрываем другие меню
            if (profileMenu.classList.contains('show')) {
                profileMenu.classList.remove('show');
            }

            // Переключаем текущее меню
            if (!isVisible) {
                notificationMenu.classList.add('show');
            } else {
                notificationMenu.classList.remove('show');
            }
        });

        // Закрытие меню при клике вне
        document.addEventListener('click', function(e) {
            if (!profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
                profileMenu.classList.remove('show');
            }
            if (!notificationMenu.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationMenu.classList.remove('show');
            }
        });

        // Предотвращаем закрытие при клике внутри меню
        profileMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        notificationMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Закрытие меню при клике на ссылку внутри
        const menuLinks = document.querySelectorAll('.admin-menu-link');
        menuLinks.forEach(link => {
            link.addEventListener('click', function() {
                profileMenu.classList.remove('show');
                notificationMenu.classList.remove('show');
            });
        });

        // Обновление счетчика обновлений каждые 5 минут
        function updateUpdatesCounter() {
            // Только если мы на странице, где нужно обновлять счетчик
            if (<?= ($admin_role === 'admin' || $admin_role === 'superadmin') ? 'true' : 'false' ?>) {
                fetch('/admin/check_updates_count.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.count > 0) {
                            // Обновляем бейдж на иконке обновлений
                            const updateBadge = document.querySelector('.admin-update-badge');
                            const updateLink = document.querySelector('a[href="/admin/update.php"]');
                            
                            if (updateBadge) {
                                updateBadge.textContent = data.count;
                            } else if (updateLink) {
                                // Создаем бейдж если его нет
                                const badge = document.createElement('span');
                                badge.className = 'admin-update-badge';
                                badge.textContent = data.count;
                                updateLink.appendChild(badge);
                            }
                        } else if (data.count === 0) {
                            // Удаляем бейдж если обновлений нет
                            const updateBadge = document.querySelector('.admin-update-badge');
                            if (updateBadge) {
                                updateBadge.remove();
                            }
                        }
                    })
                    .catch(error => console.error('Ошибка обновления счетчика обновлений:', error));
            }
        }

        // Запускаем обновление счетчика каждые 5 минут
        setInterval(updateUpdatesCounter, 300000); // 5 минут = 300000 мс

        // Также обновляем при загрузке страницы
        setTimeout(updateUpdatesCounter, 5000); // Через 5 секунд после загрузки
    });
    </script>
</body>
</html>