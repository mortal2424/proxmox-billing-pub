<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется вход']);
    exit;
}
$userId = (int) $_SESSION['user']['id'];
$_SESSION['mobile_csrf'] ??= bin2hex(random_bytes(32));
$mobileCsrf = $_SESSION['mobile_csrf'];
session_write_close();
try {
    $pdo = (new Database())->getConnection();
    $query = $pdo->prepare('SELECT full_name, balance, bonus_balance, is_admin, is_active FROM users WHERE id = ?');
    $query->execute([$userId]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['is_active']) {
        http_response_code(401);
        echo json_encode(['error' => 'Учётная запись недоступна']);
        exit;
    }
    $query = $pdo->prepare("SELECT id, vm_id, node_id, hostname, vm_type, status, cpu, ram, disk
        FROM vms WHERE user_id = ? AND status <> 'deleted' ORDER BY created_at DESC, id DESC");
    $query->execute([$userId]);
    $guests = [];
    foreach ($query as $guest) {
        $guests[] = [
            'id' => (int) $guest['id'], 'vmId' => (int) $guest['vm_id'],
            'nodeId' => (int) $guest['node_id'], 'name' => (string) $guest['hostname'],
            'type' => $guest['vm_type'], 'status' => $guest['status'],
            'cpu' => (int) $guest['cpu'], 'ramMiB' => (int) $guest['ram'], 'diskGiB' => (int) $guest['disk'],
        ];
    }
    $nodes = $pdo->query("SELECT n.id, n.node_name, c.name AS zone,
        CASE WHEN n.last_check >= NOW() - INTERVAL 15 MINUTE THEN n.status ELSE 'unknown' END AS status,
        s.cpu_usage, s.ram_usage, TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS age
        FROM proxmox_nodes n JOIN proxmox_clusters c ON c.id = n.cluster_id
        LEFT JOIN node_stats s ON s.id = (
            SELECT r.id FROM node_stats r WHERE r.node_id = n.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1
        )
        WHERE n.is_active = 1 AND n.available_for_users = 1 AND c.is_active = 1
        ORDER BY c.name, n.node_name")->fetchAll(PDO::FETCH_ASSOC);
    $infrastructure = [];
    foreach ($nodes as $node) {
        $fresh = $node['age'] !== null && (int) $node['age'] >= 0 && (int) $node['age'] <= 900;
        $infrastructure[] = [
            'id' => (int) $node['id'], 'name' => $node['node_name'], 'zone' => $node['zone'],
            'status' => $node['status'],
            'cpu' => $fresh ? (float) $node['cpu_usage'] : null,
            'memory' => $fresh ? (float) $node['ram_usage'] : null,
        ];
    }
    echo json_encode([
        'csrf' => $mobileCsrf,
        'name' => $user['full_name'] ?: 'Пользователь',
        'balance' => (float) $user['balance'], 'bonusBalance' => (float) $user['bonus_balance'],
        'isAdmin' => (bool) $user['is_admin'], 'guests' => $guests, 'nodes' => $infrastructure,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Mobile dashboard failed: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'Не удалось загрузить кабинет']);
}
