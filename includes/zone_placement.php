<?php
require_once __DIR__ . '/zone_access.php';
require_once __DIR__ . '/proxmox_functions.php';
require_once __DIR__ . '/cloud_images.php';
require_once __DIR__ . '/storage_placement.php';

function placementNodes(PDO $pdo, int $zone): array
{
    $query = $pdo->prepare('SELECT n.*,z.max_vms_per_node,z.load_balancing_threshold FROM proxmox_nodes n JOIN proxmox_clusters z ON z.id=n.cluster_id
        WHERE z.id=? AND z.is_active=1 AND COALESCE(z.maintenance_mode,0)=0 AND n.is_active=1 AND n.available_for_users=1 ORDER BY n.id');
    $query->execute([$zone]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function placementPoolEntries(PDO $pdo, int $zone): array
{
    $query = $pdo->prepare('SELECT p.node_id,p.kind,p.pool_name,p.resource,p.display_name
        FROM node_placement_pools p JOIN proxmox_nodes n ON n.id=p.node_id
        WHERE n.cluster_id=? AND n.is_active=1 AND n.available_for_users=1
        ORDER BY p.pool_name,p.node_id,p.resource,p.display_name');
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
        if ((!empty($interface['active']) || ($interface['type'] ?? '') === 'vnet') && !empty($interface['iface'])) $live[$interface['iface']] = true;
    }
    return array_values(array_filter($api->getSDNNetworks(), static fn(array $entry): bool =>
        isset($live[$entry['vnet'] ?? ''])
    ));
}

function choosePlacement(PDO $pdo, int $zone, string $type, string $networkPool, string $storagePool,
    string $media, int $cpu, int $ram, int $disk, bool $windows = false, string $cloudImage = ''): array
{
    if (!in_array($type, ['qemu', 'lxc'], true) || min($cpu,$ram,$disk) < 1 || $storagePool === '' || $networkPool === '') {
        throw new InvalidArgumentException('Выберите пул сети и пул хранилищ');
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
        if (!$bridges) continue;
        try {
            $api = placementClient($node, $pdo);
            $base = '/nodes/' . rawurlencode($node['node_name']);
            $status = $api->request('GET', $base . '/status');
            $cpuLoad = (float)($status['cpu'] ?? 1);
            $memoryTotal = (int)($status['memory']['total'] ?? 0);
            $memoryUsed = (int)($status['memory']['used'] ?? 0);
            $threshold = (int)($node['load_balancing_threshold'] ?? 80);
            $load = max($cpuLoad, $memoryTotal > 0 ? $memoryUsed / $memoryTotal : 1);
            if ($load * 100 >= $threshold) continue;
            $guestCount = 0;
            foreach (['qemu', 'lxc'] as $guestType) {
                $guests = $api->request('GET', $base . '/' . $guestType);
                if (!is_array($guests)) {
                    throw new RuntimeException('Не удалось получить список гостей');
                }
                $guestCount += count(array_filter($guests, static fn(array $guest): bool => empty($guest['template'])));
            }
            if ($guestCount >= (int)($node['max_vms_per_node'] ?? 50)) continue;
            $freeRam = (int)($status['memory']['free'] ?? 0);
            if ($freeRam < $ram * 1048576 || (int)($status['cpuinfo']['cpus'] ?? 0) < $cpu) continue;
            $network = '';
            $interfaces = $api->request('GET', $base . '/network', ['type'=>'any_bridge']);
            $selectedSdn = '';
            foreach ($interfaces as $interface) {
                if (in_array($interface['type'] ?? '', ['bridge','OVSBridge'], true) &&
                    !empty($interface['active']) && in_array($interface['iface'] ?? '', $bridges, true)) {
                    $network = $interface['iface'];
                    break;
                }
            }
            if ($network === '' && array_filter($bridges, static fn($entry) => str_contains($entry, '/'))) {
                foreach (appliedPlacementSdn($api, $interfaces) as $entry) {
                    if (in_array($entry['name'], $bridges, true)) {
                        $network = $entry['vnet'];
                        $selectedSdn = $entry['name'];
                        break;
                    }
                }
            }
            if ($network === '') continue;
            if ($media !== '' || ($windows && $cloudImage === '')) {
                $images = $type === 'qemu' ? $api->getISOImages() : $api->getLXCTemplates();
                $volumes = array_column($images, 'volid');
                if ($media !== '' && !in_array($media, $volumes, true)) continue;
                if ($windows && $cloudImage === '' && !in_array('local:iso/virtio-win.iso', $volumes, true)) continue;
            }
            $stores = $api->request('GET', $base . '/storage');
            $image = null;
            if ($cloudImage !== '') {
                $availableImages = publishedCloudImages($pdo, (int)$node['id'], cloudImportVolumes([$api, 'request'], $node['node_name'], $stores, $pdo));
                foreach ($availableImages[$cloudImage] ?? [] as $version) {
                    if ($version['minimum_disk'] <= $disk && ($version['minimum_ram'] ?? 0) <= $ram && cloudImageCpuSupported($version, $status['cpuinfo'] ?? [])) {
                        $image = $version;
                        break;
                    }
                }
                if ($image === null) continue;
                if (!$windows) {
                    $image['agent_snippet'] = cloudGuestAgentSnippet([$api, 'request'], $node['node_name'], $stores);
                }
            }
            $storage = bestPlacementStorage($stores, $storagePoolMembers, $type, $disk);
            if ($storage !== null) {
                $candidates[] = [
                    'node' => $node, 'network' => $network, 'sdn' => $selectedSdn, 'storage' => $storage['name'],
                    'cloud_image' => $image,
                    'score' => [$load, -$freeRam, -$storage['headroom'], -$storage['remaining'], (int)$node['id'], $storage['name']],
                ];
            }
        } catch (Throwable $error) {
            error_log('Placement inventory unavailable for node ' . (int)$node['id']);
        }
    }
    if (!$candidates) throw new RuntimeException('В выбранной зоне нет ноды, подходящей по лимитам, загрузке, сети, образу и хранилищу');
    usort($candidates, static fn($a,$b) => $a['score'] <=> $b['score']);
    return $candidates[0];
}
