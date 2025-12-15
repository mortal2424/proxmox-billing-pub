<?php
// ajax_apply_update.php - AJAX обработчик обновления
session_start();

// Определяем корневую директорию
define('ROOT_PATH', dirname(__DIR__));
define('ADMIN_PATH', __DIR__);
define('BACKUPS_PATH', ROOT_PATH . '/backups');
define('UPDATES_PATH', ADMIN_PATH . '/updates');

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/auth.php';

if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Включаем вывод всех ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/update_errors.log');

// Создаем папку для логов если нет
if (!file_exists(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

// Функция для записи лога
function addLog(&$log, $message, $type = 'info') {
    $timestamp = date('H:i:s');
    $log[] = [
        'time' => $timestamp,
        'message' => $message,
        'type' => $type
    ];
    // Также пишем в error_log для отладки
    error_log("[$timestamp] $type: $message");
}

// Основная функция применения обновления
function applyUpdate($pdo, $version, &$log) {
    addLog($log, "Начало применения обновления $version", 'start');
    
    $update_dir = UPDATES_PATH . '/' . $version;
    if (!file_exists($update_dir)) {
        addLog($log, "Ошибка: Папка обновления не найдена: $update_dir", 'error');
        return false;
    }
    
    addLog($log, "Папка обновления: $update_dir", 'info');
    
    // 1. Создаем бэкап текущей папки обновления
    addLog($log, "1. Создание бэкапа папки обновления...", 'info');
    $backup_updates_dir = BACKUPS_PATH . '/updates/' . $version;
    if (!file_exists($backup_updates_dir)) {
        if (!mkdir($backup_updates_dir, 0755, true)) {
            addLog($log, "Ошибка: Не удалось создать папку для бэкапа: $backup_updates_dir", 'error');
            return false;
        }
        addLog($log, "Создана папка для бэкапа обновления", 'success');
    }
    
    // Копируем всю папку обновления
    if (!copyDirectory($update_dir, $backup_updates_dir)) {
        addLog($log, "Ошибка: Не удалось скопировать папку обновления в бэкап", 'error');
        return false;
    }
    addLog($log, "Бэкап папки обновления создан: $backup_updates_dir", 'success');
    
    // 2. Применяем SQL обновления
    $sql_file = $update_dir . '/update.sql';
    if (file_exists($sql_file)) {
        addLog($log, "2. Применение SQL обновлений...", 'info');
        addLog($log, "SQL файл: $sql_file", 'info');
        
        $result = applyDatabaseUpdate($pdo, $sql_file, $version, $log);
        if (!$result['success']) {
            addLog($log, "Ошибка применения SQL: " . $result['message'], 'error');
            return false;
        }
        addLog($log, "SQL обновления успешно применены", 'success');
    } else {
        addLog($log, "2. SQL файл не найден, пропускаем обновление базы данных", 'info');
    }
    
    // 3. Обновляем файлы
    $files_dir = $update_dir . '/files';
    if (file_exists($files_dir)) {
        addLog($log, "3. Обновление файлов системы...", 'info');
        addLog($log, "Папка с файлами: $files_dir", 'info');
        
        $files_result = updateSystemFiles($update_dir, $version, $log);
        
        if (!empty($files_result['conflicts'])) {
            foreach ($files_result['conflicts'] as $conflict) {
                addLog($log, "Конфликт: " . $conflict['message'] . " (файл: " . $conflict['file'] . ")", 'warning');
            }
        }
        
        $success_count = count(array_filter($files_result['results'], fn($r) => $r['status'] === 'success'));
        $error_count = count(array_filter($files_result['results'], fn($r) => $r['status'] === 'error'));
        
        addLog($log, "Файлов обновлено: $success_count, ошибок: $error_count", 'info');
        
        if ($error_count > 0) {
            addLog($log, "Есть ошибки при обновлении файлов. Проверьте права на запись.", 'warning');
        }
    } else {
        addLog($log, "3. Папка с файлами не найдена, пропускаем обновление файлов", 'info');
    }
    
    // 4. Обновляем версию в базе данных
    addLog($log, "4. Обновление информации о версии...", 'info');
    $description = getUpdateDescription($update_dir);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_versions (version, release_date, description)
            VALUES (?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE release_date = CURDATE(), description = ?
        ");
        $stmt->execute([$version, $description, $description]);
        addLog($log, "Версия $version записана в базу данных", 'success');
    } catch (Exception $e) {
        addLog($log, "Ошибка записи версии в базу: " . $e->getMessage(), 'error');
        return false;
    }
    
    // 5. Записываем историю обновления
    addLog($log, "5. Запись истории обновления...", 'info');
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_updates (version, update_type, description, success, backup_path)
            VALUES (?, 'upgrade', ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                applied_at = CURRENT_TIMESTAMP,
                success = 1,
                backup_path = ?
        ");
        $stmt->execute([$version, $description, $backup_updates_dir, $backup_updates_dir]);
        addLog($log, "История обновления записана", 'success');
    } catch (Exception $e) {
        addLog($log, "Ошибка записи истории: " . $e->getMessage(), 'warning');
    }
    
    // 6. Удаляем папку обновления
    addLog($log, "6. Удаление папки обновления...", 'info');
    if (deleteUpdateFolder($update_dir)) {
        addLog($log, "Папка обновления успешно удалена", 'success');
    } else {
        addLog($log, "Предупреждение: Не удалось удалить папку обновления. Удалите вручную: $update_dir", 'warning');
    }
    
    addLog($log, "Обновление $version успешно завершено!", 'success');
    return true;
}

// Вспомогательные функции
function copyDirectory($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $srcFile = $source . '/' . $file;
            $dstFile = $dest . '/' . $file;
            
            if (is_dir($srcFile)) {
                copyDirectory($srcFile, $dstFile);
            } else {
                if (!copy($srcFile, $dstFile)) {
                    error_log("Не удалось скопировать файл: $srcFile -> $dstFile");
                    return false;
                }
            }
        }
    }
    closedir($dir);
    return true;
}

