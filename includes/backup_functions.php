<?php
/**
 * Общие функции для резервного копирования
 */
//session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup_database.php';
require_once __DIR__ . '/version.php';
//require_once __DIR__ . '/auth.php';
//require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/admin_functions.php';

//checkAuth();

// Глобальные константы для бэкапа
define('BACKUP_DIR', dirname(__DIR__) . '/admin/backups/');
define('PROJECT_ROOT', realpath(__DIR__ . '/../'));
define('MAX_BACKUPS', 10);

/**
 * Создание бэкапа
 */
function createBackup($type = 'full', $comment = '', $is_auto = false, $scheduleId = null, $keepCount = MAX_BACKUPS) {
    global $pdo;

    $backup_dir = BACKUP_DIR;
    $project_root = PROJECT_ROOT;

    // Создаем папку для бэкапов, если ее нет
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $backup_name = $is_auto ? "auto_backup_{$timestamp}_{$type}" : "backup_{$timestamp}_{$type}";
    if ($comment) {
        $backup_name .= "_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($comment, 0, 20));
    }

    if ($scheduleId !== null) {
        if ((int) $scheduleId < 1 || $keepCount < 1 || $keepCount > 1000) {
            return ['success' => false, 'error' => 'Некорректные параметры хранения'];
        }
        $backup_name = 'schedule_' . (int) $scheduleId . '_' . $backup_name;
    }
    $filename = $backup_name . '_' . bin2hex(random_bytes(4)) . '.zip';
    $filepath = $backup_dir . $filename;

    $databaseTemporary = null; $operationLock = null;
    try {
        $operationLock = backupOperationLock($project_root);
        // Создаем ZIP архив
        $zip = new ZipArchive();
        if ($zip->open($filepath . '.part', ZipArchive::CREATE | ZipArchive::EXCL) !== TRUE) {
            throw new Exception("Не удалось создать архив");
        }

        // Добавляем файлы в архив (если нужно)
        if ($type === 'full' || $type === 'files') {
            addFilesToArchive($zip, $project_root);
        }

        // Добавляем дамп базы данных (если нужно)
        if ($type === 'full' || $type === 'db') {
            $databaseTemporary = addDatabaseToArchive($zip, $pdo);
        }

        // Добавляем мета-информацию
        $meta = [
            'type' => $type,
            'comment' => $comment,
            'created_at' => date('Y-m-d H:i:s'),
            'is_auto' => $is_auto,
            'schedule_id' => $scheduleId,
            'version' => '1.0',
            'system_version' => HOMEVLAD_VERSION,
            'project_root' => $project_root,
            'files' => ($type === 'full' || $type === 'files') ? 'yes' : 'no',
            'database' => ($type === 'full' || $type === 'db') ? 'yes' : 'no'
        ];

        $zip->addFromString('backup_meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        if (!$zip->close()) {
            throw new Exception("Ошибка при сохранении архива");
        }

        if (!chmod($filepath . '.part', 0600) || !rename($filepath . '.part', $filepath)) {
            throw new RuntimeException('Не удалось завершить создание архива');
        }
        try { $pdo = (new Database())->getConnection(); } catch (Throwable $connectionError) { error_log('Backup completed; log connection unavailable'); }
        // A schedule only prunes its own completed archives.
        try {
            cleanupOldBackups($backup_dir, (int) $keepCount, $scheduleId);
        } catch (Throwable $cleanupError) {
            error_log('Backup created but retention cleanup failed: ' . $cleanupError->getMessage());
        }

        // Записываем в лог
        $user_id = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0;
        logBackupAction($pdo, $user_id, 'create', $filename, $comment . ($is_auto ? ' [АВТО]' : ''));

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath)
        ];

    } catch (Throwable $e) {
        // Удаляем частично созданный файл
        if (isset($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }

        if (isset($filepath) && is_file($filepath . '.part')) {
            unlink($filepath . '.part');
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    } finally {
        if ($databaseTemporary && is_file($databaseTemporary)) unlink($databaseTemporary);
        if ($operationLock) { flock($operationLock, LOCK_UN); fclose($operationLock); }
    }
}

/**
 * Добавление файлов в архив
 */
function addFilesToArchive($zip, $project_root) {
    $exclude_patterns = [
        'backups/',
        'admin/backups/',
        'logs/',
        'tmp/',
        'cache/',
        '\.zip$',
        '\.log$',
        'node_modules',
        'vendor',
        '.git',
        '.env',
        'config.php',
        'backup_',
        '\.backup',
        'temp_uploads'
    ];

    // Добавляем основные папки проекта
    $folders_to_backup = [
        'includes',
        'templates',
        'admin',
        'api',
        'bots',
        'cronjobs',
        'login',
        'static',
        'css',
        'js',
        'img',
        'uploads',
        'console'
    ];

    foreach ($folders_to_backup as $folder) {
        $folder_path = $project_root . '/' . $folder;
        if (is_dir($folder_path)) {
            addFolderToZip($zip, $folder_path, $folder, $exclude_patterns, $project_root);
        }
    }

    // Добавляем файлы в корне проекта
    addRootFilesToZip($zip, $project_root, $exclude_patterns);
}

/**
 * Добавление папки в архив
 */
function addFolderToZip($zip, $folder_path, $relative_path, $exclude_patterns, $project_root) {
    if (!is_dir($folder_path)) return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder_path),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) continue;

        $filepath = $file->getRealPath();
        $relative_filepath = substr($filepath, strlen($project_root) + 1);

        // Проверяем исключения
        $exclude = false;
        foreach ($exclude_patterns as $pattern) {
            if (preg_match('#'.$pattern.'#', $relative_filepath)) {
                $exclude = true;
                break;
            }
        }

        if (!$exclude && is_readable($filepath)) {
            $zip->addFile($filepath, $relative_filepath);
        }
    }
}

