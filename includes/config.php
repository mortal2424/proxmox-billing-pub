<?php
// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'mortal_prox');
define('DB_USER', 'mortal_prox');
define('DB_PASS', 'sAwklEmiiI');

// Настройки Telegram бота
define('TELEGRAM_BOT_TOKEN', '7733127948:AAHzUlwbL0Iw0dK-0h4d3KJXZDNA9aa6spo'); // Например: '123456789:AAFm-xxxxxxxxxxxxxxxxxxx'
define('TELEGRAM_CHAT_ID', '331473849'); // Например: '-123456789' или '123456789'

// Настройки SMTP для отправки email
define('SMTP_HOST', 'smtp.mail.ru'); // Например: 'smtp.mail.ru'
define('SMTP_PORT', 465); // Обычно 587 для TLS или 465 для SSL
define('SMTP_USER', 'cloud@homevlad.ru'); // Ваш email для отправки
define('SMTP_PASS', 'gpQSp2af3kKDabqQELDn'); // Пароль от почты
define('SMTP_FROM', 'cloud@homevlad.ru'); // Email отправителя
define('SMTP_FROM_NAME', 'HomeVlad Cloud Support'); // Имя отправителя
define('SMTP_SECURE', 'ssl'); // 'ssl' или 'tls'

// Настройки загрузки файлов
define('UPLOAD_DIR', __DIR__ . '/../uploads/tickets/'); // Путь к папке с загрузками
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // Максимальный размер файла (5MB)
define('ALLOWED_TYPES', [ // Разрешенные MIME-типы файлов
    'image/jpeg',
    'image/png',
    'application/pdf',
    'text/plain',
    'application/msword', // .doc
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // .docx
]);
