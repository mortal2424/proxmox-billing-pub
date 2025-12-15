<?php
// check_updates_count.php - AJAX проверка количества обновлений
session_start();

// Определяем корневую директорию
define('ROOT_PATH', dirname(__DIR__));
define('ADMIN_PATH', __DIR__);
define('UPDATES_PATH', ADMIN_PATH . '/updates');

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/auth.php';

header('Content-Type: application/json');

// Проверяем права администратора
if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

$admin_role = $_SESSION['role'] ?? 'user';
if ($admin_role !== 'admin' && $admin_role !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    $available_updates_count = 0;
    
    // Проверяем существование папки обновлений
    if (file_exists(UPDATES_PATH)) {
        // Проверяем, существует ли таблица system_updates
        $table_exists = $pdo->query("SHOW TABLES LIKE 'system_updates'")->rowCount() > 0;
        $applied_versions = [];
        
        if ($table_exists) {
            // Получаем список примененных версий
            $applied_versions = $pdo->query("SELECT version FROM system_updates WHERE success = 1")->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Сканируем папку обновлений
        $folders = scandir(UPDATES_PATH);
        foreach ($folders as $folder) {
            $folder_path = UPDATES_PATH . '/' . $folder;
            
            // Проверяем, что это папка и соответствует формату версии X.Y.Z
            if ($folder != '.' && $folder != '..' && is_dir($folder_path) && preg_match('/^\d+\.\d+\.\d+$/', $folder)) {
                // Проверяем, применено ли обновление
                if (!in_array($folder, $applied_versions)) {
                    $available_updates_count++;
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => $available_updates_count,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'count' => 0
    ]);
}