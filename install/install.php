<?php
// install/install.php
session_start();

// Проверяем, установлена ли уже система
if (file_exists('../includes/config.php')) {
    header('Location: ../index.php');
    exit;
}

// Функция для проверки требований
function checkRequirements() {
    $requirements = [];

    // PHP версия
    $php_version = phpversion();
    $requirements['php_version'] = [
        'name' => 'PHP версия 8.0 или выше',
        'status' => version_compare($php_version, '8.0.0', '>='),
        'current' => $php_version,
        'required' => '8.0.0+',
        'icon' => 'php'
    ];

    // Критически важные расширения
    $required_extensions = [
        'pdo_mysql' => ['name' => 'PDO MySQL', 'icon' => 'database'],
        'mysqli' => ['name' => 'MySQLi', 'icon' => 'database'],
        'mbstring' => ['name' => 'Multibyte String', 'icon' => 'user'],
        'json' => ['name' => 'JSON', 'icon' => 'code'],
        'curl' => ['name' => 'cURL', 'icon' => 'globe'],
        'openssl' => ['name' => 'OpenSSL', 'icon' => 'lock'],
        'session' => ['name' => 'Session', 'icon' => 'user'],
        'xml' => ['name' => 'XML', 'icon' => 'file-code'],
        'ssh2' => ['name' => 'SSH2 (php-ssh2)', 'icon' => 'terminal'],
        'bcmath' => ['name' => 'BCMath', 'icon' => 'calculator'],
        'zlib' => ['name' => 'Zlib', 'icon' => 'compress'],
        'gd' => ['name' => 'GD Library', 'icon' => 'image'],
        'fileinfo' => ['name' => 'Fileinfo', 'icon' => 'file'],
        'intl' => ['name' => 'Internationalization', 'icon' => 'language']
    ];

    foreach ($required_extensions as $ext => $info) {
        $requirements['ext_' . $ext] = [
            'name' => 'Расширение: ' . $info['name'],
            'status' => extension_loaded($ext),
            'current' => extension_loaded($ext) ? 'Установлено' : 'Отсутствует',
            'required' => 'Обязательно',
            'icon' => $info['icon']
        ];
    }

    // Права на запись
    $writable_dirs = [
        '../includes' => ['name' => 'Директория includes', 'icon' => 'folder'],
        '../uploads' => ['name' => 'Директория uploads', 'icon' => 'upload'],
        '../logs' => ['name' => 'Директория logs', 'icon' => 'clipboard'],
        '../cache' => ['name' => 'Директория cache', 'icon' => 'bolt'],
        '../temp' => ['name' => 'Директория temp', 'icon' => 'folder'],
        '../backups' => ['name' => 'Директория backups', 'icon' => 'save'],
        '.' => ['name' => 'Директория установки', 'icon' => 'folder-open']
    ];

    foreach ($writable_dirs as $dir => $info) {
        $requirements['write_' . md5($dir)] = [
            'name' => 'Доступ на запись: ' . $info['name'],
            'status' => is_writable($dir),
            'current' => is_writable($dir) ? 'Доступен' : 'Запрещен',
            'required' => 'Для установки',
            'icon' => $info['icon']
        ];
    }

    // Проверка файла config.php.simple
    $config_template_exists = file_exists('../includes/config.php.simple');
    $requirements['config_template'] = [
        'name' => 'Файл шаблона config.php.simple',
        'status' => $config_template_exists,
        'current' => $config_template_exists ? 'Найден' : 'Не найден',
        'required' => 'Обязательно',
        'icon' => 'file-code'
    ];

    // Проверка размера памяти
    $memory_limit = ini_get('memory_limit');
    $requirements['memory_limit'] = [
        'name' => 'Лимит памяти не менее 256M',
        'status' => (intval($memory_limit) >= 256 || $memory_limit == '-1'),
        'current' => $memory_limit,
        'required' => '256M',
        'icon' => 'memory'
    ];

    // Проверка максимального размера загрузки
    $upload_max = ini_get('upload_max_filesize');
    $requirements['upload_max'] = [
        'name' => 'Максимальный размер загрузки не менее 10M',
        'status' => (intval($upload_max) >= 10),
        'current' => $upload_max,
        'required' => '10M',
        'icon' => 'file-upload'
    ];

    // Проверка максимального времени выполнения
    $max_execution_time = ini_get('max_execution_time');
    $requirements['max_execution_time'] = [
        'name' => 'Максимальное время выполнения не менее 300s',
        'status' => (intval($max_execution_time) >= 300 || $max_execution_time == '0'),
        'current' => $max_execution_time,
        'required' => '300s',
        'icon' => 'clock'
    ];

    return $requirements;
}

// Обработка формы установки
$errors = [];
$success = false;
$current_step = 1;
$form_data = [];

// Шаг 1: Проверка требований
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1) {
    $form_data['step1'] = [
        'agree_terms' => isset($_POST['agree_terms']) ? 1 : 0
    ];

    if (!$form_data['step1']['agree_terms']) {
        $errors[] = "Необходимо принять условия лицензионного соглашения";
    } else {
        $requirements = checkRequirements();
        $all_requirements_met = true;
        foreach ($requirements as $req) {
            if (!$req['status']) {
                $all_requirements_met = false;
                break;
            }
        }

        if (!$all_requirements_met) {
            $errors[] = "Не все системные требования выполнены. Пожалуйста, исправьте отмеченные проблемы.";
        } else {
            $current_step = 2;
        }
    }
}

