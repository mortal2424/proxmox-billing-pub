<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (!isLoggedIn()) {
        http_response_code(401);
        throw new RuntimeException('Требуется вход в панель');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Требуется POST');
    }
    $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    $key = $input['control'] ?? '';
    $action = $input['action'] ?? '';
    $context = is_string($key) ? ($_SESSION['console_controls'][$key] ?? null) : null;
    if (!$context || $context['expires'] < time() ||
        !in_array($action, ['start', 'shutdown', 'reboot', 'stop'], true)) {
        http_response_code(403);
        throw new RuntimeException('Сессия управления истекла. Откройте консоль из панели');
    }
    $userId = (int) $_SESSION['user']['id'];
    session_write_close();
    $pdo = (new Database())->getConnection();
    $account = $pdo->prepare('SELECT is_active,is_admin FROM users WHERE id=?');
    $account->execute([$userId]);
    $currentUser = $account->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser || !$currentUser['is_active'] ||
        ($context['scope'] !== 'customer' && !$currentUser['is_admin'])) {
        http_response_code(403);
        throw new RuntimeException('Доступ к управлению запрещён');
    }
    if ($context['scope'] === 'customer') {
        $query = $pdo->prepare("SELECT vm_type FROM vms WHERE user_id = ? AND node_id = ? AND vm_id = ? AND status <> 'deleted'");
        $query->execute([$userId, $context['node_id'], $context['vm_id']]);
        $type = $query->fetchColumn();
        if (!in_array($type, ['qemu', 'lxc'], true)) {
            throw new RuntimeException('Машина недоступна');
        }
    } else {
        $type = $context['type'];
        if ($type === 'detect') {
            $type = $input['type'] ?? '';
            if (!in_array($type, ['qemu', 'lxc'], true)) {
                throw new RuntimeException('Дождитесь подключения консоли');
            }
        } else {
            $query = $pdo->prepare('SELECT 1 FROM vms_admin WHERE node_id = ? AND vm_id = ?');
            $query->execute([$context['node_id'], $context['vm_id']]);
            if (!$query->fetchColumn()) {
                throw new RuntimeException('Машина недоступна');
            }
        }
    }
    $query = $pdo->prepare('SELECT * FROM proxmox_nodes WHERE id = ? AND is_active = 1');
    $query->execute([$context['node_id']]);
    $node = $query->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        throw new RuntimeException('Узел недоступен');
    }
    $api = new ProxmoxAPI($node['hostname'], $node['username'], $node['password'],
        $node['ssh_port'] ?? 22, $node['node_name'], $node['id'], $pdo);
    if ($action === 'start') {
        $method = $type === 'lxc' ? 'startContainer' : 'startVM';
        $result = $api->$method((int) $context['vm_id']);
        if (is_array($result) && ($result['success'] ?? true) === false) {
            throw new RuntimeException('Не удалось запустить машину');
        }
    } else {
        $api->controlGuest((int) $context['vm_id'], $type, $action);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    error_log('Console control failed: ' . get_class($error));
    echo json_encode(['success' => false, 'error' => $error instanceof JsonException
        ? 'Некорректный запрос' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
