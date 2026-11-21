<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_health.php';

$lock = fopen(sys_get_temp_dir() . '/homevlad-node-health.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$pdo = (new Database())->getConnection();
$update = $pdo->prepare('UPDATE proxmox_nodes SET status = ?, last_check = NOW() WHERE id = ?');
foreach ($pdo->query('SELECT id, hostname, api_port, node_name, username, password FROM proxmox_nodes WHERE is_active = 1') as $node) {
    $update->execute([probeNodeHealth($node), $node['id']]);
}
flock($lock, LOCK_UN);
fclose($lock);
