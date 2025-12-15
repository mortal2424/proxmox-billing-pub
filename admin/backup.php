<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup_functions.php';

session_start();
checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Проверяем, является ли пользователь администратором
try {
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

if (!$user || !$user['is_admin']) {
    header('Location: /templates/access_denied.php');
    exit;
}

// Конфигурация резервного копирования
$backup_dir = BACKUP_DIR;
$project_root = PROJECT_ROOT;
$max_backups = MAX_BACKUPS;
$backup_types = [
    'full' => 'Полный бэкап (файлы + БД)',
    'files' => 'Только файлы',
    'db' => 'Только база данных'
];

// Создаем папку для бэкапов, если ее нет
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Функция логирования действий с бэкапами
/*function logBackupAction($pdo, $user_id, $action, $filename, $details) {
    try {
        // Создаем таблицу, если ее нет
        $createTableQuery = "
            CREATE TABLE IF NOT EXISTS backup_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                filename VARCHAR(255),
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($createTableQuery);

        // Вставляем запись
        $query = "INSERT INTO backup_logs (user_id, action, filename, details, ip_address)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $user_id,
            $action,
            $filename,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log backup action: " . $e->getMessage());
        return false;
    }
}*/

// Функция форматирования размера файла
function formatBytesss($bytes) {
    if ($bytes == 0) return '0 Bytes';

    $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Обработка действий
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

switch ($action) {
    case 'create':
        handleCreateBackup();
        break;
    case 'delete':
        deleteBackup();
        break;
    case 'download':
        downloadBackup();
        break;
    case 'sync_ftp':
        syncWithFTP();
        break;
    case 'restore':
        // Показываем модальное окно восстановления
        $message = 'Функция восстановления в разработке';
        $message_type = 'info';
        break;
    case 'save_auto_backup':
        saveAutoBackupSettings();
        break;
    case 'delete_schedule':
        deleteSchedule();
        break;
    case 'toggle_schedule':
        toggleSchedule();
        break;
    case 'run_now':
        runScheduleNow();
        break;
    case 'save_schedule':
        saveSchedule();
        break;
}

/**
 * Обработка создания бэкапа
 */
function handleCreateBackup() {
    global $backup_types, $message, $message_type, $pdo, $user_id;

    $type = $_POST['backup_type'] ?? 'full';
    $comment = trim($_POST['comment'] ?? '');

    if (!array_key_exists($type, $backup_types)) {
        $message = 'Неверный тип бэкапа';
        $message_type = 'error';
        return;
    }

    $result = createBackup($type, $comment, false);

    if ($result['success']) {
        $message = "Бэкап успешно создан: {$result['filename']}";
        $message_type = 'success';

        // Логируем действие
        logBackupAction($pdo, $user_id, 'create', $result['filename'], "Тип: {$type}, Комментарий: {$comment}");
    } else {
        $message = "Ошибка при создании бэкапа: " . $result['error'];
        $message_type = 'error';
    }
}

/**
 * Получение списка запланированных бэкапов
 */
function getScheduledBackups() {
    global $pdo;

    try {
        // Сначала создаем таблицу, если ее нет
        $query = "
            CREATE TABLE IF NOT EXISTS backup_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                schedule_type ENUM('daily', 'weekly', 'monthly', 'hourly') NOT NULL,
                schedule_time TIME NULL,
                schedule_day INT NULL,
                backup_type ENUM('full', 'files', 'db') NOT NULL DEFAULT 'full',
                is_active BOOLEAN DEFAULT 1,
                last_run DATETIME NULL,
                next_run DATETIME NULL,
                keep_count INT DEFAULT 10,
                comment TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($query);

        // Затем получаем данные
        $stmt = $pdo->query("
            SELECT * FROM backup_schedules
            ORDER BY is_active DESC, next_run ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Не удалось получить расписания: " . $e->getMessage());
        return [];
    }
}

/**
 * Сохранение расписания
 */
function saveSchedule() {
    global $pdo, $message, $message_type;

    $id = $_POST['id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $schedule_type = $_POST['schedule_type'] ?? 'daily';
    $schedule_time = $_POST['schedule_time'] ?? '03:00';
    $schedule_day = $_POST['schedule_day'] ?? null;
    $backup_type = $_POST['backup_type'] ?? 'full';
    $keep_count = (int)($_POST['keep_count'] ?? 10);
    $comment = trim($_POST['comment'] ?? '');

    // Валидация
    if (empty($name)) {
        $message = 'Введите название расписания';
        $message_type = 'error';
        return;
    }

    if (!in_array($schedule_type, ['daily', 'weekly', 'monthly', 'hourly'])) {
        $message = 'Неверный тип расписания';
        $message_type = 'error';
        return;
    }

    try {
        if ($id > 0) {
            // Обновление существующего
            $stmt = $pdo->prepare("
                UPDATE backup_schedules SET
                name = ?,
                schedule_type = ?,
                schedule_time = ?,
                schedule_day = ?,
                backup_type = ?,
                keep_count = ?,
                comment = ?,
                next_run = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name,
                $schedule_type,
                $schedule_time,
                $schedule_day,
                $backup_type,
                $keep_count,
                $comment,
                calculateNextRun($schedule_type, $schedule_time, $schedule_day),
                $id
            ]);

            $message = 'Расписание обновлено';
        } else {
            // Создание нового
            $stmt = $pdo->prepare("
                INSERT INTO backup_schedules
                (name, schedule_type, schedule_time, schedule_day, backup_type, keep_count, comment, next_run)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name,
                $schedule_type,
                $schedule_time,
                $schedule_day,
                $backup_type,
                $keep_count,
                $comment,
                calculateNextRun($schedule_type, $schedule_time, $schedule_day)
            ]);

            $message = 'Расписание создано';
        }

        $message_type = 'success';

    } catch (Exception $e) {
        $message = 'Ошибка сохранения расписания: ' . $e->getMessage();
        $message_type = 'error';
    }
}

/**
 * Удаление расписания
 */
function deleteSchedule() {
    global $pdo, $message, $message_type;

    $id = $_GET['id'] ?? 0;

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM backup_schedules WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Расписание удалено';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Ошибка удаления расписания: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

/**
 * Включение/выключение расписания
 */
function toggleSchedule() {
    global $pdo, $message, $message_type;

    $id = $_GET['id'] ?? 0;
    $action = $_GET['toggle'] ?? '';

    if ($id > 0 && in_array($action, ['activate', 'deactivate'])) {
        try {
            $is_active = $action === 'activate' ? 1 : 0;
            $stmt = $pdo->prepare("
                UPDATE backup_schedules SET is_active = ? WHERE id = ?
            ");
            $stmt->execute([$is_active, $id]);

            $message = $action === 'activate' ? 'Расписание активировано' : 'Расписание деактивировано';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Ошибка изменения статуса расписания: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

/**
 * Запуск расписания сейчас
 */
function runScheduleNow() {
    global $pdo, $message, $message_type, $user_id;

    $id = $_GET['id'] ?? 0;

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM backup_schedules WHERE id = ?");
            $stmt->execute([$id]);
            $schedule = $stmt->fetch();

            if ($schedule) {
                $result = createBackup($schedule['backup_type'], $schedule['comment'] . ' [По расписанию: ' . $schedule['name'] . ']', true);

                if ($result['success']) {
                    // Обновляем время последнего запуска
                    $updateStmt = $pdo->prepare("
                        UPDATE backup_schedules SET last_run = NOW(), next_run = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        calculateNextRun($schedule['schedule_type'], $schedule['schedule_time'], $schedule['schedule_day']),
                        $id
                    ]);

                    // Логируем действие
                    logBackupAction($pdo, $user_id, 'schedule_run', $result['filename'], "Расписание: {$schedule['name']}, Тип: {$schedule['backup_type']}");

                    $message = 'Бэкап выполнен успешно';
                    $message_type = 'success';
                } else {
                    $message = 'Ошибка выполнения бэкапа: ' . $result['error'];
                    $message_type = 'error';
                }
            }
        } catch (Exception $e) {
            $message = 'Ошибка запуска расписания: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

/**
 * Расчет следующего запуска
 */
function calculateNextRun($schedule_type, $schedule_time, $schedule_day = null) {
    $now = time();

    switch ($schedule_type) {
        case 'hourly':
            return date('Y-m-d H:i:00', strtotime('+1 hour', $now));

        case 'daily':
            $time = strtotime($schedule_time);
            $next = strtotime(date('Y-m-d') . ' ' . date('H:i', $time));
            if ($next <= $now) {
                $next = strtotime('+1 day', $next);
            }
            return date('Y-m-d H:i:s', $next);

        case 'weekly':
            $day = $schedule_day ?? 1; // 1 = понедельник
            $time = strtotime($schedule_time);
            $next = strtotime('next Monday +' . ($day - 1) . ' days', $now);
            $next = strtotime(date('Y-m-d', $next) . ' ' . date('H:i', $time));
            if ($next <= $now) {
                $next = strtotime('+1 week', $next);
            }
            return date('Y-m-d H:i:s', $next);

        case 'monthly':
            $day = $schedule_day ?? 1;
            $time = strtotime($schedule_time);
            $next = strtotime(date('Y-m-' . str_pad($day, 2, '0', STR_PAD_LEFT)), $now);
            if ($next <= $now) {
                $next = strtotime('+1 month', $next);
            }
            $next = strtotime(date('Y-m-d', $next) . ' ' . date('H:i', $time));
            return date('Y-m-d H:i:s', $next);

        default:
            return date('Y-m-d H:i:s', strtotime('+1 day', $now));
    }
}

/**
 * Получение списка бэкапов
 */
function getBackupList() {
    global $backup_dir;

    $backups = [];
    $files = glob($backup_dir . '*.zip');

    foreach ($files as $file) {
        $filename = basename($file);
        $size = filesize($file);
        $modified = filemtime($file);

        // Читаем метаданные из архива
        $meta = [];
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file) === TRUE) {
                $meta_json = $zip->getFromName('backup_meta.json');
                if ($meta_json) {
                    $meta = json_decode($meta_json, true);
                }
                $zip->close();
            }
        }

        $backups[] = [
            'filename' => $filename,
            'size' => $size,
            'modified' => $modified,
            'date' => date('d.m.Y H:i:s', $modified),
            'size_formatted' => formatBytesss($size),
            'type' => $meta['type'] ?? 'unknown',
            'comment' => $meta['comment'] ?? '',
            'is_auto' => $meta['is_auto'] ?? false,
            'files' => $meta['files'] ?? 'no',
            'database' => $meta['database'] ?? 'no'
        ];
    }

    // Сортируем по дате (новые сверху)
    usort($backups, function($a, $b) {
        return $b['modified'] <=> $a['modified'];
    });

    return $backups;
}

/**
 * Удаление бэкапа
 */
function deleteBackup() {
    global $backup_dir, $message, $message_type, $pdo, $user_id;

    $filename = $_GET['file'] ?? '';
    if (!$filename) {
        $message = 'Не указан файл для удаления';
        $message_type = 'error';
        return;
    }

    $filepath = $backup_dir . basename($filename);
    if (!file_exists($filepath)) {
        $message = 'Файл не найден';
        $message_type = 'error';
        return;
    }

    if (unlink($filepath)) {
        $message = 'Бэкап успешно удален';
        $message_type = 'success';
        logBackupAction($pdo, $user_id, 'delete', $filename, '');
    } else {
        $message = 'Ошибка при удалении файла';
        $message_type = 'error';
    }
}

/**
 * Скачивание бэкапа
 */
function downloadBackup() {
    global $backup_dir, $pdo, $user_id;

    $filename = $_GET['file'] ?? '';
    if (!$filename) {
        die('Не указан файл для скачивания');
    }

    $filepath = $backup_dir . basename($filename);
    if (!file_exists($filepath)) {
        die('Файл не найден');
    }

    // Логируем скачивание
    logBackupAction($pdo, $user_id, 'download', $filename, '');

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');

    readfile($filepath);
    exit;
}

/**
 * Синхронизация с FTP
 */
function syncWithFTP() {
    global $backup_dir, $message, $message_type, $pdo, $user_id;

    $host = $_POST['ftp_host'] ?? '';
    $username = $_POST['ftp_username'] ?? '';
    $password = $_POST['ftp_password'] ?? '';
    $remote_dir = $_POST['ftp_dir'] ?? '/';
    $filename = $_POST['backup_file'] ?? '';

    if (empty($host) || empty($username) || empty($filename)) {
        $message = 'Заполните обязательные поля FTP';
        $message_type = 'error';
        return;
    }

    $local_file = $backup_dir . basename($filename);
    if (!file_exists($local_file)) {
        $message = 'Локальный файл не найден';
        $message_type = 'error';
        return;
    }

    try {
        $conn = ftp_connect($host);
        if (!$conn) {
            throw new Exception("Не удалось подключиться к FTP серверу");
        }

        if (!ftp_login($conn, $username, $password)) {
            throw new Exception("Неверные учетные данные FTP");
        }

        ftp_pasv($conn, true);

        $remote_path = rtrim($remote_dir, '/') . '/' . basename($filename);
        $dir_path = dirname($remote_path);

        if (!@ftp_chdir($conn, $dir_path)) {
            $dirs = explode('/', trim($dir_path, '/'));
            $current_path = '';
            foreach ($dirs as $dir) {
                $current_path .= '/' . $dir;
                if (!@ftp_chdir($conn, $current_path)) {
                    @ftp_mkdir($conn, $current_path);
                    @ftp_chdir($conn, $current_path);
                }
            }
        }

        if (!ftp_put($conn, $remote_path, $local_file, FTP_BINARY)) {
            throw new Exception("Ошибка при загрузке файла на FTP");
        }

        ftp_close($conn);

        $message = "Бэкап успешно загружен на FTP сервер";
        $message_type = 'success';
        logBackupAction($pdo, $user_id, 'ftp_upload', $filename, $host);

    } catch (Exception $e) {
        $message = "Ошибка FTP: " . $e->getMessage();
        $message_type = 'error';

        if (isset($conn)) {
            @ftp_close($conn);
        }
    }
}

/**
 * Получение FTP бэкапов
 */
function getFTPBackups() {
    return [];
}

/**
 * Сохранение настроек авто-бэкапа
 */
function saveAutoBackupSettings() {
    global $message, $message_type;

    // Для совместимости со старым кодом
    $message = 'Используйте новую систему планирования';
    $message_type = 'info';
}

// Получаем список бэкапов и расписаний
$backups = getBackupList();
$schedules = getScheduledBackups();
$ftp_backups = getFTPBackups();

$title = "Резервное копирование | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* Добавляем новые стили для расписаний */
.schedule-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.schedule-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.schedule-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.schedule-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--db-text);
}

.schedule-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.schedule-status-active {
    background: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.schedule-status-inactive {
    background: rgba(148, 163, 184, 0.2);
    color: var(--db-text-secondary);
}

.schedule-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin-bottom: 15px;
}

.schedule-detail {
    font-size: 14px;
    color: var(--db-text-secondary);
}

.schedule-detail strong {
    color: var(--db-text);
}

.schedule-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Стили для модального окна расписания */
.schedule-form-modal .modal-dialog {
    max-width: 600px;
}

/* Адаптивность */
@media (max-width: 768px) {
    .schedule-details {
        grid-template-columns: 1fr;
    }

    .schedule-actions {
        flex-direction: column;
    }

    .schedule-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

/* Остальные стили остаются без изменений */
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

.backup-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.backup-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    box-shadow: var(--db-shadow);
}

.backup-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--db-shadow-hover);
}

