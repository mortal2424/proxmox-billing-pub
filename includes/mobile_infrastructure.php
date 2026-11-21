<?php
require_once __DIR__ . '/guest_operation_lock.php';
require_once __DIR__ . '/node_health.php';
require_once __DIR__ . '/proxmox_node_import.php';

function mobileAdminNumber(mixed $value, int $min, int $max): int
{
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ((!is_int($value) && !is_string($value)) || $result === false) {
        throw new InvalidArgumentException("Введите целое число от $min до $max");
    }
    return $result;
}

function mobileAdminText(array $input, string $key, int $limit): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || mb_strlen($value) > $limit) {
        throw new InvalidArgumentException("Текстовое поле должно содержать до $limit символов");
    }
    return trim($value);
}

function mobileAdminFlag(array $input, string $key): int
{
    if (!is_bool($input[$key] ?? null)) throw new InvalidArgumentException('Проверьте переключатели формы');
    return (int)$input[$key];
}

function mobileAdminInfrastructure(PDO $pdo): array
{
    $zones = $pdo->query('SELECT id,name,description,is_active,max_vms_per_node,load_balancing_threshold,maintenance_mode
        FROM proxmox_clusters ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $nodes = $pdo->query('SELECT id,cluster_id,node_name,hostname,api_port,username,is_active,available_for_users,
        is_cluster_master,description,status,last_check FROM proxmox_nodes ORDER BY node_name,id')->fetchAll(PDO::FETCH_ASSOC);
    return [
        'zones'=>array_map(static fn(array $z): array => [
            'id'=>(int)$z['id'],'name'=>$z['name'],'description'=>(string)$z['description'],'active'=>(bool)$z['is_active'],
            'limit'=>(int)($z['max_vms_per_node'] ?? 50),'threshold'=>(int)($z['load_balancing_threshold'] ?? 80),
            'maintenance'=>(bool)$z['maintenance_mode'],
        ], $zones),
        'nodes'=>array_map(static fn(array $n): array => [
            'id'=>(int)$n['id'],'zoneId'=>(int)$n['cluster_id'],'name'=>$n['node_name'],'host'=>$n['hostname'],
            'port'=>(int)($n['api_port'] ?: 8006),'tokenId'=>str_contains((string)$n['username'], '!') ? $n['username'] : '',
            'active'=>(bool)$n['is_active'],'orders'=>(bool)$n['available_for_users'],'primary'=>(bool)$n['is_cluster_master'],
            'description'=>(string)$n['description'],'status'=>$n['status'] ?? 'unknown','lastCheck'=>$n['last_check'],
        ], $nodes),
    ];
}

function mobileAdminSaveZone(PDO $pdo, array $input): void
{
    $id = mobileAdminNumber($input['id'] ?? 0,0,PHP_INT_MAX);
    $name = mobileAdminText($input,'name',50);
    if ($name === '') throw new InvalidArgumentException('Укажите название зоны');
    $values = [$name,mobileAdminText($input,'description',500),mobileAdminFlag($input,'active'),
        mobileAdminNumber($input['limit'] ?? null,1,1000),mobileAdminNumber($input['threshold'] ?? null,1,100),
        mobileAdminFlag($input,'maintenance')];
    withGuestOperationLocks(['mobile-zones','placement-zone:' . $id], static function () use ($pdo,$id,$values): void {
        $pdo->beginTransaction();
        try {
            $zones = $pdo->query('SELECT id,name,is_active FROM proxmox_clusters ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            if ($id && !in_array($id, array_map('intval', array_column($zones,'id')), true)) {
                throw new DomainException('Зона не найдена');
            }
            $duplicate = $pdo->prepare('SELECT 1 FROM proxmox_clusters WHERE name=? AND id<>?');
            $duplicate->execute([$values[0],$id]);
            if ($duplicate->fetchColumn()) throw new DomainException('Зона с таким названием уже существует');
            if ($id && !$values[2]) {
                $check = $pdo->prepare("SELECT
                    EXISTS(SELECT 1 FROM vms v JOIN proxmox_nodes n ON v.node_id=n.id WHERE n.cluster_id=? AND v.status IN ('running','creating'))
                    OR EXISTS(SELECT 1 FROM vms_admin v JOIN proxmox_nodes n ON v.node_id=n.id WHERE n.cluster_id=? AND v.status IN ('running','creating'))");
                $check->execute([$id,$id]);
                if ($check->fetchColumn()) throw new DomainException('В зоне есть работающие или создаваемые машины');
            }
            if ($id) {
                $query = $pdo->prepare('UPDATE proxmox_clusters SET name=?,description=?,is_active=?,max_vms_per_node=?,
                    load_balancing_threshold=?,maintenance_mode=?,updated_at=NOW() WHERE id=?');
                $query->execute([...$values,$id]);
            } else {
                $query = $pdo->prepare('INSERT INTO proxmox_clusters(name,description,is_active,max_vms_per_node,
                    load_balancing_threshold,maintenance_mode) VALUES(?,?,?,?,?,?)');
                $query->execute($values);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });
}

function mobileAdminNode(PDO $pdo, int $id, bool $lock = false): array
{
    $query = $pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    $node = $query->fetch(PDO::FETCH_ASSOC);
    if (!$node) throw new DomainException('Нода не найдена');
    return $node;
}

function mobileAdminNodeAction(PDO $pdo, array $input): string
{
    $node = mobileAdminNode($pdo,mobileAdminNumber($input['id'] ?? null,1,PHP_INT_MAX));
    if ($input['action'] === 'node_import') {
        $members = discoverProxmoxMembers(new ProxmoxApiClient($node), $node['node_name']);
        return mobileAdminImport($node,$members);
    }
    $status = probeNodeHealth($node);
    $pdo = (new Database())->getConnection();
    $query = $pdo->prepare("UPDATE proxmox_nodes SET status=?,last_check=NOW() WHERE id=? AND hostname=? AND api_port <=> ?
        AND node_name=? AND username <=> ? AND password <=> ?");
    $query->execute([$status,$node['id'],$node['hostname'],$node['api_port'],$node['node_name'],$node['username'],$node['password']]);
    return match ($status) {
        'online'=>'Нода доступна','offline'=>'Нода недоступна',default=>'Не удалось подтвердить доступность ноды',
    };
}

function mobileAdminImport(array $node, array $members): string
{
    $result = importProxmoxMembers((new Database())->getConnection(), $node, $members);
    $notice = sprintf('Найдено нод: %d. Добавлено: %d.', count($members), $result['added']);
    if ($result['skipped']) $notice .= ' Проверьте адреса нод: ' . implode(', ', $result['skipped']);
    return $notice;
}

function mobileAdminSaveNode(PDO $pdo, array $input): string
{
    $id = mobileAdminNumber($input['id'] ?? 0,0,PHP_INT_MAX);
    $original = $id ? mobileAdminNode($pdo,$id) : null;
    $node = [
        'cluster_id'=>mobileAdminNumber($input['zoneId'] ?? null,1,PHP_INT_MAX),
        'node_name'=>mobileAdminText($input,'name',50),'hostname'=>mobileAdminText($input,'host',255),
        'api_port'=>mobileAdminNumber($input['port'] ?? null,1,65535),
        'username'=>mobileAdminText($input,'tokenId',100),'password'=>mobileAdminText($input,'secret',255),
        'is_active'=>mobileAdminFlag($input,'active'),'description'=>mobileAdminText($input,'description',500),
        'is_cluster_master'=>mobileAdminFlag($input,'primary'),
        'available_for_users'=>mobileAdminFlag($input,'orders'),
    ];
    if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$node['node_name'])) throw new InvalidArgumentException('Некорректное имя ноды');
    if ($node['password'] === '') {
        foreach (['hostname','api_port','username'] as $field) {
            if (!$original || (string)$original[$field] !== (string)$node[$field]) {
                throw new InvalidArgumentException('Укажите секрет токена при смене адреса, порта или ID');
            }
        }
    }
    $verify = !$original || $node['password'] !== '' ||
        ($node['is_active'] && !$original['is_active']) ||
        ($node['available_for_users'] && !$original['available_for_users']);
    if (!preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+![A-Za-z0-9_.-]+$/D',$node['username'])) {
        throw new InvalidArgumentException('Укажите API-токен Proxmox');
    }
    if (!$original) {
        $members = discoverProxmoxMembers(new ProxmoxApiClient($node),$node['node_name']);
        return mobileAdminImport($node,$members);
    }
    $identityChanged = false;
    foreach (['cluster_id','node_name','hostname','api_port'] as $field) {
        $identityChanged = $identityChanged || (string)$original[$field] !== (string)$node[$field];
    }
    $verify = $verify || $identityChanged;
    if ($verify) {
        $client = new ProxmoxApiClient(array_replace($node,[
            'password'=>$node['password'] !== '' ? $node['password'] : $original['password'],
        ]));
        $client->request('GET','/nodes/' . rawurlencode($node['node_name']) . '/status');
    }
    $keys = ['placement-zone:' . $original['cluster_id'],'placement-zone:' . $node['cluster_id']];
    sort($keys);
    return withGuestOperationLocks($keys, static function () use ($original,$node,$id,$identityChanged,$verify): string {
        $pdo = (new Database())->getConnection();
        $pdo->beginTransaction();
        try {
            $zones = $pdo->query('SELECT id,is_active FROM proxmox_clusters ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!array_key_exists($node['cluster_id'],$zones) ||
                (!$zones[$node['cluster_id']] && (int)$original['cluster_id'] !== $node['cluster_id'])) {
                throw new DomainException('Выберите активную зону');
            }
            $current = mobileAdminNode($pdo,$id,true);
            foreach (array_keys($node) as $field) {
                if ((string)$current[$field] !== (string)$original[$field]) {
                    throw new DomainException('Настройки ноды изменились. Обновите список');
                }
            }
            $query = $pdo->prepare('SELECT 1 FROM proxmox_nodes WHERE id<>? AND
                ((hostname=? AND api_port=?) OR (cluster_id=? AND (node_name=? OR (is_cluster_master=1 AND ?=1))))');
            $query->execute([$id,$node['hostname'],$node['api_port'],$node['cluster_id'],$node['node_name'],$node['is_cluster_master']]);
            if ($query->fetchColumn()) throw new DomainException('Адрес или имя ноды уже используется либо в зоне есть главная нода');

            if ($identityChanged || !$node['is_active']) {
                $filter = $identityChanged ? "status<>'deleted'" : "status IN ('running','creating')";
                $check = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM vms WHERE node_id=? AND $filter)
                    OR EXISTS(SELECT 1 FROM vms_admin WHERE node_id=? AND $filter)");
                $check->execute([$id,$id]);
                if ($check->fetchColumn()) throw new DomainException('Сначала освободите ноду от связанных машин или закройте новые заказы');
            }
            $verified = $verify ? ",status='online',last_check=NOW()" : '';
            $query = $pdo->prepare("UPDATE proxmox_nodes SET cluster_id=?,node_name=?,hostname=?,api_port=?,username=?,
                password=?,is_active=?,description=?,is_cluster_master=?,available_for_users=?$verified WHERE id=?");
            $query->execute([$node['cluster_id'],$node['node_name'],$node['hostname'],$node['api_port'],$node['username'],
                $node['password'] !== '' ? encryptNodePassword($node['password']) : $original['password'],
                $node['is_active'],$node['description'],$node['is_cluster_master'],$node['available_for_users'],$id]);
            $pdo->commit();
            return 'Настройки ноды сохранены';
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });
}
