<?php
// update.php - Система обновления HomeVlad Cloud
session_start();

// Определяем корневую директорию
define('ROOT_PATH', dirname(__DIR__)); // Корень сайта
define('ADMIN_PATH', __DIR__); // Папка admin
define('BACKUPS_PATH', ROOT_PATH . '/backups'); // Папка для бэкапов
define('UPDATES_PATH', ADMIN_PATH . '/updates'); // Папка с обновлениями

// Устанавливаем флаг для заголовка, чтобы не подгружать статистику
define('ON_DASHBOARD', false);

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Создаем необходимые папки, если их нет
function createRequiredDirectories() {
    // Создаем папку для обновлений
    if (!file_exists(UPDATES_PATH)) {
        mkdir(UPDATES_PATH, 0755, true);
    }
    
    // Создаем папку для бэкапов
    if (!file_exists(BACKUPS_PATH)) {
        mkdir(BACKUPS_PATH, 0755, true);
    }
    
    // Создаем папку для бэкапов обновлений
    $backup_updates_dir = BACKUPS_PATH . '/updates';
    if (!file_exists($backup_updates_dir)) {
        mkdir($backup_updates_dir, 0755, true);
    }
}

createRequiredDirectories();

// Создаем необходимые таблицы, если их нет
function createSystemTables($pdo) {
    try {
        // Таблица для хранения информации о версиях
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_versions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                version VARCHAR(20) NOT NULL UNIQUE,
                release_date DATE,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Таблица для хранения истории обновлений
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_updates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                version VARCHAR(20) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                update_type ENUM('upgrade', 'downgrade', 'patch') DEFAULT 'upgrade',
                description TEXT,
                success BOOLEAN DEFAULT TRUE,
                error_message TEXT,
                backup_path VARCHAR(255),
                UNIQUE KEY unique_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Добавляем текущую версию по умолчанию, если ее нет
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_versions WHERE version = '2.5.1'");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO system_versions (version, release_date, description)
                VALUES ('2.5.1', CURDATE(), 'Initial system version')
            ");
        }

    } catch (Exception $e) {
        error_log("Ошибка при создании таблиц системы: " . $e->getMessage());
    }
}

createSystemTables($pdo);

