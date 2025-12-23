<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Доступ запрещен']));
}

if (!isset($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'ID ноды не указан']));
}

$db = new Database();
$pdo = $db->getConnection();

$nodeId = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
$stmt->execute([$nodeId]);
$node = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    header('HTTP/1.1 404 Not Found');
    exit(json_encode(['error' => 'Нода не найдена']));
}

// Проверяем, что есть данные для подключения
if (empty($node['hostname']) || empty($node['username']) || empty($node['password'])) {
    exit(json_encode(['error' => 'Недостаточно данных для подключения']));
}

try {
    $info = getNodeSSHInfo($node['hostname'], $node['username'], $node['password']);
    header('Content-Type: application/json');
    echo json_encode($info);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}