.backup-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 15px;
    color: white;
}

.backup-stat-icon-success { background: var(--db-success); }
.backup-stat-icon-warning { background: var(--db-warning); }
.backup-stat-icon-info { background: var(--db-info); }
.backup-stat-icon-danger { background: var(--db-danger); }

.backup-stat-value {
    color: var(--db-text);
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px 0;
}

.backup-stat-label {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.backup-form-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

@media (max-width: 992px) {
    .backup-form-container {
        grid-template-columns: 1fr;
    }
}

.backup-form-section {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    padding: 24px;
    box-shadow: var(--db-shadow);
    margin-bottom: 30px !important;
}

.section-title {
    color: var(--db-text);
    font-size: 18px;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--db-border);
}

.section-title i {
    color: var(--db-accent);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    color: var(--db-text);
    font-weight: 500;
    font-size: 14px;
}

.form-select,
.form-input,
.form-textarea {
    width: 87%;
    padding: 12px 16px;
    border: 1px solid var(--db-border);
    border-radius: 8px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-select:focus,
.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px var(--db-accent-light);
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
    font-family: inherit;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--db-border);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.btn-secondary:hover {
    background: var(--db-hover);
    border-color: var(--db-accent);
}

.btn-success {
    background: linear-gradient(135deg, var(--db-success), #059669);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--db-danger), #dc2626);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, var(--db-warning), #d97706);
    color: white;
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

.backup-table-container {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    overflow: hidden;
    box-shadow: var(--db-shadow);
    margin-top: 30px;
}

.backup-table {
    width: 100%;
    border-collapse: collapse;
}

.backup-table thead th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
    background: var(--db-hover);
}

.backup-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.backup-table tbody tr:hover {
    background: var(--db-hover);
}

.backup-table tbody td {
    color: var(--db-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

.backup-type-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.backup-type-full { background: rgba(139, 92, 246, 0.2); color: var(--db-purple); }
.backup-type-files { background: rgba(59, 130, 246, 0.2); color: var(--db-info); }
.backup-type-db { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }

.backup-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.backup-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    color: var(--db-text);
    text-decoration: none;
    transition: all 0.3s ease;
    font-size: 14px;
}

.backup-action-btn:hover {
    background: var(--db-hover);
    border-color: var(--db-accent);
    color: var(--db-accent);
    transform: translateY(-2px);
}

.backup-action-btn-success:hover { color: var(--db-success); border-color: var(--db-success); }
.backup-action-btn-warning:hover { color: var(--db-warning); border-color: var(--db-warning); }
.backup-action-btn-danger:hover { color: var(--db-danger); border-color: var(--db-danger); }

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border: 1px solid transparent;
    animation: fadeIn 0.3s ease;
}

.alert-success {
    background-color: rgba(16, 185, 129, 0.1);
    border-color: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.alert-error {
    background-color: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.alert-warning {
    background-color: rgba(245, 158, 11, 0.1);
    border-color: rgba(245, 158, 11, 0.2);
    color: var(--db-warning);
}

.alert-info {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.2);
    color: var(--db-info);
}

.alert i {
    font-size: 18px;
    margin-top: 2px;
}

.alert strong {
    font-weight: 600;
}

.alert p {
    margin: 4px 0 0 0;
    font-size: 14px;
}

.progress-container {
    margin: 20px 0;
}

.progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    color: var(--db-text);
    font-size: 14px;
}

.progress-bar {
    height: 8px;
    background: var(--db-border);
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--db-success), var(--db-accent));
    border-radius: 4px;
    transition: width 0.3s ease;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--db-text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    color: var(--db-text);
    margin: 0 0 8px 0;
    font-size: 18px;
}

