<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account_totp.php';

function completePasswordLogin(PDO $pdo, string $email, string $password, mixed $totp = null): void
{
    $query = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $query->execute([$email]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Неверный email или пароль');
    }
    if (!$user['is_active']) {
        throw new RuntimeException('Ваш аккаунт деактивирован. Обратитесь к администратору');
    }
    accountTotpVerify($pdo, (int)$user['id'], $totp);
    openAccountSession($user);
}
