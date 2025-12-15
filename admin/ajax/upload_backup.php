<?php
/**
 * Обработчик загрузки бэкапов через веб-интерфейс
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_functions.php';

session_start();

// Проверяем авторизацию
/*if (!checkAuth()) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}*/

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'] ?? 0;

// Проверяем, является ли пользователь администратором
try {
    $stmt = safeQuery($pdo, "SELECT is_admin FROM users WHERE id = ?", [$user_id], 'users');
    $user = $stmt->fetch();
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
    exit;
}

if (!$user || !$user['is_admin']) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен. Требуются права администратора']);
    exit;
}

// Проверяем, был ли загружен файл
if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Файл не был загружен или произошла ошибка загрузки',
        'error_code' => $_FILES['backup_file']['error'] ?? 'unknown'
    ]);
    exit;
}

$uploaded_file = $_FILES['backup_file'];
$max_file_size = 500 * 1024 * 1024; // 500MB
$allowed_types = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'];
$allowed_extensions = ['.zip'];

// Проверяем размер файла
if ($uploaded_file['size'] > $max_file_size) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Размер файла превышает лимит в ' . formatBytess($max_file_size),
        'size' => $uploaded_file['size'],
        'max_size' => $max_file_size
    ]);
    exit;
}

// Проверяем расширение файла
$file_extension = strtolower(strrchr($uploaded_file['name'], '.'));
if (!in_array($file_extension, $allowed_extensions)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Недопустимый формат файла. Разрешены только ZIP архивы',
        'extension' => $file_extension,
        'allowed' => $allowed_extensions
    ]);
    exit;
}

// Определяем MIME-тип
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $uploaded_file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types) && !preg_match('/^application\/.*zip/', $mime_type)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Недопустимый тип файла. Требуется ZIP архив',
        'mime_type' => $mime_type,
        'allowed' => $allowed_types
    ]);
    exit;
}

// Проверяем, является ли файл валидным ZIP архивом
$zip = new ZipArchive();
if ($zip->open($uploaded_file['tmp_name']) !== TRUE) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Файл не является корректным ZIP архивом'
    ]);
    exit;
}

// Проверяем наличие метаданных бэкапа
$meta_json = $zip->getFromName('backup_meta.json');
if (!$meta_json) {
    $zip->close();
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'В архиве отсутствуют метаданные бэкапа (backup_meta.json). Возможно, это не бэкап системы.'
    ]);
    exit;
}

/*$meta = json_decode($meta_json, true);
if (!$meta || !isset($meta['backup_system']) || $meta['backup_system'] !== 'HomeVlad Cloud Panel') {
    $zip->close();
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'success' => false,
        'error' => 'Архив не является бэкапом этой системы или повреждены метаданные'
    ]);
    exit;
}*/

// Проверяем версию системы для совместимости
if (isset($meta['system_version'])) {
    $current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0';
    $uploaded_version = $meta['system_version'];

    // Простая проверка совместимости (можно расширить)
    if (version_compare($uploaded_version, '1.0.0', '<')) {
        $zip->close();
        header('HTTP/1.1 400 Bad Request');
        echo json_encode([
            'success' => false,
            'error' => 'Версия бэкапа слишком старая для восстановления. Минимальная версия: 1.0.0',
            'backup_version' => $uploaded_version,
            'current_version' => $current_version
        ]);
        exit;
    }
}

$zip->close();

// Создаем уникальное имя файла, если файл с таким именем уже существует
$original_filename = basename($uploaded_file['name']);
$backup_dir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../backups/';
$target_filename = $original_filename;
$counter = 1;

while (file_exists($backup_dir . $target_filename)) {
    // Если файл уже существует, добавляем суффикс с числом
    $pathinfo = pathinfo($original_filename);
    $target_filename = $pathinfo['filename'] . '_' . $counter . '.' . $pathinfo['extension'];
    $counter++;

    if ($counter > 100) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode([
            'success' => false,
            'error' => 'Не удалось сгенерировать уникальное имя файла'
        ]);
        exit;
    }
}

$target_path = $backup_dir . $target_filename;

// Создаем директорию для бэкапов, если она не существует
if (!file_exists($backup_dir)) {
    if (!mkdir($backup_dir, 0755, true)) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode([
            'success' => false,
            'error' => 'Не удалось создать директорию для бэкапов'
        ]);
        exit;
    }
}

// Проверяем доступность директории для записи
if (!is_writable($backup_dir)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'error' => 'Директория для бэкапов недоступна для записи',
        'directory' => $backup_dir
    ]);
    exit;
}

// Перемещаем загруженный файл
if (!move_uploaded_file($uploaded_file['tmp_name'], $target_path)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'error' => 'Не удалось сохранить файл на сервере',
        'source' => $uploaded_file['tmp_name'],
        'target' => $target_path
    ]);
    exit;
}

// Устанавливаем корректные права на файл
chmod($target_path, 0644);

// Получаем информацию о загруженном файле для ответа
$file_size = filesize($target_path);
$file_modified = filemtime($target_path);

// Записываем действие в лог
logBackupAction($pdo, $user_id, 'upload', $target_filename, 'Загружен через веб-интерфейс');

// Возвращаем успешный ответ
echo json_encode([
    'success' => true,
    'message' => 'Бэкап успешно загружен',
    'filename' => $target_filename,
    'original_filename' => $original_filename,
    'size' => $file_size,
    'size_formatted' => formatBytess($file_size),
    'date' => date('d.m.Y H:i:s', $file_modified),
    'metadata' => $meta,
    'backup_info' => [
        'type' => $meta['type'] ?? 'unknown',
        'comment' => $meta['comment'] ?? '',
        'is_auto' => $meta['is_auto'] ?? false,
        'created' => $meta['created'] ?? date('d.m.Y H:i:s', $file_modified)
    ]
]);

exit;
