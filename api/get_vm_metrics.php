<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

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

// Проверяем доступ пользователя к VM
$stmt = $pdo->prepare("SELECT 1 FROM vms WHERE vm_id = ? AND user_id = ?");
$stmt->execute([$vmId, $_SESSION['user']['id']]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Виртуальная машина не найдена или у вас нет к ней доступа.']);
    exit;
}

// Определяем интервал времени для выборки
$interval = '';
switch ($timeframe) {
    case 'hour': $interval = '1 HOUR'; break;
    case 'day': $interval = '1 DAY'; break;
    case 'week': $interval = '1 WEEK'; break;
    case 'month': $interval = '1 MONTH'; break;
    case 'year': $interval = '1 YEAR'; break;
    default: $interval = '1 HOUR';
}

$stmt = $pdo->prepare("
    SELECT 
        timestamp,
        cpu_usage,
        mem_usage,
        mem_total,
        net_in,
        net_out,
        disk_read,
        disk_write
    FROM vm_metrics
    WHERE vm_id = ? AND timestamp >= NOW() - INTERVAL $interval
    ORDER BY timestamp ASC
");
$stmt->execute([$vmId]);
$metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($metrics)) {
    echo json_encode(['success' => false, 'error' => 'Данные метрик не найдены.']);
    exit;
}

$result = [
    'success' => true,
    'labels' => [],
    'cpuData' => [],
    'memData' => [],
    'memTotalData' => [],
    'netInData' => [],
    'netOutData' => [],
    'diskReadData' => [],
    'diskWriteData' => []
];

foreach ($metrics as $metric) {
    switch ($timeframe) {
        case 'hour':
            $result['labels'][] = date('H:i', strtotime($metric['timestamp']));
            break;
        case 'day':
            $result['labels'][] = date('d M H:i', strtotime($metric['timestamp']));
            break;
        case 'week':
            $result['labels'][] = date('d M H:i', strtotime($metric['timestamp']));
            break;
        case 'month':
            $result['labels'][] = date('d M', strtotime($metric['timestamp']));
            break;
        case 'year':
            $result['labels'][] = date('M Y', strtotime($metric['timestamp']));
            break;
        default:
            $result['labels'][] = date('H:i', strtotime($metric['timestamp']));
    }
    
    $result['cpuData'][] = $metric['cpu_usage'];
    $result['memData'][] = $metric['mem_usage'];
    $result['memTotalData'][] = $metric['mem_total'];
    $result['netInData'][] = $metric['net_in'];
    $result['netOutData'][] = $metric['net_out'];
    $result['diskReadData'][] = $metric['disk_read'];
    $result['diskWriteData'][] = $metric['disk_write'];
}

echo json_encode($result);