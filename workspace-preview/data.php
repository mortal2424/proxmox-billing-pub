<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['HTTPS'] ?? '') !== 'on' || ($_SERVER['SERVER_NAME'] ?? '') !== 'dev.homevlad.ru') {
    respond(['error' => 'Недоступно'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(['error' => 'Разрешён только просмотр'], 405);
}

$configPath = getenv('HOMEVLAD_WORKSPACE_CONFIG') ?: dirname(__DIR__, 4) . '/.config/homevlad-workspace.php';
$configPath = realpath($configPath);
$webRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
if (!$configPath || !$webRoot || str_starts_with($configPath, $webRoot . DIRECTORY_SEPARATOR)) {
    respond(['error' => 'Предпросмотр пока не настроен'], 503);
}
$config = require $configPath;
$login = $_SERVER['PHP_AUTH_USER'] ?? '';
$password = $_SERVER['PHP_AUTH_PW'] ?? '';
if (!hash_equals($config['login'], $login) || !password_verify($password, $config['password_hash'])) {
    header('WWW-Authenticate: Basic realm="HomeVlad workspace preview", charset="UTF-8"');
    respond(['error' => 'Требуется вход'], 401);
}
$accountId = filter_var($config['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$accountId) {
    respond(['error' => 'Аккаунт предпросмотра не задан'], 503);
}

try {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('START TRANSACTION READ ONLY');
    $owner = $db->prepare('SELECT is_active FROM users WHERE id=?');
    $owner->execute([$accountId]);
    if (!(int) $owner->fetchColumn()) respond(['error' => 'Аккаунт недоступен'], 403);

    if (isset($_GET['metrics'])) {
        $id = filter_var($_GET['metrics'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) respond(['error' => 'Сервер не найден'], 404);
        $query = $db->prepare("SELECT vm_id, vm_type, created_at FROM vms WHERE id=? AND user_id=? AND status<>'deleted'");
        $query->execute([$id, $accountId]);
        $guest = $query->fetch();
        if (!$guest) respond(['error' => 'Сервер не найден'], 404);

        // Older collectors index measurements only by VMID. Omit ambiguous series.
        $query = $db->prepare("SELECT (SELECT COUNT(*) FROM vms WHERE vm_id=? AND vm_type=?)
            + (SELECT COUNT(*) FROM vms_admin WHERE vm_id=?)");
        $query->execute([$guest['vm_id'], $guest['vm_type'], $guest['vm_id']]);
        if ((int) $query->fetchColumn() !== 1 || !$guest['created_at']) respond(['metrics' => []]);
        $table = $guest['vm_type'] === 'lxc' ? 'lxc_metrics' : 'vm_metrics';
        $query = $db->prepare("SELECT cpu_usage, mem_usage, net_in, net_out, disk_read, disk_write
            FROM $table WHERE vm_id=? AND timestamp>=? ORDER BY timestamp DESC LIMIT 120");
        $query->execute([$guest['vm_id'], $guest['created_at']]);
        respond(['metrics' => array_reverse($query->fetchAll())]);
    }

    $query = $db->prepare("SELECT v.id,v.vm_id,v.hostname,v.vm_type,v.status,v.cpu,v.ram,v.disk,v.ip_address,v.os_type,v.os_version,
            z.name AS zone, t.name AS tariff
        FROM vms v LEFT JOIN proxmox_nodes n ON n.id=v.node_id
        LEFT JOIN proxmox_clusters z ON z.id=n.cluster_id LEFT JOIN tariffs t ON t.id=v.tariff_id
        WHERE v.user_id=? AND v.status<>'deleted' ORDER BY v.hostname,v.id");
    $query->execute([$accountId]);
    $guests = $query->fetchAll();
    $plans = $db->query('SELECT id,name,cpu,ram,disk,price,vm_type,os_type FROM tariffs
        WHERE is_active=1 AND is_custom=0 ORDER BY price,id')->fetchAll();
    $pools = $db->query('SELECT DISTINCT z.id,z.name,p.kind,p.pool_name FROM proxmox_clusters z
        JOIN proxmox_nodes n ON n.cluster_id=z.id JOIN node_placement_pools p ON p.node_id=n.id
        WHERE z.is_active=1 AND COALESCE(z.maintenance_mode,0)=0 AND n.is_active=1 AND n.available_for_users=1
        ORDER BY z.name,p.pool_name')->fetchAll();
    $zones = [];
    foreach ($pools as $pool) {
        if (!in_array($pool['kind'], ['network', 'storage'], true)) continue;
        $zones[$pool['id']] ??= ['id' => $pool['id'], 'name' => $pool['name'], 'network' => [], 'storage' => []];
        $zones[$pool['id']][$pool['kind']][] = $pool['pool_name'];
    }
    require_once __DIR__ . '/../includes/cloud_images.php';
    $images = [];
    foreach (cloudImageCatalog() as $id => $image) {
        $images[] = ['id' => $id, 'label' => $image['label'], 'os' => $image['os_type'] ?? 'linux', 'kind' => 'qemu'];
    }
    $images[] = ['id' => 'lxc-template', 'label' => 'Шаблон Linux для LXC', 'os' => 'linux', 'kind' => 'lxc'];
    respond([
        'account' => $config['account_label'] ?? 'HomeVlad',
        'guests' => $guests,
        'plans' => $plans,
        'zones' => array_values($zones),
        'images' => $images,
    ]);
} catch (Throwable $error) {
    error_log('Workspace preview failed: ' . get_class($error));
    respond(['error' => 'Данные предпросмотра временно недоступны'], 503);
}
