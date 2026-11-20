<?php
require_once __DIR__ . '/../includes/ProxmoxApiClient.php';
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

// Получаем все ноды из базы
$nodes = $pdo->query("SELECT id, hostname, username, password, api_port FROM proxmox_nodes")->fetchAll();

foreach ($nodes as $node) {
    try {
        $api = new ProxmoxApiClient($node);
        if ($api->usesToken()) continue;
        $data = ['data' => $api->getPasswordTicket()];

        // Устанавливаем срок действия (2 часа от текущего момента)
        $expiresAt = new DateTime();
        $expiresAt->add(new DateInterval('PT2H')); // Добавляем 2 часа

        // Проверяем существование записи
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_tickets WHERE node_id = ?");
        $stmt->execute([$node['id']]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            // Обновляем существующую запись
            $stmt = $pdo->prepare("UPDATE proxmox_tickets
                                 SET ticket = ?, csrf_token = ?, expires_at = ?
                                 WHERE node_id = ?");
        } else {
            // Создаем новую запись
            $stmt = $pdo->prepare("INSERT INTO proxmox_tickets
                                 (ticket, csrf_token, expires_at, node_id)
                                 VALUES (?, ?, ?, ?)");
        }

        $stmt->execute([
            $data['data']['ticket'],
            $data['data']['CSRFPreventionToken'],
            $expiresAt->format('Y-m-d H:i:s'),
            $node['id']
        ]);

        echo "Successfully " . ($exists ? "updated" : "created") . " ticket for node {$node['hostname']}\n";
    } catch (Throwable $e) {
        error_log("Ticket refresh failed for node " . (int)$node['id']);
    }
}
