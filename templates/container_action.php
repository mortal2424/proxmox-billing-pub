<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

try {
    // Проверяем метод запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }

    // Проверяем обязательные параметры
    $action = $input['action'] ?? null;
    $nodeId = $input['node_id'] ?? null;
    $vmid = $input['vm_id'] ?? null;

    if (!$action || !$nodeId || !$vmid) {
        throw new Exception('Missing required parameters: action, node_id or vm_id');
    }

    // Подключаемся к базе данных
    $db = new Database();
    $pdo = $db->getConnection();

    // Получаем данные ноды
    $stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch();

    if (!$node) {
        throw new Exception('Node not found');
    }

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
            $result = $proxmoxApi->startContainer($vmid);
            break;
        case 'stop':
            $result = $proxmoxApi->stopContainer($vmid);
            break;
        case 'reboot':
            $result = $proxmoxApi->rebootContainer($vmid);
            break;
        default:
            throw new Exception('Invalid action');
    }

    // Обновляем статус контейнера в базе данных
    if (in_array($action, ['start', 'stop', 'reboot'])) {
        $newStatus = $action === 'start' ? 'running' : ($action === 'stop' ? 'stopped' : 'running');
        $stmt = $pdo->prepare("UPDATE vms SET status = ? WHERE vm_id = ? AND vm_type = 'lxc'");
        $stmt->execute([$newStatus, $vmid]);
    }

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