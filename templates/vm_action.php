<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется вход']);
    exit;
}

try {
    // Проверяем метод запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON input');
    }

    // Проверяем обязательные параметры
    $action = $input['action'] ?? null;
    $nodeId = filter_var($input['node_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $vmid = filter_var($input['vm_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$nodeId || !$vmid || !in_array($action, ['start', 'stop', 'reboot'], true)) {
        throw new InvalidArgumentException('Некорректные параметры операции');
    }

    $db = new Database();
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("
        SELECT pn.* FROM proxmox_nodes pn
        JOIN vms v ON v.node_id = pn.id
        WHERE pn.id = ? AND v.vm_id = ? AND v.vm_type = ?
          AND (v.user_id = ? OR ? = 1)
    ");
    $stmt->execute([$nodeId, $vmid, 'qemu', $_SESSION['user']['id'], (int)isAdmin()]);
    $node = $stmt->fetch();
    if (!$node) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
        exit;
    }
    session_write_close();

    // Создаем экземпляр ProxmoxAPI
    $proxmoxApi = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $node['id'],
        $pdo
    );

    // Выполняем запрошенное действие
    switch ($action) {
        case 'start':
            $result = $proxmoxApi->startVM($vmid);
            break;
        case 'stop':
            $result = $proxmoxApi->stopVM($vmid);
            break;
        case 'reboot':
            $result = $proxmoxApi->rebootVM($vmid);
            break;
    }

    if (is_array($result) && ($result['success'] ?? true) === false) {
        throw new RuntimeException($result['error'] ?? 'Операция не выполнена');
    }
    $newStatus = $action === 'stop' ? 'stopped' : 'running';
    $stmt = $pdo->prepare('UPDATE vms SET status = ? WHERE vm_id = ? AND node_id = ?');
    $stmt->execute([$newStatus, $vmid, $nodeId]);

    echo json_encode([
        'success' => true,
        'result' => $result,
        'vmid' => $vmid,
        'status' => $newStatus ?? null
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}