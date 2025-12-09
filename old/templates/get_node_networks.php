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
    $vmType = $_GET['vm_type'] ?? 'qemu'; // По умолчанию QEMU
    
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
    
    // Получаем общие данные
    $networks = $proxmoxApi->getNodeNetworks();
    $sdnNetworks = $proxmoxApi->getSDNNetworks();
    $resources = $proxmoxApi->getNodeResources();
    $storages = $proxmoxApi->getNodeStorages();
    
    // Формируем базовый ответ
    $response = [
        'success' => true,
        'networks' => $networks,
        'sdnNetworks' => $sdnNetworks,
        'resources' => [
            'free_memory' => round($resources['free_memory'] / 1024 / 1024 / 1024, 2), // B -> GB
            'free_disk' => round($resources['free_disk'] / 1024 / 1024 / 1024, 2), // B -> GB
            'cpu_usage' => round($resources['cpu_usage'], 2) // Уже в процентах
        ],
        'storages' => $storages,
    ];
    
    // Добавляем специфичные данные в зависимости от типа VM
    if ($vmType === 'qemu') {
        $response['isos'] = $proxmoxApi->getISOImages();
    } else {
        $response['templates'] = $proxmoxApi->getLXCTemplates();
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}