// Получаем текущую версию системы
function getCurrentVersion($pdo) {
    try {
        $stmt = $pdo->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1");
        if ($stmt->rowCount() > 0) {
            return $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Ошибка получения текущей версии: " . $e->getMessage());
    }
    return '2.5.1';
}

// Функция для сравнения версий
function compareVersions($version1, $version2) {
    $v1 = array_map('intval', explode('.', $version1));
    $v2 = array_map('intval', explode('.', $version2));

    for ($i = 0; $i < 3; $i++) {
        if ($v1[$i] > $v2[$i]) return 1;
        if ($v1[$i] < $v2[$i]) return -1;
    }
    return 0;
}

// Получаем список доступных обновлений
function getAvailableUpdates($pdo) {
    if (!file_exists(UPDATES_PATH)) {
        mkdir(UPDATES_PATH, 0755, true);
        return [];
    }

    $updates = [];
    $current_version = getCurrentVersion($pdo);

    // Сканируем папку updates на наличие папок с версиями
    $items = scandir(UPDATES_PATH);

    foreach ($items as $item) {
        $item_path = UPDATES_PATH . '/' . $item;
        
        // Проверяем, что это папка и соответствует формату версии X.Y.Z
        if ($item != '.' && $item != '..' && is_dir($item_path) && preg_match('/^\d+\.\d+\.\d+$/', $item)) {
            $version = $item;
            
            // Проверяем наличие хотя бы одного файла обновления
            $has_sql = file_exists($item_path . '/update.sql');
            $has_files = file_exists($item_path . '/files') && count(scandir($item_path . '/files')) > 2;
            
            // Если нет ни SQL ни файлов - пропускаем
            if (!$has_sql && !$has_files) {
                continue;
            }
            
            // Определяем тип обновления
            $comparison = compareVersions($version, $current_version);
            if ($comparison > 0) {
                $update_type = 'upgrade';
                $status_text = 'Обновление';
            } elseif ($comparison < 0) {
                $update_type = 'downgrade';
                $status_text = 'Даунгрейд';
            } else {
                $update_type = 'patch';
                $status_text = 'Патч';
            }

            // Получаем описание обновления
            $description = getUpdateDescription($item_path);

            // Проверяем, применено ли обновление
            $applied = checkUpdateApplied($pdo, $version);

            $updates[$version] = [
                'version' => $version,
                'path' => $item_path,
                'description' => $description,
                'update_type' => $update_type,
                'status_text' => $status_text,
                'has_sql' => $has_sql,
                'has_files' => $has_files,
                'applied' => $applied,
                'files_list' => $has_files ? getFilesList($item_path . '/files') : []
            ];
        }
    }

    // Сортируем по версии (новые версии первыми)
    uksort($updates, function($a, $b) {
        return compareVersions($b, $a);
    });

    return $updates;
}

// Получаем описание обновления
function getUpdateDescription($update_path) {
    $description_file = $update_path . '/description.txt';
    if (file_exists($description_file)) {
        $desc = file_get_contents($description_file);
        if (!empty(trim($desc))) {
            return trim($desc);
        }
    }
    
    $sql_file = $update_path . '/update.sql';
    if (file_exists($sql_file)) {
        $content = file_get_contents($sql_file);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '-- Description:') === 0) {
                return trim(str_replace('-- Description:', '', $line));
            }
            if (strpos($line, '-- description:') === 0) {
                return trim(str_replace('-- description:', '', $line));
            }
            if (strpos($line, '--') === 0 && strlen($line) > 2) {
                return trim(substr($line, 2));
            }
        }
    }
    
    return 'Обновление системы';
}

// Получаем список файлов в директории
function getFilesList($dir) {
    $files = [];
    if (!file_exists($dir)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative_path = str_replace($dir . '/', '', $file->getPathname());
            $files[] = [
                'path' => $relative_path,
                'size' => $file->getSize(),
                'modified' => $file->getMTime()
            ];
        }
    }

    return $files;
}

// Проверяем, применено ли обновление
function checkUpdateApplied($pdo, $version) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM system_updates WHERE version = ? AND success = 1");
        $stmt->execute([$version]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Вспомогательная функция для форматирования размера файла
function formatSize($bytes) {
    if ($bytes == 0) return '0 Б';
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Обработка действий
$current_version = getCurrentVersion($pdo);
$available_updates = getAvailableUpdates($pdo);

// Сортируем обновления по типу
$upgrades = array_filter($available_updates, fn($u) => $u['update_type'] === 'upgrade');
$downgrades = array_filter($available_updates, fn($u) => $u['update_type'] === 'downgrade');
$patches = array_filter($available_updates, fn($u) => $u['update_type'] === 'patch');

$title = "Обновление системы | Админ панель | HomeVlad Cloud";

// Подключаем заголовок
require_once 'admin_header.php';
?>

<!-- Подключаем сайдбар -->
<?php require_once 'admin_sidebar.php'; ?>

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

/* ========== ОСНОВНЫЕ СТИЛИ ========== */
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

@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }
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

.dashboard-action-btn-warning {
    background: linear-gradient(135deg, var(--db-warning), #d97706);
    color: white;
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.dashboard-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.dashboard-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    box-shadow: var(--db-shadow);
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
}

.dashboard-stat-content h3 {
    color: var(--db-text-secondary);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
}

.dashboard-stat-value {
    color: var(--db-text);
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.dashboard-stat-subtext {
    color: var(--db-text-muted);
    font-size: 12px;
    margin: 0;
}

.dashboard-stat-card-current .dashboard-stat-icon { background: var(--db-success); }
.dashboard-stat-card-upgrades .dashboard-stat-icon { background: var(--db-info); }
.dashboard-stat-card-downgrades .dashboard-stat-icon { background: var(--db-warning); }

.dashboard-main-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 25px;
    margin-bottom: 30px;
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

.dashboard-widget-body {
    padding: 20px;
}

.update-alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.update-alert-success {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--db-success);
}

.update-alert-error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--db-danger);
}

