<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/admin/admin_functions.php';
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/notifications_error.log');

// Тест Telegram
if (sendTelegramNotification("Тестовое сообщение из HomeVlad")) {
    echo "Telegram notification sent successfully!<br>";
} else {
    echo "Failed to send Telegram notification<br>";
}

// Тест Email
if (sendEmailNotification('mortal24@yandex.ru', 'Тестовое письмо', '<h1>Это тест</h1><p>Проверка работы почтовой системы</p>')) {
    echo "Email notification sent successfully!<br>";
} else {
    echo "Failed to send Email notification<br>";
}

// Проверка логов
echo "Check error logs: " . ini_get('error_log');