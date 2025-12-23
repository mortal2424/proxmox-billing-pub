<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            v.id, v.vm_id, v.hostname, v.cpu, v.ram, v.disk, v.status, 
            v.created_at, v.node_id, v.ip_address,
            t.name as tariff_name, 
            n.node_name
        FROM vms v
        LEFT JOIN tariffs t ON t.id = v.tariff_id
        JOIN proxmox_nodes n ON n.id = v.node_id
        WHERE v.user_id = ? AND v.vm_type = 'qemu'
        ORDER BY v.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $vms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'vms' => $vms
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}