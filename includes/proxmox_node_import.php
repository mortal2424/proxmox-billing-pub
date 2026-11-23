<?php
require_once __DIR__ . '/license_guard.php';
require_once __DIR__ . '/ProxmoxApiClient.php';

function discoverProxmoxMembers(ProxmoxApiClient $client, string $entryNode): array
{
    if (!$client->usesToken()) throw new InvalidArgumentException('Укажите ID и секрет API-токена');
    if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $entryNode)) throw new InvalidArgumentException('Некорректное имя ноды');
    $client->request('GET', '/nodes/' . $entryNode . '/status');
    $rows = $client->request('GET', '/cluster/status');
    if (!is_array($rows)) throw new RuntimeException('Некорректный ответ о составе кластера');
    $members = [];
    $clustered = false;
    foreach ($rows as $row) {
        if (($row['type'] ?? '') === 'cluster') $clustered = true;
        if (($row['type'] ?? '') !== 'node') continue;
        $name = $row['name'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $name) || isset($members[$name])) {
            throw new RuntimeException('Некорректный список участников Proxmox');
        }
        $members[$name] = !empty($row['online']) ? 'online' : 'offline';
    }
    if (!$clustered) return [$entryNode => 'online'];
    if (!isset($members[$entryNode])) throw new RuntimeException('Указанная нода отсутствует в ответе кластера');
    return $members;
}

function proxmoxMemberEndpoint(array $connection, string $name): ?string
{
    $entry = $connection['node_name'];
    if ($name === $entry) return $connection['hostname'];
    $prefix = $entry . '.';
    if (!str_starts_with(strtolower($connection['hostname']), strtolower($prefix)) ||
        !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $name)) return null;

    $candidate = $name . '.' . substr($connection['hostname'], strlen($prefix));
    if (!filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return null;
    try {
        $api = new ProxmoxApiClient(array_replace($connection, ['hostname' => $candidate]));
        $rows = $api->request('GET', '/cluster/status');
        $nodes = array_filter($rows, static fn(array $row): bool => ($row['type'] ?? '') === 'node');
        $local = array_filter($nodes, static fn(array $row): bool => !empty($row['local']));
        if (array_values(array_column($local, 'name')) === [$name] &&
            in_array($entry, array_column($nodes, 'name'), true)) return $candidate;
    } catch (Throwable $error) {
        error_log('Cannot verify direct Proxmox endpoint for member ' . $name);
    }
    return null;
}

function importProxmoxMembers(PDO $db, array $connection, array $members): array
{
    $zone = (int)$connection['cluster_id'];
    $known = $db->prepare('SELECT node_name FROM proxmox_nodes WHERE cluster_id=?');
    $known->execute([$zone]);
    $registered = array_map('strtolower', $known->fetchAll(PDO::FETCH_COLUMN));
    $hosts = [];
    $skipped = [];
    foreach ($members as $name => $status) {
        if (in_array(strtolower($name), $registered, true)) continue;
        $hostname = proxmoxMemberEndpoint($connection, $name);
        if ($hostname === null) {
            $skipped[] = $name;
            continue;
        }
        $hosts[$name] = $hostname;
    }

    $license = $hosts ? homevladLicenseConfig() : null;
    if ($license !== null) homevladLicenseAcquire($db);
    try {
    $db->beginTransaction();
    try {
        $zones = $db->query('SELECT id,is_active FROM proxmox_clusters ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($zones[$zone])) throw new RuntimeException('Выберите активную зону доступности');
        $refresh = isset($connection['id']);
        if ($refresh) {
            $source = $db->prepare('SELECT cluster_id,node_name,hostname,api_port,username,password,is_active FROM proxmox_nodes WHERE id=? FOR UPDATE');
            $source->execute([$connection['id']]);
            $saved = $source->fetch(PDO::FETCH_ASSOC);
            foreach (['cluster_id','node_name','hostname','api_port','username','password','is_active'] as $key) {
                if (!$saved || (string)$saved[$key] !== (string)$connection[$key]) {
                    throw new RuntimeException('Настройки исходной ноды изменились. Повторите обновление списка.');
                }
            }
        }
        $existing = $db->query('SELECT cluster_id,node_name,hostname,api_port,is_cluster_master FROM proxmox_nodes')->fetchAll(PDO::FETCH_ASSOC);
        $secret = encryptNodePassword($refresh ? decryptNodePassword($connection['password']) : $connection['password']);
        $insert = $db->prepare('INSERT INTO proxmox_nodes
            (cluster_id,node_name,hostname,api_port,ssh_port,username,password,is_active,description,is_cluster_master,last_check,status,available_for_users)
            VALUES (?,?,?,?,22,?,?,?,?,?,NOW(),?,?)');
        $count = 0;
        foreach ($hosts as $name => $hostname) {
            foreach ($existing as $node) {
                $sameEndpoint = strcasecmp($node['hostname'], $hostname) === 0 &&
                    (int)$node['api_port'] === (int)$connection['api_port'];
                $sameZone = (int)$node['cluster_id'] === $zone;
                if ($sameZone && strcasecmp($node['node_name'], $name) === 0) continue 2;
                if ($sameEndpoint) {
                    if ($name === $connection['node_name']) throw new RuntimeException('Этот адрес API уже используется другой нодой');
                    $skipped[] = $name;
                    continue 2;
                }
            }
            $status = $members[$name];
            $master = !$refresh && $connection['is_cluster_master'] && $name === $connection['node_name'];
            if ($master) {
                foreach ($existing as $node) {
                    if ((int)$node['cluster_id'] === $zone && $node['is_cluster_master']) {
                        throw new RuntimeException('В зоне уже назначена главная нода');
                    }
                }
            }
            if ($license !== null) homevladAssertLicense($db, $license, ['nodes' => 1]);
            $insert->execute([$zone,$name,$hostname,$connection['api_port'],$connection['username'],
                $secret,(int)($connection['is_active'] && $status === 'online'),$connection['description'],(int)$master,
                $status,(int)(!$refresh && ($connection['available_for_users'] ?? true))]);
            ++$count;
        }
        $db->commit();
        return ['added' => $count, 'skipped' => $skipped];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    } finally {
        if ($license !== null) homevladLicenseRelease($db);
    }
}
