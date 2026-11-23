<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$pdo = (new Database())->getConnection();
$column = $pdo->query("SHOW COLUMNS FROM proxmox_clusters LIKE 'allowed_user_ids'");
$exists = (bool)$column->fetchColumn();
$column->closeCursor();
if (!$exists) {
    $pdo->exec('ALTER TABLE proxmox_clusters ADD COLUMN allowed_user_ids TEXT DEFAULT NULL');
}

echo "Zone access schema is ready.\n";
