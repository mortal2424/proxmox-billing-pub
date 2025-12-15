<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../admin_functions.php';

session_start();
checkAuth();

header('Content-Type: application/json');

// Проверяем права администратора
$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

try {
    $stmt = safeQuery($pdo, "SELECT is_admin FROM users WHERE id = ?", [$user_id], 'users');
    $user = $stmt->fetch();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка проверки прав: ' . $e->getMessage()]);
    exit;
}

if (!$user || !$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

$days = $_POST['days'] ?? 7;
$logs_dir = realpath(__DIR__ . '/../../../logs/');
$compressed = 0;

if (is_dir($logs_dir)) {
    $files = scandir($logs_dir);
    $cutoff_time = time() - ($days * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filepath = $logs_dir . '/' . $file;
        if (is_file($filepath) && preg_match('/\.(log|txt)$/i', $file)) {
            if (filemtime($filepath) < $cutoff_time) {
                // Архивируем файл
                $zip = new ZipArchive();
                $zip_filename = $logs_dir . '/' . $file . '.zip';
                
                if ($zip->open($zip_filename, ZipArchive::CREATE) === TRUE) {
                    $zip->addFile($filepath, $file);
                    $zip->close();
                    
                    // Удаляем оригинальный файл
                    unlink($filepath);
                    $compressed++;
                }
            }
        }
    }
}

echo json_encode(['success' => true, 'message' => "Архивировано {$compressed} файлов"]);
?>