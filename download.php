<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit;
}

$db = new Database();
$stmt = $db->getConnection()->prepare(
    'SELECT a.file_path, a.file_name FROM ticket_attachments a
     JOIN tickets t ON t.id = a.ticket_id
     WHERE a.id = ? AND (t.user_id = ? OR ? = 1)'
);
$stmt->execute([$id, $_SESSION['user']['id'], (int)isAdmin()]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
session_write_close();

$root = realpath(__DIR__ . '/uploads/tickets');
$path = $file && $root ? realpath($root . DIRECTORY_SEPARATOR . basename($file['file_path'])) : false;
if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode(basename($file['file_name'])));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