.empty-state p {
    margin: 0;
    font-size: 14px;
}

.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1040;
    display: none;
}

.modal-backdrop.show {
    display: block;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1050;
    display: none;
    overflow: hidden;
    outline: 0;
}

.modal.show {
    display: block;
}

.modal-dialog {
    position: relative;
    width: auto;
    max-width: 500px;
    margin: 50px auto;
    pointer-events: none;
}

.modal-content {
    position: relative;
    display: flex;
    flex-direction: column;
    pointer-events: auto;
    background-color: var(--db-card-bg);
    background-clip: padding-box;
    border: 1px solid var(--db-border);
    border-radius: 12px;
    outline: 0;
    box-shadow: var(--db-shadow-hover);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    padding: 12px 16px;
}

.modal-title {
    margin: 0;
    color: var(--db-text);
    font-size: 18px;
}

.btn-close {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--db-text-secondary);
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.btn-close:hover {
    background: var(--db-hover);
    color: var(--db-text);
}

.modal-body {
    padding: 20px;
    color: var(--db-text);
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 20px;
    border-top: 1px solid var(--db-border);
    gap: 12px;
    padding: 12px 16px;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.pulse {
    animation: pulse 2s infinite;
}

@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
    }

    .backup-table {
        display: block;
        overflow-x: auto;
    }

    .backup-actions {
        flex-wrap: wrap;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .backup-stats-grid {
        grid-template-columns: 1fr;
    }

    .modal-dialog {
        margin: 20px;
        max-width: calc(100% - 40px);
    }
}

@media (max-width: 480px) {
    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }
}
/* Стили для модального окна с прокруткой */
.modal-dialog-scrollable .modal-content {
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}

.modal-dialog-scrollable .modal-body {
    overflow-y: auto;
    flex: 1;
}

/* Улучшаем отображение полей формы в модальном окне */
.modal-body .form-group {
    margin-bottom: 15px;
}

.modal-body .row {
    margin-left: -10px;
    margin-right: -10px;
}

.modal-body .col-md-6, .modal-body .col-md-12 {
    padding-left: 10px;
    padding-right: 10px;
}

/* Улучшаем видимость модального окна на мобильных устройствах */
@media (max-width: 768px) {
    .modal-dialog {
        margin: 10px !important;
        max-width: calc(100% - 20px) !important;
    }

    .modal-body {
        padding: 15px !important;
    }

    .modal-header, .modal-footer {
        padding: 15px !important;
    }
}
/* Стили для статус бара операций */
.operation-item {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

.operation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.operation-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--db-text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.operation-time {
    font-size: 12px;
    color: var(--db-text-secondary);
}

.operation-progress {
    margin: 10px 0;
}

.operation-details {
    font-size: 12px;
    color: var(--db-text-secondary);
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 5px;
    margin-top: 10px;
}

.operation-action-btn {
    padding: 4px 12px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.operation-action-cancel {
    background: var(--db-danger);
    color: white;
}

.operation-action-cancel:hover {
    background: #dc2626;
}

.progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
    font-size: 12px;
}

.progress-text {
    color: var(--db-text);
    font-weight: 500;
}

.progress-percent {
    color: var(--db-accent);
    font-weight: 600;
}

.progress-bar {
    height: 6px;
    background: var(--db-border);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--db-accent), #00e5ff);
    border-radius: 3px;
    transition: width 0.5s ease;
    position: relative;
    overflow: hidden;
}

.progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(255, 255, 255, 0.3),
        transparent
    );
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.operation-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 10px;
}

.operation-status-running {
    background: rgba(59, 130, 246, 0.2);
    color: var(--db-info);
}

.operation-status-completed {
    background: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.operation-status-failed {
    background: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.operation-status-pending {
    background: rgba(245, 158, 11, 0.2);
    color: var(--db-warning);
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-database"></i> Резервное копирование</h1>
            <p>Управление резервными копиями системы</p>
        </div>
        <div class="header-right">
            <button onclick="showAddScheduleModal()" class="btn btn-primary">
                <i class="fas fa-plus"></i> Добавить расписание
            </button>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?> fade-in">
            <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
            <div>
                <strong><?= $message_type === 'error' ? 'Ошибка!' : 'Успешно!' ?></strong>
                <p><?= htmlspecialchars($message) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Карточки статистики -->
    <div class="backup-stats-grid">
        <div class="backup-stat-card">
            <div class="backup-stat-icon backup-stat-icon-success">
                <i class="fas fa-file-archive"></i>
            </div>
            <div class="backup-stat-value"><?= count($backups) ?></div>
            <p class="backup-stat-label">Всего бэкапов</p>
        </div>

        <div class="backup-stat-card">
            <div class="backup-stat-icon backup-stat-icon-info">
                <i class="fas fa-clock"></i>
            </div>
            <div class="backup-stat-value"><?= count($schedules) ?></div>
            <p class="backup-stat-label">Активных расписаний</p>
        </div>

        <div class="backup-stat-card">
            <div class="backup-stat-icon backup-stat-icon-warning">
                <i class="fas fa-history"></i>
            </div>
            <div class="backup-stat-value">
                <?php
                    if (!empty($backups)) {
                        $latest = $backups[0];
                        echo date('d.m.Y', $latest['modified']);
                    } else {
                        echo 'Нет';
                    }
                ?>
            </div>
            <p class="backup-stat-label">Последний бэкап</p>
        </div>

        <div class="backup-stat-card">
            <div class="backup-stat-icon backup-stat-icon-danger">
                <i class="fas fa-server"></i>
            </div>
            <div class="backup-stat-value">
                <?= formatBytesss(disk_free_space($backup_dir)) ?>
            </div>
            <p class="backup-stat-label">Свободно места</p>
        </div>
    </div>

    <div class="backup-form-section" id="current-operations-section" style="margin-bottom: 30px; display: none;">
    <h3 class="section-title">
        <i class="fas fa-sync-alt fa-spin"></i> Текущие операции
    </h3>

    <div id="current-operations-list">
        <!-- Операции будут добавляться динамически -->
    </div>
    </div>

    <!-- Секция расписаний -->
    <div class="backup-form-section" id="schedule-section" style="margin-bottom: 30px;">
        <h3 class="section-title">
            <i class="fas fa-clock"></i> Расписания автоматического бэкапа
        </h3>

        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-plus"></i>
                <h3>Нет активных расписаний</h3>
                <p>Добавьте расписание для автоматического создания бэкапов</p>
                <button class="btn btn-primary" onclick="showAddScheduleModal()" style="margin-top: 20px;">
                    <i class="fas fa-plus"></i> Добавить расписание
                </button>
            </div>
        <?php else: ?>
            <div id="schedules-list">
                <?php foreach ($schedules as $schedule): ?>
                    <div class="schedule-card" id="schedule-<?= $schedule['id'] ?>">
                        <div class="schedule-header">
                            <div class="schedule-title"><?= htmlspecialchars($schedule['name']) ?></div>
                            <span class="schedule-status schedule-status-<?= $schedule['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $schedule['is_active'] ? 'Активно' : 'Неактивно' ?>
                            </span>
                        </div>

                        <div class="schedule-details">
                            <div class="schedule-detail">
                                <strong>Тип:</strong> <?= htmlspecialchars($backup_types[$schedule['backup_type']] ?? $schedule['backup_type']) ?>
                            </div>
                            <div class="schedule-detail">
                                <strong>Расписание:</strong>
                                <?php
                                    $schedule_text = '';
                                    switch ($schedule['schedule_type']) {
                                        case 'hourly': $schedule_text = 'Каждый час'; break;
                                        case 'daily': $schedule_text = 'Ежедневно в ' . $schedule['schedule_time']; break;
                                        case 'weekly':
                                            $days = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
                                            $schedule_text = 'Еженедельно по ' . ($days[$schedule['schedule_day'] - 1] ?? 'Понедельник') . ' в ' . $schedule['schedule_time'];
                                            break;
                                        case 'monthly':
                                            $schedule_text = 'Ежемесячно ' . $schedule['schedule_day'] . '-го числа в ' . $schedule['schedule_time'];
                                            break;
                                    }
                                    echo $schedule_text;
                                ?>
                            </div>
                            <div class="schedule-detail">
                                <strong>Следующий запуск:</strong>
                                <?= $schedule['next_run'] ? date('d.m.Y H:i', strtotime($schedule['next_run'])) : 'Не запланирован' ?>
                            </div>
                            <div class="schedule-detail">
                                <strong>Последний запуск:</strong>
                                <?= $schedule['last_run'] ? date('d.m.Y H:i', strtotime($schedule['last_run'])) : 'Никогда' ?>
                            </div>
                        </div>

                        <?php if ($schedule['comment']): ?>
                            <div class="schedule-detail" style="grid-column: 1 / -1;">
                                <strong>Комментарий:</strong> <?= htmlspecialchars($schedule['comment']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="schedule-actions">
                            <button class="btn btn-sm btn-success" onclick="runScheduleNow(<?= $schedule['id'] ?>)">
                                <i class="fas fa-play"></i> Запустить сейчас
                            </button>

                            <?php if ($schedule['is_active']): ?>
                                <button class="btn btn-sm btn-warning" onclick="toggleSchedule(<?= $schedule['id'] ?>, 'deactivate')">
                                    <i class="fas fa-pause"></i> Отключить
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-primary" onclick="toggleSchedule(<?= $schedule['id'] ?>, 'activate')">
                                    <i class="fas fa-play"></i> Включить
                                </button>
                            <?php endif; ?>

                            <button class="btn btn-sm btn-secondary" onclick="editSchedule(<?= $schedule['id'] ?>)">
                                <i class="fas fa-edit"></i> Редактировать
                            </button>

                            <button class="btn btn-sm btn-danger" onclick="deleteSchedule(<?= $schedule['id'] ?>)">
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Формы создания бэкапа и FTP -->
    <div class="backup-form-container">
        <!-- Форма создания бэкапа -->
        <div class="backup-form-section">
            <h3 class="section-title">
                <i class="fas fa-plus-circle"></i> Создать новый бэкап
            </h3>

            <form method="POST" action="?action=create" onsubmit="return handleCreateBackupWithProgress()">
                <div class="form-group">
                    <label class="form-label">Тип бэкапа</label>
                    <select name="backup_type" class="form-select" required>
                        <?php foreach ($backup_types as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Комментарий (необязательно)</label>
                    <input type="text" name="comment" class="form-input"
                           placeholder="Например: 'Перед обновлением системы'">
                </div>

                <div class="progress-container" id="backupProgress" style="display: none;">
                    <div class="progress-label">
                        <span>Создание бэкапа...</span>
                        <span id="progressPercent">0%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="createBackupBtn">
                        <i class="fas fa-database"></i> Создать бэкап
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showAddScheduleModal()">
                        <i class="fas fa-clock"></i> Создать расписание
                    </button>
                </div>
            </form>
        </div>

        <!-- Форма синхронизации с FTP -->
        <div class="backup-form-section">
            <h3 class="section-title">
                <i class="fas fa-cloud-upload-alt"></i> Синхронизация с FTP
            </h3>

            <form method="POST" action="?action=sync_ftp">
                <div class="form-group">
                    <label class="form-label">Выберите бэкап</label>
                    <select name="backup_file" class="form-select" required>
                        <option value="">-- Выберите файл --</option>
                        <?php foreach ($backups as $backup): ?>
                            <option value="<?= $backup['filename'] ?>">
                                <?= $backup['filename'] ?> (<?= $backup['size_formatted'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">FTP Хост *</label>
                    <input type="text" name="ftp_host" class="form-input"
                           placeholder="ftp.example.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label">FTP Пользователь *</label>
                    <input type="text" name="ftp_username" class="form-input"
                           placeholder="username" required>
                </div>

                <div class="form-group">
                    <label class="form-label">FTP Пароль *</label>
                    <input type="password" name="ftp_password" class="form-input"
                           placeholder="********" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Удаленная директория</label>
                    <input type="text" name="ftp_dir" class="form-input"
                           placeholder="/backups/" value="/backups/">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-upload"></i> Загрузить на FTP
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="testFTPConnection()">
                        <i class="fas fa-plug"></i> Проверить подключение
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Список бэкапов -->
    <div class="backup-table-container">
        <div style="padding: 24px 24px 0 24px;">
            <h3 class="section-title" style="border: none; padding: 0; margin: 0;">
                <i class="fas fa-list"></i> Список резервных копий
            </h3>
            <p style="color: var(--db-text-secondary); font-size: 14px; margin-top: 8px;">
                Показано <?= count($backups) ?> из <?= $max_backups ?> возможных бэкапов
            </p>
        </div>

        <?php if (empty($backups)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>Бэкапы отсутствуют</h3>
                <p>Создайте первый бэкап для защиты данных системы</p>
            </div>
        <?php else: ?>
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>Имя файла</th>
                        <th>Тип</th>
                        <th>Размер</th>
                        <th>Дата создания</th>
                        <th>Комментарий</th>
                        <th style="text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-file-archive" style="color: var(--db-purple);"></i>
                                    <div>
                                        <div style="font-weight: 500; color: var(--db-text);">
                                            <?= htmlspecialchars($backup['filename']) ?>
                                            <?php if ($backup['is_auto']): ?>
                                                <span style="font-size: 11px; color: var(--db-success);">[АВТО]</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--db-text-muted);">
                                            <?= $backup['files'] === 'yes' ? 'Файлы ' : '' ?>
                                            <?= $backup['database'] === 'yes' ? 'БД' : '' ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="backup-type-badge backup-type-<?= $backup['type'] ?>">
                                    <?= $backup['type'] ?>
                                </span>
                            </td>
                            <td style="font-family: monospace; font-weight: 500;">
                                <?= $backup['size_formatted'] ?>
                            </td>
                            <td>
                                <?= $backup['date'] ?>
                            </td>
                            <td>
                                <?= $backup['comment'] ? htmlspecialchars($backup['comment']) :
                                    '<span style="color: var(--db-text-muted); font-style: italic;">Нет комментария</span>' ?>
                            </td>
                            <td>
                                <div class="backup-actions">
                                    <a href="?action=download&file=<?= urlencode($backup['filename']) ?>"
                                       class="backup-action-btn backup-action-btn-success"
                                       title="Скачать">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="?action=restore&file=<?= urlencode($backup['filename']) ?>"
                                       class="backup-action-btn backup-action-btn-warning"
                                       title="Восстановить">
                                        <i class="fas fa-undo"></i>
                                    </a>
                                    <a href="?action=delete&file=<?= urlencode($backup['filename']) ?>"
                                       class="backup-action-btn backup-action-btn-danger"
                                       title="Удалить"
                                       onclick="return confirm('Вы уверены, что хотите удалить этот бэкап?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Панель быстрых действий -->
    <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center;">
        <button class="btn btn-warning" onclick="cleanupOldBackups()">
            <i class="fas fa-broom"></i> Очистить старые бэкапы
        </button>
        <button class="btn btn-info" onclick="showLogs()">
            <i class="fas fa-history"></i> Показать логи
        </button>
        <button class="btn btn-danger" onclick="createEmergencyBackup()">
            <i class="fas fa-first-aid"></i> Экстренный бэкап
        </button>
    </div>
</div>

<!-- Модальное окно добавления/редактирования расписания -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg" style="max-height: 85vh;">
        <div class="modal-content">
            <div class="modal-header" style="position: sticky; top: 0; background: var(--db-card-bg); z-index: 10; padding: 12px 16px; border-radius: 8px;">
                <h5 class="modal-title" id="scheduleModalTitle">
                    <i class="fas fa-clock"></i> Добавить расписание
                </h5>
                <button type="button" class="btn-close" onclick="scheduleModal.hide()"></button>
            </div>

            <div class="modal-body" style="max-height: calc(85vh - 140px); overflow-y: auto;">
                <form method="POST" action="?action=save_schedule" id="scheduleForm" onsubmit="return validateScheduleForm()">
                    <input type="hidden" name="id" id="scheduleId" value="0">

                    <div class="row" style="margin-bottom: 20px;">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="form-label">Название расписания *</label>
                                <input type="text" name="name" class="form-input" required
                                       placeholder="Например: 'Ежедневный полный бэкап'"
                                       style="width: 87%;">
                            </div>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 20px;">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Тип бэкапа *</label>
                                <select name="backup_type" class="form-select" required style="width: 87%;">
                                    <?php foreach ($backup_types as $value => $label): ?>
                                        <option value="<?= $value ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Тип расписания *</label>
                                <select name="schedule_type" class="form-select" id="scheduleType" required
                                        onchange="updateScheduleFields()" style="width: 87%;">
                                    <option value="daily">Ежедневно</option>
                                    <option value="weekly">Еженедельно</option>
                                    <option value="monthly">Ежемесячно</option>
                                    <option value="hourly">Каждый час</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 20px;">
                        <div class="col-md-6">
                            <div class="form-group" id="timeField">
                                <label class="form-label">Время запуска *</label>
                                <input type="time" name="schedule_time" class="form-input"
                                       value="03:00" required style="width: 87%;">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group" id="dayField" style="display: none;">
                                <label class="form-label">День недели *</label>
                                <select name="schedule_day" class="form-select" style="width: 87%;">
                                    <option value="1">Понедельник</option>
                                    <option value="2">Вторник</option>
                                    <option value="3">Среда</option>
                                    <option value="4">Четверг</option>
                                    <option value="5">Пятница</option>
                                    <option value="6">Суббота</option>
                                    <option value="7">Воскресенье</option>
                                </select>
                            </div>

                            <div class="form-group" id="monthDayField" style="display: none;">
                                <label class="form-label">День месяца *</label>
                                <select name="schedule_day" class="form-select" style="width: 87%;">
                                    <?php for ($i = 1; $i <= 31; $i++): ?>
                                        <option value="<?= $i ?>" <?= $i == 1 ? 'selected' : '' ?>>
                                            <?= $i ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 20px;">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Хранить бэкапов</label>
                                <input type="number" name="keep_count" class="form-input"
                                       value="10" min="1" max="50" style="width: 87%;">
                                <small class="text-muted" style="font-size: 12px;">Старые бэкапы будут удаляться автоматически</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label">Комментарий (необязательно)</label>
                        <textarea name="comment" class="form-textarea" rows="2"
                                  placeholder="Дополнительная информация о расписании" style="width: 87%;"></textarea>
                    </div>

                    <div class="alert alert-info" style="margin-bottom: 0;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Как настроить cron?</strong>
                            <p>Для работы автоматических бэкапов добавьте в crontab команду:</p>
                            <code style="display: block; padding: 8px; background: var(--db-bg); border-radius: 4px; margin-top: 5px; font-size: 10px;">
                                */5 * * * * php <?= realpath(__DIR__ . '/../cronjobs/backup_cron.php') ?>
                            </code>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer" style="position: sticky; bottom: 0; background: var(--db-card-bg); z-index: 10; border-top: 1px solid var(--db-border); padding: 12px 16px; border-radius: 8px;">
                <button type="button" class="btn btn-secondary" onclick="scheduleModal.hide()">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="submitScheduleForm()">
                    <i class="fas fa-save"></i> Сохранить расписание
                </button>
            </div>
        </div>
    </div>
</div>

<?php require 'admin_footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Инициализация модального окна Bootstrap
const scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));

// Глобальные переменные для отслеживания операций
let currentOperations = {};
let operationCheckInterval = null;

document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления элементов
    const elements = document.querySelectorAll('.dashboard-header, .backup-stats-grid, .backup-form-section, .backup-table-container');
    elements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';

        setTimeout(() => {
            element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 100);
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

    // Обработчик формы создания бэкапа
    const backupForm = document.querySelector('form[action*="create"]');
    if (backupForm) {
        backupForm.onsubmit = function(e) {
            e.preventDefault();
            return handleCreateBackupWithProgress();
        };
    }
});

// Функции для работы с бэкапами
function handleCreateBackupWithProgress() {
    const form = document.querySelector('form[action*="create"]');
    const backupType = form.querySelector('[name="backup_type"]').value;
    const comment = form.querySelector('[name="comment"]').value;

    // Показываем прогресс бар
    const progressContainer = document.getElementById('backupProgress');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
    const createBtn = document.getElementById('createBackupBtn');

    progressContainer.style.display = 'block';
    createBtn.disabled = true;
    createBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Создание...';

    // Генерируем уникальный ID операции
    const operationId = 'backup_' + Date.now();

    // Начинаем отслеживание операции
    startOperationTracking(operationId, 'backup_create', {
        type: backupType,
        comment: comment,
        timestamp: new Date().toISOString()
    });

    // Симулируем прогресс
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += Math.random() * 20;
        if (progress > 95) {
            progress = 95;
            clearInterval(progressInterval);
        }
        progressFill.style.width = progress + '%';
        progressPercent.textContent = Math.round(progress) + '%';
        updateOperationProgress(operationId, progress, 'Создание бэкапа...');
    }, 500);

    // Отправляем форму
    const formData = new FormData(form);

    fetch(form.action, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(data => {
        clearInterval(progressInterval);
        progressFill.style.width = '100%';
        progressPercent.textContent = '100%';

        // Парсим HTML для поиска сообщения об успехе/ошибке
        const parser = new DOMParser();
        const doc = parser.parseFromString(data, 'text/html');
        const alert = doc.querySelector('.alert');

        if (alert && alert.classList.contains('alert-success')) {
            const message = alert.querySelector('p')?.textContent || 'Бэкап успешно создан';
            completeOperation(operationId, true, message);

            // Обновляем страницу через 2 секунды
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else if (alert && alert.classList.contains('alert-error')) {
            const errorMsg = alert.querySelector('p')?.textContent || 'Ошибка при создании бэкапа';
            completeOperation(operationId, false, errorMsg);
            createBtn.disabled = false;
            createBtn.innerHTML = '<i class="fas fa-database"></i> Создать бэкап';
        } else {
            // Если не нашли alert, предполагаем успех
            completeOperation(operationId, true, 'Бэкап успешно создан');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        }
    })
    .catch(error => {
        clearInterval(progressInterval);
        console.error('Ошибка при создании бэкапа:', error);
        completeOperation(operationId, false, 'Ошибка сети: ' + error.message);
        createBtn.disabled = false;
        createBtn.innerHTML = '<i class="fas fa-database"></i> Создать бэкап';
    });

    return false;
}

function showAddScheduleModal() {
    document.getElementById('scheduleModalTitle').innerHTML = '<i class="fas fa-clock"></i> Добавить расписание';
    document.getElementById('scheduleId').value = '0';
    document.getElementById('scheduleForm').reset();
    updateScheduleFields();
    scheduleModal.show();
}

function editSchedule(id) {
    // Показываем индикатор загрузки
    showNotification('Загрузка данных расписания...', 'info');

    // Используем правильный путь к файлу
    fetch(`/admin/ajax/get_schedule.php?id=${id}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const schedule = data.schedule;

            // Заполняем форму данными
            document.getElementById('scheduleModalTitle').innerHTML = '<i class="fas fa-edit"></i> Редактировать расписание';
            document.getElementById('scheduleId').value = schedule.id;

            const form = document.getElementById('scheduleForm');
            form.querySelector('[name="name"]').value = schedule.name;
            form.querySelector('[name="backup_type"]').value = schedule.backup_type;
            form.querySelector('[name="schedule_type"]').value = schedule.schedule_type;

            // Время запуска
            if (schedule.schedule_time) {
                form.querySelector('[name="schedule_time"]').value = schedule.schedule_time.substring(0, 5);
            }

            form.querySelector('[name="keep_count"]').value = schedule.keep_count || 10;
            form.querySelector('[name="comment"]').value = schedule.comment || '';

            updateScheduleFields();

            // Устанавливаем день для еженедельного расписания
            if (schedule.schedule_type === 'weekly') {
                setTimeout(() => {
                    const daySelect = form.querySelector('#dayField select[name="schedule_day"]');
                    if (daySelect) {
                        daySelect.value = schedule.schedule_day;
                    }
                }, 100);
            }

            // Устанавливаем день для ежемесячного расписания
            if (schedule.schedule_type === 'monthly') {
                setTimeout(() => {
                    const monthDaySelect = form.querySelector('#monthDayField select[name="schedule_day"]');
                    if (monthDaySelect) {
                        monthDaySelect.value = schedule.schedule_day;
                    }
                }, 100);
            }

            // Показываем модальное окно
            scheduleModal.show();

        } else {
            showNotification('Ошибка загрузки расписания: ' + data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Ошибка при загрузке расписания:', error);
        showNotification('Ошибка сети: ' + error.message, 'error');
    });
}

function updateScheduleFields() {
    const scheduleType = document.getElementById('scheduleType').value;
    const timeField = document.getElementById('timeField');
    const dayField = document.getElementById('dayField');
    const monthDayField = document.getElementById('monthDayField');

    if (scheduleType === 'hourly') {
        timeField.style.display = 'none';
        dayField.style.display = 'none';
        monthDayField.style.display = 'none';
    } else if (scheduleType === 'weekly') {
        timeField.style.display = 'block';
        dayField.style.display = 'block';
        monthDayField.style.display = 'none';
    } else if (scheduleType === 'monthly') {
        timeField.style.display = 'block';
        dayField.style.display = 'none';
        monthDayField.style.display = 'block';
    } else {
        timeField.style.display = 'block';
        dayField.style.display = 'none';
        monthDayField.style.display = 'none';
    }
}

function deleteSchedule(id) {
    if (confirm('Вы уверены, что хотите удалить это расписание?')) {
        fetch(`?action=delete_schedule&id=${id}`)
            .then(() => {
                document.getElementById(`schedule-${id}`).remove();
                showNotification('Расписание удалено', 'success');
            })
            .catch(error => {
                showNotification('Ошибка удаления расписания', 'error');
            });
    }
}

function toggleSchedule(id, action) {
    fetch(`?action=toggle_schedule&id=${id}&toggle=${action}`)
        .then(() => {
            location.reload();
        })
        .catch(error => {
            showNotification('Ошибка изменения статуса расписания', 'error');
        });
}

function runScheduleNow(id) {
    if (confirm('Запустить это расписание сейчас?')) {
        const scheduleName = document.querySelector(`#schedule-${id} .schedule-title`)?.textContent || 'Расписание';

        // Генерируем уникальный ID операции
        const operationId = 'schedule_' + id + '_' + Date.now();

        // Начинаем отслеживание операции
        startOperationTracking(operationId, 'schedule_run', {
            scheduleId: id,
            scheduleName: scheduleName
        });

        // Отправляем запрос через AJAX
        fetch(`?action=run_now&id=${id}`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => {
            if (response.ok) {
                completeOperation(operationId, true, 'Расписание успешно запущено');
                // Обновляем страницу через 2 секунды
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                completeOperation(operationId, false, 'Ошибка запуска расписания');
            }
        })
        .catch(error => {
            completeOperation(operationId, false, 'Ошибка сети: ' + error.message);
        });
    }
}

function testFTPConnection() {
    const form = document.querySelector('form[action*="sync_ftp"]');
    const formData = new FormData(form);

    // Убираем файл бэкапа из проверки
    formData.delete('backup_file');

    showNotification('Проверка подключения к FTP...', 'info');

    fetch('/admin/ajax/test_ftp.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Подключение к FTP успешно установлено!', 'success');
        } else {
            showNotification('Ошибка подключения: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сети', 'error');
    });
}

function cleanupOldBackups() {
    if (confirm('Удалить все бэкапы старше 30 дней, кроме последних 10?')) {
        fetch('/admin/ajax/cleanup_backups.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                days: 30,
                keep: 10
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(`Удалено ${data.deleted} старых бэкапов`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка сети', 'error');
        });
    }
}

function showLogs() {
    showNotification('Логи бэкапов в разработке', 'info');
}

function createEmergencyBackup() {
    if (confirm('Создать экстренный бэкап без сжатия (быстрее, но больше места)?')) {
        window.location.href = '?action=create&type=full&emergency=1';
    }
}

function showNotification(message, type = 'info') {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
        animation: fadeIn 0.3s ease;
    `;

    const icon = type === 'success' ? 'check-circle' :
                 type === 'error' ? 'exclamation-circle' :
                 type === 'warning' ? 'exclamation-triangle' : 'info-circle';

    alert.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <div>
            <strong>${type === 'success' ? 'Успешно!' :
                      type === 'error' ? 'Ошибка!' :
                      type === 'warning' ? 'Внимание!' : 'Информация'}</strong>
            <p>${message}</p>
        </div>
    `;

    document.body.appendChild(alert);

    setTimeout(() => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 3000);
}

// Drag and drop для бэкапов
if (window.File && window.FileReader && window.FileList && window.Blob) {
    const dropZone = document.createElement('div');
    dropZone.innerHTML = `
        <div style="text-align: center; padding: 40px; border: 2px dashed var(--db-border);
                    border-radius: 12px; margin-top: 30px; cursor: pointer;">
            <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: var(--db-accent); margin-bottom: 16px;"></i>
            <h3 style="color: var(--db-text); margin: 0 0 8px 0;">Загрузить бэкап</h3>
            <p style="color: var(--db-text-secondary); margin: 0;">Перетащите файл бэкапа сюда или кликните для выбора</p>
            <input type="file" id="backupUpload" accept=".zip" style="display: none;">
        </div>
    `;

    document.querySelector('.dashboard-wrapper').appendChild(dropZone);

    dropZone.addEventListener('click', () => {
        document.getElementById('backupUpload').click();
    });

    document.getElementById('backupUpload').addEventListener('change', function(e) {
        if (this.files.length > 0) {
            uploadBackup(this.files[0]);
        }
    });

    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--db-accent)';
        this.style.backgroundColor = 'var(--db-accent-light)';
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--db-border)';
        this.style.backgroundColor = '';
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--db-border)';
        this.style.backgroundColor = '';

        if (e.dataTransfer.files.length > 0) {
            uploadBackup(e.dataTransfer.files[0]);
        }
    });
}

