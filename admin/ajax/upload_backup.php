<?php
/**
 * Обработчик загрузки бэкапов через веб-интерфейс
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_functions.php';
require_once __DIR__ . '/../../includes/backup_restore.php';

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
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['backup_csrf'] ?? null) ||
    !isset($_SESSION['backup_csrf']) || !hash_equals($_SESSION['backup_csrf'], $_POST['backup_csrf'])) {
    http_response_code(403); echo json_encode(['success' => false, 'error' => 'Неверный метод или CSRF-токен']); exit;
}


// Проверяем, является ли пользователь администратором
try {
    $stmt = safeQuery($pdo, "SELECT is_admin,is_active FROM users WHERE id = ?", [$user_id], 'users');
    $user = $stmt->fetch();
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
    exit;
}

if (!$user || !$user['is_admin'] || !$user['is_active']) {
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

try {
    $analysis = inspectBackupArchive($uploaded_file['tmp_name']);
    $meta = $analysis['meta'];
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'Повреждены метаданные архива']);
    exit;
}
$original_filename = basename($uploaded_file['name']);
$backup_dir = BACKUP_DIR;
$target_filename = 'upload_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(12)) . '.zip';

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
chmod($target_path, 0600);

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
