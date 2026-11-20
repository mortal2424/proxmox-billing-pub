<?php

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
    if (!session_regenerate_id(true)) throw new RuntimeException('Не удалось открыть сессию');
    unset($user['password_hash'], $user['verification_code']);
    $_SESSION['user'] = $user;
    unset($_SESSION['captcha'], $_SESSION['captcha_question'], $_SESSION['login_error'],
        $_SESSION['error'], $_SESSION['mobile_captcha_expires']);
}