function uploadBackup(file) {
    if (!file.name.endsWith('.zip')) {
        showNotification('Только ZIP файлы разрешены', 'error');
        return;
    }

    if (file.size > 500 * 1024 * 1024) { // 500MB
        showNotification('Файл слишком большой (макс. 500MB)', 'error');
        return;
    }

    const operationId = 'upload_' + Date.now();

    // Начинаем отслеживание операции
    startOperationTracking(operationId, 'backup_upload', {
        filename: file.name,
        size: file.size,
        sizeFormatted: formatBytesss(file.size)
    });

    const formData = new FormData();
    formData.append('backup_file', file);

    fetch('/admin/ajax/upload_backup.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            completeOperation(operationId, true, 'Бэкап успешно загружен');
            setTimeout(() => location.reload(), 1500);
        } else {
            completeOperation(operationId, false, 'Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        completeOperation(operationId, false, 'Ошибка сети: ' + error.message);
    });
}

// Валидация формы расписания
function validateScheduleForm() {
    const form = document.getElementById('scheduleForm');
    const name = form.querySelector('[name="name"]').value.trim();

    if (!name) {
        showNotification('Введите название расписания', 'error');
        return false;
    }

    return true;
}

// Отправка формы расписания
function submitScheduleForm() {
    if (validateScheduleForm()) {
        document.getElementById('scheduleForm').submit();
    }
}

