<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

try {
    session_start();

    if (!isset($_SESSION['user'])) {
        throw new Exception('Доступ запрещен: требуется авторизация', 403);
    }

    $nodeId = (int)($_GET['node_id'] ?? 0);
    $vmType = $_GET['vm_type'] ?? 'qemu';

    if (!$nodeId) {
        throw new Exception('Не указан ID ноды', 400);
    }

    $db = new Database();
    $pdo = $db->getConnection();

    $stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) {
        throw new Exception('Нода не найдена', 404);
    }

    $proxmoxApi = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $node['id'],
        $pdo
    );

    // Получение данных (теперь getNodeResources() возвращает значения в GB)
    $networks = $proxmoxApi->getNodeNetworks();
    $sdnNetworks = $proxmoxApi->getSDNNetworks();
    $resources = $proxmoxApi->getNodeResources();
    $storages = $proxmoxApi->getNodeStorages();

    $response = [
    'success' => true,
    'networks' => $networks,
    'sdnNetworks' => $sdnNetworks,
    'resources' => [
        'free_memory' => $resources['free_memory'] ?? 0,
        'cpu_usage'   => $resources['cpu_usage'] ?? 0,
        'storages'    => $resources['storages'] ?? []     // добавляем список хранилищ
    ],
    'storages' => $storages,  // для выпадающего списка (можно оставить)
];

    if ($vmType === 'qemu') {
        $response['isos'] = $proxmoxApi->getISOImages();
    } else {
        $response['templates'] = $proxmoxApi->getLXCTemplates();
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("get_node_networks.php error: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
