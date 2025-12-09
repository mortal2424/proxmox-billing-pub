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
if (!$nodeId || !is_numeric($nodeId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный ID ноды']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    // Получаем информацию о ноде
    $stmt = $pdo->prepare("
        SELECT n.*, c.name as cluster_name 
        FROM proxmox_nodes n
        JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.id = ? AND n.is_active = 1
    ");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Нода не найдена или неактивна']);
        exit;
    }

    // Получаем список VMID, которые уже принадлежат пользователям
    $stmt = $pdo->prepare("
        SELECT vm_id FROM vms WHERE node_id = ?
        UNION
        SELECT vm_id FROM vms_admin WHERE node_id = ?
    ");
    $stmt->execute([$nodeId, $nodeId]);
    $usedVmids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Создаем экземпляр ProxmoxAPI
    $proxmox = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $nodeId,
        $pdo
    );
    
    // Получаем все ВМ с ноды
    $allVms = $proxmox->getVirtualMachines();
    
    // Фильтруем ВМ, исключая те, что уже принадлежат пользователям и шаблоны
    $availableVms = array_filter($allVms, function($vm) use ($usedVmids) {
        return !in_array($vm['vmid'], $usedVmids) && !isset($vm['template']);
    });
    
    // Форматируем данные для вывода
    $formattedVms = [];
    foreach ($availableVms as $vm) {
        $vmConfig = $proxmox->getVMConfig($vm['vmid']);
        
        // Получаем память (в MB)
        $memory = $vmConfig['memory'] ?? 0;
        // Получаем количество CPU
        $cores = $vmConfig['cores'] ?? $vmConfig['sockets'] ?? 1;
        
        // Вычисляем общий размер диска
        $totalDisk = 0;
        foreach ($vmConfig as $key => $value) {
            if (strpos($key, 'disk') === 0 || strpos($key, 'ide') === 0 || 
                strpos($key, 'sata') === 0 || strpos($key, 'scsi') === 0) {
                if (preg_match('/size=(\d+)(\w+)/i', $value, $matches)) {
                    $size = (int)$matches[1];
                    $unit = strtolower($matches[2]);
                    
                    // Конвертируем в GB
                    switch ($unit) {
                        case 't': $size *= 1024; break;
                        case 'g': break;
                        case 'm': $size /= 1024; break;
                        case 'k': $size /= 1024 / 1024; break;
                        default: $size = 0;
                    }
                    
                    $totalDisk += $size;
                }
            }
        }
        
        $formattedVms[] = [
            'vmid' => $vm['vmid'],
            'name' => $vm['name'] ?? 'VM ' . $vm['vmid'],
            'status' => $vm['status'] ?? 'stopped',
            'cpus' => $cores,
            'mem' => round($memory / 1024, 1), // Конвертируем MB в GB
            'disk' => round($totalDisk, 1) // Округляем до 1 знака после запятой
        ];
    }

    echo json_encode(['vms' => $formattedVms]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка получения данных',
        'details' => $e->getMessage()
    ]);
    error_log("Proxmox Admin VMs Error: " . $e->getMessage());
}