// Функция для начала отслеживания операции
function startOperationTracking(operationId, operationType, details = {}) {
    // Показываем секцию текущих операций
    document.getElementById('current-operations-section').style.display = 'block';

    // Создаем объект операции
    currentOperations[operationId] = {
        id: operationId,
        type: operationType,
        details: details,
        startTime: new Date(),
        progress: 0,
        status: 'running',
        logs: []
    };

    // Обновляем UI
    updateOperationsUI();

    // Запускаем проверку статуса операций
    if (!operationCheckInterval) {
        operationCheckInterval = setInterval(checkOperationsStatus, 3000);
    }

    // Запускаем обновление прогресса для этой операции
    simulateProgress(operationId);

    return operationId;
}

// Функция для обновления UI операций
function updateOperationsUI() {
    const container = document.getElementById('current-operations-list');
    container.innerHTML = '';

    Object.values(currentOperations).forEach(operation => {
        const operationEl = document.createElement('div');
        operationEl.className = 'operation-item';
        operationEl.id = `operation-${operation.id}`;

        // Форматируем время
        const timeDiff = Math.floor((new Date() - operation.startTime) / 1000);
        const timeFormatted = formatDuration(timeDiff);

        // Определяем иконку и заголовок
        let icon = 'fa-sync-alt fa-spin';
        let title = 'Операция';

        switch (operation.type) {
            case 'backup_create':
                icon = 'fa-database';
                title = 'Создание бэкапа';
                break;
            case 'backup_restore':
                icon = 'fa-undo';
                title = 'Восстановление из бэкапа';
                break;
            case 'backup_upload':
                icon = 'fa-cloud-upload-alt';
                title = 'Загрузка бэкапа';
                break;
            case 'schedule_run':
                icon = 'fa-clock';
                title = 'Запуск расписания';
                break;
            case 'ftp_sync':
                icon = 'fa-exchange-alt';
                title = 'Синхронизация с FTP';
                break;
        }

        operationEl.innerHTML = `
            <div class="operation-header">
                <div class="operation-title">
                    <i class="fas ${icon}"></i>
                    ${title}
                    <span class="operation-status operation-status-${operation.status}">
                        ${operation.status === 'running' ? 'Выполняется' :
                          operation.status === 'completed' ? 'Завершено' :
                          operation.status === 'failed' ? 'Ошибка' : 'Ожидание'}
                    </span>
                </div>
                <div class="operation-time">${timeFormatted}</div>
            </div>

            ${operation.status === 'running' ? `
            <div class="operation-progress">
                <div class="progress-label">
                    <span class="progress-text">Прогресс:</span>
                    <span class="progress-percent">${Math.round(operation.progress)}%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${operation.progress}%"></div>
                </div>
            </div>
            ` : ''}

            <div class="operation-details">
                ${operation.details.filename ? `
                <div><strong>Файл:</strong> ${operation.details.filename}</div>
                ` : ''}

                ${operation.details.type ? `
                <div><strong>Тип:</strong> ${operation.details.type}</div>
                ` : ''}

                ${operation.details.comment ? `
                <div><strong>Комментарий:</strong> ${operation.details.comment}</div>
                ` : ''}
            </div>

            ${operation.status === 'running' ? `
            <div style="text-align: right; margin-top: 10px;">
                <button class="operation-action-btn operation-action-cancel"
                        onclick="cancelOperation('${operation.id}')">
                    <i class="fas fa-times"></i> Отменить
                </button>
            </div>
            ` : ''}

            ${operation.logs.length > 0 ? `
            <div class="operation-logs" style="margin-top: 10px; font-size: 11px;">
                <strong>Лог:</strong>
                <div style="max-height: 60px; overflow-y: auto; background: var(--db-bg);
                          padding: 5px; border-radius: 4px; margin-top: 5px;">
                    ${operation.logs.map(log => `<div>${log}</div>`).join('')}
                </div>
            </div>
            ` : ''}
        `;

        container.appendChild(operationEl);
    });
}