.update-alert-warning {
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: var(--db-warning);
}

.update-alert-info {
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--db-info);
}

.version-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.version-badge-upgrade { background: rgba(16, 185, 129, 0.15); color: var(--db-success); }
.version-badge-downgrade { background: rgba(245, 158, 11, 0.15); color: var(--db-warning); }
.version-badge-patch { background: rgba(59, 130, 246, 0.15); color: var(--db-info); }
.version-badge-applied { background: rgba(156, 163, 175, 0.15); color: var(--db-text-secondary); }

.update-item {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
}

.update-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.update-item-version {
    font-weight: 600;
    color: var(--db-text);
    font-size: 16px;
}

.update-item-actions {
    display: flex;
    gap: 8px;
}

.update-item-btn {
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
}

.update-item-btn-primary {
    background: linear-gradient(135deg, var(--db-success), #059669);
    color: white;
}

.update-item-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.update-item-description {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin-bottom: 10px;
}

.update-item-details {
    background: var(--db-card-bg);
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
    border: 1px solid var(--db-border);
    display: none;
}

.conflict-item {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 6px;
    padding: 10px;
    margin: 5px 0;
    font-size: 12px;
}

.conflict-item i {
    color: var(--db-danger);
    margin-right: 8px;
}

.file-list {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 200px;
    overflow-y: auto;
}

.file-list li {
    padding: 5px 0;
    border-bottom: 1px solid var(--db-border);
    font-size: 12px;
    color: var(--db-text-secondary);
    font-family: monospace;
}

.file-list li:last-child {
    border-bottom: none;
}

.no-updates {
    text-align: center;
    padding: 40px 20px;
    color: var(--db-text-secondary);
}

.no-updates i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}

.system-check-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-top: 15px;
}

.system-check-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
}

.system-check-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: white;
}

.system-check-icon-success { background: var(--db-success); }
.system-check-icon-warning { background: var(--db-warning); }

.recommendations-box {
    margin-top: 20px;
    padding: 15px;
    background: rgba(245, 158, 11, 0.1);
    border-radius: 8px;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.recommendations-box h4 {
    color: var(--db-warning);
    margin: 0 0 10px 0;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.recommendations-box ul {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin: 0;
    padding-left: 20px;
}

/* Стили для модального окна лога обновления */
.update-log-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.8);
}

.update-log-modal-content {
    background: var(--db-card-bg);
    margin: 50px auto;
    padding: 0;
    width: 90%;
    max-width: 1200px;
    max-height: 80vh;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--db-border);
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.update-log-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--db-hover);
}

.update-log-header h3 {
    margin: 0;
    color: var(--db-text);
    display: flex;
    align-items: center;
    gap: 10px;
}

.update-log-body {
    padding: 0;
    max-height: 60vh;
    overflow-y: auto;
}

.update-log-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--db-text-secondary);
    cursor: pointer;
    padding: 5px;
    border-radius: 4px;
}

.update-log-close:hover {
    background: var(--db-border);
    color: var(--db-text);
}

