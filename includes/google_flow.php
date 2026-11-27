<?php
declare(strict_types=1);

if (!isset($db, $callback, $loginUrl, $dashboardUrl, $vpn)) { http_response_code(404); exit; }
require_once __DIR__ . '/social_oidc.php';
require_once __DIR__ . '/account_totp.php';
require_once __DIR__ . '/web_login_challenge.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$settings = $db->query('SELECT enabled,client_id,secret_box FROM web_login_google WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if (!$settings || !$settings['enabled'] || !$settings['client_id'] || !$settings['secret_box']) {
    http_response_code(404);
    exit('Вход через Google выключен.');
}

$redirectGoogleFlow = static function (string $url): never {
    header('Location: '.$url, true, 303);
    exit;
};
$route = (string)parse_url($callback, PHP_URL_PATH);
$marker = hash('sha256', $settings['client_id']."\0".$settings['secret_box']);
if ($vpn ? isset($_SESSION['user_id']) : isLoggedIn()) $redirectGoogleFlow($dashboardUrl);
$error = '';
$pending = null;
try {
    if ($method === 'GET' && isset($_GET['start'])) {
        limitWebLogin($db, $_SERVER['REMOTE_ADDR'] ?? '');
        unset($_SESSION['google_identity']);
        [$transaction, $url] = billingOidcStart('google', $settings['client_id'], $callback);
        $transaction['marker'] = $marker;
        $_SESSION['google_transaction'] = $transaction;
        $redirectGoogleFlow($url);
    }
    if ($method === 'GET' && (isset($_GET['code']) || isset($_GET['error']))) {
        $transaction = $_SESSION['google_transaction'] ?? [];
        unset($_SESSION['google_transaction'], $_SESSION['google_identity']);
        if (($transaction['marker'] ?? null) !== $marker) throw new DomainException('Срок входа истёк. Начните заново.');
        $identity = billingOidcFinish($transaction, $_GET, $settings['client_id'],
            accountTotpCrypt($settings['secret_box'], false), $callback);
        if (!preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $identity['subject'])) {
            throw new DomainException('Google не подтвердил идентификатор аккаунта.');
        }
        session_regenerate_id(true);
        $_SESSION['google_identity'] = [
            'identity'=>$identity, 'csrf'=>bin2hex(random_bytes(32)), 'until'=>time()+600, 'marker'=>$marker,
        ];
        $redirectGoogleFlow($route);
    }

    if ($method === 'GET' && isset($_GET['linked']) && !empty($_SESSION['google_linked_notice'])) {
        unset($_SESSION['google_linked_notice']);
        $linkedNotice = true;
        throw new DomainException('Google привязан к аккаунту.');
    }
    $pending = $_SESSION['google_identity'] ?? null;
    if (!$pending || $pending['until'] <= time() || $pending['marker'] !== $marker) {
        unset($_SESSION['google_identity']);
        throw new DomainException('Срок входа истёк. Начните заново.');
    }
    $identity = $pending['identity'];
    $lookup = $db->prepare('SELECT u.* FROM web_google_identities g JOIN users u ON u.id=g.user_id WHERE g.google_subject=?');
    $lookup->execute([$identity['subject']]);
    $linked = $lookup->fetch(PDO::FETCH_ASSOC);
    $existing = null;
    if (!$linked && $identity['email'] !== null) {
        $lookup = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 2');
        $lookup->execute([$identity['email']]);
        $accounts = $lookup->fetchAll(PDO::FETCH_ASSOC);
        if (count($accounts) > 1) throw new DomainException('Для привязки обратитесь в поддержку.');
        $existing = $accounts[0] ?? null;
    }
    if (!$linked && !$existing && $identity['email'] === null) throw new DomainException('Google не подтвердил адрес почты.');

    $complete = static function (array $user, mixed $totp) use ($db, $vpn, $redirectGoogleFlow, $dashboardUrl): never {
        if ($vpn) {
            if ($user['status'] !== 'active') throw new DomainException('Аккаунт недоступен.');
            billingCompleteLogin($db, $user, 'Google', $totp);
        } else {
            if (empty($user['is_active'])) throw new DomainException('Аккаунт недоступен.');
            accountTotpVerify($db, (int)$user['id'], $totp);
            openAccountSession($user);
        }
        $redirectGoogleFlow($dashboardUrl);
    };

    if ($method === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($pending['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Сессия формы истекла.');
        }
        limitWebLogin($db, $_SERVER['REMOTE_ADDR'] ?? '');
        $totp = $_POST['totp'] ?? null;
        if ($linked) $complete($linked, $totp);
        if ($existing) {
            $password = $_POST['password'] ?? null;
            $hash = $existing[$vpn ? 'password' : 'password_hash'];
            if (($vpn ? $existing['status'] !== 'active' : empty($existing['is_active'])) ||
                !is_string($password) || strlen($password) > 1024 || !password_verify($password, $hash)) {
                throw new DomainException('Не удалось подтвердить доступ к существующему аккаунту.');
            }
            accountTotpVerify($db, (int)$existing['id'], $totp);
            $db->beginTransaction();
            try {
                $roleRead = $db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
                $roleRead->execute([$existing['id']]);
                $fresh = $roleRead->fetch(PDO::FETCH_ASSOC);
                if (!$fresh || ($vpn ? $fresh['status'] !== 'active' : empty($fresh['is_active'])) ||
                    !hash_equals($hash, $fresh[$vpn ? 'password' : 'password_hash']) ||
                    !hash_equals($existing['email'], $fresh['email'])) {
                    throw new DomainException('Аккаунт изменился. Повторите вход.');
                }
                $db->prepare('INSERT INTO web_google_identities (google_subject,user_id) VALUES (?,?)')
                    ->execute([$identity['subject'], $fresh['id']]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            // The next screen completes sign-in with the normal session and TOTP checks.
            unset($_SESSION['google_identity']);
            $_SESSION['google_linked_notice'] = true;
            $redirectGoogleFlow($route.'?linked=1');
        }

        if (empty($identity['email_authoritative'])) {
            throw new DomainException('Для этого адреса сначала зарегистрируйтесь в панели с подтверждением почты, затем привяжите Google.');
        }
        $newId = googleRegisterPanelAccount($db, $identity, $_POST);
        $lookup = $db->prepare('SELECT * FROM users WHERE id=?');
        $lookup->execute([$newId]);
        $created = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$created) throw new DomainException('Не удалось загрузить новый аккаунт.');
        $complete($created, null);
    } elseif ($method !== 'GET') {
        http_response_code(405);
        exit;
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Google sign-in failed: '.$e::class);
    $error = 'Не удалось завершить вход через Google. Повторите попытку.';
}
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Вход через Google</title>
<style>
body{font:15px/1.5 system-ui;background:#f2f6fc;color:#172842;margin:0;padding:1rem}
main{max-width:28rem;margin:5vh auto;background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:1.25rem}
label{display:block;margin:.65rem 0}input{display:block;box-sizing:border-box;width:100%;padding:.55rem;border:1px solid #8799b2;border-radius:6px}
button{background:#2458ac;color:white;border:0;border-radius:6px;padding:.6rem .9rem;font:inherit}
</style></head><body><main>
<h1>Вход через Google</h1>
<?php if ($error !== ''): ?><p role="alert"><?= $e($error) ?></p><?php endif ?>
<?php if (!empty($linkedNotice)): ?><p><a href="<?= $e($route) ?>?start=1">Продолжить вход через Google</a></p><?php endif ?>
<?php if ($pending && isset($linked)): ?>
<form method="post" action="<?= $e($route) ?>">
<input type="hidden" name="csrf" value="<?= $e($pending['csrf']) ?>">
<?php if ($existing): ?>
<p>Для этой почты уже есть аккаунт. Подтвердите, что хотите привязать к нему Google.</p>
<label>Текущий пароль панели <input name="password" type="password" autocomplete="current-password" required></label>
<?php endif ?>
<?php if ($existing || $linked): ?>
<?php if (accountTotpRow($db, (int)($linked ?: $existing)['id'])): ?>
<label>Код Google Authenticator <input name="totp" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" required></label>
<?php endif ?>
<button type="submit"><?= $existing ? 'Привязать Google' : 'Войти' ?></button>
<?php elseif ($identity['email'] !== null): ?>
<p>Создание аккаунта для <?= $e($identity['email']) ?></p>
<?php if ($vpn): ?>
<label>Имя пользователя <input name="username" required minlength="3" maxlength="32" autocomplete="username"></label>
<?php else: ?>
<label>Имя <input name="first_name" required maxlength="100" autocomplete="given-name"></label>
<label>Фамилия <input name="last_name" required maxlength="100" autocomplete="family-name"></label>
<label>Телефон <input type="tel" name="phone" required maxlength="12" autocomplete="tel" placeholder="+7XXXXXXXXXX"></label>
<?php endif ?>
<button type="submit">Создать аккаунт</button>
<?php endif ?>
</form>
<?php endif ?>
<p><a href="<?= $e($loginUrl) ?>">Вернуться к форме входа</a></p>
</main></body></html>
