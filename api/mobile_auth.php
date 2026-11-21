<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/password_login.php';
require_once __DIR__ . '/../includes/mobile_device_login.php';

function mobileChallenge(): array
{
    $first = random_int(1, 10);
    $second = random_int(1, 10);
    $_SESSION['captcha'] = $first + $second;
    $_SESSION['captcha_question'] = "$first + $second";
    $_SESSION['mobile_captcha_expires'] = time() + 300;
    return ['csrf' => $_SESSION['mobile_csrf'], 'captcha' => $_SESSION['captcha_question']];
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
$answer = filter_var($input['captcha'] ?? null, FILTER_VALIDATE_INT);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 1 || strlen($password) > 1024) {
    mobileRespond(['error' => 'Введите email и пароль'] + mobileChallenge(), 400);
}
if ($answer === false || ($_SESSION['captcha'] ?? null) !== $answer ||
    ($_SESSION['mobile_captcha_expires'] ?? 0) < time()) {
    mobileRespond(['error' => 'Неправильный ответ или истёк срок CAPTCHA'] + mobileChallenge(), 400);
}
try {
    completePasswordLogin((new Database())->getConnection(), $email, $password);
    unset($_SESSION['mobile_device_token']);
    $_SESSION['mobile_password_login_at'] = time();
    $_SESSION['mobile_csrf'] = bin2hex(random_bytes(32));
    mobileRespond(['success' => true, 'csrf' => $_SESSION['mobile_csrf']]);
} catch (PDOException $error) {
    error_log('Mobile login database unavailable');
    mobileRespond(['error' => 'Сервис входа временно недоступен'] + mobileChallenge(), 503);
} catch (RuntimeException $error) {
    mobileRespond(['error' => $error->getMessage()] + mobileChallenge(), 401);
} catch (Throwable $error) {
    error_log('Mobile login failed: ' . get_class($error));
    mobileRespond(['error' => 'Сервис входа временно недоступен'] + mobileChallenge(), 503);
}
