<?php
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
ensureSessionStarted();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$_SESSION['mobile_csrf'] ??= bin2hex(random_bytes(32));

function mobileRespond(array $data, int $status = 200): never
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function mobileInput(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') mobileRespond(['error' => 'Требуется POST'], 405);
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) mobileRespond(['error' => 'Запрос слишком большой'], 413);
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        mobileRespond(['error' => 'Некорректный JSON'], 400);
    }
    if (!is_array($input) || !is_string($input['csrf'] ?? null) ||
        !hash_equals($_SESSION['mobile_csrf'], $input['csrf'])) {
        mobileRespond(['error' => 'Сессия формы истекла'], 403);
    }
    return $input;
}

function mobileUser(PDO $pdo): array
{
    if (!isLoggedIn()) mobileRespond(['error' => 'Требуется вход'], 401);
    $query = $pdo->prepare('SELECT id, full_name, balance, bonus_balance, is_admin, is_active FROM users WHERE id = ?');
    $query->execute([(int) $_SESSION['user']['id']]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['is_active']) mobileRespond(['error' => 'Учётная запись недоступна'], 401);
    return $user;
}
