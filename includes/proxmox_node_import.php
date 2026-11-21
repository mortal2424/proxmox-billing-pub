<?php
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

function importProxmoxMembers(PDO $db, array $connection, array $members): int
{
    $db->beginTransaction();
    try {
        $zones = $db->query('SELECT id,is_active FROM proxmox_clusters ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_KEY_PAIR);
        $zone = $connection['cluster_id'];
        if (empty($zones[$zone])) throw new RuntimeException('Выберите активную зону доступности');
        $existing = $db->query('SELECT cluster_id,node_name,hostname,api_port,is_cluster_master FROM proxmox_nodes')->fetchAll(PDO::FETCH_ASSOC);
        $secret = encryptNodePassword($connection['password']);
        $insert = $db->prepare('INSERT INTO proxmox_nodes
            (cluster_id,node_name,hostname,api_port,ssh_port,username,password,is_active,description,is_cluster_master,last_check,status)
            VALUES (?,?,?,?,22,?,?,?,?,?,NOW(),?)');
        $count = 0;
        foreach ($members as $name => $status) {
            foreach ($existing as $node) {
                $sameEndpoint = strcasecmp($node['hostname'], $connection['hostname']) === 0 &&
                    (int)$node['api_port'] === $connection['api_port'];
                if ($sameEndpoint && (int)$node['cluster_id'] !== $zone) {
                    throw new RuntimeException('Этот адрес API уже используется в другой зоне');
                }
                if ((int)$node['cluster_id'] === $zone && strcasecmp($node['node_name'], $name) === 0) {
                    if (!$sameEndpoint) throw new RuntimeException('Имя участника уже занято другим подключением в этой зоне');
                    continue 2;
                }
            }
            $master = $connection['is_cluster_master'] && $name === $connection['node_name'];
            if ($master) {
                foreach ($existing as $node) {
                    if ((int)$node['cluster_id'] === $zone && $node['is_cluster_master']) {
                        throw new RuntimeException('В зоне уже назначена главная нода');
                    }
                }
            }
            $insert->execute([$zone,$name,$connection['hostname'],$connection['api_port'],$connection['username'],
                $secret,(int)($connection['is_active'] && $status === 'online'),$connection['description'],(int)$master,$status]);
            ++$count;
        }
        $db->commit();
        return $count;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
