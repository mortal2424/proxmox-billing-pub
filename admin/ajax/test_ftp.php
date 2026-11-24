<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

session_start();
checkAuth();
$pdo = (new Database())->getConnection();
$guardQuery = $pdo->prepare('SELECT is_admin,is_active FROM users WHERE id=?');
$guardQuery->execute([(int)($_SESSION['user']['id'] ?? 0)]);
$guardUser = $guardQuery->fetch(PDO::FETCH_ASSOC);
if (!$guardUser || !$guardUser['is_admin'] || !$guardUser['is_active']) {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Доступ запрещён']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['backup_csrf'] ?? null) ||
    !isset($_SESSION['backup_csrf']) || !hash_equals($_SESSION['backup_csrf'], $_POST['backup_csrf'])) {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Неверный метод или CSRF-токен']); exit;
}


header('Content-Type: application/json');

$host = $_POST['ftp_host'] ?? '';
$username = $_POST['ftp_username'] ?? '';
$password = $_POST['ftp_password'] ?? '';

try {
    require_once __DIR__ . '/../../includes/backup_ftps.php';
    transferBackupFtps($host, $username, $password);
    echo json_encode(['success' => true, 'message' => 'Подключение успешно']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>