<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

$nodeId = $_GET['node_id'] ?? null;
if (!$nodeId || !is_numeric($nodeId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный ID ноды']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->prepare("
        SELECT n.*, c.name as cluster_name 
        FROM proxmox_nodes n
        JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.id = ? AND n.is_active = 1
    ");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Нода не найдена или неактивна']);
        exit;
    }

    // Передаем все необходимые параметры, включая nodeId и pdo
    $proxmox = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $nodeId, // Добавляем ID ноды
        $pdo     // Добавляем подключение к БД
    );
    
    $vms = $proxmox->getVirtualMachines();
    echo json_encode(['vms' => $vms]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка получения данных',
        'details' => $e->getMessage()
    ]);
    error_log("Proxmox VM Error: " . $e->getMessage());
}