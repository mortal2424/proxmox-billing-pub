<?php
declare(strict_types=1);

const HOMEVLAD_LICENSE_LABELS = [
    'zones' => 'Зоны доступности', 'nodes' => 'Ноды', 'users' => 'Пользователи',
    'vcpu' => 'vCPU', 'ram_mib' => 'RAM, МиБ',
    'vms_created' => 'Созданные ВМ и LXC', 'vms_running' => 'Запущенные ВМ и LXC',
];

function homevladLicenseConfig(): ?array
{
    $path = getenv('HOMEVLAD_LICENSE_CONFIG');
    if (!$path && basename(dirname(__DIR__, 2)) === 'dev.homevlad.ru') {
        $path = dirname(__DIR__, 4) . '/tmp/homevlad-dev-license/config.php';
    }
    if (!$path) {
        return null;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Настройки лицензии недоступны.');
    }
    return require $path;
}

function homevladLicenseUsage(PDO $pdo): array
{
    $base = $pdo->query('SELECT (SELECT COUNT(*) FROM proxmox_clusters) AS zones,
        (SELECT COUNT(*) FROM proxmox_nodes) AS nodes, (SELECT COUNT(*) FROM users) AS users')->fetch(PDO::FETCH_ASSOC);
    $guests = $pdo->query("SELECT COUNT(*) AS vms_created, COALESCE(SUM(cpu),0) AS vcpu,
        COALESCE(SUM(ram),0) AS ram_mib, COALESCE(SUM(status='running'),0) AS vms_running
        FROM (
            SELECT cpu,ram,status FROM vms WHERE status <> 'deleted'
            UNION ALL
            SELECT a.cpu,a.ram,a.status FROM vms_admin a WHERE a.status <> 'deleted'
                AND NOT EXISTS (SELECT 1 FROM vms v WHERE v.node_id=a.node_id AND v.vm_id=a.vm_id AND v.status <> 'deleted')
        ) licensed_guests")->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $base + $guests);
}

function homevladLicenseRequest(array $config): ?string
{
    require_once $config['client'];
    return isset($config['nonce'], $config['source_ip'])
        ? pairingRequest('homevlad', $config['domain'], $config['source_ip'], $config['nonce'])
        : null;
}

function homevladLicenseCredentials(array $config): array
{
    if (isset($config['key_file']) && is_file($config['key_file'])) {
        $request = homevladLicenseRequest($config);
        $pair = readPairingRequest($request);
        return [trim(file_get_contents($config['key_file'])), $pair['installation']];
    }
    return [$config['key'] ?? '', $config['installation'] ?? ''];
}

function homevladLicenseLease(array $config, array $usage, bool $force = false): array
{
    [$key, $installation] = homevladLicenseCredentials($config);
    require_once $config['client'];
    if ($key === '') throw new DomainException('Лицензия не активирована.');
    try {
        return currentLease($key, $config['public_key'], 'homevlad', $installation,
            $usage, $config['cache'], $config['domain'], $config['server_address'] ?? null, $force);
    } catch (Throwable $error) {
        error_log('HomeVlad license refresh failed: ' . get_class($error));
        throw new DomainException('Не удалось подтвердить лицензию панели.');
    }
}

function homevladLicenseMode(array $config, array $usage, bool $force = false): array
{
    require_once $config['client'];
    [$key] = homevladLicenseCredentials($config);
    if ($key !== '') {
        try {
            return ['mode'=>'licensed', 'claims'=>homevladLicenseLease($config, $usage, $force)];
        } catch (DomainException $error) {
            return ['mode'=>'restricted', 'error'=>$error->getMessage()];
        }
    }
    $until = licenseTrialUntil($config, time());
    return $until === null ? ['mode'=>'restricted'] : ['mode'=>'trial', 'until'=>$until];
}

function homevladActivateLicense(PDO $pdo, array $config, string $key): void
{
    $request = homevladLicenseRequest($config);
    if ($request === null || !isset($config['key_file']) || !preg_match('/^dvl1_[a-f0-9]{24}_[A-Za-z0-9_-]{43}$/D', $key)) {
        throw new DomainException('Проверьте формат ключа лицензии.');
    }
    homevladLicenseAcquire($pdo);
    $tmp = tempnam(dirname($config['key_file']), '.activation-');
    try {
        if ($tmp === false) throw new RuntimeException('Не удалось подготовить активацию.');
        $pair = readPairingRequest($request);
        try {
            currentLease($key, $config['public_key'], 'homevlad', $pair['installation'],
                homevladLicenseUsage($pdo), $tmp . '.lease', $config['domain'], $config['server_address'] ?? null, true);
        } catch (Throwable) {
            throw new DomainException('Ключ не подтверждён для этой установки.');
        }
        $payload = $key . "\n";
        if (!chmod($tmp, 0600) || file_put_contents($tmp, $payload) !== strlen($payload) ||
            !rename($tmp, $config['key_file'])) {
            throw new RuntimeException('Не удалось сохранить ключ лицензии.');
        }
        rename($tmp . '.lease', $config['cache']);
    } finally {
        if ($tmp !== false) foreach ([$tmp, $tmp . '.lease', $tmp . '.lease.lock'] as $file) {
            if (is_file($file)) unlink($file);
        }
        homevladLicenseRelease($pdo);
    }
}

function homevladLicenseAcquire(PDO $pdo): void
{
    $query = $pdo->query("SELECT GET_LOCK(CONCAT('homevlad-license:', LEFT(SHA2(DATABASE(),256),32)), 8)");
    $locked = (int)$query->fetchColumn() === 1;
    $query->closeCursor();
    if (!$locked) {
        throw new DomainException('Другая операция проверяет лимиты лицензии. Повторите запрос.');
    }
}

function homevladLicenseRelease(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK(CONCAT('homevlad-license:', LEFT(SHA2(DATABASE(),256),32)))")->closeCursor();
}

function homevladLicensedMutation(PDO $pdo, array $increase, callable $operation): mixed
{
    if (!array_filter($increase, static fn(int $delta): bool => $delta > 0)) {
        return $operation();
    }
    $config = homevladLicenseConfig();
    if ($config === null) {
        return $operation();
    }
    homevladLicenseAcquire($pdo);
    try {
        homevladAssertLicense($pdo, $config, $increase);
        return $operation();
    } finally {
        homevladLicenseRelease($pdo);
    }
}

function homevladAssertLicense(PDO $pdo, array $config, array $increase): void
{
    $usage = homevladLicenseUsage($pdo);
    $mode = homevladLicenseMode($config, $usage);
    if ($mode['mode'] === 'trial') return;
    if ($mode['mode'] === 'restricted') {
        $growth = array_diff_key($increase, ['vms_running' => true]);
        if (!array_filter($growth, static fn(int $delta): bool => $delta > 0)) return;
        throw new DomainException('Ограниченный режим. Для добавления ресурсов активируйте лицензию.');
    }
    $claims = $mode['claims'];
    foreach ($increase as $metric => $delta) {
        $limit = $claims['limits'][$metric];
        if ($delta > 0 && $limit !== null && $usage[$metric] + $delta > $limit) {
            throw new DomainException('Лимит лицензии: ' . HOMEVLAD_LICENSE_LABELS[$metric] .
                ' (' . $usage[$metric] . '/' . $limit . ').');
        }
    }
}

// Legacy request handlers hold this lock until their non-persistent PDO connection closes.
function homevladReserveLicense(PDO $pdo, array $increase): void
{
    $config = homevladLicenseConfig();
    if ($config === null) return;
    homevladLicenseAcquire($pdo);
    try {
        homevladAssertLicense($pdo, $config, $increase);
    } catch (Throwable $error) {
        homevladLicenseRelease($pdo);
        throw $error;
    }
}
