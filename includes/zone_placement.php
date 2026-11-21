<?php
require_once __DIR__ . '/proxmox_functions.php';

function placementNodes(PDO $pdo, int $zone): array
{
    $query = $pdo->prepare('SELECT n.* FROM proxmox_nodes n JOIN proxmox_clusters z ON z.id=n.cluster_id
        WHERE z.id=? AND z.is_active=1 AND n.is_active=1 AND n.available_for_users=1 ORDER BY n.id');
    $query->execute([$zone]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function placementPoolEntries(PDO $pdo, int $zone): array
{
    $query = $pdo->prepare('SELECT p.node_id,p.kind,p.pool_name,p.resource
        FROM node_placement_pools p JOIN proxmox_nodes n ON n.id=p.node_id
        WHERE n.cluster_id=? AND n.is_active=1 AND n.available_for_users=1
        ORDER BY p.pool_name,p.resource');
    $query->execute([$zone]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function placementClient(array $node, PDO $pdo): ProxmoxAPI
{
    return new ProxmoxAPI($node['hostname'], $node['username'], $node['password'], 22,
        $node['node_name'], $node['id'], $pdo);
}

function appliedPlacementSdn(ProxmoxAPI $api, array $interfaces): array
{
    $live = [];
    foreach ($interfaces as $interface) {
        if (!empty($interface['active']) && !empty($interface['iface'])) $live[$interface['iface']] = true;
    }
    return array_values(array_filter($api->getSDNNetworks(), static fn(array $entry): bool =>
        isset($live[$entry['vnet'] ?? ''])
    ));
}

function choosePlacement(PDO $pdo, int $zone, string $type, string $networkPool, string $storagePool,
    string $sdn, string $media, int $cpu, int $ram, int $disk, bool $windows = false): array
{
    if (!in_array($type, ['qemu', 'lxc'], true) || min($cpu,$ram,$disk) < 1 || $storagePool === '' ||
        ($networkPool === '' && $sdn === '') || ($networkPool !== '' && $sdn !== '')) {
        throw new InvalidArgumentException('Выберите пул хранилищ и один вариант сети');
    }
    $pools = [];
    foreach (placementPoolEntries($pdo, $zone) as $entry) {
        $pools[$entry['node_id']][$entry['kind']][$entry['pool_name']][] = $entry['resource'];
    }
    $candidates = [];
    foreach (placementNodes($pdo, $zone) as $node) {
        $available = $pools[$node['id']] ?? [];
        $storagePoolMembers = $available['storage'][$storagePool] ?? [];
        if (!$storagePoolMembers) continue;
        $bridges = $available['network'][$networkPool] ?? [];
        if ($sdn === '' && !$bridges) continue;
        try {
            $api = placementClient($node, $pdo);
            $base = '/nodes/' . rawurlencode($node['node_name']);
            $status = $api->request('GET', $base . '/status');
            $freeRam = (int)($status['memory']['free'] ?? 0);
            if ($freeRam < $ram * 1048576 || (int)($status['cpuinfo']['cpus'] ?? 0) < $cpu) continue;
            $network = '';
            $interfaces = $api->request('GET', $base . '/network');
            if ($sdn !== '') {
                $listed = array_column(appliedPlacementSdn($api, $interfaces), 'name');
                if (!in_array($sdn, $listed, true)) continue;
                $network = basename($sdn);
            } else {
                foreach ($interfaces as $interface) {
                    if (in_array($interface['type'] ?? '', ['bridge','OVSBridge'], true) &&
                        !empty($interface['active']) && in_array($interface['iface'] ?? '', $bridges, true)) {
                        $network = $interface['iface'];
                        break;
                    }
                }
                if ($network === '') continue;
            }
            if ($media !== '' || $windows) {
                $images = $type === 'qemu' ? $api->getISOImages() : $api->getLXCTemplates();
                $volumes = array_column($images, 'volid');
                if ($media !== '' && !in_array($media, $volumes, true)) continue;
                if ($windows && !in_array('local:iso/virtio-win.iso', $volumes, true)) continue;
            }
            foreach ($api->request('GET', $base . '/storage') as $storage) {
                $content = explode(',', $storage['content'] ?? '');
                if (empty($storage['active']) || empty($storage['enabled']) ||
                    !in_array($storage['storage'], $storagePoolMembers, true) ||
                    !in_array($type === 'qemu' ? 'images' : 'rootdir', $content, true) ||
                    (float)($storage['avail'] ?? 0) < $disk * 1073741824 + ($type === 'qemu' ? 67108864 : 0)) continue;
                $candidates[] = [
                    'node' => $node, 'network' => $network, 'storage' => $storage['storage'],
                    'score' => [(float)($status['cpu'] ?? 1), -$freeRam, -(float)$storage['avail'], (int)$node['id'], $storage['storage']],
                ];
            }
        } catch (Throwable $error) {
            error_log('Placement inventory unavailable for node ' . (int)$node['id']);
        }
    }
    if (!$candidates) throw new RuntimeException('В выбранной зоне нет ноды с нужными ресурсами, сетью, образом и хранилищем');
    usort($candidates, static fn($a,$b) => $a['score'] <=> $b['score']);
    return $candidates[0];
}
