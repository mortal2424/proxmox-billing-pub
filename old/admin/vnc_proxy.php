<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';

if (!isAdmin()) {
    die('Доступ запрещен');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Неверный метод запроса');
}

// Получаем данные из POST
$vncUrl = $_POST['vnc_url'] ?? '';
$cookieName = $_POST['cookie_name'] ?? '';
$cookieValue = $_POST['cookie_value'] ?? '';
$cookieDomain = $_POST['cookie_domain'] ?? '';
$cookiePath = $_POST['cookie_path'] ?? '/';
$cookieSecure = isset($_POST['cookie_secure']) && $_POST['cookie_secure'] === 'true';
$cookieHttpOnly = isset($_POST['cookie_httponly']) && $_POST['cookie_httponly'] === 'true';
$cookieSameSite = $_POST['cookie_samesite'] ?? 'None';

// Устанавливаем cookie
setcookie(
    $cookieName,
    $cookieValue,
    [
        'expires' => time() + 3600,
        'path' => $cookiePath,
        'domain' => $cookieDomain,
        'secure' => $cookieSecure,
        'httponly' => $cookieHttpOnly,
        'samesite' => $cookieSameSite
    ]
);

// Перенаправляем на VNC консоль
header("Location: $vncUrl");
exit;