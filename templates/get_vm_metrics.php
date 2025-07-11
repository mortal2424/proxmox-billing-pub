<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

checkAuth();

header('Content-Type: application/json');

$vmId = $_GET['vm_id'] ?? '';
$timeframe = $_GET['timeframe'] ?? 'hour';

if (empty($vmId)) {
    echo json_encode(['success' => false, 'error' => 'Не указан параметр VM ID.']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$stmt = $pdo->prepare("
    SELECT v.*, n.hostname as node_hostname, n.node_name, n.username, n.password 
    FROM vms v
    JOIN proxmox_nodes n ON n.id = v.node_id
    WHERE v.vm_id = ? AND v.user_id = ?
");
$stmt->execute([$vmId, $_SESSION['user']['id']]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    echo json_encode(['success' => false, 'error' => 'Виртуальная машина не найдена или у вас нет к ней доступа.']);
    exit;
}

$proxmoxApi = new ProxmoxAPI(
    $vm['node_hostname'],
    $vm['username'],
    $vm['password'],
    22,
    $vm['node_name'],
    $vm['node_id'],
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
            $memData[] = round($point['mem'] / (1024 * 1024 * 1024), 2); // Используемая память в GB
            $memTotalData[] = round($vmInfo['maxmem'] / (1024 * 1024 * 1024), 1); // Общая память в GB
            // Сетевой трафик: переводим в Mbits/s (1 byte = 8 bits, 1 Mbit = 1024*1024 bits)
            $netInData[] = round(($point['netin'] * 8) / (1024 * 1024 * 1024), 2); // Входящий в Mbits/s
            $netOutData[] = round(($point['netout'] * 8) / (1024 * 1024 * 1024), 2); // Исходящий в Mbits/s
            // Дисковые операции: переводим в мегабайты (1 MB = 1024 KB)
            $diskReadData[] = round($point['diskread'] / 1024, 2); // Чтение в MB
            $diskWriteData[] = round($point['diskwrite'] / 1024, 2); // Запись в MB
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