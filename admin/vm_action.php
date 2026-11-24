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

requirePostCsrf();

$action = $_POST['action'] ?? null;
$nodeId = filter_var($_POST['node_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$vmid = filter_var($_POST['vmid'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

if (!$action || !$nodeId || !$vmid) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указаны обязательные параметры']);
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
        throw new Exception("Нода не найдена или неактивна");
    }

    $proxmox = new ProxmoxAPI(
        $node['hostname'],
        $node['username'],
        $node['password'],
        $node['ssh_port'] ?? 22,
        $node['node_name'],
        $nodeId, // Добавляем ID ноды
        $pdo     // Добавляем подключение к БД
    );
    
    switch ($action) {
        case 'start':
            $result = $proxmox->startVM($vmid);
            if (!($result['success'] ?? false)) throw new DomainException($result['error'] ?? 'Запуск не выполнен');
            echo json_encode(['success' => true]);
            break;
            
        case 'stop':
            $proxmox->stopVM($vmid);
            echo json_encode(['success' => true]);
            break;
            
        case 'reboot':
            $proxmox->rebootVM($vmid);
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Неизвестное действие']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка выполнения действия: ' . $e->getMessage()]);
}