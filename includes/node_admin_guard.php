<?php
require_once __DIR__ . '/auth.php';

function requireNodeAdministrator(PDO $pdo): void
{
    if (empty(currentSessionAccount($pdo)['is_admin'])) {
        http_response_code(403); exit('Доступ запрещён');
    }
    $_SESSION['node_csrf'] ??= bin2hex(random_bytes(32));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($_POST['node_csrf'] ?? null) ||
        !hash_equals($_SESSION['node_csrf'], $_POST['node_csrf']))) {
        http_response_code(403); exit('Сессия формы истекла');
    }
}