.log-entry {
    padding: 12px 20px;
    border-bottom: 1px solid var(--db-border);
    font-family: 'Courier New', monospace;
    font-size: 13px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.log-entry:last-child {
    border-bottom: none;
}

.log-time {
    color: var(--db-text-muted);
    min-width: 70px;
    flex-shrink: 0;
}

.log-message {
    flex-grow: 1;
    white-space: pre-wrap;
    word-break: break-word;
}

.log-type-start { background: rgba(59, 130, 246, 0.1); }
.log-type-info { background: transparent; }
.log-type-success { background: rgba(16, 185, 129, 0.1); color: var(--db-success); }
.log-type-warning { background: rgba(245, 158, 11, 0.1); color: var(--db-warning); }
.log-type-error { background: rgba(239, 68, 68, 0.1); color: var(--db-danger); }

.log-type-start .log-message { font-weight: bold; color: var(--db-info); }

.log-loader {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px;
    text-align: center;
}

.log-loader-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--db-border);
    border-top: 3px solid var(--db-accent);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.update-progress {
    height: 4px;
    background: var(--db-border);
    margin-top: 10px;
    border-radius: 2px;
    overflow: hidden;
}

.update-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--db-accent), #0097a7);
    width: 0%;
    transition: width 0.3s ease;
}