// Функция для обновления прогресса операции
function updateOperationProgress(operationId, progress, log = null) {
    if (currentOperations[operationId]) {
        currentOperations[operationId].progress = Math.min(100, Math.max(0, progress));

        if (log) {
            currentOperations[operationId].logs.push(`[${new Date().toLocaleTimeString()}] ${log}`);
            if (currentOperations[operationId].logs.length > 10) {
                currentOperations[operationId].logs.shift();
            }
        }

        updateOperationsUI();
    }
}

// Функция для завершения операции
function completeOperation(operationId, success = true, message = '') {
    if (currentOperations[operationId]) {
        currentOperations[operationId].status = success ? 'completed' : 'failed';
        currentOperations[operationId].progress = 100;

        if (message) {
            currentOperations[operationId].logs.push(`[${new Date().toLocaleTimeString()}] ${message}`);
        }

        updateOperationsUI();

        // Удаляем операцию через 10 секунд
        setTimeout(() => {
            removeOperation(operationId);
        }, 10000);

        // Показываем уведомление
        if (success) {
            showNotification(message || 'Операция завершена успешно', 'success');
        } else {
            showNotification(message || 'Произошла ошибка при выполнении операции', 'error');
        }
    }
}

// Функция для удаления операции
function removeOperation(operationId) {
    if (currentOperations[operationId]) {
        delete currentOperations[operationId];
        updateOperationsUI();

        // Если операций нет, скрываем секцию
        if (Object.keys(currentOperations).length === 0) {
            document.getElementById('current-operations-section').style.display = 'none';
            if (operationCheckInterval) {
                clearInterval(operationCheckInterval);
                operationCheckInterval = null;
            }
        }
    }
}

