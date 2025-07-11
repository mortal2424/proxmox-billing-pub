<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    session_start();
    checkAuth();
    
    $nodeId = (int)($_GET['node_id'] ?? 0);
    if (!$nodeId) {
        throw new Exception('Node ID not specified');
    }

    $db = new Database();
    $pdo = $db->getConnection();
    $userId = $_SESSION['user']['id'];

    // Проверяем права доступа
    $stmt = $pdo->prepare("SELECT pn.* FROM proxmox_nodes pn
                         JOIN vms ON vms.node_id = pn.id
                         WHERE pn.id = ? AND vms.user_id = ?");
    $stmt->execute([$nodeId, $userId]);
    $nodeData = $stmt->fetch();

    if (!$nodeData) {
        throw new Exception('Access denied or node not found');
    }

    // Возвращаем только необходимые данные
    echo json_encode([
        'success' => true,
        'data' => [
            'host' => $nodeData['hostname'],
            'port' => $nodeData['api_port'] ?? 8006,
            'username' => $nodeData['username'],
            'password' => $nodeData['password'],
            'node_name' => $nodeData['node_name']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}