.log-actions {
    padding: 15px 20px;
    border-top: 1px solid var(--db-border);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* Результаты обновления файлов */
.update-results {
    margin-top: 20px;
    max-height: 300px;
    overflow-y: auto;
}

.update-result-item {
    padding: 8px 12px;
    margin: 5px 0;
    border-radius: 6px;
    font-size: 12px;
    font-family: monospace;
}

.update-result-success {
    background: rgba(16, 185, 129, 0.1);
    border-left: 3px solid var(--db-success);
    color: var(--db-success);
}

.update-result-error {
    background: rgba(239, 68, 68, 0.1);
    border-left: 3px solid var(--db-danger);
    color: var(--db-danger);
}
</style>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-sync-alt"></i> Обновление системы</h1>
            <p>Управление обновлениями HomeVlad Cloud. Путь к обновлениям: <?= htmlspecialchars(UPDATES_PATH) ?></p>
        </div>
        <div class="dashboard-quick-actions">
            <button type="button" onclick="checkUpdates()" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-search"></i> Проверить обновления
            </button>
        </div>
    </div>

    <!-- Основная сетка -->
    <div class="dashboard-main-grid">
        <!-- Карточки статистики -->
        <div class="dashboard-stats-grid">
            <div class="dashboard-stat-card dashboard-stat-card-current">
                <div class="dashboard-stat-header">
                    <div class="dashboard-stat-icon">
                        <i class="fas fa-tag"></i>
                    </div>
                    <?php if (count($upgrades) > 0): ?>
                    <span class="version-badge version-badge-upgrade">
                        <?= count($upgrades) ?> обновлений
                    </span>
                    <?php endif; ?>
                </div>
                <div class="dashboard-stat-content">
                    <h3>Текущая версия</h3>
                    <div class="dashboard-stat-value">v<?= htmlspecialchars($current_version) ?></div>
                    <p class="dashboard-stat-subtext">
                        Последняя проверка: <?= date('d.m.Y H:i:s') ?>
                    </p>
                </div>
            </div>

            <div class="dashboard-stat-card dashboard-stat-card-upgrades">
                <div class="dashboard-stat-header">
                    <div class="dashboard-stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <span class="version-badge version-badge-upgrade">
                        <?= count($upgrades) ?> доступно
                    </span>
                </div>
                <div class="dashboard-stat-content">
                    <h3>Обновления</h3>
                    <div class="dashboard-stat-value"><?= count($upgrades) ?></div>
                    <p class="dashboard-stat-subtext">
                        Новые версии системы
                    </p>
                </div>
            </div>

            <div class="dashboard-stat-card dashboard-stat-card-downgrades">
                <div class="dashboard-stat-header">
                    <div class="dashboard-stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <span class="version-badge version-badge-downgrade">
                        <?= count($downgrades) ?> доступно
                    </span>
                </div>
                <div class="dashboard-stat-content">
                    <h3>Даунгрейды</h3>
                    <div class="dashboard-stat-value"><?= count($downgrades) ?></div>
                    <p class="dashboard-stat-subtext">
                        Возврат к старым версиям
                    </p>
                </div>
            </div>
        </div>

        <!-- Список обновлений -->
        <div class="dashboard-widget">
            <div class="dashboard-widget-header">
                <h3><i class="fas fa-list"></i> Доступные обновления</h3>
                <span style="color: var(--db-text-secondary); font-size: 14px;">
                    Всего: <?= count($available_updates) ?>
                </span>
            </div>
            <div class="dashboard-widget-body">
                <?php if (empty($available_updates)): ?>
                <div class="no-updates">
                    <i class="fas fa-check-circle"></i>
                    <h3 style="margin: 0 0 10px 0;">Нет доступных обновлений</h3>
                    <p>Создайте папку с версией в <?= htmlspecialchars(UPDATES_PATH) ?>/ для добавления обновления</p>
                    <p style="font-size: 12px; margin-top: 10px; font-family: monospace;">
                        Пример структуры:<br>
                        <?= htmlspecialchars(UPDATES_PATH) ?>/2.5.2/<br>
                        ├── update.sql (опционально)<br>
                        ├── description.txt (опционально)<br>
                        └── files/ (папка с файлами для обновления)<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;├── index.php<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;├── css/style.css<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;└── ...
                    </p>
                </div>
                <?php else: ?>
                    <?php foreach ($available_updates as $version => $update): ?>
                    <div class="update-item">
                        <div class="update-item-header">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="update-item-version">v<?= htmlspecialchars($version) ?></div>
                                <span class="version-badge version-badge-<?= $update['update_type'] ?>">
                                    <?= htmlspecialchars($update['status_text']) ?>
                                </span>
                                <?php if ($update['applied']): ?>
                                <span class="version-badge version-badge-applied">Применено</span>
                                <?php endif; ?>
                            </div>
                            <div class="update-item-actions">
                                <?php if (!$update['applied']): ?>
                                <button type="button" onclick="applyUpdate('<?= htmlspecialchars($version) ?>')" 
                                        class="update-item-btn update-item-btn-primary">
                                    <i class="fas fa-play"></i> Применить
                                </button>
                                <?php endif; ?>
                                <button type="button" class="update-item-btn update-item-btn-secondary btn-show-details"
                                        data-version="<?= htmlspecialchars($version) ?>">
                                    <i class="fas fa-info-circle"></i> Подробнее
                                </button>
                            </div>
                        </div>

                        <div class="update-item-description">
                            <?= htmlspecialchars($update['description']) ?>
                        </div>

                        <div class="update-item-details" id="details-<?= htmlspecialchars($version) ?>">
                            <div style="margin-bottom: 15px;">
                                <strong><i class="fas fa-database"></i> SQL запросы:</strong>
                                <?= $update['has_sql'] ? 'Да' : 'Нет' ?>
                            </div>

                            <?php if ($update['has_files']): ?>
                            <div style="margin-bottom: 15px;">
                                <strong><i class="fas fa-file"></i> Файлы для обновления (<?= count($update['files_list']) ?>):</strong>
                                <ul class="file-list">
                                    <?php foreach ($update['files_list'] as $file): ?>
                                    <li><?= htmlspecialchars($file['path']) ?> (<?= formatSize($file['size']) ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if ($update['update_type'] === 'downgrade'): ?>
                            <div style="color: var(--db-warning); font-size: 12px; padding: 10px; background: rgba(245, 158, 11, 0.1); border-radius: 6px;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Внимание:</strong> Это даунгрейд - возврат к более старой версии системы.
                                Могут возникнуть проблемы с совместимостью данных.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Проверка системы -->
        <div class="dashboard-widget">
            <div class="dashboard-widget-header">
                <h3><i class="fas fa-shield-alt"></i> Проверка системы</h3>
            </div>
            <div class="dashboard-widget-body">
                <p style="color: var(--db-text-secondary); font-size: 14px; margin-bottom: 15px;">
                    Перед применением обновлений рекомендуется проверить состояние системы:
                </p>
                <div class="system-check-grid">
                    <div class="system-check-item">
                        <div class="system-check-icon system-check-icon-success">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--db-text); font-size: 12px;">Доступность базы данных</div>
                            <div style="color: var(--db-text-secondary); font-size: 10px;">Соединение установлено</div>
                        </div>
                    </div>
                    <div class="system-check-item">
                        <div class="system-check-icon <?= is_writable(ROOT_PATH) ? 'system-check-icon-success' : 'system-check-icon-warning' ?>">
                            <i class="fas fa-<?= is_writable(ROOT_PATH) ? 'check' : 'exclamation' ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--db-text); font-size: 12px;">Права на запись</div>
                            <div style="color: var(--db-text-secondary); font-size: 10px;">
                                <?= is_writable(ROOT_PATH) ? 'Корневая папка доступна для записи' : 'Проверьте права на корневую папку' ?>
                            </div>
                        </div>
                    </div>
                    <div class="system-check-item">
                        <div class="system-check-icon <?= is_writable(BACKUPS_PATH) ? 'system-check-icon-success' : 'system-check-icon-warning' ?>">
                            <i class="fas fa-<?= is_writable(BACKUPS_PATH) ? 'check' : 'exclamation' ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--db-text); font-size: 12px;">Папка бэкапов</div>
                            <div style="color: var(--db-text-secondary); font-size: 10px;">
                                <?= is_writable(BACKUPS_PATH) ? 'Доступна для записи' : 'Недоступна для записи' ?>
                            </div>
                        </div>
                    </div>
                    <div class="system-check-item">
                        <div class="system-check-icon <?= is_writable(UPDATES_PATH) ? 'system-check-icon-success' : 'system-check-icon-warning' ?>">
                            <i class="fas fa-<?= is_writable(UPDATES_PATH) ? 'check' : 'exclamation' ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--db-text); font-size: 12px;">Папка обновлений</div>
                            <div style="color: var(--db-text-secondary); font-size: 10px;">
                                <?= is_writable(UPDATES_PATH) ? 'Доступна для записи' : 'Недоступна для записи' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="recommendations-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> Рекомендации</h4>
                    <ul>
                        <li>Перед обновлением обязательно создайте полную резервную копию сайта и базы данных</li>
                        <li>Обновления применяются автоматически и папка с обновлением удаляется после успешного применения</li>
                        <li>Бэкапы файлов сохраняются в папке: <?= htmlspecialchars(BACKUPS_PATH) ?>/</li>
                        <li>Даунгрейды выполняйте только при крайней необходимости</li>
                        <li>После обновления проверьте работу всех функций системы</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно лога обновления -->
<div id="updateLogModal" class="update-log-modal">
    <div class="update-log-modal-content">
        <div class="update-log-header">
            <h3><i class="fas fa-sync-alt"></i> Применение обновления</h3>
            <button class="update-log-close" onclick="closeUpdateLog()">&times;</button>
        </div>
        <div class="update-log-body" id="updateLogBody">
            <div class="log-loader" id="logLoader">
                <div class="log-loader-spinner"></div>
                <p>Подготовка к обновлению...</p>
            </div>
            <div id="logEntries"></div>
        </div>
        <div class="update-progress">
            <div class="update-progress-bar" id="updateProgressBar"></div>
        </div>
        <div class="log-actions">
            <button onclick="closeUpdateLog()" class="dashboard-action-btn dashboard-action-btn-secondary" id="closeBtn" style="display: none;">
                <i class="fas fa-times"></i> Закрыть
            </button>
            <button onclick="location.reload()" class="dashboard-action-btn dashboard-action-btn-primary" id="reloadBtn" style="display: none;">
                <i class="fas fa-redo"></i> Обновить страницу
            </button>
        </div>
    </div>
</div>

<script>
let logEntries = [];
let updateInProgress = false;

function checkUpdates() {
    location.reload();
}

function applyUpdate(version) {
    if (updateInProgress) {
        alert('Обновление уже выполняется. Пожалуйста, дождитесь завершения.');
        return;
    }
    
    if (!confirm(`Применить обновление до версии ${version}?\n\nПеред продолжением убедитесь, что создана резервная копия системы!`)) {
        return;
    }
    
    updateInProgress = true;
    logEntries = [];
    document.getElementById('logLoader').style.display = 'flex';
    document.getElementById('logEntries').innerHTML = '';
    document.getElementById('closeBtn').style.display = 'none';
    document.getElementById('reloadBtn').style.display = 'none';
    document.getElementById('updateProgressBar').style.width = '0%';
    
    showUpdateLog();
    
    // Начинаем загрузку лога
    fetchUpdateLog(version);
}

function fetchUpdateLog(version) {
    const formData = new FormData();
    formData.append('version', version);
    
    fetch('ajax_apply_update.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            addLogEntry('success', 'Обновление успешно завершено!');
            document.getElementById('updateProgressBar').style.width = '100%';
            document.getElementById('reloadBtn').style.display = 'inline-block';
        } else {
            addLogEntry('error', 'Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            document.getElementById('updateProgressBar').style.width = '100%';
            document.getElementById('closeBtn').style.display = 'inline-block';
        }
        
        // Отображаем весь лог
        if (data.log && data.log.length > 0) {
            data.log.forEach(entry => {
                addLogEntry(entry.type, `[${entry.time}] ${entry.message}`);
            });
        }
        
        updateInProgress = false;
        document.getElementById('logLoader').style.display = 'none';
    })
    .catch(error => {
        addLogEntry('error', 'Ошибка подключения: ' + error.message);
        document.getElementById('updateProgressBar').style.width = '100%';
        document.getElementById('closeBtn').style.display = 'inline-block';
        document.getElementById('logLoader').style.display = 'none';
        updateInProgress = false;
    });
}

