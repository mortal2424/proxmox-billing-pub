<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/update_installer.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (!isAdmin() || $_SERVER['REQUEST_METHOD'] !== 'POST' ||
        !is_string($_POST['csrf_token'] ?? null) || !isset($_SESSION['update_csrf']) ||
        !hash_equals($_SESSION['update_csrf'], $_POST['csrf_token'])) {
        http_response_code(403);
        throw new RuntimeException('Доступ запрещён');
    }
    $manifest = releaseManifest((string) ($_POST['version'] ?? ''));
    installUpdate((new Database())->getConnection(), dirname(__DIR__), $manifest, static fn () => (new Database())->getConnection());
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