// Шаг 2: Настройка базы данных
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 2) {
    $form_data['step2'] = [
        'db_host' => trim($_POST['db_host'] ?? 'localhost'),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_pass' => $_POST['db_pass'] ?? '',
        'db_port' => trim($_POST['db_port'] ?? '3306'),
        'create_db' => isset($_POST['create_db']) ? 1 : 0
    ];

    // Валидация
    if (empty($form_data['step2']['db_name'])) {
        $errors[] = "Имя базы данных обязательно";
    }

    if (empty($form_data['step2']['db_user'])) {
        $errors[] = "Имя пользователя базы данных обязательно";
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$form_data['step2']['db_host']};port={$form_data['step2']['db_port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $form_data['step2']['db_user'], $form_data['step2']['db_pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Проверяем существование базы данных
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$form_data['step2']['db_name']}'");
            $db_exists = $stmt->fetch();

            if (!$db_exists && $form_data['step2']['create_db']) {
                // Создаем базу данных
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$form_data['step2']['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$form_data['step2']['db_name']}`");
                $db_created = true;
            } elseif (!$db_exists && !$form_data['step2']['create_db']) {
                $errors[] = "База данных не существует. Отметьте 'Создать базу данных' для автоматического создания.";
            } else {
                $pdo->exec("USE `{$form_data['step2']['db_name']}`");
                $db_created = false;
            }

            if (empty($errors)) {
                $_SESSION['install_db'] = $form_data['step2'];
                $current_step = 3;
            }

        } catch (PDOException $e) {
            $errors[] = "Ошибка подключения к базе данных: " . $e->getMessage();
        }
    }
}

// Шаг 3: Импорт SQL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 3) {
    if (!isset($_SESSION['install_db'])) {
        $errors[] = "Данные базы данных утеряны. Пожалуйста, начните установку заново.";
        $current_step = 1;
    } else {
        try {
            $db_config = $_SESSION['install_db'];
            $dsn = "mysql:host={$db_config['db_host']};port={$db_config['db_port']};dbname={$db_config['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_config['db_user'], $db_config['db_pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Устанавливаем таймаут для долгих операций
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, 300);

            // Читаем SQL файл
            $sql_file = __DIR__ . '/sql-install.sql';
            if (!file_exists($sql_file)) {
                throw new Exception("Файл sql-install.sql не найден в папке install");
            }

            $sql_content = file_get_contents($sql_file);

            // Удаляем комментарии
            $sql_content = preg_replace('/\/\*.*?\*\/;/s', '', $sql_content);
            $sql_content = preg_replace('/--.*?[\r\n]/', '', $sql_content);

            // Удаляем команды DELIMITER, так как они не нужны для PDO
            $sql_content = preg_replace('/DELIMITER \$\$/i', '', $sql_content);
            $sql_content = preg_replace('/\$\$/i', ';', $sql_content);
            $sql_content = preg_replace('/DELIMITER ;/i', '', $sql_content);

            // Разбиваем на отдельные запросы
            $queries = array_filter(
                array_map('trim',
                    explode(';', $sql_content)
                )
            );

            $success_count = 0;
            $failed_queries = [];

            // Выполняем каждый запрос отдельно
            foreach ($queries as $index => $query) {
                if (!empty($query)) {
                    // Пропускаем пустые запросы после обработки
                    if (strlen(trim($query)) < 10) {
                        continue;
                    }

                    try {
                        $pdo->exec($query);
                        $success_count++;
                    } catch (PDOException $e) {
                        // Игнорируем только ошибки "таблица уже существует" или "дубликат ключа"
                        $error_msg = $e->getMessage();
                        if (strpos($error_msg, 'already exists') !== false ||
                            strpos($error_msg, 'Duplicate') !== false ||
                            strpos($error_msg, 'duplicate') !== false) {
                            // Эти ошибки не критичны для установки
                            $success_count++;
                            continue;
                        }

                        // Сохраняем информацию об ошибке для отладки
                        $failed_queries[] = [
                            'index' => $index + 1,
                            'query_preview' => substr($query, 0, 100) . (strlen($query) > 100 ? '...' : ''),
                            'error' => $error_msg
                        ];
                    }
                }
            }

            // Проверяем результат
            $total_queries = count($queries);

            if (count($failed_queries) > 0) {
                // Были реальные ошибки, а не только "таблица уже существует"
                $errors[] = "При импорте SQL возникли ошибки в " . count($failed_queries) . " запросах из " . $total_queries . ".";

                // Для отладки показываем первую ошибку
                if (isset($failed_queries[0])) {
                    $errors[] = "Ошибка #1: " . $failed_queries[0]['error'];
                }

                // Остаемся на этом шаге
                $current_step = 3;
            } else {
                // Все запросы выполнены успешно или были проигнорированы не критические ошибки
                $_SESSION['tables_created'] = true;
                $current_step = 4;

                // Добавляем информационное сообщение о успешном импорте
                $success_msg = "Успешно выполнено $success_count из $total_queries SQL запросов.";
                // Можем добавить в сессии, чтобы показать на следующем шаге
                $_SESSION['import_success'] = $success_msg;
            }

        } catch (Exception $e) {
            $errors[] = "Ошибка подключения или выполнения SQL: " . $e->getMessage();
            $current_step = 3; // Остаемся на шаге 3
        }
    }
}