function deleteUpdateFolder($path) {
    if (!file_exists($path)) {
        return true;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isDir()) {
            if (!rmdir($file->getPathname())) {
                error_log("Не удалось удалить директорию: " . $file->getPathname());
                return false;
            }
        } else {
            if (!unlink($file->getPathname())) {
                error_log("Не удалось удалить файл: " . $file->getPathname());
                return false;
            }
        }
    }
    
    return rmdir($path);
}

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
        }
    }
    
    return 'Обновление системы';
}

function applyDatabaseUpdate($pdo, $sql_file, $version, &$log) {
    try {
        addLog($log, "Чтение SQL файла", 'info');
        $sql_content = file_get_contents($sql_file);
        
        if (empty($sql_content)) {
            addLog($log, "SQL файл пуст", 'info');
            return ['success' => true, 'message' => 'SQL файл пуст'];
        }
        
        // Удаляем комментарии
        addLog($log, "Обработка SQL запросов", 'info');
        $sql_content = preg_replace('/\/\*.*?\*\/;/s', '', $sql_content);
        $sql_content = preg_replace('/--.*?[\r\n]/', '', $sql_content);
        
        // Разбиваем на запросы
        $queries = array_filter(
            array_map('trim',
                explode(';', $sql_content)
            )
        );
        
        addLog($log, "Найдено " . count($queries) . " SQL запросов", 'info');
        
        if (count($queries) === 0) {
            addLog($log, "Нет SQL запросов для выполнения", 'info');
            return ['success' => true, 'message' => 'Нет SQL запросов'];
        }
        
        $pdo->beginTransaction();
        $query_num = 0;
        
        foreach ($queries as $query) {
            $query_num++;
            if (!empty($query)) {
                addLog($log, "Выполнение запроса #$query_num: " . substr($query, 0, 100) . "...", 'info');
                try {
                    $pdo->exec($query);
                    addLog($log, "Запрос #$query_num выполнен", 'success');
                } catch (Exception $e) {
                    addLog($log, "Ошибка выполнения запроса #$query_num: " . $e->getMessage(), 'error');
                    throw $e;
                }
            }
        }
        
        $pdo->commit();
        addLog($log, "Все SQL запросы успешно выполнены", 'success');
        
        return ['success' => true, 'message' => 'База данных успешно обновлена'];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Ошибка при обновлении базы данных: ' . $e->getMessage()];
    }
}

