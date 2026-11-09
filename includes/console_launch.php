<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/node_credentials.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function consoleError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    consoleError(401, 'Войдите в биллинг');
}
if ($consoleScope !== 'customer' && !isAdmin()) {
    consoleError(403, 'Доступ запрещён');
}
$userId = (int) $_SESSION['user']['id'];
session_write_close();

$nodeId = filter_var($_GET['node_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$vmId = filter_var($_GET['vm_id'] ?? $_GET['vmid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$nodeId || !$vmId) {
    consoleError(400, 'Укажите узел и ВМ');
}

try {
    $pdo = (new Database())->getConnection();
    if ($consoleScope === 'customer') {
        $guest = $pdo->prepare('SELECT vm_type FROM vms WHERE node_id = ? AND vm_id = ? AND user_id = ?');
        $guest->execute([$nodeId, $vmId, $userId]);
        $type = $guest->fetchColumn();
    } elseif ($consoleScope === 'managed') {
        $guest = $pdo->prepare('SELECT 1 FROM vms_admin WHERE node_id = ? AND vm_id = ?');
        $guest->execute([$nodeId, $vmId]);
        $type = $guest->fetchColumn() ? 'qemu' : false;
    } else {
        $type = 'detect';
    }
    if (!in_array($type, ['qemu', 'lxc', 'detect'], true)) {
        consoleError(404, 'ВМ или контейнер не найдены');
    }

    $node = $pdo->prepare('SELECT hostname, api_port, node_name, username, password
        FROM proxmox_nodes WHERE id = ? AND is_active = 1');
    $node->execute([$nodeId]);
    $target = $node->fetch();
    if (!$target) {
        consoleError(404, 'Узел Proxmox недоступен');
    }
    $target['password'] = decryptNodePassword($target['password']);

    $socketPath = '/run/homevlad-console/issuer.sock';
    $socket = @stream_socket_client('unix://' . $socketPath, $errno, $error, 2);
    if (!$socket) {
        consoleError(503, 'Сервис консоли недоступен');
    }
    stream_set_timeout($socket, 3);
    $payload = json_encode($target + ['vmid' => $vmId, 'type' => $type], JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($socket, $payload) !== strlen($payload)) {
        fclose($socket);
        consoleError(503, 'Не удалось открыть консоль');
    }
    $token = trim((string) fgets($socket, 66));
    fclose($socket);
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        consoleError(503, 'Не удалось открыть консоль');
    }
    $url = '/console/view.html#' . $token;
    if (($_GET['redirect'] ?? '') === '1') {
        header('Location: ' . $url, true, 303);
        exit;
    }
    echo json_encode(['success' => true, 'data' => ['url' => $url, 'vm_type' => $type]]);
} catch (Throwable $exception) {
    error_log('Console launch failed: ' . get_class($exception));
    consoleError(502, 'Не удалось подготовить консоль');
}
