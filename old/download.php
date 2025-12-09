<?php
// Исправляем пути к подключаемым файлам
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Проверяем авторизацию
if (!isLoggedIn()) {
    header('HTTP/1.0 403 Forbidden');
    die('Доступ запрещен');
}

$db = new Database();
$pdo = $db->getConnection();

if (!isset($_GET['file'])) {
    header('HTTP/1.0 400 Bad Request');
    die('Не указан файл для скачивания');
}

$file_path = urldecode($_GET['file']);
$safe_path = realpath(UPLOAD_DIR . basename($file_path));

// Проверяем, что файл существует и находится в разрешенной директории
if (!$safe_path || strpos($safe_path, realpath(UPLOAD_DIR)) !== 0) {
    header('HTTP/1.0 404 Not Found');
    die('Файл не найден');
}

// Получаем информацию о файле из БД
$stmt = $pdo->prepare("SELECT * FROM ticket_attachments WHERE file_path = ?");
$stmt->execute([$file_path]);
$file_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file_info) {
    header('HTTP/1.0 404 Not Found');
    die('Файл не найден в базе данных');
}

// Проверяем права доступа
$ticket_stmt = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
$ticket_stmt->execute([$file_info['ticket_id']]);
$ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

if (!isAdmin() && $ticket['user_id'] != $_SESSION['user']['id']) {
    header('HTTP/1.0 403 Forbidden');
    die('У вас нет прав для скачивания этого файла');
}

// Отправляем файл
header('Content-Description: File Transfer');
header('Content-Type: ' . mime_content_type($safe_path));
header('Content-Disposition: attachment; filename="' . basename($file_info['file_name']) . '"');
header('Content-Length: ' . filesize($safe_path));
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');

readfile($safe_path);
exit;