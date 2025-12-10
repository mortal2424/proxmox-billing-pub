<?php
session_start();
require_once '../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    // Статистика пользователей
    $users_stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $total_users = $users_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Статистика ВМ
    $vms_stmt = $pdo->query("SELECT 
        COUNT(*) as total_vms,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as active_vms
        FROM vms");
    $vms_stats = $vms_stmt->fetch(PDO::FETCH_ASSOC);
    $total_vms = $vms_stats['total_vms'] ?? 0;
    $active_vms = $vms_stats['active_vms'] ?? 0;
    
    // Открытые тикеты
    $tickets_stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status IN ('open', 'pending')");
    $open_tickets = $tickets_stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'total_users' => (int)$total_users,
        'total_vms' => (int)$total_vms,
        'active_vms' => (int)$active_vms,
        'open_tickets' => (int)$open_tickets
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}