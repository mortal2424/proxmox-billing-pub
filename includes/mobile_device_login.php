<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account_totp.php';

const MOBILE_DEVICE_LOGIN_LIFETIME = 30 * 86400;

function mobileDeviceTokenHash(string $token): ?string
{
    return preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token, true) : null;
}

function mobileAccountFingerprint(array $account): string
{
    return hash('sha256', $account['auth_generation'] . ':' . $account['password_hash'], true);
}

function issueMobileDeviceToken(PDO $pdo, int $userId): string
{
    $query = $pdo->prepare('SELECT password_hash,auth_generation FROM users WHERE id = ? AND is_active = 1');
    $query->execute([$userId]);
    $account = $query->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int) ($_SESSION['user']['id'] ?? 0) !== $userId ||
        (string) ($_SESSION['user']['auth_generation'] ?? '') !== (string) $account['auth_generation']) throw new RuntimeException('Учётная запись недоступна');

    $token = bin2hex(random_bytes(32));
    $hash = mobileDeviceTokenHash($token);
    $query = $pdo->prepare('INSERT INTO mobile_device_tokens (token_hash, user_id, password_fingerprint, expires_at)
        VALUES (?, ?, ?, ?)');
    $query->execute([$hash, $userId, mobileAccountFingerprint($account), time() + MOBILE_DEVICE_LOGIN_LIFETIME]);
    $_SESSION['mobile_device_token'] = bin2hex($hash);
    return $token;
}

function resumeMobileDeviceLogin(PDO $pdo, string $token): bool
{
    $hash = mobileDeviceTokenHash($token);
    if ($hash === null) return false;
    $query = $pdo->prepare('SELECT u.*, t.password_fingerprint FROM mobile_device_tokens t
        JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ? AND u.is_active = 1');
    $query->execute([$hash, time()]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !hash_equals($user['password_fingerprint'], mobileAccountFingerprint($user))) return false;
    if (accountTotpRow($pdo, (int)$user['id']) !== null) return false;
    openAccountSession($user);
    $_SESSION['mobile_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['mobile_device_token'] = bin2hex($hash);
    return true;
}

function revokeMobileDeviceLogin(PDO $pdo): void
{
    $hash = $_SESSION['mobile_device_token'] ?? '';
    if ($hash === '') return;
    $query = $pdo->prepare('DELETE FROM mobile_device_tokens WHERE token_hash = ? AND user_id = ?');
    $query->execute([hex2bin($hash), (int) $_SESSION['user']['id']]);
    unset($_SESSION['mobile_device_token']);
}
