<?php

function ensureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function openAccountSession(array $user): void
{
    ensureSessionStarted();
    if (empty($user['is_active']) || !isset($user['auth_generation']) || !session_regenerate_id(true)) {
        throw new RuntimeException('Учётная запись недоступна');
    }
    unset($user['password_hash'], $user['password_fingerprint'], $user['verification_code']);
    $_SESSION = ['user' => $user];
}

function currentSessionAccount(?PDO $pdo = null): ?array
{
    ensureSessionStarted();
    $session = $_SESSION['user'] ?? [];
    if (!isset($session['id'], $session['auth_generation'])) {
        if (isset($_SESSION['user'])) $_SESSION = [];
        return null;
    }
    try {
        if ($pdo === null) {
            require_once __DIR__ . '/db.php';
            static $database;
            $pdo = $database ??= (new Database())->getConnection();
        }
        $query = $pdo->prepare('SELECT id,is_active,is_admin,auth_generation FROM users WHERE id=?');
        $query->execute([(int) $session['id']]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        if ($account && $account['is_active'] &&
            (string) $session['auth_generation'] === (string) $account['auth_generation']) {
            $_SESSION['user']['is_admin'] = (int) $account['is_admin'];
            return $account;
        }
    } catch (Throwable $error) {
        error_log('Account session validation failed: ' . $error::class);
    }
    $_SESSION = [];
    return null;
}

function isLoggedIn(): bool
{
    return currentSessionAccount() !== null;
}

function isAdmin(): bool
{
    return !empty(currentSessionAccount()['is_admin']);
}

function checkAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /login/login.php');
        exit;
    }
}

function validateCaptcha($answer): bool
{
    ensureSessionStarted();
    return isset($_SESSION['captcha']) && $_SESSION['captcha'] === $answer;
}

function formCsrfToken(): string
{
    ensureSessionStarted();
    return $_SESSION['form_csrf'] ??= bin2hex(random_bytes(32));
}

function requirePostCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }
    $provided = $_POST['form_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($provided) || !hash_equals(formCsrfToken(), $provided)) {
        http_response_code(403);
        exit('Invalid form session');
    }
}

function auditAccountEdit(int $actor, int $subject, array $changes): void
{
    if ($changes) error_log('account_admin_change ' . json_encode([
        'actor_id'=>$actor, 'user_id'=>$subject, 'changes'=>$changes,
    ], JSON_THROW_ON_ERROR));
}
