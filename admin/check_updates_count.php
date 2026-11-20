<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/update_catalog.php';
require_once __DIR__ . '/../includes/version.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}
try {
    $pdo = (new Database())->getConnection();
    $current = homevladVersion($pdo->query('SELECT version FROM system_versions ORDER BY id DESC LIMIT 1')->fetchColumn());
    $count = count(array_filter(updateCatalog(), static fn ($release) => version_compare($release['version'], $current, '>')));
    echo json_encode(['success' => true, 'count' => $count, 'available_updates' => $count]);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Каталог обновлений недоступен']);
}
