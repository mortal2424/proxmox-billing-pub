<?php
if (!isset($db, $googleCallback, $backUrl)) {
    http_response_code(404);
    exit;
}
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['google_settings_csrf'] ??= bin2hex(random_bytes(32));
$config = ['enabled'=>0, 'client_id'=>'', 'secret_box'=>''];
$message = '';
try {
    $config = $db->query('SELECT enabled, client_id, secret_box FROM web_login_google WHERE id=1')
        ->fetch(PDO::FETCH_ASSOC) ?: $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403);
            exit('Сессия формы истекла.');
        }
        $client = trim(is_string($_POST['client_id'] ?? null) ? $_POST['client_id'] : '');
        $secret = trim(is_string($_POST['client_secret'] ?? null) ? $_POST['client_secret'] : '');
        $enabled = isset($_POST['enabled']);
        if ($client !== '' && !preg_match('/^[A-Za-z0-9_-]{1,210}\.apps\.googleusercontent\.com$/D', $client)) {
            throw new DomainException('Проверьте Client ID веб-клиента Google.');
        }
        if ($secret !== '' && !preg_match('/^[A-Za-z0-9_-]{10,255}$/D', $secret)) {
            throw new DomainException('Проверьте Client Secret.');
        }
        $box = $secret === '' && $client === $config['client_id'] ? $config['secret_box'] : '';
        if ($secret !== '') $box = accountTotpCrypt($secret, true);
        if ($enabled && ($client === '' || $box === '')) throw new DomainException('Для включения нужны Client ID и Client Secret.');
        $save = $db->prepare('INSERT INTO web_login_google (id,enabled,client_id,secret_box) VALUES (1,?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),client_id=VALUES(client_id),secret_box=VALUES(secret_box)');
        $save->execute([(int)$enabled, $client, $box]);
        $config = ['enabled'=>$enabled, 'client_id'=>$client, 'secret_box'=>$box];
        $message = 'Настройки сохранены.';
    }
} catch (DomainException $error) {
    $message = $error->getMessage();
} catch (Throwable $error) {
    $message = 'Настройки Google временно недоступны.';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Вход через Google</title>
<style>
body{margin:0;background:#f2f6fc;color:#172842;font:14px/1.5 system-ui}
main{max-width:42rem;margin:1.5rem auto;padding:1rem 1.25rem;background:#fff;border:1px solid #cbd5e1;border-radius:12px}
h1{font-size:1.4rem;margin:.6rem 0}label{display:block;margin:.65rem 0}
input:not([type=checkbox]){display:block;width:100%;box-sizing:border-box;margin-top:.25rem;padding:.45rem;border:1px solid #8799b2;border-radius:6px;font:inherit}
button{padding:.5rem .85rem;background:#2458ac;color:#fff;border:0;border-radius:6px;font:inherit}
</style>
</head>
<body><main>
<a target="_top" href="<?= $escape($backUrl) ?>">Настройки панели</a>
<h1>Вход через Google</h1>
<label>Разрешённый URI перенаправления в Google Cloud Console
<input readonly value="<?= $escape($googleCallback) ?>">
</label>
<?php if ($message !== ''): ?><p role="status"><?= $escape($message) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<?php if (isset($nodeCsrf)): ?><input type="hidden" name="node_csrf" value="<?= $escape($nodeCsrf) ?>"><?php endif; ?>
<label><input type="checkbox" name="enabled" <?= $config['enabled'] ? 'checked' : '' ?>> Включить вход через Google</label>
<label>Client ID <input name="client_id" maxlength="255" autocomplete="off" value="<?= $escape($config['client_id']) ?>"></label>
<label>Client Secret <input type="password" name="client_secret" maxlength="255" autocomplete="new-password"></label>
<?php if ($config['secret_box'] !== ''): ?><p>Секрет сохранён. При том же Client ID поле можно оставить пустым.</p><?php endif; ?>
<button type="submit">Сохранить</button>
</form>
</main></body>
</html>
