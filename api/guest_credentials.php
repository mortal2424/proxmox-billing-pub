<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/guest_initial_password.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function sendGuestCredential(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendGuestCredential(['error' => 'Требуется POST'], 405);
}
if (!isLoggedIn()) sendGuestCredential(['error' => 'Войдите в кабинет'], 401);
$input = json_decode((string) file_get_contents('php://input', false, null, 0, 2049), true, 8);
$action = $input['action'] ?? '';
if (!in_array($action, ['available', 'username', 'password'], true)) {
    sendGuestCredential(['error' => 'Некорректный запрос'], 400);
}
$ownerId = (int) $_SESSION['user']['id'];
$control = $input['control'] ?? null;
$console = $control !== null;
if ($console) {
    $context = is_string($control) ? ($_SESSION['console_controls'][$control] ?? null) : null;
    if (!$context || $context['expires'] <= time() || $context['scope'] !== 'customer') {
        sendGuestCredential(['error' => 'Откройте консоль из карточки гостя'], 403);
    }
    $condition = 'v.node_id = ? AND v.vm_id = ?';
    $params = [$context['node_id'], $context['vm_id']];
} else {
    $csrf = $input['csrf'] ?? null;
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id || $action !== 'password' || !is_string($csrf) ||
        !isset($_SESSION['guest_settings_csrf']) || !hash_equals($_SESSION['guest_settings_csrf'], $csrf)) {
        sendGuestCredential(['error' => 'Обновите карточку гостя'], 403);
    }
    $condition = 'v.id = ?';
    $params = [$id];
}
session_write_close();

try {
    $pdo = (new Database())->getConnection();
    $query = $pdo->prepare("SELECT v.id, v.os_type, v.vm_type, p.username
        FROM vms v JOIN users u ON u.id = v.user_id
        LEFT JOIN guest_initial_passwords p ON p.guest_id = v.id
        WHERE u.is_active = 1 AND v.user_id = ? AND v.status <> 'deleted' AND $condition LIMIT 1");
    $query->execute([$ownerId, ...$params]);
    $guest = $query->fetch(PDO::FETCH_ASSOC);
    if (!$guest) sendGuestCredential(['error' => 'Гость недоступен'], 404);

    $username = $guest['username'] ?: ($guest['os_type'] === 'windows' ? 'Administrator' : 'root');
    if ($action === 'username') sendGuestCredential(['value' => $username]);
    $record = visibleGuestPassword($pdo, (int) $guest['id'], $ownerId);
    $available = $record !== null && (!$console || $record['applied']);
    if ($action === 'available') sendGuestCredential(['password' => $available]);
    if (!$available) sendGuestCredential(['error' => 'Пароль больше недоступен. Откройте карточку гостя'], 404);
    sendGuestCredential(['value' => $record['password'], 'ttl' => max(0, $record['expires_at'] - time())]);
} catch (Throwable $error) {
    error_log('Guest credential lookup failed: ' . get_class($error));
    sendGuestCredential(['error' => 'Пароль временно недоступен'], 503);
}
