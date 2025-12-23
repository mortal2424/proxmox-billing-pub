<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

if (!isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

header('Content-Type: application/json');

$vmId = $_GET['vm_id'] ?? '';
$nodeId = $_GET['node_id'] ?? '';
$timeframe = $_GET['timeframe'] ?? 'hour';

if (empty($vmId) || empty($nodeId)) {
    echo json_encode(['success' => false, 'error' => 'Не указаны параметры VM ID или Node ID.']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем информацию о ноде
$stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
$stmt->execute([$nodeId]);
$node = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    echo json_encode(['success' => false, 'error' => 'Нода не найдена.']);
    exit;
}

// Проверяем, что ВМ принадлежит администратору
$stmt = $pdo->prepare("SELECT * FROM vms_admin WHERE vm_id = ? AND node_id = ?");
$stmt->execute([$vmId, $nodeId]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    echo json_encode(['success' => false, 'error' => 'Административная виртуальная машина не найдена.']);
    exit;
}

$proxmoxApi = new ProxmoxAPI(
    $node['hostname'],
    $node['username'],
    $node['password'],
    $node['ssh_port'] ?? 22,
    $node['node_name'],
    $nodeId,
    $pdo
);

try {
    $vmInfo = $proxmoxApi->getVMStatus($vmId);
    $rrdData = $proxmoxApi->getRRDData($vmId, $timeframe);

    $labels = [];
    $cpuData = [];
    $memData = [];
    $memTotalData = [];
    $netInData = [];
    $netOutData = [];
    $diskReadData = [];
    $diskWriteData = [];

    if ($rrdData && is_array($rrdData)) {
        foreach ($rrdData as $point) {
            $timestamp = $point['time'];
            switch ($timeframe) {
                case 'hour':
                    $labels[] = date('H:i', $timestamp);
                    break;
                case 'day':
                    $labels[] = date('d M H:i', $timestamp);
                    break;
                case 'week':
                    $labels[] = date('d M H:i', $timestamp);
                    break;
                case 'month':
                    $labels[] = date('d M', $timestamp);
                    break;
                case 'year':
                    $labels[] = date('M Y', $timestamp);
                    break;
                default:
                    $labels[] = date('H:i', $timestamp);
            }
            
            $cpuData[] = round($point['cpu'] * 100, 2);
            
            // Память: переводим в гигабайты (1 GB = 1024 MB)
            $memData[] = round($point['mem'] / (1024 * 1024 * 1024), 2);
            $memTotalData[] = round($vmInfo['maxmem'] / (1024 * 1024 * 1024), 1);
            
            // Сетевой трафик: переводим в Mbits/s
            $netInData[] = round(($point['netin'] * 8) / (1024 * 1024 * 1024), 2);
            $netOutData[] = round(($point['netout'] * 8) / (1024 * 1024 * 1024), 2);
            
            // Дисковые операции: переводим в мегабайты
            $diskReadData[] = round($point['diskread'] / 1024, 2);
            $diskWriteData[] = round($point['diskwrite'] / 1024, 2);
        }
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'cpuData' => $cpuData,
        'memData' => $memData,
        'memTotalData' => $memTotalData,
        'netInData' => $netInData,
        'netOutData' => $netOutData,
        'diskReadData' => $diskReadData,
        'diskWriteData' => $diskWriteData
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка при получении данных: ' . $e->getMessage()]);
}