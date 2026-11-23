<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_ticket.php';

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


$nodeId = filter_var($_GET['node_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$vmId = filter_var($_GET['vm_id'] ?? $_GET['vmid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$nodeId || !$vmId) {
    consoleError(400, 'Укажите узел и ВМ');
}

try {
    $pdo = (new Database())->getConnection();
    $account = $pdo->prepare('SELECT is_active,is_admin FROM users WHERE id=?');
    $account->execute([$userId]);
    $currentUser = $account->fetch();
    if (!$currentUser || !$currentUser['is_active'] || ($consoleScope !== 'customer' && !$currentUser['is_admin'])) {
        consoleError(403, 'Доступ запрещён');
    }
    if ($consoleScope === 'customer') {
        $guest = $pdo->prepare("SELECT vm_type FROM vms WHERE node_id = ? AND vm_id = ? AND user_id = ? AND status <> 'deleted'");
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

    $node = $pdo->prepare('SELECT id, hostname, api_port, node_name, username, password
        FROM proxmox_nodes WHERE id = ? AND is_active = 1');
    $node->execute([$nodeId]);
    $target = $node->fetch();
    if (!$target) {
        consoleError(404, 'Узел Proxmox недоступен');
    }
    $token = issueConsoleTicket($target, $vmId, $type);
    $control = bin2hex(random_bytes(24));
    $_SESSION['console_controls'] = array_filter(
        $_SESSION['console_controls'] ?? [],
        static fn ($entry) => ($entry['expires'] ?? 0) > time()
    );
    $_SESSION['console_controls'] = array_slice($_SESSION['console_controls'], -19, null, true);
    $_SESSION['console_controls'][$control] = [
        'node_id' => (int) $nodeId, 'vm_id' => (int) $vmId,
        'scope' => $consoleScope, 'type' => $type, 'expires' => time() + 28800,
    ];
    session_write_close();
    $view = consoleRemoteBroker() === null ? '/console/view.html' : '/console/view.php';
    $url = $view . '#' . $token . '&control=' . $control . ($consoleScope === 'customer' ? '&owner=1' : '');
    if (($_GET['redirect'] ?? '') === '1') {
        header('Location: ' . $url, true, 303);
        exit;
    }
    echo json_encode(['success' => true, 'data' => ['url' => $url, 'vm_type' => $type]]);
} catch (ConsoleBrokerUnavailable $exception) {
    consoleError(503, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Console launch failed: ' . get_class($exception));
    consoleError(502, 'Не удалось подготовить консоль');
}
