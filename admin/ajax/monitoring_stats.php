<?php
// admin/ajax/monitoring_stats.php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем актуальные данные
$response = [];

// Системная информация
$disk_total = disk_total_space('/');
$disk_free = disk_free_space('/');
$disk_used = $disk_total - $disk_free;
$response['disk_percent'] = $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 2) : 0;

// Использование памяти
$meminfo = @file('/proc/meminfo');
$mem_total = 0;
$mem_available = 0;
if ($meminfo) {
    foreach ($meminfo as $line) {
        if (preg_match('/^MemTotal:\s+(\d+)\s*kB/i', $line, $matches)) {
            $mem_total = $matches[1] * 1024;
        } elseif (preg_match('/^MemAvailable:\s+(\d+)\s*kB/i', $line, $matches)) {
            $mem_available = $matches[1] * 1024;
        }
    }
}
$mem_used = $mem_total - $mem_available;
$response['memory_percent'] = $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 2) : 0;

// CPU
$response['cpu_percent'] = getCpuUsage();
$response['load'] = sys_getloadavg();

// Пользователи
$response['total_users'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users")->fetchColumn();
$response['active_users'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$response['new_today'] = (int)safeQuery($pdo, 
    "SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

// ВМ
if (safeQuery($pdo, "SHOW TABLES LIKE 'vms'")->rowCount() > 0) {
    $response['total_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms")->fetchColumn();
    $response['running_vms'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'running'")->fetchColumn();
    $response['vm_errors'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM vms WHERE status = 'error'")->fetchColumn();
}

// Платежи
if (safeQuery($pdo, "SHOW TABLES LIKE 'payments'")->rowCount() > 0) {
    $response['pending_payments'] = (int)safeQuery($pdo, 
        "SELECT COUNT(*) FROM payments WHERE status = 'pending'"
    )->fetchColumn();
}

// Тикеты
if (safeQuery($pdo, "SHOW TABLES LIKE 'tickets'")->rowCount() > 0) {
    $response['open_tickets'] = (int)safeQuery($pdo, 
        "SELECT COUNT(*) FROM tickets WHERE status = 'open'"
    )->fetchColumn();
}

// Кластер
if (safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0) {
    $response['total_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes")->fetchColumn();
    $response['active_nodes'] = (int)safeQuery($pdo, "SELECT COUNT(*) FROM proxmox_nodes WHERE is_active = 1")->fetchColumn();
    $response['inactive_nodes'] = $response['total_nodes'] - $response['active_nodes'];
}

$response['success'] = true;
$response['timestamp'] = date('Y-m-d H:i:s');

header('Content-Type: application/json');
echo json_encode($response);

function getCpuUsage() {
    $stat1 = @file('/proc/stat');
    if (!$stat1) return 0;
    
    $info1 = explode(' ', preg_replace('!cpu +!', '', $stat1[0]));
    
    usleep(100000); // 0.1 секунды
    
    $stat2 = @file('/proc/stat');
    if (!$stat2) return 0;
    
    $info2 = explode(' ', preg_replace('!cpu +!', '', $stat2[0]));
    
    $dif = [];
    $dif['user'] = $info2[0] - $info1[0];
    $dif['nice'] = $info2[1] - $info1[1];
    $dif['sys'] = $info2[2] - $info1[2];
    $dif['idle'] = $info2[3] - $info1[3];
    $total = array_sum($dif);
    
    return $total > 0 ? round(($total - $dif['idle']) / $total * 100, 2) : 0;
}
?>