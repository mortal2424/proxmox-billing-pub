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
        $key = $entry['pool_name'];
        $label = $entry['display_name'] ?: $key;
        if (!isset($result[$entry['kind']][$key]) || $result[$entry['kind']][$key]['label'] === $key) {
            $result[$entry['kind']][$key] = ['id'=>$key, 'label'=>$label];
        }
    }
    foreach ($nodes as $node) {
        try {
            $api = placementClient($node, $pdo);
            $interfaces = $api->request('GET', '/nodes/' . rawurlencode($node['node_name']) . '/network', ['type'=>'any_bridge']);
            foreach (appliedPlacementSdn($api, $interfaces) as $entry) $result['sdn'][$entry['name']] = $entry['name'];
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