/**
 * Добавление файлов из корня
 */
function addRootFilesToZip($zip, $project_root, $exclude_patterns) {
    $root_files = scandir($project_root);

    foreach ($root_files as $file) {
        if ($file === '.' || $file === '..') continue;

        $filepath = $project_root . '/' . $file;
        $relative_filepath = $file;

        // Проверяем исключения
        $exclude = false;
        foreach ($exclude_patterns as $pattern) {
            if (preg_match('#'.$pattern.'#', $relative_filepath)) {
                $exclude = true;
                break;
            }
        }

        if (is_dir($filepath)) continue;

        if (!$exclude && is_file($filepath) && is_readable($filepath)) {
            $zip->addFile($filepath, $relative_filepath);
        }
    }
}

/**
 * Добавление базы данных в архив
 */
function addDatabaseToArchive($zip, $pdo) {
    $temporary = tempnam(BACKUP_DIR, '.database-');
    if ($temporary === false) throw new RuntimeException('Не удалось подготовить дамп БД');
    chmod($temporary, 0600);
    try {
        backupDatabaseCommand('export', $temporary);
        if (!$zip->addFile($temporary, 'database/database_dump.sql')) throw new RuntimeException('Не удалось добавить дамп в архив');
        // ZipArchive reads addFile sources when close() is called.
        return $temporary;
    } catch (Throwable $error) {
        unlink($temporary);
        throw $error;
    }
}

/**
 * Очистка старых бэкапов
 */
function backupRetentionCandidates(string $backupDir, int $keep, ?int $scheduleId = null): array {
    if ($keep < 1 || $keep > 1000 || ($scheduleId !== null && $scheduleId < 1)) {
        throw new InvalidArgumentException('Количество копий должно быть от 1 до 1000');
    }
    $pattern = $scheduleId === null ? 'backup_*.zip' : 'schedule_' . $scheduleId . '_*.zip';
    $files = array_filter(glob(rtrim($backupDir, '/') . '/' . $pattern) ?: [],
        static fn ($file) => is_file($file) && !is_link($file));
    usort($files, static fn ($a, $b) => (filemtime($b) <=> filemtime($a)) ?: strcmp($b, $a));
    return array_slice($files, $keep);
}

function cleanupOldBackups($backup_dir, int $keep = MAX_BACKUPS, ?int $scheduleId = null): int {
    $lock = fopen(rtrim($backup_dir, '/') . '/.retention.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать очистку архивов');
    }
    $deleted = 0;
    try {
        foreach (backupRetentionCandidates($backup_dir, $keep, $scheduleId) as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Не удалось удалить старую копию');
            }
            $deleted++;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $deleted;
}

/**
 * Логирование действий
 */
function logBackupAction($pdo, $user_id, $action, $filename, $details = '') {
    try {
        $stmt = safeQuery($pdo, "
            INSERT INTO backup_logs (user_id, action, filename, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ", [
            $user_id,
            $action,
            $filename,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ], 'backup_logs');
    } catch (Exception $e) {
        // Если таблицы нет, создаем ее
        createBackupLogsTable($pdo);
        // Пробуем снова
        try {
            $stmt = safeQuery($pdo, "
                INSERT INTO backup_logs (user_id, action, filename, details, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ", [
                $user_id,
                $action,
                $filename,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ], 'backup_logs');
        } catch (Exception $e2) {
            error_log("Не удалось записать лог бэкапа: " . $e2->getMessage());
        }
    }
}

/**
 * Создание таблицы логов
 */
function createBackupLogsTable($pdo) {
    try {
        $query = "
            CREATE TABLE IF NOT EXISTS backup_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action VARCHAR(50) NOT NULL,
                filename VARCHAR(255),
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        safeQuery($pdo, $query, [], null, true);
    } catch (Exception $e) {
        error_log("Не удалось создать таблицу backup_logs: " . $e->getMessage());
    }
}

/**
 * Форматирование размера файла
 */
function formatBytess($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
