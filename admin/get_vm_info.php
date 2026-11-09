<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

$nodeId = $_GET['node_id'] ?? null;
$vmid = $_GET['vmid'] ?? null;

if (!$nodeId || !$vmid) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указаны параметры node_id и vmid']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->prepare("
        SELECT n.*, c.name as cluster_name 
        FROM proxmox_nodes n
        JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.id = ? AND n.is_active = 1
    ");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) {
        throw new Exception("Нода не найдена или неактивна");
    }

    $proxmox = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $nodeId, // Добавляем ID ноды
        $pdo     // Добавляем подключение к БД
    );
    
    $vmConfig = $proxmox->getVMConfig($vmid);
    $vmStatus = $proxmox->getVMStatus($vmid);
    
    // QEMU reports configured sizes on each attached disk, not in a top-level field.
    $diskBytes = 0;
    $units = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4];
    foreach ($vmConfig ?? [] as $device => $definition) {
        if (!preg_match('/^(?:scsi|virtio|sata|ide)\d+$/D', $device) || !is_string($definition)) {
            continue;
        }
        if (preg_match('/(?:^|,)media=cdrom(?:,|$)/', $definition)) {
            continue;
        }
        if (preg_match('/(?:^|,)size=([0-9]+(?:\.[0-9]+)?)([KMGT]?)(?:,|$)/i', $definition, $size)) {
            $diskBytes += (float) $size[1] * $units[strtoupper($size[2])];
        }
    }
    if ($diskBytes === 0) {
        $diskBytes = (float) ($vmStatus['maxdisk'] ?? $vmConfig['disk'] ?? 0);
    }

    $result = [
        'vmid' => $vmid,
        'name' => $vmConfig['name'] ?? 'N/A',
        'status' => $vmStatus['status'] ?? 'unknown',
        'cpus' => $vmConfig['cores'] ?? 1,
        'mem' => round(($vmConfig['memory'] ?? 512) / 1024, 1),
        'disk' => round($diskBytes / (1024 ** 3), 1),
        'ip' => $vmConfig['ipconfig0'] ?? null
    ];
    
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка получения информации о VM: ' . $e->getMessage()]);
}