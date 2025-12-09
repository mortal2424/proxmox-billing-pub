<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

try {
    // Общее количество ВМ
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM vms WHERE user_id = ? AND vm_type = 'qemu'");
    $stmt->execute([$user_id]);
    $total = $stmt->fetchColumn();
    
    // Количество запущенных ВМ
    $stmt = $pdo->prepare("SELECT COUNT(*) as running FROM vms WHERE user_id = ? AND status = 'running' AND vm_type = 'qemu'");
    $stmt->execute([$user_id]);
    $running = $stmt->fetchColumn();
    
    // Суммарные ресурсы
    $stmt = $pdo->prepare("SELECT SUM(cpu) as cpu, SUM(ram) as ram, SUM(disk) as disk FROM vms WHERE user_id = ? AND vm_type = 'qemu'");
    $stmt->execute([$user_id]);
    $resources = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_count' => (int)$total,
        'running_count' => (int)$running,
        'total_cpu' => (int)$resources['cpu'],
        'total_ram' => (int)$resources['ram'],
        'total_disk' => (int)$resources['disk']
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}