function updateSystemFiles($update_dir, $version, &$log) {
    $results = [];
    $conflicts = [];
    
    $files_dir = $update_dir . '/files';
    if (!file_exists($files_dir)) {
        return ['results' => $results, 'conflicts' => $conflicts];
    }
    
    addLog($log, "Сканирование папки с файлами...", 'info');
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($files_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            // Получаем относительный путь от папки files
            $relative_path = substr($file->getPathname(), strlen($files_dir) + 1);
            $target_path = ROOT_PATH . '/' . $relative_path;
            
            addLog($log, "Обработка файла: $relative_path", 'info');
            
            // Проверяем, существует ли целевой файл
            if (file_exists($target_path)) {
                // Создаем бэкап
                addLog($log, "Создание бэкапа файла...", 'info');
                if (!createBackup($target_path, $version, $log)) {
                    $conflicts[] = [
                        'file' => $relative_path,
                        'message' => 'Не удалось создать бэкап файла'
                    ];
                    addLog($log, "Ошибка создания бэкапа для: $relative_path", 'error');
                    continue;
                }
                addLog($log, "Бэкап создан для: $relative_path", 'success');
            }
            
            // Создаем директорию, если не существует
            $target_dir = dirname($target_path);
            if (!file_exists($target_dir)) {
                if (!mkdir($target_dir, 0755, true)) {
                    $results[] = [
                        'file' => $relative_path,
                        'status' => 'error',
                        'message' => 'Не удалось создать директорию: ' . $target_dir
                    ];
                    addLog($log, "Ошибка создания директории: $target_dir", 'error');
                    continue;
                }
                addLog($log, "Создана директория: $target_dir", 'success');
            }
            
            // Копируем файл
            if (copy($file->getPathname(), $target_path)) {
                chmod($target_path, 0644);
                $results[] = [
                    'file' => $relative_path,
                    'status' => 'success',
                    'message' => 'Файл обновлен'
                ];
                addLog($log, "Файл обновлен: $relative_path", 'success');
            } else {
                $results[] = [
                    'file' => $relative_path,
                    'status' => 'error',
                    'message' => 'Не удалось скопировать файл'
                ];
                addLog($log, "Ошибка копирования файла: $relative_path", 'error');
            }
        }
    }
    
    return ['results' => $results, 'conflicts' => $conflicts];
}

function createBackup($source_file, $version, &$log) {
    if (!file_exists($source_file)) {
        return true;
    }
    
    // Создаем директорию для бэкапов этой версии
    $backup_dir = BACKUPS_PATH . '/' . $version;
    if (!file_exists($backup_dir)) {
        if (!mkdir($backup_dir, 0755, true)) {
            addLog($log, "Ошибка создания папки для бэкапов: $backup_dir", 'error');
            return false;
        }
        addLog($log, "Создана папка для бэкапов: $backup_dir", 'success');
    }
    
    // Получаем относительный путь от корня сайта
    $relative_path = str_replace(ROOT_PATH . '/', '', $source_file);
    
    // Создаем путь для бэкапа (сохраняем структуру папок)
    $backup_path = $backup_dir . '/' . $relative_path;
    
    // Создаем директорию в бэкапе, если нужно
    $backup_file_dir = dirname($backup_path);
    if (!file_exists($backup_file_dir)) {
        if (!mkdir($backup_file_dir, 0755, true)) {
            addLog($log, "Ошибка создания поддиректории в бэкапе: $backup_file_dir", 'error');
            return false;
        }
    }
    
    // Копируем файл
    if (copy($source_file, $backup_path)) {
        chmod($backup_path, 0644);
        return true;
    } else {
        addLog($log, "Ошибка копирования файла в бэкап: $source_file -> $backup_path", 'error');
        return false;
    }
}

// Обработка AJAX запроса
header('Content-Type: application/json');

$version = $_POST['version'] ?? '';
if (empty($version)) {
    echo json_encode(['success' => false, 'error' => 'Не указана версия обновления']);
    exit;
}

// Проверяем существование обновления
$update_dir = UPDATES_PATH . '/' . $version;
if (!file_exists($update_dir)) {
    echo json_encode(['success' => false, 'error' => "Обновление $version не найдено в папке " . UPDATES_PATH]);
    exit;
}

// Запускаем процесс обновления
$log = [];
$success = applyUpdate($pdo, $version, $log);

echo json_encode([
    'success' => $success,
    'log' => $log,
    'version' => $version
]);