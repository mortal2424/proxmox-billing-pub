<?php
/**
 * Общие функции для резервного копирования
 */
//session_start();
require_once __DIR__ . '/db.php';
//require_once __DIR__ . '/auth.php';
//require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/admin_functions.php';

//checkAuth();

// Глобальные константы для бэкапа
define('BACKUP_DIR', realpath(__DIR__ . '/../admin/backups/') . '/');
define('PROJECT_ROOT', realpath(__DIR__ . '/../'));
define('MAX_BACKUPS', 10);

/**
 * Создание бэкапа
 */
function createBackup($type = 'full', $comment = '', $is_auto = false) {
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

    $filename = $backup_name . '.zip';
    $filepath = $backup_dir . $filename;

    try {
        // Создаем ZIP архив
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Не удалось создать архив");
        }

        // Добавляем файлы в архив (если нужно)
        if ($type === 'full' || $type === 'files') {
            addFilesToArchive($zip, $project_root);
        }

        // Добавляем дамп базы данных (если нужно)
        if ($type === 'full' || $type === 'db') {
            addDatabaseToArchive($zip, $pdo);
        }

        // Добавляем мета-информацию
        $meta = [
            'type' => $type,
            'comment' => $comment,
            'created_at' => date('Y-m-d H:i:s'),
            'is_auto' => $is_auto,
            'version' => '1.0',
            'project_root' => $project_root,
            'files' => ($type === 'full' || $type === 'files') ? 'yes' : 'no',
            'database' => ($type === 'full' || $type === 'db') ? 'yes' : 'no'
        ];

        $zip->addFromString('backup_meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        if (!$zip->close()) {
            throw new Exception("Ошибка при сохранении архива");
        }

        // Очищаем старые бэкапы
        cleanupOldBackups($backup_dir);

        // Записываем в лог
        $user_id = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0;
        logBackupAction($pdo, $user_id, 'create', $filename, $comment . ($is_auto ? ' [АВТО]' : ''));

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath)
        ];

    } catch (Exception $e) {
        // Удаляем частично созданный файл
        if (isset($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
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
        'uploads'
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
    $config = require __DIR__ . '/config.php';
    $db_name = $config['dbname'];

    try {
        $tables_result = safeQuery($pdo, "SHOW TABLES");
        $tables = $tables_result->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        throw new Exception("Ошибка при получении списка таблиц: " . $e->getMessage());
    }

    $sql_dump = "-- HomeVlad Cloud Database Dump\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- Database: {$db_name}\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Структура таблицы
        $sql_dump .= "--\n-- Table structure for table `{$table}`\n--\n";
        $sql_dump .= "DROP TABLE IF EXISTS `{$table}`;\n";

        try {
            $create_table_result = safeQuery($pdo, "SHOW CREATE TABLE `{$table}`", [], $table);
            $create_table = $create_table_result->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Ошибка при получении структуры таблицы {$table}: " . $e->getMessage());
        }

        $sql_dump .= $create_table['Create Table'] . ";\n\n";

        // Данные таблицы
        $sql_dump .= "--\n-- Dumping data for table `{$table}`\n--\n";

        try {
            $rows_result = safeQuery($pdo, "SELECT * FROM `{$table}`", [], $table);
            $rows = $rows_result->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Ошибка при получении данных таблицы {$table}: " . $e->getMessage());
        }

        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $column_list = '`' . implode('`, `', $columns) . '`';

            $sql_dump .= "INSERT INTO `{$table}` ({$column_list}) VALUES\n";

            $values = [];
            foreach ($rows as $row) {
                $escaped_values = array_map(function($value) use ($pdo) {
                    if (is_null($value)) {
                        return 'NULL';
                    }
                    return $pdo->quote($value);
                }, $row);
                $values[] = "(" . implode(', ', $escaped_values) . ")";
            }

            $sql_dump .= implode(",\n", $values) . ";\n\n";
        }
    }

    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $zip->addFromString('database/database_dump.sql', $sql_dump);
}

/**
 * Очистка старых бэкапов
 */
function cleanupOldBackups($backup_dir) {
    $files = glob($backup_dir . '*.zip');
    if (count($files) > MAX_BACKUPS) {
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        $files_to_delete = count($files) - MAX_BACKUPS;
        for ($i = 0; $i < $files_to_delete; $i++) {
            @unlink($files[$i]);
        }
    }
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
