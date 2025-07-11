<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

$db = new Database();
$pdo = $db->getConnection();

// Получаем список всех активных LXC контейнеров
$stmt = $pdo->query("SELECT vm_id, node_id FROM vms WHERE vm_type = 'lxc' AND status = 'running'");
$containers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($containers as $container) {
    $nodeStmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
    $nodeStmt->execute([$container['node_id']]);
    $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) continue;

    $proxmoxApi = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        22,
        $node['node_name'],
        $node['id'],
        $pdo
    );

    try {
        $containerInfo = $proxmoxApi->getLXCStatusMetric($container['vm_id']);
        $rrdData = $proxmoxApi->getLxcRRDData($container['vm_id'], 'hour');
        
        if (empty($rrdData)) continue;
        
        // Берем последнюю точку данных
        $lastPoint = end($rrdData);
        
        // Вставляем данные в БД
        $insertStmt = $pdo->prepare("
            INSERT INTO lxc_metrics 
            (vm_id, timestamp, cpu_usage, mem_usage, mem_total, net_in, net_out, disk_read, disk_write)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $container['vm_id'],
            round($lastPoint['cpu'] * 100, 2),
            round($lastPoint['mem'] / (1024 * 1024 * 1024), 2),
            round($containerInfo['maxmem'] / (1024 * 1024 * 1024), 2),
            round(($lastPoint['netin'] * 8) / (1024 * 1024), 2),
            round(($lastPoint['netout'] * 8) / (1024 * 1024), 2),
            round($lastPoint['diskread'] / 1024, 2),
            round($lastPoint['diskwrite'] / 1024, 2)
        ]);
        
    } catch (Exception $e) {
        error_log("Error collecting metrics for LXC {$container['vm_id']}: " . $e->getMessage());
    }
}