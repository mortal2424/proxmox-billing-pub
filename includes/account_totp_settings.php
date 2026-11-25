<?php
declare(strict_types=1);

// The settings entry point supplies the authenticated user, PDO, and product's password column.
if (!isset($db, $userId, $passwordColumn, $issuer, $backUrl)) {
    http_response_code(404);
    exit;
}
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['account_totp_csrf'] ??= bin2hex(random_bytes(32));
$message = '';
$enabled = accountTotpRow($db, $userId) !== null;
if (($_SESSION['account_totp_pending']['until'] ?? 0) <= time()) unset($_SESSION['account_totp_pending']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new RuntimeException('Обновите страницу и повторите действие.');
        }
        $query = $db->prepare("SELECT $passwordColumn FROM users WHERE id=?");
        $query->execute([$userId]);
        $hash = $query->fetchColumn();
        if (!is_string($hash) || !is_string($_POST['password'] ?? null) || !password_verify($_POST['password'], $hash)) {
            throw new RuntimeException('Проверьте текущий пароль.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'begin' && !$enabled) {
            $secret = random_bytes(20);
            $_SESSION['account_totp_pending'] = ['secret' => accountTotpCrypt($secret, true), 'until' => time() + 600];
        } elseif ($action === 'enable' && !$enabled && isset($_SESSION['account_totp_pending'])) {
            $pending = $_SESSION['account_totp_pending'];
            $step = accountTotpMatch(accountTotpCrypt($pending['secret'], false), $_POST['totp'] ?? null);
            if ($step === null) throw new RuntimeException('Код не совпал. Проверьте время на телефоне.');
            $query = $db->prepare('INSERT INTO account_totp (user_id,secret_box,last_step) VALUES (?,?,?)');
            $query->execute([$userId, $pending['secret'], $step]);
            unset($_SESSION['account_totp_pending']);
            $enabled = true;
            $message = 'Двухфакторная аутентификация включена. Для следующего входа дождитесь нового кода.';
        } elseif ($action === 'disable' && $enabled) {
            accountTotpVerify($db, $userId, $_POST['totp'] ?? null);
            $db->prepare('DELETE FROM account_totp WHERE user_id=?')->execute([$userId]);
            $enabled = false;
            $message = 'Двухфакторная аутентификация отключена.';
        }
    } catch (Throwable $error) {
        $message = $error instanceof PDOException ? 'Не удалось сохранить настройку. Проверьте миграцию базы.' : $error->getMessage();
    }
}
$manualKey = isset($_SESSION['account_totp_pending']) && !$enabled
    ? accountTotpManualKey(accountTotpCrypt($_SESSION['account_totp_pending']['secret'], false)) : null;
$qr = null;
if ($manualKey !== null) {
    try {
        require_once __DIR__ . '/qr_lib/qr.php';
        $label = rawurlencode($issuer) . ':' . rawurlencode('#' . $userId);
        $qr = accountTotpSvg('otpauth://totp/' . $label . '?' . http_build_query([
            'secret'=>$manualKey,'issuer'=>$issuer,'algorithm'=>'SHA1','digits'=>6,'period'=>30
        ], '', '&', PHP_QUERY_RFC3986));
    } catch (Throwable $error) {
        error_log('TOTP QR unavailable: ' . get_class($error));
    }
}
?>
<!doctype html>
<html lang="ru">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Двухфакторная аутентификация</title>
<style>

:root{color-scheme:light dark;font:16px/1.5 system-ui;--bg:#f2f6fc;--card:#fff;--text:#172842;--muted:#4b6077;--line:#cbd5e1;--accent:#2458ac}
@media(prefers-color-scheme:dark){:root{--bg:#111827;--card:#192438;--text:#eff5ff;--muted:#bacbe0;--line:#475569;--accent:#a0c6ff}}
body{margin:0;padding:clamp(16px,5vw,56px);background:var(--bg);color:var(--text)}
main{max-width:640px;margin:auto;background:var(--card);padding:clamp(18px,4vw,36px);border:1px solid var(--line);border-radius:18px}
h1{font-size:clamp(1.5rem,4vw,2rem);margin:1rem 0 .25rem} a{color:var(--accent)} p{color:var(--muted)}
label{display:block;margin:.9rem 0}input,button{font:inherit;padding:.6rem .8rem;border:1px solid var(--line);border-radius:8px}
input{display:block;margin-top:.35rem;width:min(100%,24rem);box-sizing:border-box;background:var(--card);color:var(--text)}
button{background:#2458ac;color:#fff;border-color:#2458ac;cursor:pointer;font-weight:600}
summary{cursor:pointer;color:var(--accent)}code{overflow-wrap:anywhere;user-select:all}
.setup{margin:1.2rem 0;padding:1rem;border:1px solid var(--line);border-radius:12px}
.setup svg{display:block;width:min(100%,216px);height:auto;border-radius:8px;margin-bottom:1rem}

</style>
<main>
<a href="<?= $escape($backUrl) ?>">Вернуться в кабинет</a>
<h1>Двухфакторная аутентификация</h1>
<p>Google Authenticator · <?= $enabled ? 'включён' : 'выключен' ?></p>
<?php if ($message !== ''): ?><p role="status"><?= $escape($message) ?></p><?php endif ?>
<?php if ($manualKey !== null): ?>
    <section class="setup">
    <?php if ($qr !== null): ?>
        <p>В Google Authenticator выберите «Сканировать QR-код».</p>
        <?= $qr ?>
    <?php endif ?>
    <details <?= $qr === null ? 'open' : '' ?>>
    <summary>Ввести ключ вручную</summary>
    <p>В Google Authenticator нажмите «Добавить код», выберите «Ввести ключ настройки» и укажите тип «По времени».</p>
    <p>Имя аккаунта: <strong><?= $escape($issuer . ' #' . $userId) ?></strong></p>
    <p>Ключ настройки: <code><?= $escape($manualKey) ?></code></p>
    </details>
    </section>
<?php endif ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <label>Текущий пароль <input type="password" name="password" autocomplete="current-password" required></label>
    <?php if ($enabled || $manualKey !== null): ?>
        <label>Шестизначный код <input name="totp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
    <?php endif ?>
    <button name="action" value="<?= $enabled ? 'disable' : ($manualKey === null ? 'begin' : 'enable') ?>">
        <?= $enabled ? 'Отключить' : ($manualKey === null ? 'Начать настройку' : 'Подтвердить и включить') ?>
    </button>
</form>
</main>
</html>