function addLogEntry(type, message) {
    const entry = {
        id: Date.now() + Math.random(),
        type: type,
        message: message,
        time: new Date().toLocaleTimeString()
    };
    
    logEntries.push(entry);
    
    const logEntriesDiv = document.getElementById('logEntries');
    const entryDiv = document.createElement('div');
    entryDiv.className = `log-entry log-type-${type}`;
    entryDiv.id = `log-${entry.id}`;
    
    entryDiv.innerHTML = `
        <div class="log-time">${entry.time}</div>
        <div class="log-message">${escapeHtml(message)}</div>
    `;
    
    logEntriesDiv.appendChild(entryDiv);
    
    // Прокручиваем к последнему сообщению
    entryDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    // Обновляем прогресс бар (простая имитация прогресса)
    if (logEntries.length > 0) {
        const progress = Math.min(90, (logEntries.length / 30) * 90);
        document.getElementById('updateProgressBar').style.width = progress + '%';
    }
}

function showUpdateLog() {
    document.getElementById('updateLogModal').style.display = 'block';
}

function closeUpdateLog() {
    document.getElementById('updateLogModal').style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Показ/скрытие подробностей обновления
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-show-details').forEach(btn => {
        btn.addEventListener('click', function() {
            const version = this.getAttribute('data-version');
            const details = document.getElementById('details-' + version);
            const isActive = details.style.display === 'block';

            // Скрываем все детали
            document.querySelectorAll('.update-item-details').forEach(d => {
                d.style.display = 'none';
            });

            // Обновляем все кнопки
            document.querySelectorAll('.btn-show-details').forEach(b => {
                b.innerHTML = '<i class="fas fa-info-circle"></i> Подробнее';
            });

            // Показываем/скрываем выбранные детали
            if (!isActive) {
                details.style.display = 'block';
                this.innerHTML = '<i class="fas fa-eye-slash"></i> Скрыть';
            }
        });
    });
});

// Закрытие по клику вне окна
window.onclick = function(event) {
    const modal = document.getElementById('updateLogModal');
    if (event.target === modal) {
        closeUpdateLog();
    }
}

// Закрытие по Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeUpdateLog();
    }
});
</script>

<?php
require_once 'admin_footer.php';
?>