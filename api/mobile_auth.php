<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/password_login.php';
require_once __DIR__ . '/../includes/mobile_device_login.php';
require_once __DIR__ . '/../includes/web_login_challenge.php';

function mobileChallenge(bool $turnstileRequired = false): array
{
    return ['csrf' => $_SESSION['mobile_csrf'], 'turnstile_required' => $turnstileRequired];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mobileRespond(mobileChallenge());
}
$input = mobileInput();
if (($input['action'] ?? '') === 'logout') {
    if (isset($_SESSION['mobile_device_token'])) {
        try { revokeMobileDeviceLogin((new Database())->getConnection()); }
        catch (PDOException $error) { mobileRespond(['error' => 'Не удалось отключить вход на этом устройстве'], 503); }
    }
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'],
        'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    mobileRespond(['success' => true]);
}
$action = $input['action'] ?? 'password_login';
if (in_array($action, ['device_enable', 'device_login', 'device_disable'], true)) {
    try {
        $pdo = (new Database())->getConnection();
        if ($action === 'device_login') {
            $token = is_string($input['token'] ?? null) ? $input['token'] : '';
            if (!resumeMobileDeviceLogin($pdo, $token)) {
                mobileRespond(['error' => 'Войдите с паролем и заново включите биометрию'] + mobileChallenge(), 401);
            }
            mobileRespond(['success' => true, 'csrf' => $_SESSION['mobile_csrf']]);
        }
        $user = mobileUser($pdo);
        if ($action === 'device_disable') {
            revokeMobileDeviceLogin($pdo);
            mobileRespond(['success' => true]);
        }
        if (($_SESSION['mobile_password_login_at'] ?? 0) < time() - 600) {
            mobileRespond(['error' => 'Войдите с паролем перед включением биометрии'], 403);
        }
        revokeMobileDeviceLogin($pdo);
        $token = issueMobileDeviceToken($pdo, (int) $user['id']);
        mobileRespond(['success' => true, 'token' => $token]);
    } catch (Throwable $error) {
        error_log('Mobile device authentication failed: ' . get_class($error));
        mobileRespond(['error' => 'Вход по биометрии временно недоступен'], 503);
    }
}
$email = is_string($input['email'] ?? null) ? trim($input['email']) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$turnstileRequired = false;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 1 || strlen($password) > 1024) {
    mobileRespond(['error' => 'Введите email и пароль'] + mobileChallenge(), 400);
}
try {
    $pdo = (new Database())->getConnection();
    limitWebLogin($pdo, $_SERVER['REMOTE_ADDR'] ?? '');
    completePasswordLogin($pdo, $email, $password, $input['totp'] ?? null,
        static function () use ($pdo, $input, &$turnstileRequired): void {
            $config = loginCaptchaSettings($pdo);
            if (!$config['enabled']) return;
            $turnstileRequired = true;
            verifyLoginTurnstile($input['turnstile_token'] ?? null, $config);
        }
    );
    unset($_SESSION['mobile_device_token']);
    $_SESSION['mobile_password_login_at'] = time();
    $_SESSION['mobile_csrf'] = bin2hex(random_bytes(32));
    mobileRespond(['success' => true, 'csrf' => $_SESSION['mobile_csrf']]);
} catch (PDOException $error) {
    error_log('Mobile login database unavailable');
    mobileRespond(['error' => 'Сервис входа временно недоступен'] + mobileChallenge(), 503);
} catch (RuntimeException $error) {
    mobileRespond(['error' => $error->getMessage()] + mobileChallenge($turnstileRequired), 401);
} catch (Throwable $error) {
    error_log('Mobile login failed: ' . get_class($error));
    mobileRespond(['error' => 'Сервис входа временно недоступен'] + mobileChallenge(), 503);
}
