<?php

const MOBILE_DEVICE_LOGIN_LIFETIME = 30 * 86400;

function mobileDeviceTokenHash(string $token): ?string
{
    return preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token, true) : null;
}

function issueMobileDeviceToken(PDO $pdo, int $userId): string
{
    $query = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
    $query->execute([$userId]);
    $passwordHash = $query->fetchColumn();
    if (!is_string($passwordHash)) throw new RuntimeException('Учётная запись недоступна');

    $token = bin2hex(random_bytes(32));
    $hash = mobileDeviceTokenHash($token);
    $query = $pdo->prepare('INSERT INTO mobile_device_tokens (token_hash, user_id, password_fingerprint, expires_at)
        VALUES (?, ?, ?, ?)');
    $query->execute([$hash, $userId, hash('sha256', $passwordHash, true), time() + MOBILE_DEVICE_LOGIN_LIFETIME]);
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
    if (!$user || !hash_equals($user['password_fingerprint'], hash('sha256', $user['password_hash'], true))) return false;
    if (!session_regenerate_id(true)) throw new RuntimeException('Не удалось открыть сессию');
    unset($user['password_hash'], $user['password_fingerprint'], $user['verification_code']);
    $_SESSION = ['user' => $user, 'mobile_csrf' => bin2hex(random_bytes(32)), 'mobile_device_token' => bin2hex($hash)];
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
