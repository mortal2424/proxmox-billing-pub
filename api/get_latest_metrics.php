<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$vmId = $_GET['vm_id'] ?? '';
$type = $_GET['type'] ?? ''; // 'qemu' или 'lxc'

if (empty($vmId) || !in_array($type, ['qemu', 'lxc'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$table = $type === 'lxc' ? 'lxc_metrics' : 'vm_metrics';

$stmt = $pdo->prepare("
    SELECT 
        cpu_usage,
        mem_usage,
        mem_total,
        net_in,
        net_out,
        disk_read,
        disk_write
    FROM $table
    WHERE vm_id = ?
    ORDER BY timestamp DESC
    LIMIT 1
");
$stmt->execute([$vmId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

$data['cpu_usage'] = (float)$data['cpu_usage'];
$data['mem_usage'] = (float)$data['mem_usage'];
$data['mem_total'] = (float)$data['mem_total'];
$data['net_in'] = (float)$data['net_in'];
$data['net_out'] = (float)$data['net_out'];
$data['disk_read'] = (float)$data['disk_read'];
$data['disk_write'] = (float)$data['disk_write'];

if ($data) {
    echo json_encode(['success' => true, 'data' => $data]);
} else {
    echo json_encode(['success' => false, 'error' => 'No data found']);
}