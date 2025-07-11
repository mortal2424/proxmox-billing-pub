<?php
header('Content-Type: application/json');

try {
    session_start();
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/proxmox_functions.php';

    if (!isAdmin()) {
        throw new Exception('Доступ запрещен: недостаточно прав');
    }

    $nodeId = (int)($_GET['node_id'] ?? 0);
    $vmid = (int)($_GET['vmid'] ?? 0);
    
    if (!$nodeId || !$vmid) {
        throw new Exception('Не указан node_id или vmid');
    }

    $db = new Database();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch();
    
    if (!$node) {
        throw new Exception('Нода не найдена');
    }

    // Получаем тикет для конкретной ноды
    $auth = new ProxmoxAuth($pdo, $node['id'], $node['hostname'], $node['username'], $node['password']);
    $ticket = $auth->getTicket();

    // Формируем URL для VNC консоли (без тикета в URL)
    $vncUrl = sprintf(
        "https://%s:%d/?console=kvm&novnc=1&vmid=%d&node=%s&resize=scale",
        $node['hostname'],
        $node['api_port'] ?? 8006,
        $vmid,
        rawurlencode($node['node_name'])
    );

    // Возвращаем URL и данные для установки cookie
    echo json_encode([
        'success' => true,
        'data' => [
            'url' => $vncUrl,
            'cookie' => [
                'name' => 'PVEAuthCookie',
                'value' => $ticket['ticket'],
                'domain' => parse_url($node['hostname'], PHP_URL_HOST),
                'path' => '/',
                'secure' => true,
                'httponly' => true
            ],
            'vmid' => $vmid,
            'node' => $node['node_name']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'details' => 'Проверьте: 1) Доступность ноды 2) Права доступа 3) Состояние VM'
    ]);
}