// Шаг 4: Настройка администратора и системы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 4) {
    if (!isset($_SESSION['install_db'])) {
        $errors[] = "Данные базы данных утеряны. Пожалуйста, начните установку заново.";
        $current_step = 1;
    } else {
        $form_data['step4'] = [
            'admin_email' => trim($_POST['admin_email'] ?? ''),
            'admin_password' => $_POST['admin_password'] ?? '',
            'admin_password_confirm' => $_POST['admin_password_confirm'] ?? '',
            'admin_first_name' => trim($_POST['admin_first_name'] ?? ''),
            'admin_last_name' => trim($_POST['admin_last_name'] ?? ''),
            'admin_phone' => trim($_POST['admin_phone'] ?? ''),
            'site_url' => rtrim(trim($_POST['site_url'] ?? ''), '/'),
            // Telegram Support Bot
            'telegram_support_bot_token' => trim($_POST['telegram_support_bot_token'] ?? ''),
            'telegram_support_bot_name' => trim($_POST['telegram_support_bot_name'] ?? 'Support Bot'),
            // Telegram Chat Bot
            'telegram_chat_bot_token' => trim($_POST['telegram_chat_bot_token'] ?? ''),
            'telegram_chat_bot_name' => trim($_POST['telegram_chat_bot_name'] ?? 'Chat Bot'),
            // SMTP settings
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => trim($_POST['smtp_port'] ?? '465'),
            'smtp_user' => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass' => $_POST['smtp_pass'] ?? '',
            'smtp_from' => trim($_POST['smtp_from'] ?? ''),
            'smtp_from_name' => trim($_POST['smtp_from_name'] ?? 'HomeVlad Cloud Support'),
            'smtp_secure' => trim($_POST['smtp_secure'] ?? 'ssl')
        ];

        // Валидация
        if (empty($form_data['step4']['admin_email']) || !filter_var($form_data['step4']['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Введите корректный email администратора";
        }

        if (empty($form_data['step4']['admin_password'])) {
            $errors[] = "Введите пароль администратора";
        }

        if ($form_data['step4']['admin_password'] !== $form_data['step4']['admin_password_confirm']) {
            $errors[] = "Пароли администратора не совпадают";
        }

        if (empty($form_data['step4']['admin_first_name']) || empty($form_data['step4']['admin_last_name'])) {
            $errors[] = "Введите имя и фамилию администратора";
        }

        if (empty($form_data['step4']['site_url'])) {
            $errors[] = "Введите URL сайта";
        }

        if (empty($errors)) {
            $_SESSION['install_config'] = $form_data['step4'];
            $current_step = 5;
        }
    }
}

// Шаг 5: Создание конфигурационных файлов и заполнение таблиц
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 5) {
    if (!isset($_SESSION['install_db']) || !isset($_SESSION['install_config'])) {
        $errors[] = "Данные установки утеряны. Пожалуйста, начните установку заново.";
        $current_step = 1;
    } else {
        try {
            $db_config = $_SESSION['install_db'];
            $sys_config = $_SESSION['install_config'];

            // 1. Создаем администратора
            try {
                $dsn = "mysql:host={$db_config['db_host']};port={$db_config['db_port']};dbname={$db_config['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_config['db_user'], $db_config['db_pass']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $hashed_password = password_hash($sys_config['admin_password'], PASSWORD_DEFAULT);
                $verification_code = substr(md5(uniqid()), 0, 10);
                $full_name = $sys_config['admin_first_name'] . ' ' . $sys_config['admin_last_name'];

                // ДОБАВЛЕНО: Поле is_active со значением 1
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (email, phone, full_name, password_hash, first_name, last_name,
                     balance, is_admin, created_at, user_type, company_name,
                     email_verified, is_active, updated_at, bonus_balance, telegram_username,
                     verification_code, verification_sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())
                ");

                $balance = 10000.00;
                $is_admin = 1;
                $user_type = 'individual';
                $company_name = '';
                $email_verified = 1;
                $is_active = 1; // ДОБАВЛЕНО: Активируем аккаунт администратора
                $bonus_balance = 500.00;
                $telegram_username = 'admin';

                $stmt->execute([
                    $sys_config['admin_email'],
                    $sys_config['admin_phone'],
                    $full_name,
                    $hashed_password,
                    $sys_config['admin_first_name'],
                    $sys_config['admin_last_name'],
                    $balance,
                    $is_admin,
                    $user_type,
                    $company_name,
                    $email_verified,
                    $is_active, // ДОБАВЛЕНО: Передаем значение для is_active
                    $bonus_balance,
                    $telegram_username,
                    $verification_code
                ]);

                $admin_id = $pdo->lastInsertId();

                // Создаем квоты для администратора
                $pdo->exec("
                    INSERT INTO user_quotas (user_id, max_vms, max_cpu, max_ram, max_disk)
                    VALUES ($admin_id, 100, 100, 1048576, 10000)
                ");

            } catch (PDOException $e) {
                throw new Exception("Ошибка создания администратора: " . $e->getMessage());
            }

            // 2. Заполняем таблицу с ботом поддержки
            try {
                $stmt = $pdo->prepare("INSERT INTO telegram_support_bot (bot_token, bot_name) VALUES (?, ?)");
                $stmt->execute([
                    $sys_config['telegram_support_bot_token'],
                    $sys_config['telegram_support_bot_name']
                ]);
            } catch (PDOException $e) {
                throw new Exception("Ошибка записи настроек бота поддержки: " . $e->getMessage());
            }

            // 3. Заполняем таблицу с чат-ботом
            try {
                $stmt = $pdo->prepare("INSERT INTO telegram_chat_bot (bot_token, bot_name) VALUES (?, ?)");
                $stmt->execute([
                    $sys_config['telegram_chat_bot_token'],
                    $sys_config['telegram_chat_bot_name']
                ]);
            } catch (PDOException $e) {
                throw new Exception("Ошибка записи настроек чат-бота: " . $e->getMessage());
            }

            // 4. Заполняем таблицу с настройками SMTP
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO smtp_settings
                    (host, port, user, pass, from_email, from_name, secure)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $sys_config['smtp_host'],
                    $sys_config['smtp_port'],
                    $sys_config['smtp_user'],
                    $sys_config['smtp_pass'],
                    $sys_config['smtp_from'],
                    $sys_config['smtp_from_name'],
                    $sys_config['smtp_secure']
                ]);
            } catch (PDOException $e) {
                throw new Exception("Ошибка записи настроек SMTP: " . $e->getMessage());
            }

            // 5. Создаем config.php из шаблона
            $config_template = file_get_contents('../includes/config.php.simple');

            $replacements = [
                'define(\'DB_HOST\', \'\')' => "define('DB_HOST', '" . addslashes($db_config['db_host']) . "')",
                'define(\'DB_NAME\', \'\')' => "define('DB_NAME', '" . addslashes($db_config['db_name']) . "')",
                'define(\'DB_USER\', \'\')' => "define('DB_USER', '" . addslashes($db_config['db_user']) . "')",
                'define(\'DB_PASS\', \'\')' => "define('DB_PASS', '" . addslashes($db_config['db_pass']) . "')",
                'define(\'TELEGRAM_SUPPORT_BOT_TOKEN\', \'\')' => "define('TELEGRAM_SUPPORT_BOT_TOKEN', '" . addslashes($sys_config['telegram_support_bot_token']) . "')",
                'define(\'TELEGRAM_SUPPORT_BOT_NAME\', \'Support Bot\')' => "define('TELEGRAM_SUPPORT_BOT_NAME', '" . addslashes($sys_config['telegram_support_bot_name']) . "')",
                'define(\'TELEGRAM_CHAT_BOT_TOKEN\', \'\')' => "define('TELEGRAM_CHAT_BOT_TOKEN', '" . addslashes($sys_config['telegram_chat_bot_token']) . "')",
                'define(\'TELEGRAM_CHAT_BOT_NAME\', \'Chat Bot\')' => "define('TELEGRAM_CHAT_BOT_NAME', '" . addslashes($sys_config['telegram_chat_bot_name']) . "')",
                'define(\'SMTP_HOST\', \'smtp.mail.ru\')' => "define('SMTP_HOST', '" . addslashes($sys_config['smtp_host']) . "')",
                'define(\'SMTP_PORT\', 465)' => "define('SMTP_PORT', " . $sys_config['smtp_port'] . ")",
                'define(\'SMTP_USER\', \'\')' => "define('SMTP_USER', '" . addslashes($sys_config['smtp_user']) . "')",
                'define(\'SMTP_PASS\', \'\')' => "define('SMTP_PASS', '" . addslashes($sys_config['smtp_pass']) . "')",
                'define(\'SMTP_FROM\', \'\')' => "define('SMTP_FROM', '" . addslashes($sys_config['smtp_from']) . "')",
                'define(\'SMTP_FROM_NAME\', \'HomeVlad Cloud Support\')' => "define('SMTP_FROM_NAME', '" . addslashes($sys_config['smtp_from_name']) . "')",
                'define(\'SMTP_SECURE\', \'ssl\')' => "define('SMTP_SECURE', '" . addslashes($sys_config['smtp_secure']) . "')",
            ];

            $config_content = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $config_template
            );

            // Создаем config.php
            if (file_put_contents('../includes/config.php', $config_content) === false) {
                throw new Exception("Не удалось создать файл includes/config.php");
            }

            // 3. Создаем необходимые директории
            $directories = ['uploads', 'logs', 'cache', 'backups', 'temp', 'uploads/tickets'];
            foreach ($directories as $dir) {
                if (!file_exists("../$dir")) {
                    mkdir("../$dir", 0755, true);
                }
            }

            // 4. Создаем .htaccess
            $htaccess_content = <<<HTACCESS
