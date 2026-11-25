<?php
if (!isset($db, $hostname, $backUrl)) { http_response_code(404); exit; }
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['captcha_settings_csrf'] ??= bin2hex(random_bytes(32));
$settings = loginCaptchaSettings($db);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(403); exit('Сессия формы истекла.');
    }
    try {
        $siteKey = trim(is_string($_POST['site_key'] ?? null) ? $_POST['site_key'] : '');
        $secret = trim(is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        foreach ([$siteKey, $secret] as $v) {
            if ($v !== '' && !preg_match('/^[A-Za-z0-9_-]{10,128}$/D', $v)) throw new RuntimeException('Проверьте формат ключей.');
        }
        $box = $secret !== '' ? accountTotpCrypt($secret, true) : $settings['secret_box'];
        if ($enabled && ($siteKey === '' || $box === '')) throw new RuntimeException('Для включения нужны Site key и Secret key.');
        $stmt = $db->prepare('INSERT INTO web_login_captcha (id,enabled,site_key,secret_box,hostname) VALUES (1,?,?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),site_key=VALUES(site_key),secret_box=VALUES(secret_box),hostname=VALUES(hostname)');
        $stmt->execute([$enabled,$siteKey,$box,$hostname]);
        $settings = loginCaptchaSettings($db);
        $message = 'Настройки сохранены.';
    } catch (PDOException $error) {
        $message = 'Не удалось сохранить настройки.';
    } catch (RuntimeException $error) {
        $message = $error->getMessage();
    }
}
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Защита входа</title>
<style>
body{font:16px/1.5 system-ui;background:#f2f6fc;color:#172842;margin:0;padding:2rem 1rem}main{max-width:36rem;margin:auto;background:#fff;padding:1.5rem;border-radius:16px;border:1px solid #cbd5e1}
label{display:block;margin-top:1rem}input:not([type=checkbox]){display:block;width:100%;box-sizing:border-box;padding:.6rem;border:1px solid #8799b2;border-radius:8px}
button{margin-top:1.4rem;padding:.65rem 1rem;border:0;border-radius:8px;background:#2458ac;color:#fff;font:inherit;cursor:pointer}
</style>
<main><a href="<?= $e($backUrl) ?>">Настройки панели</a><h1>Защита входа</h1>
<p>Cloudflare Turnstile для <?= $e($hostname) ?>. Вход с Google Authenticator проходит без капчи.</p>
<?php if ($message): ?><p role="status"><?= $e($message) ?></p><?php endif ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if (isset($_SESSION["node_csrf"])): ?><input type="hidden" name="node_csrf" value="<?= $e($_SESSION["node_csrf"]) ?>"><?php endif ?>
<label><input type="checkbox" name="enabled" <?= $settings['enabled'] ? 'checked' : '' ?>> Включить капчу</label>
<label>Site key <input name="site_key" maxlength="128" value="<?= $e($settings['site_key']) ?>" autocomplete="off"></label>
<label>Secret key <input type="password" name="secret" maxlength="128" autocomplete="new-password"></label>
<?php if ($settings['secret_box']): ?><small>Секрет сохранён. Оставьте поле пустым, чтобы его сохранить.</small><?php endif ?>
<br><button>Сохранить</button>
</form></main></html>
