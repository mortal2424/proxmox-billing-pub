<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

session_start();
checkAuth();

header('Content-Type: application/json');

$host = $_POST['ftp_host'] ?? '';
$username = $_POST['ftp_username'] ?? '';
$password = $_POST['ftp_password'] ?? '';

try {
    $conn = ftp_connect($host);
    if (!$conn) {
        throw new Exception("Не удалось подключиться к FTP серверу");
    }
    
    if (!ftp_login($conn, $username, $password)) {
        throw new Exception("Неверные учетные данные FTP");
    }
    
    ftp_close($conn);
    
    echo json_encode(['success' => true, 'message' => 'Подключение успешно']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>