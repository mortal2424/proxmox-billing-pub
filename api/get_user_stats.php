<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? 0;

$db = new Database();
$pdo = $db->getConnection();

$stats = [
    'success' => true,
    'vm_running' => 0,
    'vm_total' => 0,
    'lxc_running' => 0,
    'lxc_total' => 0,
    'total_cpu' => 0,
    'total_ram' => 0
];

// Получаем статистику по VM
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram
    FROM vms 
    WHERE user_id = ? AND vm_type = 'qemu'
");
$stmt->execute([$user_id]);
$vmStats = $stmt->fetch();
if ($vmStats) {
    $stats['vm_running'] = (int)$vmStats['running'];
    $stats['vm_total'] = (int)$vmStats['total'];
    $stats['total_cpu'] += (int)$vmStats['total_cpu'];
    $stats['total_ram'] += (int)$vmStats['total_ram'] / 1024; // в GB
}

// Получаем статистику по LXC
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram
    FROM vms 
    WHERE user_id = ? AND vm_type = 'lxc'
");
$stmt->execute([$user_id]);
$lxcStats = $stmt->fetch();
if ($lxcStats) {
    $stats['lxc_running'] = (int)$lxcStats['running'];
    $stats['lxc_total'] = (int)$lxcStats['total'];
    $stats['total_cpu'] += (int)$lxcStats['total_cpu'];
    $stats['total_ram'] += (int)$lxcStats['total_ram'] / 1024; // в GB
}

echo json_encode($stats);