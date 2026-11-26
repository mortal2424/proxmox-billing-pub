<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/zone_placement.php';
require_once __DIR__ . '/../includes/lxc_template_labels.php';
ensureSessionStarted();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn()) { http_response_code(401); echo '{}'; exit; }
$userId = (int)$_SESSION['user']['id'];
session_write_close();
$zone = filter_input(INPUT_GET, 'zone_id', FILTER_VALIDATE_INT);
$type = $_GET['vm_type'] ?? '';
if (!$zone || !in_array($type,['qemu','lxc'],true)) { http_response_code(400); echo '{}'; exit; }
try {
    $pdo = (new Database())->getConnection();
    if (!zoneAllowsUser($pdo, $zone, $userId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Зона доступности недоступна']);
        exit;
    }
    $nodes = placementNodes($pdo, $zone);
    $result = ['network'=>[], 'storage'=>[], 'media'=>[], 'cloud'=>[]];
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
            if ($type === 'qemu') {
                foreach (publishedCloudImages($pdo, (int)$node['id'], cloudImportVolumes([$api, 'request'], $node['node_name'], null, $pdo, (int)$node['id'])) as $key => $versions) {
                    $minimum = min(array_column($versions, 'minimum_disk'));
                    if (isset($result['cloud'][$key]) && $result['cloud'][$key]['minimum_disk'] <= $minimum) continue;
                    $result['cloud'][$key] = [
                        'id' => $key, 'label' => $versions[0]['label'], 'user' => $versions[0]['user'],
                        'minimum_disk' => $minimum, 'os_type' => $versions[0]['os_type'] ?? 'linux',
                    ];
                }
            }
            $images = $type === 'qemu' ? $api->getISOImages() : $api->getLXCTemplates();
            foreach ($images as $entry) {
                $volume = $entry['volid'];
                $result['media'][$volume] = $type === 'lxc'
                    ? ['id' => $volume, 'label' => lxcTemplateLabel($volume)]
                    : $volume;
            }
        } catch (Throwable $error) {
            error_log('Zone options unavailable for node ' . (int)$node['id']);
        }
    }
    if ($type === 'lxc') {
        $labels = array_count_values(array_column($result['media'], 'label'));
        foreach ($result['media'] as &$entry) {
            if ($labels[$entry['label']] > 1) {
                $entry['label'] .= ' · ' . explode(':', $entry['id'], 2)[0];
            }
        }
        unset($entry);
        uasort($result['media'], static fn($a, $b) => strnatcasecmp($a['label'], $b['label']) ?: strcmp($a['id'], $b['id']));
    }
    echo json_encode(array_map('array_values', $result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error'=>'Список ресурсов зоны недоступен']);
}
