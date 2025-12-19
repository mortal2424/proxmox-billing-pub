<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Генерация CSRF токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Создаем таблицы если не существуют
$tables = [
    'tariffs' => "CREATE TABLE IF NOT EXISTS tariffs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        cpu INT NOT NULL,
        ram INT NOT NULL,
        disk INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        price_per_hour_cpu DECIMAL(10,4) DEFAULT 0.0000,
        price_per_hour_ram DECIMAL(10,4) DEFAULT 0.0000,
        price_per_hour_disk DECIMAL(10,4) DEFAULT 0.0000,
        traffic VARCHAR(50) DEFAULT NULL,
        backups VARCHAR(50) DEFAULT NULL,
        support VARCHAR(50) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        is_popular BOOLEAN DEFAULT FALSE,
        is_active BOOLEAN DEFAULT TRUE,
        os_type ENUM('linux', 'windows') NULL DEFAULT NULL COMMENT 'Тип операционной системы: linux или windows (если NULL - выбирается вручную)',
        is_custom BOOLEAN DEFAULT FALSE COMMENT 'Является ли тариф кастомным (с почасовой оплатой)',
        vm_type ENUM('qemu', 'lxc') NOT NULL DEFAULT 'qemu' COMMENT 'Тип виртуальной машины (KVM или LXC)',
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    'features' => "CREATE TABLE IF NOT EXISTS features (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        icon VARCHAR(50) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    'promotions' => "CREATE TABLE IF NOT EXISTS promotions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        image VARCHAR(255),
        is_active TINYINT(1) DEFAULT 1,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    'vm_billing' => "CREATE TABLE IF NOT EXISTS vm_billing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vm_id INT NOT NULL,
        user_id INT NOT NULL,
        cpu INT NOT NULL,
        ram INT NOT NULL,
        disk INT NOT NULL,
        price_per_hour_cpu DECIMAL(10,6) NOT NULL,
        price_per_hour_ram DECIMAL(10,6) NOT NULL,
        price_per_hour_disk DECIMAL(10,6) NOT NULL,
        total_per_hour DECIMAL(10,6) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (vm_id) REFERENCES vms(vm_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",

    'resource_prices' => "CREATE TABLE IF NOT EXISTS resource_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        price_per_hour_cpu DECIMAL(10,6) NOT NULL DEFAULT 0.001000,
        price_per_hour_ram DECIMAL(10,6) NOT NULL DEFAULT 0.000010,
        price_per_hour_disk DECIMAL(10,6) NOT NULL DEFAULT 0.000050,
        price_per_hour_lxc_cpu DECIMAL(10,6) NOT NULL DEFAULT 0.000800,
        price_per_hour_lxc_ram DECIMAL(10,6) NOT NULL DEFAULT 0.000008,
        price_per_hour_lxc_disk DECIMAL(10,6) NOT NULL DEFAULT 0.000030,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
];

foreach ($tables as $table => $sql) {
    safeQuery($pdo, $sql);
}

// Создаем таблицы для настроек ботов и SMTP если не существуют
$settingsTables = [
    'telegram_support_bot' => "CREATE TABLE IF NOT EXISTS telegram_support_bot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_token VARCHAR(255) NOT NULL,
        bot_name VARCHAR(100) DEFAULT 'Support Bot',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'telegram_chat_bot' => "CREATE TABLE IF NOT EXISTS telegram_chat_bot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_token VARCHAR(255) NOT NULL,
        bot_name VARCHAR(100) DEFAULT 'Chat Bot',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'smtp_settings' => "CREATE TABLE IF NOT EXISTS smtp_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        host VARCHAR(255) NOT NULL DEFAULT 'smtp.mail.ru',
        port INT NOT NULL DEFAULT 465,
        user VARCHAR(255) NOT NULL,
        pass VARCHAR(255) NOT NULL,
        from_email VARCHAR(255) NOT NULL,
        from_name VARCHAR(255) NOT NULL DEFAULT 'HomeVlad Cloud Support',
        secure ENUM('ssl', 'tls') NOT NULL DEFAULT 'ssl',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

foreach ($settingsTables as $table => $sql) {
    safeQuery($pdo, $sql);
}

// Инициализация базовых цен, если таблица пуста
$pricesExist = safeQuery($pdo, "SELECT COUNT(*) as count FROM resource_prices")->fetch(PDO::FETCH_ASSOC);
if ($pricesExist['count'] == 0) {
    safeQuery($pdo, "INSERT INTO resource_prices
        (price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk,
         price_per_hour_lxc_cpu, price_per_hour_lxc_ram, price_per_hour_lxc_disk)
        VALUES (0.001000, 0.000010, 0.000050, 0.000800, 0.000008, 0.000030)");
}

// Получаем текущие настройки
$supportBotSettings = safeQuery($pdo, "SELECT * FROM telegram_support_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$chatBotSettings = safeQuery($pdo, "SELECT * FROM telegram_chat_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$smtpSettings = safeQuery($pdo, "SELECT * FROM smtp_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Обработка действий - ВАЖНО: Этот код должен выполняться ДО получения данных для отображения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Ошибка безопасности CSRF токена";
        header("Location: settings.php");
        exit;
    }
    
    try {
        // Обработка тарифов
        if (isset($_POST['add_tariff'])) {
            $isCustom = isset($_POST['is_custom']) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO tariffs
                (name, cpu, ram, disk, price, price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk,
                traffic, backups, support, description, is_popular, is_active, os_type, is_custom, vm_type, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['name'],
                $_POST['cpu'],
                $_POST['ram'],
                $_POST['disk'],
                $_POST['price'],
                $_POST['price_per_hour_cpu'] ?? 0,
                $_POST['price_per_hour_ram'] ?? 0,
                $_POST['price_per_hour_disk'] ?? 0,
                $_POST['traffic'] ?? null,
                $_POST['backups'] ?? null,
                $_POST['support'] ?? null,
                $_POST['description'] ?? null,
                isset($_POST['is_popular']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['os_type'] ?? null,
                $isCustom,
                $_POST['vm_type'] ?? 'qemu',
                $_POST['sort_order'] ?? 0
            ]);
            $_SESSION['success'] = "Тариф успешно добавлен";
        }
        elseif (isset($_POST['update_tariff'])) {
            $isCustom = isset($_POST['is_custom']) ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE tariffs SET
                name=?, cpu=?, ram=?, disk=?, price=?, price_per_hour_cpu=?, price_per_hour_ram=?, price_per_hour_disk=?,
                traffic=?, backups=?, support=?, description=?, is_popular=?, is_active=?, os_type=?, is_custom=?,
                vm_type=?, sort_order=?
                WHERE id=?");
            $stmt->execute([
                $_POST['name'],
                $_POST['cpu'],
                $_POST['ram'],
                $_POST['disk'],
                $_POST['price'],
                $_POST['price_per_hour_cpu'] ?? 0,
                $_POST['price_per_hour_ram'] ?? 0,
                $_POST['price_per_hour_disk'] ?? 0,
                $_POST['traffic'] ?? null,
                $_POST['backups'] ?? null,
                $_POST['support'] ?? null,
                $_POST['description'] ?? null,
                isset($_POST['is_popular']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['os_type'] ?? null,
                $isCustom,
                $_POST['vm_type'] ?? 'qemu',
                $_POST['sort_order'] ?? 0,
                $_POST['id']
            ]);
            $_SESSION['success'] = "Тариф успешно обновлен";
        }
        elseif (isset($_POST['delete_tariff'])) {
            $stmt = $pdo->prepare("DELETE FROM tariffs WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $_SESSION['success'] = "Тариф успешно удален";
        }

        // Обработка возможностей
        elseif (isset($_POST['add_feature'])) {
            $stmt = $pdo->prepare("INSERT INTO features (title, description, icon, is_active, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['icon'],
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['sort_order'] ?? 0
            ]);
            $_SESSION['success'] = "Возможность успешно добавлена";
        }
        elseif (isset($_POST['update_feature'])) {
            $stmt = $pdo->prepare("UPDATE features SET title=?, description=?, icon=?, is_active=?, sort_order=? WHERE id=?");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['icon'],
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['sort_order'] ?? 0,
                $_POST['id']
            ]);
            $_SESSION['success'] = "Возможность успешно обновлена";
        }
        elseif (isset($_POST['delete_feature'])) {
            $stmt = $pdo->prepare("DELETE FROM features WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $_SESSION['success'] = "Возможность успешно удалена";
        }

        // Обработка акций
        elseif (isset($_POST['add_promotion'])) {
            $stmt = $pdo->prepare("INSERT INTO promotions
                (title, description, image, is_active, start_date, end_date, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['image'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['sort_order'] ?? 0
            ]);
            $_SESSION['success'] = "Акция успешно добавлена";
        }
        elseif (isset($_POST['update_promotion'])) {
            $stmt = $pdo->prepare("UPDATE promotions SET
                title=?, description=?, image=?, is_active=?, start_date=?, end_date=?, sort_order=?
                WHERE id=?");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['image'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['sort_order'] ?? 0,
                $_POST['id']
            ]);
            $_SESSION['success'] = "Акция успешно обновлена";
        }
        elseif (isset($_POST['delete_promotion'])) {
            $stmt = $pdo->prepare("DELETE FROM promotions WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $_SESSION['success'] = "Акция успешно удалена";
        }

        // Обработка цен на кастомные ресурсы
        elseif (isset($_POST['update_custom_prices'])) {
            $cpuPrice = (float)$_POST['price_per_hour_cpu'];
            $ramPrice = (float)$_POST['price_per_hour_ram'];
            $diskPrice = (float)$_POST['price_per_hour_disk'];

            $stmt = $pdo->prepare("SELECT cpu, ram, disk FROM vm_billing WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $resource = $stmt->fetch();

            if ($resource) {
                $totalPerHour = ($resource['cpu'] * $cpuPrice) + ($resource['ram'] * $ramPrice) + ($resource['disk'] * $diskPrice);

                $stmt = $pdo->prepare("UPDATE vm_billing SET
                    price_per_hour_cpu = ?,
                    price_per_hour_ram = ?,
                    price_per_hour_disk = ?,
                    total_per_hour = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([$cpuPrice, $ramPrice, $diskPrice, $totalPerHour, $_POST['id']]);
                $_SESSION['success'] = "Цены на кастомные ресурсы успешно обновлены";
            }
        }

        // Обработка установки базовых цен
        elseif (isset($_POST['set_default_prices'])) {
            $stmt = $pdo->prepare("UPDATE resource_prices SET
                price_per_hour_cpu = ?,
                price_per_hour_ram = ?,
                price_per_hour_disk = ?,
                price_per_hour_lxc_cpu = ?,
                price_per_hour_lxc_ram = ?,
                price_per_hour_lxc_disk = ?,
                updated_at = NOW()
                WHERE id = 1");
            $stmt->execute([
                $_POST['default_price_per_hour_cpu'],
                $_POST['default_price_per_hour_ram'],
                $_POST['default_price_per_hour_disk'],
                $_POST['default_price_per_hour_lxc_cpu'],
                $_POST['default_price_per_hour_lxc_ram'],
                $_POST['default_price_per_hour_lxc_disk']
            ]);
            $_SESSION['success'] = "Базовые цены на ресурсы сохранены";
        }

        // Обработка настройки Telegram бота поддержки
        elseif (isset($_POST['update_support_bot'])) {
            $token = trim($_POST['bot_token']);
            $name = trim($_POST['bot_name']);

            if (empty($supportBotSettings)) {
                // Создаем новую запись
                $stmt = $pdo->prepare("INSERT INTO telegram_support_bot (bot_token, bot_name) VALUES (?, ?)");
                $stmt->execute([$token, $name]);
                $_SESSION['success'] = "Настройки Telegram Support Bot сохранены";
            } else {
                // Обновляем существующую запись
                $stmt = $pdo->prepare("UPDATE telegram_support_bot SET bot_token = ?, bot_name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$token, $name, $supportBotSettings['id']]);
                $_SESSION['success'] = "Настройки Telegram Support Bot обновлены";
            }
        }

        // Обработка настройки Telegram чат-бота
        elseif (isset($_POST['update_chat_bot'])) {
            $token = trim($_POST['bot_token']);
            $name = trim($_POST['bot_name']);

            if (empty($chatBotSettings)) {
                // Создаем новую запись
                $stmt = $pdo->prepare("INSERT INTO telegram_chat_bot (bot_token, bot_name) VALUES (?, ?)");
                $stmt->execute([$token, $name]);
                $_SESSION['success'] = "Настройки Telegram Chat Bot сохранены";
            } else {
                // Обновляем существующую запись
                $stmt = $pdo->prepare("UPDATE telegram_chat_bot SET bot_token = ?, bot_name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$token, $name, $chatBotSettings['id']]);
                $_SESSION['success'] = "Настройки Telegram Chat Bot обновлены";
            }
        }

        // Обработка настройки SMTP
        elseif (isset($_POST['update_smtp_settings'])) {
            $host = trim($_POST['host']);
            $port = (int)$_POST['port'];
            $user = trim($_POST['user']);
            $pass = $_POST['pass'];
            $from_email = trim($_POST['from_email']);
            $from_name = trim($_POST['from_name']);
            $secure = $_POST['secure'];

            if (empty($smtpSettings)) {
                // Создаем новую запись
                $stmt = $pdo->prepare("
                    INSERT INTO smtp_settings (host, port, user, pass, from_email, from_name, secure)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$host, $port, $user, $pass, $from_email, $from_name, $secure]);
                $_SESSION['success'] = "Настройки SMTP сохранены";
            } else {
                // Обновляем существующую запись
                $stmt = $pdo->prepare("
                    UPDATE smtp_settings
                    SET host = ?, port = ?, user = ?, pass = ?, from_email = ?, from_name = ?, secure = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$host, $port, $user, $pass, $from_email, $from_name, $secure, $smtpSettings['id']]);
                $_SESSION['success'] = "Настройки SMTP обновлены";
            }
        }

        // Обновляем настройки после сохранения
        $supportBotSettings = safeQuery($pdo, "SELECT * FROM telegram_support_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $chatBotSettings = safeQuery($pdo, "SELECT * FROM telegram_chat_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $smtpSettings = safeQuery($pdo, "SELECT * FROM smtp_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        // Редирект на текущую страницу с сохранением активной вкладки
        $tab = $_POST['active_tab'] ?? 'prices';
        header("Location: settings.php?tab=$tab");
        exit;
    } catch (PDOException $e) {
        error_log("Ошибка базы данных в settings.php: " . $e->getMessage());
        $_SESSION['error'] = "Ошибка базы данных: " . $e->getMessage();
        $tab = $_POST['active_tab'] ?? 'prices';
        header("Location: settings.php?tab=$tab");
        exit;
    }
}

// Получаем активную вкладку из GET параметра или по умолчанию
$activeTab = $_GET['tab'] ?? 'prices';

// Получаем данные
$tariffs = safeQuery($pdo, "SELECT * FROM tariffs ORDER BY sort_order ASC, is_active DESC, price ASC")->fetchAll(PDO::FETCH_ASSOC);
$features = safeQuery($pdo, "SELECT * FROM features ORDER BY sort_order ASC, is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$promotions = safeQuery($pdo, "SELECT * FROM promotions ORDER BY sort_order ASC, is_active DESC, start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$defaultPrices = safeQuery($pdo, "SELECT * FROM resource_prices LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Исправленный запрос для кастомных ресурсов
$customResources = safeQuery($pdo, "
    SELECT
        vb.*,
        IFNULL(v.hostname, CONCAT('VM #', vb.vm_id)) as vm_name,
        u.full_name as user_fullname,
        u.email as user_email
    FROM vm_billing vb
    LEFT JOIN vms v ON vb.vm_id = v.vm_id
    JOIN users u ON vb.user_id = u.id
    ORDER BY vb.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$title = "Настройки сайта | HomeVlad Cloud";
require 'admin_header.php';
?>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<style>
/* Стили для страницы настроек */
:root {
    --settings-bg: #f8fafc;
    --settings-card-bg: #ffffff;
    --settings-border: #e2e8f0;
    --settings-text: #1e293b;
    --settings-text-secondary: #64748b;
    --settings-text-muted: #94a3b8;
    --settings-hover: #f1f5f9;
    --settings-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --settings-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --settings-accent: #00bcd4;
    --settings-accent-light: rgba(0, 188, 212, 0.1);
    --settings-success: #10b981;
    --settings-warning: #f59e0b;
    --settings-danger: #ef4444;
    --settings-info: #3b82f6;
    --settings-purple: #8b5cf6;
    --settings-telegram: #0088cc;
    --settings-email: #ea4335;
}

[data-theme="dark"] {
    --settings-bg: #0f172a;
    --settings-card-bg: #1e293b;
    --settings-border: #334155;
    --settings-text: #ffffff;
    --settings-text-secondary: #cbd5e1;
    --settings-text-muted: #94a3b8;
    --settings-hover: #2d3748;
    --settings-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --settings-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
    --settings-telegram: #24a1de;
    --settings-email: #fbbc05;
}

.settings-wrapper {
    padding: 20px;
    background: var(--settings-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .settings-wrapper {
    margin-left: 70px;
}

.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--settings-card-bg);
    border-radius: 12px;
    border: 1px solid var(--settings-border);
    box-shadow: var(--settings-shadow);
}

.header-left h1 {
    color: var(--settings-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--settings-accent);
}

.header-left p {
    color: var(--settings-text-secondary);
    font-size: 14px;
    margin: 0;
}

.settings-tabs {
    display: flex;
    gap: 8px;
    background: var(--settings-card-bg);
    border-radius: 12px;
    padding: 8px;
    margin-bottom: 30px;
    box-shadow: var(--settings-shadow);
    flex-wrap: wrap;
}

.settings-tab {
    padding: 12px 24px;
    border: none;
    background: none;
    color: var(--settings-text-secondary);
    font-size: 14px;
    font-weight: 500;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    flex: 1;
    text-align: center;
    min-width: 120px;
}

.settings-tab:hover {
    background: var(--settings-hover);
    color: var(--settings-text);
}

.settings-tab.active {
    background: linear-gradient(135deg, var(--settings-accent), #0097a7);
    color: white;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.settings-section {
    background: var(--settings-card-bg);
    border: 1px solid var(--settings-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--settings-shadow);
    transition: all 0.3s ease;
    margin-bottom: 20px;
}

.settings-section:hover {
    box-shadow: var(--settings-shadow-hover);
}

.section-header {
    padding: 20px;
    border-bottom: 1px solid var(--settings-border);
    background: linear-gradient(135deg, var(--settings-accent), #0097a7);
    color: white;
}

.section-header h2 {
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-body {
    padding: 25px;
}

/* Стили для форм */
.settings-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.form-group {
    position: relative;
}

.form-label {
    display: block;
    color: var(--settings-text);
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}

.form-label.required::after {
    content: ' *';
    color: var(--settings-danger);
}

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--settings-border);
    border-radius: 8px;
    background: var(--settings-bg);
    color: var(--settings-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-input:focus {
    outline: none;
    border-color: var(--settings-accent);
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
}

.form-input[readonly] {
    background: var(--settings-hover);
    opacity: 0.7;
    cursor: not-allowed;
}

.form-textarea {
    min-height: 80px;
    resize: vertical;
}

.form-hint {
    color: var(--settings-text-secondary);
    font-size: 12px;
    margin-top: 4px;
    display: block;
}

.form-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    padding-right: 40px;
}

/* Чекбоксы и переключатели */
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.checkbox-input {
    width: 18px;
    height: 18px;
    border: 2px solid var(--settings-border);
    border-radius: 4px;
    background: var(--settings-card-bg);
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
}

.checkbox-input:checked {
    background: var(--settings-accent);
    border-color: var(--settings-accent);
}

.checkbox-input:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.checkbox-label {
    color: var(--settings-text);
    font-size: 14px;
    user-select: none;
}

/* Кнопки */
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--settings-border);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--settings-accent), #0097a7);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0, 188, 212, 0.2);
}

.btn-secondary {
    background: var(--settings-card-bg);
    color: var(--settings-text);
    border: 1px solid var(--settings-border);
}

.btn-secondary:hover {
    background: var(--settings-hover);
    transform: translateY(-2px);
}

.btn-success {
    background: linear-gradient(135deg, var(--settings-success), #0ca678);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--settings-danger), #dc2626);
    color: white;
}

.btn-telegram {
    background: linear-gradient(135deg, var(--settings-telegram), #006699);
    color: white;
}

.btn-email {
    background: linear-gradient(135deg, var(--settings-email), #c5221f);
    color: white;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
}

.btn-icon {
    font-size: 16px;
}

/* Таблицы */
.table-container {
    overflow-x: auto;
    border-radius: 8px;
    border: 1px solid var(--settings-border);
    margin-top: 20px;
}

.settings-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.settings-table thead {
    background: var(--settings-hover);
}

.settings-table th {
    color: var(--settings-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--settings-border);
}

.settings-table tbody tr {
    border-bottom: 1px solid var(--settings-border);
    transition: all 0.3s ease;
}

.settings-table tbody tr:hover {
    background: var(--settings-hover);
}

.settings-table td {
    color: var(--settings-text);
    font-size: 14px;
    padding: 12px;
    vertical-align: middle;
}

/* Статусы */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: rgba(16, 185, 129, 0.2);
    color: var(--settings-success);
}

.status-inactive {
    background: rgba(148, 163, 184, 0.2);
    color: var(--settings-text-muted);
}

.status-warning {
    background: rgba(245, 158, 11, 0.2);
    color: var(--settings-warning);
}

.status-danger {
    background: rgba(239, 68, 68, 0.2);
    color: var(--settings-danger);
}

/* Цены */
.price-cell {
    font-weight: 600;
    color: var(--settings-success);
}

.price-tooltip {
    position: relative;
    cursor: help;
}

.price-summary {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.tooltip-content {
    display: none;
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: var(--settings-card-bg);
    border: 1px solid var(--settings-border);
    border-radius: 6px;
    padding: 12px;
    font-size: 12px;
    color: var(--settings-text);
    min-width: 200px;
    box-shadow: var(--settings-shadow);
    z-index: 1000;
    white-space: nowrap;
}

.price-tooltip:hover .tooltip-content {
    display: block;
}

/* Действия */
.actions-cell {
    white-space: nowrap;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    margin: 0 4px;
}

.action-btn-edit {
    background: rgba(59, 130, 246, 0.1);
    color: var(--settings-info);
}

.action-btn-edit:hover {
    background: rgba(59, 130, 246, 0.2);
    transform: translateY(-2px);
}

.action-btn-delete {
    background: rgba(239, 68, 68, 0.1);
    color: var(--settings-danger);
}

.action-btn-delete:hover {
    background: rgba(239, 68, 68, 0.2);
    transform: translateY(-2px);
}

.action-btn-sort {
    background: rgba(148, 163, 184, 0.1);
    color: var(--settings-text-secondary);
    cursor: move;
}

.action-btn-sort:hover {
    background: rgba(148, 163, 184, 0.2);
}

/* Иконки ресурсов */
.resource-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 8px;
    font-size: 16px;
}

.resource-cpu { background: rgba(59, 130, 246, 0.1); color: var(--settings-info); }
.resource-ram { background: rgba(16, 185, 129, 0.1); color: var(--settings-success); }
.resource-disk { background: rgba(139, 92, 246, 0.1); color: var(--settings-purple); }

/* Оповещения */
.settings-alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid;
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: var(--settings-success);
    color: var(--settings-success);
}

.alert-danger {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: var(--settings-danger);
    color: var(--settings-danger);
}

.alert-warning {
    background: rgba(245, 158, 11, 0.1);
    border-left-color: var(--settings-warning);
    color: var(--settings-warning);
}

.alert-info {
    background: rgba(0, 188, 212, 0.1);
    border-left-color: var(--settings-accent);
    color: var(--settings-accent);
}

.alert i {
    margin-right: 8px;
}

/* Пустое состояние */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--settings-text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 18px;
    margin-bottom: 8px;
    color: var(--settings-text);
}

.empty-state p {
    font-size: 14px;
    margin: 0;
}

/* Адаптивность */
@media (max-width: 1200px) {
    .settings-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .settings-tabs {
        flex-wrap: wrap;
    }

    .settings-tab {
        flex: 1 0 calc(50% - 8px);
    }
}

@media (max-width: 768px) {
    .settings-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .settings-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .settings-tab {
        flex: 1 0 100%;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
    }
}

/* Анимации */
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

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.fade-in {
    animation: fadeIn 0.5s ease;
}

/* Кастомные ресурсы */
.custom-resource-card {
    background: var(--settings-hover);
    border: 1px solid var(--settings-border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.3s ease;
}

.custom-resource-card:hover {
    background: var(--settings-accent-light);
    border-color: var(--settings-accent);
    transform: translateY(-2px);
}

.resource-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.resource-title {
    color: var(--settings-text);
    font-weight: 600;
    font-size: 16px;
}

.resource-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--settings-text-secondary);
}

.resource-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.resource-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.resource-label {
    font-size: 12px;
    color: var(--settings-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.resource-value {
    font-size: 14px;
    color: var(--settings-text);
    font-weight: 600;
}

.resource-value.success {
    color: var(--settings-success);
}

.resource-value.warning {
    color: var(--settings-warning);
}

/* Инфо блоки */
.info-block {
    background: rgba(0, 188, 212, 0.05);
    border: 1px solid rgba(0, 188, 212, 0.2);
    border-radius: 8px;
    padding: 16px;
    margin-top: 20px;
}

.info-block h4 {
    color: var(--settings-accent);
    font-size: 14px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-block ul {
    margin: 0;
    padding-left: 20px;
}

.info-block li {
    color: var(--settings-text-secondary);
    font-size: 13px;
    margin-bottom: 6px;
    line-height: 1.4;
}

/* Стили для настроек интеграций */
.integration-card {
    background: var(--settings-card-bg);
    border: 1px solid var(--settings-border);
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.integration-card:hover {
    box-shadow: var(--settings-shadow-hover);
}

.integration-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--settings-border);
}

.integration-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.telegram-icon {
    background: linear-gradient(135deg, var(--settings-telegram), #006699);
}

.email-icon {
    background: linear-gradient(135deg, var(--settings-email), #c5221f);
}

.integration-info h3 {
    color: var(--settings-text);
    font-size: 18px;
    margin: 0 0 4px 0;
}

.integration-info p {
    color: var(--settings-text-secondary);
    font-size: 14px;
    margin: 0;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.status-connected {
    background: rgba(16, 185, 129, 0.1);
    color: var(--settings-success);
}

.status-disconnected {
    background: rgba(148, 163, 184, 0.1);
    color: var(--settings-text-secondary);
}

.test-btn {
    padding: 8px 16px;
    border-radius: 6px;
    border: 1px solid var(--settings-border);
    background: var(--settings-card-bg);
    color: var(--settings-text);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.test-btn:hover {
    background: var(--settings-hover);
    transform: translateY(-1px);
}

/* Модальные окна */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--settings-card-bg);
    border-radius: 12px;
    box-shadow: var(--settings-shadow-hover);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
}

.modal-header {
    padding: 20px 25px;
    border-bottom: 1px solid var(--settings-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    color: var(--settings-text);
    font-size: 18px;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    color: var(--settings-text-secondary);
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.modal-close:hover {
    background: var(--settings-hover);
    color: var(--settings-text);
}

.modal-body {
    padding: 25px;
    max-height: 60vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 20px 25px;
    border-top: 1px solid var(--settings-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Бейдж для секретов */
.secret-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: rgba(139, 92, 246, 0.1);
    color: var(--settings-purple);
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
}

.secret-value {
    font-family: 'Courier New', monospace;
    background: var(--settings-hover);
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid var(--settings-border);
    font-size: 13px;
    color: var(--settings-text);
    word-break: break-all;
}

/* Оповещение об успешном тесте */
.test-result {
    padding: 12px 16px;
    border-radius: 8px;
    margin-top: 12px;
    display: none;
}

.test-success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--settings-success);
}

.test-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--settings-danger);
}
</style>

<!-- Основной контент -->
<div class="settings-wrapper">
    <!-- Шапка -->
    <div class="settings-header">
        <div class="header-left">
            <h1><i class="fas fa-cog"></i> Настройки сайта</h1>
            <p>Управление тарифами, возможностями, акциями и системными параметрами</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="/admin/" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад в дашборд
            </a>
        </div>
    </div>

    <!-- Оповещения -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="settings-alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="settings-alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Табы -->
    <div class="settings-tabs">
        <button class="settings-tab <?= $activeTab === 'prices' ? 'active' : '' ?>" data-tab="prices">Цены</button>
        <button class="settings-tab <?= $activeTab === 'tariffs' ? 'active' : '' ?>" data-tab="tariffs">Тарифы</button>
        <button class="settings-tab <?= $activeTab === 'features' ? 'active' : '' ?>" data-tab="features">Возможности</button>
        <button class="settings-tab <?= $activeTab === 'promotions' ? 'active' : '' ?>" data-tab="promotions">Акции</button>
        <button class="settings-tab <?= $activeTab === 'resources' ? 'active' : '' ?>" data-tab="resources">Ресурсы</button>
        <button class="settings-tab <?= $activeTab === 'integrations' ? 'active' : '' ?>" data-tab="integrations">Интеграции</button>
    </div>

    <!-- Контент табов -->
    <div class="tab-content <?= $activeTab === 'prices' ? 'active' : '' ?>" id="prices-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-money-bill-wave"></i> Базовые цены на ресурсы</h2>
            </div>
            <div class="section-body">
                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="active_tab" value="prices">
                    
                    <div class="form-row">
                        <!-- Цены для QEMU -->
                        <div class="form-group">
                            <label class="form-label required">1 vCPU в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_cpu"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_cpu'] ?? '0.001000' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 vCPU в час для KVM виртуальных машин</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">1 MB RAM в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_ram"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_ram'] ?? '0.000010' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 MB оперативной памяти в час</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">1 ГБ Диска в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_disk"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_disk'] ?? '0.000050' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 ГБ дискового пространства в час</span>
                        </div>

                        <!-- Цены для LXC -->
                        <div class="form-group">
                            <label class="form-label required">1 vCPU в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_cpu"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_cpu'] ?? '0.000800' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 vCPU в час для LXC контейнеров</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">1 MB RAM в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_ram"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_ram'] ?? '0.000008' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 MB RAM в час для LXC контейнеров</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">1 ГБ Диска в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_disk"
                                   min="0.000001" step="0.000001"
                                   class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_disk'] ?? '0.000030' ?>"
                                   required>
                            <span class="form-hint">Цена за 1 ГБ диска в час для LXC контейнеров</span>
                        </div>
                    </div>

                    <div class="info-block">
                        <h4><i class="fas fa-lightbulb"></i> Информация о ценах</h4>
                        <ul>
                            <li>Цены используются для расчета стоимости кастомных конфигураций</li>
                            <li>QEMU (KVM) - полноценные виртуальные машины с полной виртуализацией</li>
                            <li>LXC - легковесные контейнеры, обычно дешевле на 20-40%</li>
                            <li>Все цены указаны в рублях за час использования</li>
                        </ul>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="set_default_prices" class="btn btn-primary">
                            <i class="fas fa-save btn-icon"></i> Сохранить базовые цены
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Таб: Тарифы -->
    <div class="tab-content <?= $activeTab === 'tariffs' ? 'active' : '' ?>" id="tariffs-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-tags"></i> Управление тарифами</h2>
            </div>
            <div class="section-body">
                <!-- Форма добавления тарифа -->
                <div class="info-block">
                    <h4><i class="fas fa-plus-circle"></i> Добавить новый тариф</h4>
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="tariffs">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Название тарифа</label>
                                <input type="text" name="name" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Тип ВМ</label>
                                <select name="vm_type" class="form-input form-select" required>
                                    <option value="qemu">QEMU (KVM)</option>
                                    <option value="lxc">LXC (Контейнер)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Ядра CPU</label>
                                <input type="number" name="cpu" min="1" max="8" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">RAM (MB)</label>
                                <input type="number" name="ram" min="512" max="32768" step="512" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Диск (GB)</label>
                                <input type="number" name="disk" min="10" max="300" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Цена (руб./месяц)</label>
                                <input type="number" name="price" min="0.00" step="0.01" class="form-input">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Тип ОС</label>
                                <select name="os_type" class="form-input form-select">
                                    <option value="">Выбрать вручную</option>
                                    <option value="linux">Linux</option>
                                    <option value="windows">Windows</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Трафик</label>
                                <input type="text" name="traffic" class="form-input" placeholder="Например: 1 TB">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Бэкапы</label>
                                <input type="text" name="backups" class="form-input" placeholder="Например: Еженедельные">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Поддержка</label>
                                <input type="text" name="support" class="form-input" placeholder="Например: 24/7">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Описание</label>
                                <textarea name="description" class="form-input form-textarea" rows="2"></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Порядок сортировки</label>
                                <input type="number" name="sort_order" min="0" max="100" class="form-input" value="0">
                            </div>

                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="is_custom" id="isCustomCheckbox" class="checkbox-input" onchange="toggleCustomFields()">
                                    <label for="isCustomCheckbox" class="checkbox-label">Кастомный тариф (почасовая оплата)</label>
                                </div>

                                <div class="checkbox-item">
                                    <input type="checkbox" name="is_popular" id="isPopularCheckbox" class="checkbox-input">
                                    <label for="isPopularCheckbox" class="checkbox-label">Популярный тариф</label>
                                </div>

                                <div class="checkbox-item">
                                    <input type="checkbox" name="is_active" id="isActiveCheckbox" class="checkbox-input" checked>
                                    <label for="isActiveCheckbox" class="checkbox-label">Активный тариф</label>
                                </div>
                            </div>

                            <!-- Кастомные цены (скрыты по умолчанию) -->
                            <div id="customPriceFields" style="display: none; grid-column: 1 / -1;">
                                <h4 style="color: var(--settings-accent); margin: 20px 0 10px 0;">Почасовые цены</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Цена за 1 vCPU в час</label>
                                        <input type="number" name="price_per_hour_cpu" min="0.0000" step="0.0001" class="form-input" value="0.0000">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Цена за 1 МB RAM в час</label>
                                        <input type="number" name="price_per_hour_ram" min="0.000000" step="0.000001" class="form-input" value="0.000000">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Цена за 1 ГБ Диска в час</label>
                                        <input type="number" name="price_per_hour_disk" min="0.000000" step="0.000001" class="form-input" value="0.000000">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_tariff" class="btn btn-primary">
                                <i class="fas fa-plus btn-icon"></i> Добавить тариф
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Список тарифов -->
                <h3 style="color: var(--settings-text); margin: 30px 0 15px 0;">Список тарифов</h3>

                <?php if (!empty($tariffs)): ?>
                    <div class="table-container">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">Порядок</th>
                                    <th>Название</th>
                                    <th>Тип</th>
                                    <th>Ресурсы</th>
                                    <th>Цена</th>
                                    <th>Статус</th>
                                    <th style="width: 120px;">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="tariffsTableBody">
                                <?php foreach ($tariffs as $tariff): ?>
                                <tr data-id="<?= $tariff['id'] ?>" data-table="tariffs">
                                    <td>
                                        <span class="sort-order"><?= $tariff['sort_order'] ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($tariff['name']) ?></strong>
                                        <?php if ($tariff['is_custom']): ?>
                                            <br><small style="color: var(--settings-warning);">Почасовая оплата</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $tariff['vm_type'] == 'qemu' ? 'status-active' : 'status-info' ?>">
                                            <?= $tariff['vm_type'] == 'qemu' ? 'KVM' : 'LXC' ?>
                                        </span>
                                        <?php if ($tariff['os_type']): ?>
                                            <br><small><?= $tariff['os_type'] == 'linux' ? 'Linux' : 'Windows' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 12px; align-items: center;">
                                            <span class="resource-icon resource-cpu">
                                                <i class="fas fa-microchip"></i>
                                            </span>
                                            <?= $tariff['cpu'] ?> ядер

                                            <span class="resource-icon resource-ram">
                                                <i class="fas fa-memory"></i>
                                            </span>
                                            <?= $tariff['ram'] ?> MB

                                            <span class="resource-icon resource-disk">
                                                <i class="fas fa-hdd"></i>
                                            </span>
                                            <?= $tariff['disk'] ?> GB
                                        </div>
                                    </td>
                                    <td class="price-cell price-tooltip">
                                        <?= number_format($tariff['price'], 2) ?> ₽/мес
                                        <?php if ($tariff['is_custom']): ?>
                                            <div class="tooltip-content">
                                                CPU: <?= number_format($tariff['price_per_hour_cpu'], 4) ?> руб./час<br>
                                                RAM: <?= number_format($tariff['price_per_hour_ram'], 6) ?> руб./час<br>
                                                Disk: <?= number_format($tariff['price_per_hour_disk'], 6) ?> руб./час
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $tariff['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $tariff['is_active'] ? 'Активен' : 'Неактивен' ?>
                                            <?php if ($tariff['is_popular']): ?>
                                                <i class="fas fa-star" style="margin-left: 4px;"></i>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell">
                                        <button type="button" class="action-btn action-btn-edit"
                                                onclick="openEditModal('tariff', <?= htmlspecialchars(json_encode($tariff)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="active_tab" value="tariffs">
                                            <input type="hidden" name="id" value="<?= $tariff['id'] ?>">
                                            <button type="submit" name="delete_tariff" class="action-btn action-btn-delete"
                                                    onclick="return confirm('Удалить этот тариф?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tags"></i>
                        <h3>Нет созданных тарифов</h3>
                        <p>Добавьте первый тариф, чтобы он отображался в панели пользователя</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Таб: Возможности -->
    <div class="tab-content <?= $activeTab === 'features' ? 'active' : '' ?>" id="features-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-star"></i> Управление возможностями</h2>
            </div>
            <div class="section-body">
                <!-- Форма добавления возможности -->
                <div class="info-block">
                    <h4><i class="fas fa-plus-circle"></i> Добавить новую возможность</h4>
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="features">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Заголовок</label>
                                <input type="text" name="title" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Описание</label>
                                <textarea name="description" class="form-input form-textarea" rows="2" required></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Иконка</label>
                                <input type="text" name="icon" class="form-input" placeholder="fas fa-rocket" required>
                                <span class="form-hint">Название иконки из Font Awesome (например: fas fa-rocket, fas fa-shield-alt)</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Порядок сортировки</label>
                                <input type="number" name="sort_order" min="0" max="100" class="form-input" value="0">
                            </div>

                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="is_active" id="featureActive" class="checkbox-input" checked>
                                    <label for="featureActive" class="checkbox-label">Активная возможность</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_feature" class="btn btn-primary">
                                <i class="fas fa-plus btn-icon"></i> Добавить возможность
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Список возможностей -->
                <h3 style="color: var(--settings-text); margin: 30px 0 15px 0;">Список возможностей</h3>

                <?php if (!empty($features)): ?>
                    <div class="table-container">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Иконка</th>
                                    <th>Заголовок</th>
                                    <th>Описание</th>
                                    <th>Статус</th>
                                    <th style="width: 120px;">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="featuresTableBody">
                                <?php foreach ($features as $feature): ?>
                                <tr data-id="<?= $feature['id'] ?>" data-table="features">
                                    <td><?= $feature['id'] ?></td>
                                    <td>
                                        <i class="<?= htmlspecialchars($feature['icon']) ?> fa-lg"
                                           style="color: var(--settings-accent);"></i>
                                    </td>
                                    <td><strong><?= htmlspecialchars($feature['title']) ?></strong></td>
                                    <td><?= htmlspecialchars($feature['description']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $feature['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $feature['is_active'] ? 'Активна' : 'Неактивна' ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell">
                                        <button type="button" class="action-btn action-btn-edit"
                                                onclick="openEditModal('feature', <?= htmlspecialchars(json_encode($feature)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="active_tab" value="features">
                                            <input type="hidden" name="id" value="<?= $feature['id'] ?>">
                                            <button type="submit" name="delete_feature" class="action-btn action-btn-delete"
                                                    onclick="return confirm('Удалить эту возможность?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <h3>Нет созданных возможностей</h3>
                        <p>Добавьте возможности, которые будут отображаться на главной странице</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Таб: Акции -->
    <div class="tab-content <?= $activeTab === 'promotions' ? 'active' : '' ?>" id="promotions-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-percentage"></i> Управление акциями</h2>
            </div>
            <div class="section-body">
                <!-- Форма добавления акции -->
                <div class="info-block">
                    <h4><i class="fas fa-plus-circle"></i> Добавить новую акцию</h4>
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="promotions">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Заголовок</label>
                                <input type="text" name="title" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Описание</label>
                                <textarea name="description" class="form-input form-textarea" rows="2" required></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Изображение (URL)</label>
                                <input type="text" name="image" class="form-input" placeholder="https://example.com/image.jpg">
                                <span class="form-hint">Оставьте пустым для использования изображения по умолчанию</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Дата начала</label>
                                <input type="date" name="start_date" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Дата окончания</label>
                                <input type="date" name="end_date" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Порядок сортировки</label>
                                <input type="number" name="sort_order" min="0" max="100" class="form-input" value="0">
                            </div>

                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="is_active" id="promotionActive" class="checkbox-input" checked>
                                    <label for="promotionActive" class="checkbox-label">Активная акция</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_promotion" class="btn btn-primary">
                                <i class="fas fa-plus btn-icon"></i> Добавить акцию
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Список акций -->
                <h3 style="color: var(--settings-text); margin: 30px 0 15px 0;">Список акций</h3>

                <?php if (!empty($promotions)): ?>
                    <div class="table-container">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Заголовок</th>
                                    <th>Период действия</th>
                                    <th>Статус</th>
                                    <th style="width: 120px;">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="promotionsTableBody">
                                <?php foreach ($promotions as $promo):
                                    $isActive = $promo['is_active'] == 1;
                                    $now = new DateTime();
                                    $startDate = new DateTime($promo['start_date']);
                                    $endDate = new DateTime($promo['end_date']);
                                    $isCurrent = $now >= $startDate && $now <= $endDate;
                                ?>
                                <tr data-id="<?= $promo['id'] ?>" data-table="promotions">
                                    <td><?= $promo['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($promo['title']) ?></strong>
                                        <?php if (!$isCurrent && $isActive): ?>
                                            <br><small style="color: var(--settings-warning);">Не в периоде действия</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('d.m.Y', strtotime($promo['start_date'])) ?> -
                                        <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
                                        <?php if ($isCurrent): ?>
                                            <br><small style="color: var(--settings-success);">Активна сейчас</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $isActive ? 'status-active' : 'status-inactive' ?>">
                                            <?= $isActive ? 'Активна' : 'Неактивна' ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell">
                                        <button type="button" class="action-btn action-btn-edit"
                                                onclick="openEditModal('promotion', <?= htmlspecialchars(json_encode($promo)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="active_tab" value="promotions">
                                            <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                                            <button type="submit" name="delete_promotion" class="action-btn action-btn-delete"
                                                    onclick="return confirm('Удалить эту акцию?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-percentage"></i>
                        <h3>Нет созданных акций</h3>
                        <p>Добавьте акционные предложения для привлечения клиентов</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Таб: Ресурсы -->
    <div class="tab-content <?= $activeTab === 'resources' ? 'active' : '' ?>" id="resources-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-cogs"></i> Кастомные конфигурации</h2>
            </div>
            <div class="section-body">
                <h3 style="color: var(--settings-text); margin: 0 0 15px 0;">Управление ценами для кастомных ВМ</h3>

                <?php if (!empty($customResources)): ?>
                    <div style="display: grid; gap: 16px;">
                        <?php foreach ($customResources as $resource):
                            $totalPerHour = $resource['cpu'] * $resource['price_per_hour_cpu'] +
                                          $resource['ram'] * $resource['price_per_hour_ram'] +
                                          $resource['disk'] * $resource['price_per_hour_disk'];
                        ?>
                        <div class="custom-resource-card">
                            <div class="resource-header">
                                <div class="resource-title"><?= htmlspecialchars($resource['vm_name']) ?></div>
                                <div class="resource-meta">
                                    <span>ID: <?= $resource['id'] ?></span>
                                    <span>Создана: <?= date('d.m.Y', strtotime($resource['created_at'])) ?></span>
                                </div>
                            </div>

                            <div style="color: var(--settings-text-secondary); font-size: 13px; margin-bottom: 12px;">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($resource['user_fullname'] . ' (' . $resource['user_email'] . ')') ?>
                            </div>

                            <div class="resource-grid">
                                <div class="resource-item">
                                    <span class="resource-label">CPU</span>
                                    <span class="resource-value"><?= $resource['cpu'] ?> ядер</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">RAM</span>
                                    <span class="resource-value"><?= $resource['ram'] ?> MB</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">Disk</span>
                                    <span class="resource-value"><?= $resource['disk'] ?> GB</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">CPU/час</span>
                                    <span class="resource-value"><?= number_format($resource['price_per_hour_cpu'], 6) ?> ₽</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">RAM/час</span>
                                    <span class="resource-value"><?= number_format($resource['price_per_hour_ram'], 6) ?> ₽</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">Disk/час</span>
                                    <span class="resource-value"><?= number_format($resource['price_per_hour_disk'], 6) ?> ₽</span>
                                </div>

                                <div class="resource-item">
                                    <span class="resource-label">Итого/час</span>
                                    <span class="resource-value success"><?= number_format($totalPerHour, 6) ?> ₽</span>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openEditCustomModal(<?= htmlspecialchars(json_encode($resource)) ?>)">
                                    <i class="fas fa-edit"></i> Редактировать цены
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-cogs"></i>
                        <h3>Нет кастомных конфигураций</h3>
                        <p>Кастомные конфигурации создаются пользователями при создании ВМ с индивидуальными параметрами</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Таб: Интеграции -->
    <div class="tab-content <?= $activeTab === 'integrations' ? 'active' : '' ?>" id="integrations-tab">
        <div class="settings-section fade-in">
            <div class="section-header">
                <h2><i class="fas fa-plug"></i> Настройки интеграций</h2>
            </div>
            <div class="section-body">
                <!-- Telegram Support Bot -->
                <div class="integration-card">
                    <div class="integration-header">
                        <div class="integration-icon telegram-icon">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <div class="integration-info">
                            <h3>Telegram Support Bot</h3>
                            <p>Бот для поддержки пользователей и уведомлений администраторов</p>
                        </div>
                        <div class="status-indicator <?= !empty($supportBotSettings['bot_token']) ? 'status-connected' : 'status-disconnected' ?>">
                            <i class="fas fa-circle"></i>
                            <?= !empty($supportBotSettings['bot_token']) ? 'Настроен' : 'Не настроен' ?>
                        </div>
                    </div>

                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="integrations">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Токен бота</label>
                                <input type="password" name="bot_token" class="form-input"
                                       value="<?= !empty($supportBotSettings['bot_token']) ? htmlspecialchars($supportBotSettings['bot_token']) : '' ?>"
                                       required>
                                <span class="form-hint">Получите у @BotFather в Telegram</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Имя бота</label>
                                <input type="text" name="bot_name" class="form-input"
                                       value="<?= !empty($supportBotSettings['bot_name']) ? htmlspecialchars($supportBotSettings['bot_name']) : 'Support Bot' ?>"
                                       required>
                            </div>
                        </div>

                        <?php if (!empty($supportBotSettings['bot_token'])): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Текущий токен (скрытый)</label>
                                <div class="secret-value">
                                    <?= substr($supportBotSettings['bot_token'], 0, 10) ?>...<?= substr($supportBotSettings['bot_token'], -10) ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Обновлен</label>
                                <div class="secret-value">
                                    <?= date('d.m.Y H:i', strtotime($supportBotSettings['updated_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" name="update_support_bot" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить настройки
                            </button>
                        </div>
                        <div id="support-bot-test-result" class="test-result"></div>
                    </form>
                </div>

                <!-- Telegram Chat Bot -->
                <div class="integration-card">
                    <div class="integration-header">
                        <div class="integration-icon telegram-icon">
                            <i class="fas fa-comment"></i>
                        </div>
                        <div class="integration-info">
                            <h3>Telegram Chat Bot</h3>
                            <p>Бот для общения с пользователями в чате</p>
                        </div>
                        <div class="status-indicator <?= !empty($chatBotSettings['bot_token']) ? 'status-connected' : 'status-disconnected' ?>">
                            <i class="fas fa-circle"></i>
                            <?= !empty($chatBotSettings['bot_token']) ? 'Настроен' : 'Не настроен' ?>
                        </div>
                    </div>

                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="integrations">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Токен бота</label>
                                <input type="password" name="bot_token" class="form-input"
                                       value="<?= !empty($chatBotSettings['bot_token']) ? htmlspecialchars($chatBotSettings['bot_token']) : '' ?>"
                                       required>
                                <span class="form-hint">Может быть тем же ботом или другим (получите у @BotFather)</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Имя бота</label>
                                <input type="text" name="bot_name" class="form-input"
                                       value="<?= !empty($chatBotSettings['bot_name']) ? htmlspecialchars($chatBotSettings['bot_name']) : 'Chat Bot' ?>"
                                       required>
                            </div>
                        </div>

                        <?php if (!empty($chatBotSettings['bot_token'])): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Текущий токен (скрытый)</label>
                                <div class="secret-value">
                                    <?= substr($chatBotSettings['bot_token'], 0, 10) ?>...<?= substr($chatBotSettings['bot_token'], -10) ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Обновлен</label>
                                <div class="secret-value">
                                    <?= date('d.m.Y H:i', strtotime($chatBotSettings['updated_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" name="update_chat_bot" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить настройки
                            </button>
                        </div>
                        <div id="chat-bot-test-result" class="test-result"></div>
                    </form>
                </div>

                <!-- SMTP Settings -->
                <div class="integration-card">
                    <div class="integration-header">
                        <div class="integration-icon email-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="integration-info">
                            <h3>Настройки Email (SMTP)</h3>
                            <p>Отправка email уведомлений пользователям</p>
                        </div>
                        <div class="status-indicator <?= !empty($smtpSettings['host']) ? 'status-connected' : 'status-disconnected' ?>">
                            <i class="fas fa-circle"></i>
                            <?= !empty($smtpSettings['host']) ? 'Настроен' : 'Не настроен' ?>
                        </div>
                    </div>

                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="active_tab" value="integrations">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">SMTP сервер</label>
                                <input type="text" name="host" class="form-input"
                                       value="<?= !empty($smtpSettings['host']) ? htmlspecialchars($smtpSettings['host']) : 'smtp.mail.ru' ?>"
                                       placeholder="smtp.mail.ru" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Порт</label>
                                <input type="number" name="port" class="form-input"
                                       value="<?= !empty($smtpSettings['port']) ? htmlspecialchars($smtpSettings['port']) : 465 ?>"
                                       placeholder="465" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Шифрование</label>
                                <select name="secure" class="form-input form-select" required>
                                    <option value="ssl" <?= (!empty($smtpSettings['secure']) && $smtpSettings['secure'] == 'ssl') ? 'selected' : '' ?>>SSL</option>
                                    <option value="tls" <?= (!empty($smtpSettings['secure']) && $smtpSettings['secure'] == 'tls') ? 'selected' : '' ?>>TLS</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Пользователь</label>
                                <input type="email" name="user" class="form-input"
                                       value="<?= !empty($smtpSettings['user']) ? htmlspecialchars($smtpSettings['user']) : '' ?>"
                                       placeholder="email@example.com" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Пароль</label>
                                <input type="password" name="pass" class="form-input"
                                       value="<?= !empty($smtpSettings['pass']) ? htmlspecialchars($smtpSettings['pass']) : '' ?>"
                                       required>
                                <span class="form-hint">Пароль приложения или основной пароль от почты</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Email отправителя</label>
                                <input type="email" name="from_email" class="form-input"
                                       value="<?= !empty($smtpSettings['from_email']) ? htmlspecialchars($smtpSettings['from_email']) : '' ?>"
                                       placeholder="email@example.com" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Имя отправителя</label>
                                <input type="text" name="from_name" class="form-input"
                                       value="<?= !empty($smtpSettings['from_name']) ? htmlspecialchars($smtpSettings['from_name']) : 'HomeVlad Cloud Support' ?>"
                                       required>
                            </div>
                        </div>

                        <?php if (!empty($smtpSettings['host'])): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Текущий пароль (скрытый)</label>
                                <div class="secret-value">
                                    <?= str_repeat('*', strlen($smtpSettings['pass'])) ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Обновлен</label>
                                <div class="secret-value">
                                    <?= date('d.m.Y H:i', strtotime($smtpSettings['updated_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="info-block">
                            <h4><i class="fas fa-lightbulb"></i> Полезная информация</h4>
                            <ul>
                                <li><strong>Mail.ru:</strong> smtp.mail.ru, порт 465 (SSL) или 587 (TLS)</li>
                                <li><strong>Gmail:</strong> smtp.gmail.com, порт 465 (SSL) или 587 (TLS)</li>
                                <li><strong>Yandex:</strong> smtp.yandex.ru, порт 465 (SSL)</li>
                                <li>Для Gmail может потребоваться пароль приложения вместо основного пароля</li>
                            </ul>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_smtp_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить настройки
                            </button>
                        </div>
                        <div id="smtp-test-result" class="test-result"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальные окна редактирования -->
<!-- Модальное окно редактирования тарифа -->
<div id="editTariffModal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">Редактировать тариф</h3>
            <button type="button" class="modal-close" onclick="closeModal('editTariffModal')">&times;</button>
        </div>
        <form method="POST" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="active_tab" value="tariffs">
            <input type="hidden" name="id" id="editTariffId">
            <input type="hidden" name="update_tariff" value="1">

            <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Название тарифа</label>
                        <input type="text" name="name" id="editTariffName" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Тип ВМ</label>
                        <select name="vm_type" id="editTariffVmType" class="form-input form-select" required>
                            <option value="qemu">QEMU (KVM)</option>
                            <option value="lxc">LXC (Контейнер)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Ядра CPU</label>
                        <input type="number" name="cpu" id="editTariffCpu" min="1" max="32" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">RAM (MB)</label>
                        <input type="number" name="ram" id="editTariffRam" min="512" max="65536" step="512" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Диск (GB)</label>
                        <input type="number" name="disk" id="editTariffDisk" min="10" max="2048" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Цена (руб./месяц)</label>
                        <input type="number" name="price" id="editTariffPrice" min="0.01" step="0.01" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Тип ОС</label>
                        <select name="os_type" id="editTariffOsType" class="form-input form-select">
                            <option value="">Выбрать вручную</option>
                            <option value="linux">Linux</option>
                            <option value="windows">Windows</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Трафик</label>
                        <input type="text" name="traffic" id="editTariffTraffic" class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Бэкапы</label>
                        <input type="text" name="backups" id="editTariffBackups" class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Поддержка</label>
                        <input type="text" name="support" id="editTariffSupport" class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Описание</label>
                        <textarea name="description" id="editTariffDescription" class="form-input form-textarea" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Порядок сортировки</label>
                        <input type="number" name="sort_order" id="editTariffSortOrder" min="0" max="100" class="form-input">
                    </div>

                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_custom" id="editTariffIsCustom" class="checkbox-input" onchange="toggleCustomPricesEdit()">
                            <label for="editTariffIsCustom" class="checkbox-label">Кастомный тариф (почасовая оплата)</label>
                        </div>

                        <div class="checkbox-item">
                            <input type="checkbox" name="is_popular" id="editTariffPopular" class="checkbox-input">
                            <label for="editTariffPopular" class="checkbox-label">Популярный тариф</label>
                        </div>

                        <div class="checkbox-item">
                            <input type="checkbox" name="is_active" id="editTariffActive" class="checkbox-input">
                            <label for="editTariffActive" class="checkbox-label">Активный тариф</label>
                        </div>
                    </div>

                    <!-- Кастомные цены для редактирования -->
                    <div id="editCustomPriceFields" style="display: none; grid-column: 1 / -1;">
                        <h4 style="color: var(--settings-accent); margin: 20px 0 10px 0;">Почасовые цены</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Цена за 1 vCPU в час</label>
                                <input type="number" name="price_per_hour_cpu" id="editTariffPricePerHourCpu"
                                       min="0.0000" step="0.0001" class="form-input" value="0.0000">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Цена за 1 MB RAM в час</label>
                                <input type="number" name="price_per_hour_ram" id="editTariffPricePerHourRam"
                                       min="0.000000" step="0.000001" class="form-input" value="0.000000">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Цена за 1 ГБ Диска в час</label>
                                <input type="number" name="price_per_hour_disk" id="editTariffPricePerHourDisk"
                                       min="0.000000" step="0.000001" class="form-input" value="0.000000">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editTariffModal')">Отмена</button>
                <button type="submit" name="update_tariff" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования возможности -->
<div id="editFeatureModal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Редактировать возможность</h3>
            <button type="button" class="modal-close" onclick="closeModal('editFeatureModal')">&times;</button>
        </div>
        <form method="POST" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="active_tab" value="features">
            <input type="hidden" name="id" id="editFeatureId">
            <input type="hidden" name="update_feature" value="1">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Заголовок</label>
                        <input type="text" name="title" id="editFeatureTitle" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Описание</label>
                        <textarea name="description" id="editFeatureDescription" class="form-input form-textarea" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Иконка</label>
                        <input type="text" name="icon" id="editFeatureIcon" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Порядок сортировки</label>
                        <input type="number" name="sort_order" id="editFeatureSortOrder" min="0" max="100" class="form-input">
                    </div>

                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_active" id="editFeatureActive" class="checkbox-input">
                            <label for="editFeatureActive" class="checkbox-label">Активная возможность</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editFeatureModal')">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования акции -->
<div id="editPromotionModal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Редактировать акцию</h3>
            <button type="button" class="modal-close" onclick="closeModal('editPromotionModal')">&times;</button>
        </div>
        <form method="POST" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="active_tab" value="promotions">
            <input type="hidden" name="id" id="editPromotionId">
            <input type="hidden" name="update_promotion" value="1">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Заголовок</label>
                        <input type="text" name="title" id="editPromotionTitle" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Описание</label>
                        <textarea name="description" id="editPromotionDescription" class="form-input form-textarea" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Изображение (URL)</label>
                        <input type="text" name="image" id="editPromotionImage" class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Дата начала</label>
                        <input type="date" name="start_date" id="editPromotionStartDate" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Дата окончания</label>
                        <input type="date" name="end_date" id="editPromotionEndDate" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Порядок сортировки</label>
                        <input type="number" name="sort_order" id="editPromotionSortOrder" min="0" max="100" class="form-input">
                    </div>

                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_active" id="editPromotionActive" class="checkbox-input">
                            <label for="editPromotionActive" class="checkbox-label">Активная акция</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editPromotionModal')">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования кастомных цен -->
<div id="editCustomModal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Редактировать цены на кастомные ресурсы</h3>
            <button type="button" class="modal-close" onclick="closeModal('editCustomModal')">&times;</button>
        </div>
        <form method="POST" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="active_tab" value="resources">
            <input type="hidden" name="id" id="editCustomId">
            <input type="hidden" name="update_custom_prices" value="1">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Виртуальная машина</label>
                        <input type="text" id="editCustomVmName" class="form-input" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Пользователь</label>
                        <input type="text" id="editCustomUsername" class="form-input" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">CPU (ядер)</label>
                        <input type="text" id="editCustomCpu" class="form-input" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">RAM (MB)</label>
                        <input type="text" id="editCustomRam" class="form-input" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Диск (GB)</label>
                        <input type="text" id="editCustomDisk" class="form-input" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Цена за CPU (CPU/час)</label>
                        <input type="number" name="price_per_hour_cpu" id="editCustomPriceCpu"
                               min="0.000001" step="0.000001" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Цена за RAM (MB/час)</label>
                        <input type="number" name="price_per_hour_ram" id="editCustomPriceRam"
                               min="0.000001" step="0.000001" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Цена за Disk (GB/час)</label>
                        <input type="number" name="price_per_hour_disk" id="editCustomPriceDisk"
                               min="0.000001" step="0.000001" class="form-input" required>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editCustomModal')">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация табов
    const tabs = document.querySelectorAll('.settings-tab');
    const tabContents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabName = this.dataset.tab;

            // Обновляем активный таб
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Показываем соответствующий контент
            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === tabName + '-tab') {
                    content.classList.add('active');
                }
            });

            // Обновляем URL без перезагрузки страницы
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        });
    });

    // Переключение полей кастомных цен при создании
    window.toggleCustomFields = function() {
        const isCustom = document.getElementById('isCustomCheckbox').checked;
        const customFields = document.getElementById('customPriceFields');
        customFields.style.display = isCustom ? 'block' : 'none';
    };

    // Переключение полей кастомных цен при редактировании
    window.toggleCustomPricesEdit = function() {
        const isCustom = document.getElementById('editTariffIsCustom').checked;
        const customFields = document.getElementById('editCustomPriceFields');
        customFields.style.display = isCustom ? 'block' : 'none';
    };
});

// Открытие модальных окон
function openEditModal(type, data) {
    if (type === 'tariff') {
        document.getElementById('editTariffId').value = data.id;
        document.getElementById('editTariffName').value = data.name || '';
        document.getElementById('editTariffVmType').value = data.vm_type || 'qemu';
        document.getElementById('editTariffCpu').value = data.cpu || '';
        document.getElementById('editTariffRam').value = data.ram || '';
        document.getElementById('editTariffDisk').value = data.disk || '';
        document.getElementById('editTariffPrice').value = data.price || '';
        document.getElementById('editTariffTraffic').value = data.traffic || '';
        document.getElementById('editTariffBackups').value = data.backups || '';
        document.getElementById('editTariffSupport').value = data.support || '';
        document.getElementById('editTariffDescription').value = data.description || '';
        document.getElementById('editTariffSortOrder').value = data.sort_order || 0;
        document.getElementById('editTariffPopular').checked = data.is_popular == 1;
        document.getElementById('editTariffActive').checked = data.is_active == 1;
        document.getElementById('editTariffOsType').value = data.os_type || '';
        document.getElementById('editTariffIsCustom').checked = data.is_custom == 1;
        document.getElementById('editTariffPricePerHourCpu').value = data.price_per_hour_cpu || '0.0000';
        document.getElementById('editTariffPricePerHourRam').value = data.price_per_hour_ram || '0.000000';
        document.getElementById('editTariffPricePerHourDisk').value = data.price_per_hour_disk || '0.000000';

        toggleCustomPricesEdit();
        document.getElementById('editTariffModal').style.display = 'flex';
    }
    else if (type === 'feature') {
        document.getElementById('editFeatureId').value = data.id;
        document.getElementById('editFeatureTitle').value = data.title;
        document.getElementById('editFeatureDescription').value = data.description;
        document.getElementById('editFeatureIcon').value = data.icon;
        document.getElementById('editFeatureSortOrder').value = data.sort_order || 0;
        document.getElementById('editFeatureActive').checked = data.is_active == 1;

        document.getElementById('editFeatureModal').style.display = 'flex';
    }
    else if (type === 'promotion') {
        document.getElementById('editPromotionId').value = data.id;
        document.getElementById('editPromotionTitle').value = data.title;
        document.getElementById('editPromotionDescription').value = data.description;
        document.getElementById('editPromotionImage').value = data.image || '';
        document.getElementById('editPromotionStartDate').value = data.start_date;
        document.getElementById('editPromotionEndDate').value = data.end_date;
        document.getElementById('editPromotionSortOrder').value = data.sort_order || 0;
        document.getElementById('editPromotionActive').checked = data.is_active == 1;

        document.getElementById('editPromotionModal').style.display = 'flex';
    }

    document.body.style.overflow = 'hidden';
}

// Открытие модального окна для кастомных цен
function openEditCustomModal(data) {
    document.getElementById('editCustomId').value = data.id;
    document.getElementById('editCustomVmName').value = data.vm_name;
    document.getElementById('editCustomUsername').value = data.user_fullname + ' (' + data.user_email + ')';
    document.getElementById('editCustomCpu').value = data.cpu + ' ядер';
    document.getElementById('editCustomRam').value = data.ram + ' MB';
    document.getElementById('editCustomDisk').value = data.disk + ' GB';
    document.getElementById('editCustomPriceCpu').value = data.price_per_hour_cpu;
    document.getElementById('editCustomPriceRam').value = data.price_per_hour_ram;
    document.getElementById('editCustomPriceDisk').value = data.price_per_hour_disk;

    document.getElementById('editCustomModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Закрытие модальных окон
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Закрытие при клике вне модального окна
window.onclick = function(event) {
    const modals = [
        'editTariffModal',
        'editFeatureModal',
        'editPromotionModal',
        'editCustomModal'
    ];

    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target == modal) {
            closeModal(modalId);
        }
    });
}

// Валидация форм при отправке
document.addEventListener('submit', function(e) {
    if (e.target.closest('form')) {
        const submitBtn = e.target.querySelector('button[type="submit"]');
        if (submitBtn) {
            // Валидация дат для акций
            if (e.target.name === 'add_promotion' || e.target.name === 'update_promotion') {
                const startDate = e.target.querySelector('input[name="start_date"]');
                const endDate = e.target.querySelector('input[name="end_date"]');
                
                if (startDate && endDate && new Date(startDate.value) > new Date(endDate.value)) {
                    e.preventDefault();
                    alert('Дата окончания не может быть раньше даты начала!');
                    return;
                }
            }
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохранение...';
            submitBtn.disabled = true;

            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = submitBtn.innerHTML.replace('fa-spinner fa-spin', 'fa-check');
            }, 2000);
        }
    }
});

// Показать/скрыть пароли
document.addEventListener('DOMContentLoaded', function() {
    const passwordFields = document.querySelectorAll('input[type="password"]');

    passwordFields.forEach(field => {
        const wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        wrapper.style.width = '100%';

        field.parentNode.insertBefore(wrapper, field);
        wrapper.appendChild(field);

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
        toggleBtn.style.position = 'absolute';
        toggleBtn.style.right = '10px';
        toggleBtn.style.top = '50%';
        toggleBtn.style.transform = 'translateY(-50%)';
        toggleBtn.style.background = 'none';
        toggleBtn.style.border = 'none';
        toggleBtn.style.color = 'var(--settings-text-secondary)';
        toggleBtn.style.cursor = 'pointer';
        toggleBtn.style.padding = '5px';

        wrapper.appendChild(toggleBtn);

        toggleBtn.addEventListener('click', function() {
            if (field.type === 'password') {
                field.type = 'text';
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                field.type = 'password';
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    });
});
</script>

<?php require 'admin_footer.php'; ?>