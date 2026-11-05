<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isAdmin()) {
    http_response_code(403);
    exit('Доступ запрещен');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Неверный метод запроса');
}

$vncUrl = (string)($_POST['vnc_url'] ?? '');
$cookieName = (string)($_POST['cookie_name'] ?? '');
$cookieValue = (string)($_POST['cookie_value'] ?? '');
$cookieDomain = strtolower(ltrim((string)($_POST['cookie_domain'] ?? ''), '.'));

$url = filter_var($vncUrl, FILTER_VALIDATE_URL) ? parse_url($vncUrl) : false;
if (!$url || strtolower($url['scheme'] ?? '') !== 'https' ||
    empty($url['host']) || isset($url['user']) || isset($url['pass']) ||
    ($url['path'] ?? '/') !== '/' || empty($url['query']) ||
    $cookieName !== 'PVEAuthCookie' || $cookieValue === '') {
    http_response_code(400);
    exit('Некорректные параметры VNC-консоли');
}

// Only redirect to a configured Proxmox node; never accept an arbitrary URL.
$db = new Database();
$nodes = $db->getConnection()->query('SELECT hostname, api_port FROM proxmox_nodes')->fetchAll(PDO::FETCH_ASSOC);
$allowedHost = false;
foreach ($nodes as $node) {
    $configuredHost = strtolower(trim((string)$node['hostname']));
    $configuredPort = (int)($node['api_port'] ?: 8006);
    $requestedPort = (int)($url['port'] ?? 443);
    if ($configuredHost === strtolower($url['host']) && $configuredPort === $requestedPort) {
        $allowedHost = true;
        break;
    }
}
if (!$allowedHost) {
    http_response_code(403);
    exit('Узел Proxmox не разрешен');
}

// A cookie domain must be the target host or one of its parent domains.
$targetHost = strtolower($url['host']);
if ($cookieDomain !== '' &&
    $targetHost !== $cookieDomain &&
    !str_ends_with($targetHost, '.' . $cookieDomain)) {
    http_response_code(400);
    exit('Некорректный домен cookie');
}

setcookie('PVEAuthCookie', $cookieValue, [
    'expires' => time() + 3600,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => true,
    'httponly' => false,
    'samesite' => 'None'
]);

header('Location: ' . $vncUrl, true, 303);
exit;