// Функция для отмены операции
function cancelOperation(operationId) {
    if (confirm('Вы уверены, что хотите отменить эту операцию?')) {
        // Здесь должна быть логика отмены на сервере
        // Пока просто помечаем как отмененную
        if (currentOperations[operationId]) {
            currentOperations[operationId].status = 'failed';
            currentOperations[operationId].logs.push(`[${new Date().toLocaleTimeString()}] Операция отменена пользователем`);
            completeOperation(operationId, false, 'Операция отменена');
        }
    }
}

// Функция для симуляции прогресса (в реальном приложении это будет получаться с сервера)
function simulateProgress(operationId) {
    const interval = setInterval(() => {
        if (currentOperations[operationId] && currentOperations[operationId].status === 'running') {
            const currentProgress = currentOperations[operationId].progress;

            // Увеличиваем прогресс на 1-5%
            const increment = 1 + Math.random() * 4;
            let newProgress = Math.min(95, currentProgress + increment);

            // Добавляем случайные логи
            if (Math.random() > 0.7) {
                const logMessages = [
                    'Копирование файлов...',
                    'Архивирование данных...',
                    'Создание резервной копии базы данных...',
                    'Проверка целостности архива...',
                    'Сохранение метаданных...'
                ];
                const randomLog = logMessages[Math.floor(Math.random() * logMessages.length)];
                updateOperationProgress(operationId, newProgress, randomLog);
            } else {
                updateOperationProgress(operationId, newProgress);
            }

            // Если прогресс достиг 95%, ждем завершения от сервера
            if (newProgress >= 95) {
                clearInterval(interval);
            }
        } else {
            clearInterval(interval);
        }
    }, 1000);
}

// Функция для проверки статуса операций (в реальном приложении запрашивает сервер)
function checkOperationsStatus() {
    // В реальном приложении здесь был бы AJAX запрос к серверу
    // для получения реального статуса операций
    console.log('Checking operations status...');
}

// Функция для форматирования времени
function formatDuration(seconds) {
    if (seconds < 60) {
        return `${seconds} сек`;
    } else if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${minutes} мин ${secs} сек`;
    } else {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return `${hours} ч ${minutes} мин`;
    }
}

// Вспомогательная функция для форматирования байтов
function formatBytesss(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Автоматически фокусируемся на первом поле при открытии модального окна
document.getElementById('scheduleModal').addEventListener('shown.bs.modal', function () {
    setTimeout(() => {
        const firstInput = this.querySelector('input[name="name"]');
        if (firstInput) {
            firstInput.focus();
        }
    }, 100);
});
</script>
