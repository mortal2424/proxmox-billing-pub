<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/console_settings.php';

$endpoint = consoleSocketUrl();
if (!str_starts_with($endpoint, 'wss://')) {
    http_response_code(404);
    exit;
}
$origin = substr($endpoint, 0, -strlen('/console/socket'));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' {$origin}; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'");
echo str_replace(
    '<title>',
    '<meta name="console-socket" content="' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '">' . "\n<title>",
    file_get_contents(__DIR__ . '/view.html')
);
