<?php
// ajax_get_system_info.php - AJAX получение информации о системе
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

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    // Получаем текущую версию системы
    $current_version = '2.5.1';
    $table_exists = $pdo->query("SHOW TABLES LIKE 'system_versions'")->rowCount() > 0;
    
    if ($table_exists) {
        $stmt = $pdo->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1");
        if ($stmt->rowCount() > 0) {
            $current_version = $stmt->fetchColumn();
        }
    }
    
    // Получаем количество доступных обновлений
    $available_updates_count = 0;
    $updates_path = dirname(__DIR__) . '/admin/updates';
    if (file_exists($updates_path)) {
        $applied_versions = [];
        $table_exists = $pdo->query("SHOW TABLES LIKE 'system_updates'")->rowCount() > 0;
        
        if ($table_exists) {
            $applied_versions = $pdo->query("SELECT version FROM system_updates WHERE success = 1")->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $folders = scandir($updates_path);
        foreach ($folders as $folder) {
            $folder_path = $updates_path . '/' . $folder;
            
            if ($folder != '.' && $folder != '..' && is_dir($folder_path) && preg_match('/^\d+\.\d+\.\d+$/', $folder)) {
                if (!in_array($folder, $applied_versions)) {
                    $available_updates_count++;
                }
            }
        }
    }
    
    // Получаем статистику системы
    $stats = [];
    try {
        $stats['total_users'] = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        $stats['active_vms'] = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status = 'running'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        $stats['total_nodes'] = $pdo->query("SELECT COUNT(*) as count FROM proxmox_nodes WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        $stats['open_tickets'] = $pdo->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        $stats['today_payments'] = $pdo->query("SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        $stats['monthly_income'] = $pdo->query("SELECT SUM(amount) as sum FROM payments WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetch(PDO::FETCH_ASSOC)['sum'] ?? 0;
        $stats['new_users_today'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error loading stats in ajax: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'version' => $current_version,
        'available_updates' => $available_updates_count,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'version' => '2.5.1',
        'available_updates' => 0
    ]);
}