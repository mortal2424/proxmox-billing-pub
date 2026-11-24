<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guest_metrics.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
ensureSessionStarted();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Требуется вход']);
    exit;
}
$pdo = (new Database())->getConnection();
$account = currentSessionAccount($pdo);
if (!$account) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Требуется вход']);
    exit;
}
$type = $metricsType ?? ($_GET['type'] ?? null);
$id = is_string($type) ? customerMetricsGuest($pdo, (int) $account['id'], $type, $_GET) : null;
if ($id === null) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Гость не найден']);
    exit;
}
if ($latestMetrics ?? false) {
    $rows = guestMetricRows($pdo, $id, $type, null);
    if (!$rows) {
        echo json_encode(['success'=>false,'error'=>'No data found']);
        exit;
    }
    unset($rows[0]['timestamp']);
    echo json_encode(['success'=>true,'data'=>array_map('floatval', $rows[0])]);
    exit;
}
$timeframe = is_string($_GET['timeframe'] ?? null) ? $_GET['timeframe'] : 'hour';
echo json_encode(historicalGuestMetrics($pdo, $id, $type, $timeframe));
