<?php
require_once __DIR__ . '/auth.php';

function completePasswordLogin(PDO $pdo, string $email, string $password): void
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
    openAccountSession($user);
}
