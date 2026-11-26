<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/web_login_challenge.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
try {
    $config = loginCaptchaSettings((new Database())->getConnection());
    if (!$config['enabled'] || !$config['site_key']) {
        http_response_code(404);
        exit;
    }
} catch (Throwable $error) {
    http_response_code(503);
    exit;
}
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src https://challenges.cloudflare.com; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="ru">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Проверка входа HomeVlad</title>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES) ?>">
function homevladCaptchaComplete(token) {
    window.location.href = 'homevlad-captcha://result?token=' + encodeURIComponent(token);
}
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($config['site_key'], ENT_QUOTES) ?>"
     data-action="panel_login" data-callback="homevladCaptchaComplete"></div>
</html>
