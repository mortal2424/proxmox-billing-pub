<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/zone_placement.php';
ensureSessionStarted();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn()) { http_response_code(401); echo '{}'; exit; }
session_write_close();
$zone = filter_input(INPUT_GET, 'zone_id', FILTER_VALIDATE_INT);
$type = $_GET['vm_type'] ?? '';
if (!$zone || !in_array($type,['qemu','lxc'],true)) { http_response_code(400); echo '{}'; exit; }
try {
    $pdo = (new Database())->getConnection();
    $nodes = placementNodes($pdo, $zone);
    $result = ['network'=>[], 'storage'=>[], 'sdn'=>[], 'media'=>[]];
    foreach (placementPoolEntries($pdo, $zone) as $entry) {
        $result[$entry['kind']][$entry['pool_name']] = $entry['pool_name'];
    }
    foreach ($nodes as $node) {
        try {
            $api = placementClient($node, $pdo);
            foreach ($api->getSDNNetworks() as $entry) $result['sdn'][$entry['name']] = $entry['name'];
            $images = $type === 'qemu' ? $api->getISOImages() : $api->getLXCTemplates();
            foreach ($images as $entry) $result['media'][$entry['volid']] = $entry['volid'];
        } catch (Throwable $error) {
            error_log('Zone options unavailable for node ' . (int)$node['id']);
        }
    }
    echo json_encode(array_map('array_values', $result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error'=>'Список ресурсов зоны недоступен']);
}