# Запрет прямого доступа к файлам
<FilesMatch "\.(sql|log|tpl|inc|bak|old|simple)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Запрет доступа к скрытым файлам
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# Защита установочного файла
<Files install.php>
    Order allow,deny
    Deny from all
</Files>

# Защита конфигурационных файлов
<FilesMatch "config\.(php|php\.simple)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Запрет листинга директорий
Options -Indexes

# Базовые настройки
AddDefaultCharset UTF-8

# Перенаправление на index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [L,QSA]
HTACCESS;

            file_put_contents('../.htaccess', $htaccess_content);

            // 5. Создаем файл версии
            file_put_contents('../version.txt', date('Y-m-d H:i:s') . ' - Установка завершена');

            $current_step = 6;

        } catch (Exception $e) {
            $errors[] = "Ошибка при создании конфигурации: " . $e->getMessage();
        }
    }
}

$requirements = checkRequirements();
$all_requirements_met = true;
foreach ($requirements as $req) {
    if (!$req['status']) {
        $all_requirements_met = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-light: #94a3b8;
            --border-color: #cbd5e1;
            --accent: #0ea5e9;
            --accent-hover: #0284c7;
            --accent-light: rgba(14, 165, 233, 0.15);
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .install-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            min-height: 100vh;
            background: var(--light-bg);
            position: relative;
        }

        .install-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 10% 20%, rgba(14, 165, 233, 0.1) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.05) 0%, transparent 40%);
        }

        /* ШАПКА */
        .install-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 2;
        }

        .install-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .install-logo i {
            font-size: 48px;
            color: var(--accent);
            background: var(--accent-light);
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(14, 165, 233, 0.3);
        }

        .install-logo h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .install-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ПРОГРЕСС */
        .install-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }

        .install-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 10%;
            right: 10%;
            height: 2px;
            background: var(--border-color);
            z-index: 1;
        }

        .progress-step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .step-number {
            width: 40px;
            height: 40px;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-light);
            margin: 0 auto 12px;
            transition: all 0.3s ease;
        }

        .progress-step.active .step-number {
            background: var(--secondary-gradient);
            border-color: transparent;
            color: white;
            transform: scale(1.1);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.3);
        }

        .progress-step.completed .step-number {
            background: var(--success-gradient);
            border-color: transparent;
            color: white;
        }

        .progress-step.completed .step-number::after {
            content: '✓';
            margin-left: 2px;
        }

        .step-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }

        .progress-step.active .step-title {
            color: var(--text-primary);
            font-weight: 700;
        }

        .progress-step.completed .step-title {
            color: var(--success);
        }

        /* ОСНОВНАЯ КАРТОЧКА */
        .main-card {
            width: 100%;
            max-width: 800px;
            position: relative;
            z-index: 2;
            background: var(--card-bg);
            border-radius: 24px;
            padding: 40px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeInUp 0.8s ease forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* УВЕДОМЛЕНИЯ */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border: 1px solid transparent;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.3);
            color: #b91c1c;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.3);
            color: #047857;
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.3);
            color: #b45309;
        }

        .alert i {
            font-size: 18px;
        }

        /* ТРЕБОВАНИЯ */
        .requirements-list {
            margin: 20px 0;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            background: white;
            transition: all 0.3s ease;
        }

        .requirement-item:hover {
            transform: translateX(5px);
            border-color: var(--accent);
        }

        .requirement-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .requirement-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            background: var(--secondary-gradient);
        }

        .requirement-icon.error {
            background: var(--danger-gradient);
        }

        .requirement-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-success {
            background: rgba(16, 185, 129, 0.15);
            color: #047857;
        }

        .status-error {
            background: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
        }

        /* ФОРМЫ */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: white;
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-light);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            border: 2px solid var(--border-color);
            border-radius: 4px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
            position: relative;
        }

        .form-check-input:checked {
            background: var(--accent);
            border-color: var(--accent);
        }

        .form-check-input:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .form-check-label {
            font-size: 14px;
            color: var(--text-primary);
            cursor: pointer;
        }

        /* КНОПКИ */
        .form-actions {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(14, 165, 233, 0.3);
        }

        .btn-secondary {
            background: var(--card-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--light-bg);
            transform: translateY(-2px);
            box-shadow: var(--card-shadow);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }

        /* УСПЕШНАЯ УСТАНОВКА */
        .success-screen {
            text-align: center;
            padding: 40px 0;
        }

        .success-icon {
            font-size: 80px;
            color: var(--success);
            margin-bottom: 30px;
        }

        .success-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .success-subtitle {
            font-size: 16px;
            color: var(--text-secondary);
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* СТАТУСНЫЕ БЭДЖИ */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: #047857;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-error {
            background: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* ИНФО БЛОКИ */
        .info-box {
            background: rgba(14, 165, 233, 0.05);
            border: 1px solid rgba(14, 165, 233, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .info-box-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: var(--text-primary);
            font-weight: 600;
        }

        .info-box-title i {
            color: var(--accent);
        }

        /* АДАПТИВНОСТЬ */
        @media (max-width: 768px) {
            .main-card {
                padding: 30px 20px;
            }

            .install-logo h1 {
                font-size: 28px;
            }

            .install-logo i {
                font-size: 36px;
                width: 60px;
                height: 60px;
            }

            .install-subtitle {
                font-size: 16px;
            }

            .step-title {
                font-size: 10px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .install-progress::before {
                left: 5%;
                right: 5%;
            }

            .step-title {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="main-card">
            <!-- Шапка -->
            <div class="install-header">
                <div class="install-logo">
                    <i class="fas fa-cloud"></i>
                    <h1>Биллинг панель для системы Proxmox</h1>
                </div>
                <p class="install-subtitle">
                    Мастер установки системы управления виртуальными серверами
                </p>
            </div>

            <!-- Прогресс установки -->
            <div class="install-progress">
                <div class="progress-step <?= $current_step >= 1 ? 'active' : '' ?> <?= $current_step > 1 ? 'completed' : '' ?>">
                    <div class="step-number">1</div>
                    <div class="step-title">Требования</div>
                </div>
                <div class="progress-step <?= $current_step >= 2 ? 'active' : '' ?> <?= $current_step > 2 ? 'completed' : '' ?>">
                    <div class="step-number">2</div>
                    <div class="step-title">База данных</div>
                </div>
                <div class="progress-step <?= $current_step >= 3 ? 'active' : '' ?> <?= $current_step > 3 ? 'completed' : '' ?>">
                    <div class="step-number">3</div>
                    <div class="step-title">Импорт SQL</div>
                </div>
                <div class="progress-step <?= $current_step >= 4 ? 'active' : '' ?> <?= $current_step > 4 ? 'completed' : '' ?>">
                    <div class="step-number">4</div>
                    <div class="step-title">Настройки</div>
                </div>
                <div class="progress-step <?= $current_step >= 5 ? 'active' : '' ?> <?= $current_step > 5 ? 'completed' : '' ?>">
                    <div class="step-number">5</div>
                    <div class="step-title">Конфигурация</div>
                </div>
                <div class="progress-step <?= $current_step >= 6 ? 'active' : '' ?>">
                    <div class="step-number">6</div>
                    <div class="step-title">Завершение</div>
                </div>
            </div>

            <!-- Уведомления -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Шаг 1: Проверка требований -->
            <?php if ($current_step == 1): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="1">

                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">Проверка требований системы</h3>

                    <div class="info-box">
                        <div class="info-box-title">
                            <i class="fas fa-info-circle"></i>
                            <span>Системные требования</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Перед началом установки убедитесь, что ваш сервер соответствует всем требованиям.
                        </p>
                    </div>

                    <div class="requirements-list">
                        <?php foreach ($requirements as $req): ?>
                            <div class="requirement-item">
                                <div class="requirement-info">
                                    <div class="requirement-icon <?= $req['status'] ? '' : 'error' ?>">
                                        <i class="fas fa-<?= $req['icon'] ?? 'cog' ?>"></i>
                                    </div>
                                    <div>
                                        <div style="font-weight: 500; color: var(--text-primary);">
                                            <?= htmlspecialchars($req['name']) ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--text-light); margin-top: 2px;">
                                            Требуется: <?= htmlspecialchars($req['required']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="requirement-status <?= $req['status'] ? 'status-success' : 'status-error' ?>">
                                    <?= htmlspecialchars($req['current']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$all_requirements_met): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <p>Некоторые требования системы не выполнены.</p>
                                <p style="margin-top: 8px; font-size: 14px;">
                                    Пожалуйста, исправьте указанные проблемы перед продолжением установки.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 30px; padding: 20px; background: var(--light-bg); border-radius: 12px;">
                        <div class="form-check">
                            <input type="checkbox"
                                   name="agree_terms"
                                   class="form-check-input"
                                   id="agree_terms"
                                   <?= isset($form_data['step1']['agree_terms']) && $form_data['step1']['agree_terms'] ? 'checked' : '' ?>
                                   required>
                            <label class="form-check-label" for="agree_terms">
                                Я принимаю условия лицензионного соглашения
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <div></div>
                        <button type="submit" class="btn btn-primary" <?= !$all_requirements_met ? 'disabled' : '' ?>>
                            <i class="fas fa-arrow-right"></i> Продолжить установку
                        </button>
                    </div>
                </form>

            <!-- Шаг 2: Настройка базы данных -->
            <?php elseif ($current_step == 2): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="2">

                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">Настройка базы данных</h3>

                    <div class="alert alert-warning">
                        <i class="fas fa-database"></i>
                        <div>
                            <p>Пожалуйста, введите данные для подключения к базе данных MySQL.</p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                Убедитесь, что база данных существует и у пользователя есть все необходимые привилегии.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="db_host" class="form-label">Хост базы данных</label>
                        <input type="text"
                               id="db_host"
                               name="db_host"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step2']['db_host'] ?? 'localhost') ?>"
                               placeholder="localhost"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Обычно localhost или 127.0.0.1
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="db_port" class="form-label">Порт базы данных</label>
                        <input type="number"
                               id="db_port"
                               name="db_port"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step2']['db_port'] ?? '3306') ?>"
                               placeholder="3306"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="db_name" class="form-label">Имя базы данных</label>
                        <input type="text"
                               id="db_name"
                               name="db_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step2']['db_name'] ?? '') ?>"
                               placeholder="homevlad_cloud"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            База будет создана автоматически, если не существует
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="db_user" class="form-label">Имя пользователя</label>
                        <input type="text"
                               id="db_user"
                               name="db_user"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step2']['db_user'] ?? '') ?>"
                               placeholder="root"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="db_pass" class="form-label">Пароль</label>
                        <input type="password"
                               id="db_pass"
                               name="db_pass"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step2']['db_pass'] ?? '') ?>"
                               placeholder="">
                    </div>

                    <div style="margin: 20px 0; padding: 15px; background: var(--light-bg); border-radius: 8px;">
                        <div class="form-check">
                            <input type="checkbox"
                                   name="create_db"
                                   class="form-check-input"
                                   id="create_db"
                                   <?= isset($form_data['step2']['create_db']) && $form_data['step2']['create_db'] ? 'checked' : 'checked' ?>>
                            <label class="form-check-label" for="create_db">
                                Создать базу данных, если она не существует только из под root
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="?step=1" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Назад
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-database"></i> Проверить подключение
                        </button>
                    </div>
                </form>

            <!-- Шаг 3: Импорт SQL -->
            <?php elseif ($current_step == 3): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="3">

                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">Импорт структуры базы данных</h3>

                    <?php if (isset($_SESSION['install_db'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <p>Подключение к базе данных успешно установлено!</p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                База данных: <strong><?= htmlspecialchars($_SESSION['install_db']['db_name']) ?></strong><br>
                                Хост: <strong><?= htmlspecialchars($_SESSION['install_db']['db_host']) ?></strong>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['import_success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                    <div>
                <p><?= htmlspecialchars($_SESSION['import_success']) ?></p>
            </div>
        </div>
        <?php unset($_SESSION['import_success']); ?>
        <?php endif; ?>

                    <div class="info-box">
                        <div class="info-box-title">
                            <i class="fas fa-file-code"></i>
                            <span>Импорт SQL файла</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Будет импортирована структура базы данных из файла <code>sql-install.sql</code>.
                            Включая новые таблицы для Telegram ботов и SMTP настроек.
                        </p>
                    </div>

                    <div style="background: var(--light-bg); border-radius: 12px; padding: 20px; margin: 20px 0;">
                        <h4 style="color: var(--text-primary); margin-bottom: 15px;">Создаваемые таблицы:</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">Пользователи (users)</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">Telegram Support Bot</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">Telegram Chat Bot</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">SMTP Settings</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">Виртуальные машины</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; background: var(--success); border-radius: 50%;"></div>
                                <span style="font-size: 14px;">Балансы</span>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <p><strong>Внимание:</strong> Если таблицы уже существуют, они будут пропущены.</p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                Убедитесь, что вы устанавливаете систему в чистую базу данных или сделайте резервную копию существующих данных.
                            </p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="?step=2" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Назад
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-import"></i> Импортировать SQL
                        </button>
                    </div>
                </form>

            <!-- Шаг 4: Настройки системы -->
            <?php elseif ($current_step == 4): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="4">

                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">Настройки системы</h3>

                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <p>Структура базы данных успешно импортирована!</p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                Теперь настройте основные параметры системы.
                            </p>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-box-title">
                            <i class="fas fa-user-shield"></i>
                            <span>Аккаунт администратора</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Создайте первого администратора системы.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="admin_first_name" class="form-label">Имя администратора</label>
                        <input type="text"
                               id="admin_first_name"
                               name="admin_first_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['admin_first_name'] ?? '') ?>"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Формат: Имя
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="admin_last_name" class="form-label">Фамилия администратора</label>
                        <input type="text"
                               id="admin_last_name"
                               name="admin_last_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['admin_last_name'] ?? '') ?>"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Формат: Фамилия
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="admin_email" class="form-label">Email администратора</label>
                        <input type="email"
                               id="admin_email"
                               name="admin_email"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['admin_email'] ?? 'admin@example.com') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="admin_phone" class="form-label">Телефон администратора</label>
                        <input type="tel"
                               id="admin_phone"
                               name="admin_phone"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['admin_phone'] ?? '') ?>"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Формат: +79999999999
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="admin_password" class="form-label">Пароль администратора</label>
                        <input type="password"
                               id="admin_password"
                               name="admin_password"
                               class="form-control"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="admin_password_confirm" class="form-label">Подтверждение пароля</label>
                        <input type="password"
                               id="admin_password_confirm"
                               name="admin_password_confirm"
                               class="form-control"
                               required>
                    </div>

                    <div class="info-box" style="margin-top: 30px;">
                        <div class="info-box-title">
                            <i class="fas fa-globe"></i>
                            <span>Настройки сайта</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Основные настройки вашего сайта.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="site_url" class="form-label">URL сайта</label>
                        <input type="url"
                               id="site_url"
                               name="site_url"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['site_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'])) ?>"
                               required>
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Без слеша в конце (пример: https://ваш-домен.ru)
                        </div>
                    </div>

                    <div class="info-box" style="margin-top: 30px;">
                        <div class="info-box-title">
                            <i class="fab fa-telegram"></i>
                            <span>Telegram Support Bot</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Бот для поддержки пользователей и уведомлений администраторов.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="telegram_support_bot_token" class="form-label">Токен Support Bot</label>
                        <input type="text"
                               id="telegram_support_bot_token"
                               name="telegram_support_bot_token"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['telegram_support_bot_token'] ?? '') ?>"
                               placeholder="123456789:AAFm-xxxxxxxxxxxxxxxxxxx">
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Получите у @BotFather в Telegram
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="telegram_support_bot_name" class="form-label">Имя Support Bot</label>
                        <input type="text"
                               id="telegram_support_bot_name"
                               name="telegram_support_bot_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['telegram_support_bot_name'] ?? 'Support Bot') ?>"
                               placeholder="Support Bot">
                    </div>

                    <div class="info-box" style="margin-top: 30px;">
                        <div class="info-box-title">
                            <i class="fab fa-telegram"></i>
                            <span>Telegram Chat Bot</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Бот для общения с пользователями в чате.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="telegram_chat_bot_token" class="form-label">Токен Chat Bot</label>
                        <input type="text"
                               id="telegram_chat_bot_token"
                               name="telegram_chat_bot_token"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['telegram_chat_bot_token'] ?? '') ?>"
                               placeholder="123456789:AAFm-xxxxxxxxxxxxxxxxxxx">
                        <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                            Получите у @BotFather в Telegram (может быть тем же ботом или другим)
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="telegram_chat_bot_name" class="form-label">Имя Chat Bot</label>
                        <input type="text"
                               id="telegram_chat_bot_name"
                               name="telegram_chat_bot_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['telegram_chat_bot_name'] ?? 'Chat Bot') ?>"
                               placeholder="Chat Bot">
                    </div>

                    <div class="info-box" style="margin-top: 30px;">
                        <div class="info-box-title">
                            <i class="fas fa-envelope"></i>
                            <span>Настройки SMTP</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Для отправки email уведомлений пользователям.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="smtp_host" class="form-label">SMTP сервер</label>
                        <input type="text"
                               id="smtp_host"
                               name="smtp_host"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_host'] ?? 'smtp.mail.ru') ?>"
                               placeholder="smtp.mail.ru">
                    </div>

                    <div class="form-group">
                        <label for="smtp_port" class="form-label">SMTP порт</label>
                        <input type="number"
                               id="smtp_port"
                               name="smtp_port"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_port'] ?? '465') ?>"
                               placeholder="465">
                    </div>

                    <div class="form-group">
                        <label for="smtp_user" class="form-label">SMTP пользователь</label>
                        <input type="email"
                               id="smtp_user"
                               name="smtp_user"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_user'] ?? '') ?>"
                               placeholder="ваш-email@example.com">
                    </div>

                    <div class="form-group">
                        <label for="smtp_pass" class="form-label">SMTP пароль</label>
                        <input type="password"
                               id="smtp_pass"
                               name="smtp_pass"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_pass'] ?? '') ?>"
                               placeholder="">
                    </div>

                    <div class="form-group">
                        <label for="smtp_from" class="form-label">Email отправителя</label>
                        <input type="email"
                               id="smtp_from"
                               name="smtp_from"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_from'] ?? $form_data['step4']['smtp_user'] ?? '') ?>"
                               placeholder="ваш-email@example.com">
                    </div>

                    <div class="form-group">
                        <label for="smtp_from_name" class="form-label">Имя отправителя</label>
                        <input type="text"
                               id="smtp_from_name"
                               name="smtp_from_name"
                               class="form-control"
                               value="<?= htmlspecialchars($form_data['step4']['smtp_from_name'] ?? 'HomeVlad Cloud Support') ?>"
                               placeholder="HomeVlad Cloud Support">
                    </div>

                    <div class="form-group">
                        <label for="smtp_secure" class="form-label">Тип шифрования</label>
                        <select id="smtp_secure" name="smtp_secure" class="form-control">
                            <option value="ssl" <?= ($form_data['step4']['smtp_secure'] ?? 'ssl') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="tls" <?= ($form_data['step4']['smtp_secure'] ?? '') == 'tls' ? 'selected' : '' ?>>TLS</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <a href="?step=3" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Назад
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Сохранить настройки
                        </button>
                    </div>
                </form>

            <!-- Шаг 5: Конфигурация -->
            <?php elseif ($current_step == 5): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="5">

                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">Создание конфигурации</h3>

                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <p>Настройки системы успешно сохранены!</p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                Теперь будут созданы конфигурационные файлы и заполнены таблицы базы данных.
                            </p>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-box-title">
                            <i class="fas fa-file-code"></i>
                            <span>Создаваемые файлы и данные</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px;">
                            Будут созданы следующие файлы и заполнены таблицы:
                        </p>
                    </div>

                    <div style="background: var(--light-bg); border-radius: 12px; padding: 20px; margin: 20px 0;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                                <div style="width: 36px; height: 36px; background: var(--accent-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-file-code" style="color: var(--accent);"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 500; color: var(--text-primary);">config.php</div>
                                    <div style="font-size: 12px; color: var(--text-light);">Основной конфигурационный файл</div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                                <div style="width: 36px; height: 36px; background: var(--accent-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-robot" style="color: var(--accent);"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 500; color: var(--text-primary);">Telegram Support Bot</div>
                                    <div style="font-size: 12px; color: var(--text-light);">Данные бота поддержки</div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                                <div style="width: 36px; height: 36px; background: var(--accent-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-comment" style="color: var(--accent);"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 500; color: var(--text-primary);">Telegram Chat Bot</div>
                                    <div style="font-size: 12px; color: var(--text-light);">Данные чат-бота</div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border-radius: 8px;">
                                <div style="width: 36px; height: 36px; background: var(--accent-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-envelope" style="color: var(--accent);"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 500; color: var(--text-primary);">SMTP Settings</div>
                                    <div style="font-size: 12px; color: var(--text-light);">Настройки почты</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <p><strong>Важно:</strong> После установки удалите папку <code>install/</code></p>
                            <p style="margin-top: 8px; font-size: 14px;">
                                Для безопасности системы рекомендуется удалить папку установки после завершения процесса.
                            </p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="?step=4" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Назад
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-cogs"></i> Создать конфигурацию
                        </button>
                    </div>
                </form>

            <!-- Шаг 6: Завершение -->
            <?php elseif ($current_step == 6): ?>
                <div class="success-screen">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>

                    <h2 class="success-title">Установка завершена!</h2>

                    <p class="success-subtitle">
                        Система биллинга успешно установлена и настроена.
                        Теперь вы можете войти под администратором и начать работу.
                    </p>

                    <div style="background: rgba(14, 165, 233, 0.1); border-radius: 12px; padding: 20px; margin: 30px 0;">
                        <h4 style="color: #0ea5e9; margin-bottom: 10px;">
                            <i class="fas fa-info-circle"></i> Важные действия:
                        </h4>
                        <ol style="text-align: left; padding-left: 20px; color: var(--text-secondary);">
                            <li style="margin-bottom: 8px;">Создайте или зарегистрируйте пользователя системы</li>
                            <li style="margin-bottom: 8px;">Удалите папку /install/ для безопасности</li>
                            <li style="margin-bottom: 8px;">Настройки Telegram ботов и SMTP сохранены в базу данных</li>
                            <li>Добавьте настройки Proxmox в файл config.php</li>
                        </ol>
                    </div>

                    <div class="form-actions" style="justify-content: center; border: none; margin-top: 30px;">
                        <?php if (isset($_SESSION['install_config']['site_url'])): ?>
                        <a href="<?= htmlspecialchars($_SESSION['install_config']['site_url']) ?>" class="btn btn-secondary" style="margin-right: 10px;">
                            <i class="fas fa-home"></i> На главную
                        </a>
                        <a href="<?= htmlspecialchars($_SESSION['install_config']['site_url']) ?>/admin" class="btn btn-primary">
                            <i class="fas fa-cog"></i> Панель администратора
                        </a>
                        <?php else: ?>
                        <a href="../" class="btn btn-secondary" style="margin-right: 10px;">
                            <i class="fas fa-home"></i> На главную
                        </a>
                        <a href="../admin" class="btn btn-primary">
                            <i class="fas fa-cog"></i> Панель администратора
                        </a>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 40px; padding: 20px; background: var(--light-bg); border-radius: 12px;">
                        <h4 style="color: var(--text-primary); margin-bottom: 10px;">
                            <i class="fas fa-key"></i> Данные для входа:
                        </h4>
                        <div style="color: var(--text-secondary);">
                            <p><strong>Email:</strong> <?= htmlspecialchars($_SESSION['install_config']['admin_email'] ?? 'admin@example.com') ?></p>
                            <p><strong>Пароль:</strong> указанный при установке</p>
                            <p><strong>Статус аккаунта:</strong> <span style="color: var(--success); font-weight: 600;">Активный</span> (is_active = 1)</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Валидация паролей
        document.addEventListener('DOMContentLoaded', function() {
            const adminPass = document.getElementById('admin_password');
            const adminPassConfirm = document.getElementById('admin_password_confirm');

            if (adminPass && adminPassConfirm) {
                function validatePasswords() {
                    if (adminPass.value !== adminPassConfirm.value) {
                        adminPassConfirm.style.borderColor = 'var(--danger)';
                        adminPassConfirm.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    } else {
                        adminPassConfirm.style.borderColor = adminPass.value ? 'var(--success)' : 'var(--border-color)';
                        adminPassConfirm.style.boxShadow = adminPass.value ? '0 0 0 3px rgba(16, 185, 129, 0.1)' : 'none';
                    }
                }

                adminPass.addEventListener('input', validatePasswords);
                adminPassConfirm.addEventListener('input', validatePasswords);
            }

            // Автозаполнение email отправителя
            const smtpUser = document.getElementById('smtp_user');
            const smtpFrom = document.getElementById('smtp_from');

            if (smtpUser && smtpFrom) {
                smtpUser.addEventListener('input', function() {
                    if (!smtpFrom.value) {
                        smtpFrom.value = this.value;
                    }
                });
            }

            // Анимация загрузки для кнопок
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обработка...';
                        submitBtn.disabled = true;
                    }
                });
            });
        });
    </script>
</body>
</html>