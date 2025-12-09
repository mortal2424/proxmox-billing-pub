<?php
// Проверка базовой функциональности
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "1. Testing server environment:\n";
echo "PHP version: " . phpversion() . "\n";
echo "cURL enabled: " . (function_exists('curl_init') ? 'Yes' : 'No') . "\n";

// Проверка Telegram API
echo "\n2. Testing Telegram connection:\n";
$tg_test = @file_get_contents("https://api.telegram.org");
if ($tg_test === false) {
    echo "ERROR: Cannot access Telegram API\n";
    $error = error_get_last();
    echo "Error details: " . $error['message'] . "\n";
} else {
    echo "SUCCESS: Telegram API is reachable\n";
}

// Проверка SMTP соединения
echo "\n3. Testing SMTP connection:\n";
$smtp_test = @fsockopen('ssl://smtp.mail.ru', 465, $errno, $errstr, 10);
if (!$smtp_test) {
    echo "ERROR: SMTP connection failed\n";
    echo "$errstr ($errno)\n";
} else {
    echo "SUCCESS: SMTP server is reachable\n";
    fclose($smtp_test);
}

// Проверка DNS
echo "\n4. Testing DNS resolution:\n";
$domains = ['api.telegram.org', 'smtp.mail.ru'];
foreach ($domains as $domain) {
    $ip = gethostbyname($domain);
    echo "$domain resolves to $ip\n";
    if ($ip === $domain) {
        echo "WARNING: DNS resolution failed for $domain\n";
    }
}