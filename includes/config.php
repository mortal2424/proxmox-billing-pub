<?php
// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// Настройки Telegram бота
define('TELEGRAM_BOT_TOKEN', 'ваш токен бота'); // Например: '123456789:AAFm-xxxxxxxxxxxxxxxxxxx'
define('TELEGRAM_CHAT_ID', 'id чата или группы'); // Например: '-123456789' или '123456789'

// Настройки SMTP для отправки email
define('SMTP_HOST', 'smtp.mail.ru'); // Например: 'smtp.mail.ru'
define('SMTP_PORT', 465); // Обычно 587 для TLS или 465 для SSL
define('SMTP_USER', ''); // Ваш email для отправки
define('SMTP_PASS', ''); // Пароль от почты
define('SMTP_FROM', ''); // Email отправителя
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
