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

// Создаем таблицы если не существуют (старый проверенный код)
safeQuery($pdo, "CREATE TABLE IF NOT EXISTS tariffs (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

safeQuery($pdo, "CREATE TABLE IF NOT EXISTS features (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    icon VARCHAR(50) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

safeQuery($pdo, "CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    image VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

safeQuery($pdo, "CREATE TABLE IF NOT EXISTS vm_billing (
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
)");

safeQuery($pdo, "CREATE TABLE IF NOT EXISTS resource_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    price_per_hour_cpu DECIMAL(10,6) NOT NULL DEFAULT 0.001000,
    price_per_hour_ram DECIMAL(10,6) NOT NULL DEFAULT 0.000010,
    price_per_hour_disk DECIMAL(10,6) NOT NULL DEFAULT 0.000050,
    price_per_hour_lxc_cpu DECIMAL(10,6) NOT NULL DEFAULT 0.000800,
    price_per_hour_lxc_ram DECIMAL(10,6) NOT NULL DEFAULT 0.000008,
    price_per_hour_lxc_disk DECIMAL(10,6) NOT NULL DEFAULT 0.000030,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Создаем таблицу для SMTP настроек если не существует (из прошлого файла)
safeQuery($pdo, "CREATE TABLE IF NOT EXISTS smtp_settings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Инициализация базовых цен, если таблица пуста
$pricesExist = safeQuery($pdo, "SELECT COUNT(*) as count FROM resource_prices")->fetch(PDO::FETCH_ASSOC);
if ($pricesExist['count'] == 0) {
    safeQuery($pdo, "INSERT INTO resource_prices
        (price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk,
         price_per_hour_lxc_cpu, price_per_hour_lxc_ram, price_per_hour_lxc_disk)
        VALUES (0.001000, 0.000010, 0.000050, 0.000800, 0.000008, 0.000030)");
}

// Инициализация SMTP настроек если таблица пуста
$smtpSettingsExist = safeQuery($pdo, "SELECT COUNT(*) as count FROM smtp_settings")->fetch(PDO::FETCH_ASSOC);
if ($smtpSettingsExist['count'] == 0) {
    safeQuery($pdo, "INSERT INTO smtp_settings (host, port, user, pass, from_email, from_name, secure, created_at, updated_at)
        VALUES ('smtp.mail.ru', 465, '', '', 'noreply@example.com', 'HomeVlad Cloud', 'ssl', NOW(), NOW())");
}

// Получаем текущие настройки
$supportBotSettings = safeQuery($pdo, "SELECT * FROM telegram_support_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$chatBotSettings = safeQuery($pdo, "SELECT * FROM telegram_chat_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$smtpSettings = safeQuery($pdo, "SELECT * FROM smtp_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Обработка тарифов
        if (isset($_POST['add_tariff'])) {
            $isCustom = isset($_POST['is_custom']) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO tariffs
                (name, cpu, ram, disk, price, price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk,
                traffic, backups, support, description, is_popular, is_active, os_type, is_custom, vm_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $_POST['vm_type'] ?? 'qemu'
            ]);
            $_SESSION['success'] = "Тариф успешно добавлен";
        }
        elseif (isset($_POST['update_tariff'])) {
            $isCustom = isset($_POST['is_custom']) ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE tariffs SET
                name=?, cpu=?, ram=?, disk=?, price=?, price_per_hour_cpu=?, price_per_hour_ram=?, price_per_hour_disk=?,
                traffic=?, backups=?, support=?, description=?, is_popular=?, is_active=?, os_type=?, is_custom=?, vm_type=?
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
            $stmt = $pdo->prepare("INSERT INTO features (title, description, icon, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['icon'],
                isset($_POST['is_active']) ? 1 : 0
            ]);
            $_SESSION['success'] = "Возможность успешно добавлена";
        }
        elseif (isset($_POST['update_feature'])) {
            $stmt = $pdo->prepare("UPDATE features SET title=?, description=?, icon=?, is_active=? WHERE id=?");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['icon'],
                isset($_POST['is_active']) ? 1 : 0,
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
                (title, description, image, is_active, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['image'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['start_date'],
                $_POST['end_date']
            ]);
            $_SESSION['success'] = "Акция успешно добавлена";
        }
        elseif (isset($_POST['update_promotion'])) {
            $stmt = $pdo->prepare("UPDATE promotions SET
                title=?, description=?, image=?, is_active=?, start_date=?, end_date=?
                WHERE id=?");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['image'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['start_date'],
                $_POST['end_date'],
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
            $stmt = $pdo->prepare("UPDATE vm_billing SET
                price_per_hour_cpu = ?,
                price_per_hour_ram = ?,
                price_per_hour_disk = ?,
                total_per_hour = (cpu * ?) + (ram * ?) + (disk * ?)
                WHERE id = ?");
            $stmt->execute([
                $_POST['price_per_hour_cpu'],
                $_POST['price_per_hour_ram'],
                $_POST['price_per_hour_disk'],
                $_POST['price_per_hour_cpu'],
                $_POST['price_per_hour_ram'],
                $_POST['price_per_hour_disk'],
                $_POST['id']
            ]);
            $_SESSION['success'] = "Цены на кастомные ресурсы успешно обновлены";
        }

        // Обработка установки базовых цен
        elseif (isset($_POST['set_default_prices'])) {
            $stmt = $pdo->prepare("UPDATE resource_prices SET
                price_per_hour_cpu = ?,
                price_per_hour_ram = ?,
                price_per_hour_disk = ?,
                price_per_hour_lxc_cpu = ?,
                price_per_hour_lxc_ram = ?,
                price_per_hour_lxc_disk = ?
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

        // Обработка настройки Telegram Support Bot (из прошлого файла)
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

        // Обработка настройки Telegram Chat Bot (из прошлого файла)
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

        // Обработка настройки SMTP (из прошлого файла)
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

        header("Location: settings.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Ошибка базы данных: " . $e->getMessage();
    }
}

// Получаем данные
$tariffs = safeQuery($pdo, "SELECT * FROM tariffs ORDER BY is_active DESC, price ASC")->fetchAll(PDO::FETCH_ASSOC);
$features = safeQuery($pdo, "SELECT * FROM features ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$promotions = safeQuery($pdo, "SELECT * FROM promotions ORDER BY is_active DESC, start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$defaultPrices = safeQuery($pdo, "SELECT * FROM resource_prices LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Исправленный запрос для кастомных ресурсов с использованием hostname и full_name
$customResources = safeQuery($pdo, "
    SELECT
        vb.*,
        IFNULL(v.hostname, CONCAT('VM #', vb.vm_id)) as vm_name,
        u.full_name as user_fullname
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
/* Стили для страницы настроек в стиле дашборда */
:root {
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
    --db-telegram: #0088cc;
    --db-email: #ea4335;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
    --db-telegram: #24a1de;
    --db-email: #fbbc05;
}

/* Основные стили */
.settings-wrapper {
    padding: 25px;
    background: var(--db-bg);
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
    background: var(--db-card-bg);
    border-radius: 16px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.header-left h1 {
    color: var(--db-text);
    font-size: 28px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
}

.header-left h1 i {
    color: var(--db-accent);
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.header-left p {
    color: var(--db-text-secondary);
    font-size: 15px;
    margin: 0;
}

/* Сетка настроек */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

/* Карточки настроек */
.setting-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--db-shadow);
    transition: all 0.3s ease;
    height: 100%;
}

.setting-card:hover {
    box-shadow: var(--db-shadow-hover);
    transform: translateY(-2px);
}

.card-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-header h3 {
    font-size: 15px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.card-body {
    padding: 24px;
}

/* Стили для форм */
.setting-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.form-group {
    position: relative;
}

.form-label {
    display: block;
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-label.required::after {
    content: '*';
    color: var(--db-danger);
    margin-left: 4px;
}

.form-input {
    width: 87%;
    padding: 12px 16px;
    border: 1px solid var(--db-border);
    border-radius: 10px;
    background: var(--db-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
    font-family: inherit;
}

.form-input:focus {
    outline: none;
    border-color: var(--db-telegram);
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.15);
}

.form-textarea {
    min-height: 1px;
    resize: vertical;
    line-height: 1.5;
}

.form-hint {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin-top: 6px;
    display: block;
    line-height: 1.4;
}

/* Чекбоксы и переключатели */
.checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 8px 0;
}

.checkbox-input {
    width: 18px;
    height: 18px;
    border: 2px solid var(--db-border);
    border-radius: 4px;
    background: var(--db-card-bg);
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
}

.checkbox-input:checked {
    background: var(--db-telegram);
    border-color: var(--db-telegram);
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
    color: var(--db-text);
    font-size: 14px;
    cursor: pointer;
    user-select: none;
}

/* Кнопки */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0, 188, 212, 0.2);
}

.btn-telegram {
    background: linear-gradient(135deg, var(--db-telegram), #006699);
    color: white;
}

.btn-email {
    background: linear-gradient(135deg, var(--db-email), #c5221f);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, var(--db-success), #0ca678);
    color: white;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
}

.btn-icon {
    font-size: 16px;
}

/* Бейджи статусов */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: rgba(16, 185, 129, 0.15);
    color: var(--db-success);
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-inactive {
    background: rgba(148, 163, 184, 0.15);
    color: var(--db-text-muted);
    border: 1px solid rgba(148, 163, 184, 0.3);
}

/* Секции с таблицами */
.table-section {
    margin-top: 30px;
    background: var(--db-card-bg);
    border-radius: 16px;
    border: 1px solid var(--db-border);
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.table-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--db-telegram), #006699);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.table-header h3 {
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.table-container {
    overflow-x: auto;
    padding: 24px;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.admin-table thead {
    background: var(--db-hover);
}

.admin-table th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
}

.admin-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.admin-table tbody tr:hover {
    background: var(--db-hover);
}

.admin-table td {
    color: var(--db-text);
    font-size: 14px;
    padding: 14px 16px;
    vertical-align: middle;
}

/* Действия в таблице */
.actions {
    display: flex;
    gap: 8px;
    white-space: nowrap;
}

.action-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
}

.action-btn-edit {
    background: rgba(59, 130, 246, 0.1);
    color: var(--db-info);
}

.action-btn-edit:hover {
    background: rgba(59, 130, 246, 0.2);
    transform: translateY(-2px);
}

.action-btn-delete {
    background: rgba(239, 68, 68, 0.1);
    color: var(--db-danger);
}

.action-btn-delete:hover {
    background: rgba(239, 68, 68, 0.2);
    transform: translateY(-2px);
}

/* Интеграционные карточки */
.integration-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.integration-card:hover {
    box-shadow: var(--db-shadow-hover);
}

.integration-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--db-border);
}

.integration-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    flex-shrink: 0;
}

.icon-telegram {
    background: linear-gradient(135deg, var(--db-telegram), #006699);
}

.icon-email {
    background: linear-gradient(135deg, var(--db-email), #c5221f);
}

.integration-info {
    flex: 1;
}

.integration-info h4 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0 0 6px 0;
    font-weight: 600;
}

.integration-info p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
    line-height: 1.5;
}

/* Оповещения */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    border-left: 4px solid;
    animation: slideIn 0.3s ease;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: var(--db-success);
    color: var(--db-success);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: var(--db-danger);
    color: var(--db-danger);
}

.alert i {
    font-size: 18px;
}

/* Информационные блоки */
.info-block {
    background: rgba(0, 188, 212, 0.08);
    border: 1px solid rgba(0, 188, 212, 0.2);
    border-radius: 12px;
    padding: 18px;
    margin-top: 20px;
}

.info-block h5 {
    color: var(--db-accent);
    font-size: 15px;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.info-block ul {
    margin: 0;
    padding-left: 20px;
}

.info-block li {
    color: var(--db-text-secondary);
    font-size: 13px;
    margin-bottom: 6px;
    line-height: 1.5;
}

/* Пустое состояние */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    color: var(--db-text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h4 {
    font-size: 18px;
    margin-bottom: 8px;
    color: var(--db-text);
    font-weight: 600;
}

.empty-state p {
    font-size: 14px;
    margin: 0;
    line-height: 1.5;
}

/* Стили для модальных окон */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

.modal-container {
    background: var(--db-card-bg);
    border-radius: 16px;
    max-width: 800px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
    border: 1px solid var(--db-border);
}

.modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--db-telegram), #006699);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 16px 16px 0 0;
    position: sticky;
    top: 0;
    z-index: 1;
}

.modal-title {
    font-size: 20px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 28px;
    cursor: pointer;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
    transform: rotate(90deg);
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    background: var(--db-hover);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    border-radius: 0 0 16px 16px;
    border-top: 1px solid var(--db-border);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

/* Анимации для модальных окон */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Обновляем чекбоксы для модальных окон */
.modal-body .checkbox-wrapper {
    margin: 15px 0;
}

.modal-body .checkbox-input {
    width: 20px;
    height: 20px;
}

.modal-body .checkbox-label {
    font-size: 14px;
    color: var(--db-text);
}

/* Стилизация скроллбара в модальных окнах */
.modal-container::-webkit-scrollbar {
    width: 8px;
}

.modal-container::-webkit-scrollbar-track {
    background: var(--db-bg);
    border-radius: 4px;
}

.modal-container::-webkit-scrollbar-thumb {
    background: var(--db-telegram);
    border-radius: 4px;
}

.modal-container::-webkit-scrollbar-thumb:hover {
    background: #005580;
}

/* Дополнительные стили для форм в модальных окнах */
.modal-body .form-group {
    margin-bottom: 15px;
}

.modal-body .form-label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: var(--db-text);
}

.modal-body .form-input,
.modal-body .form-textarea {
    width: 87%;
    padding: 12px;
    border: 1px solid var(--db-border);
    border-radius: 8px;
    background: var(--db-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.modal-body .form-input:focus,
.modal-body .form-textarea:focus {
    outline: none;
    border-color: var(--db-telegram);
    box-shadow: 0 0 0 3px rgba(0, 136, 204, 0.1);
}

.modal-body .form-textarea {
    min-height: 100px;
    resize: vertical;
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

/* Стили для отключенных полей ввода */
.form-input:disabled,
.modal-body .form-input:disabled {
    background: var(--db-hover);
    color: var(--db-text-muted);
    cursor: not-allowed;
    opacity: 0.7;
}

/* Адаптивность */
@media (max-width: 1200px) {
    .settings-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }

    .form-row {
        grid-template-columns: 1fr;
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
        padding: 20px;
    }

    .card-header,
    .table-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .integration-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .integration-info {
        margin-top: 10px;
    }

    .modal-container {
        width: 95%;
        max-height: 85vh;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .modal-footer {
        flex-direction: column;
    }

    .modal-footer .btn {
        width: 100%;
    }
}
</style>

<!-- Основной контент -->
<div class="settings-wrapper">
    <!-- Шапка -->
    <div class="settings-header">
        <div class="header-left">
            <h1><i class="fas fa-cog"></i> Настройки сайта</h1>
            <p>Управление всными настройками системы в одном месте</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="/admin/" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Назад в дашборд
            </a>
        </div>
    </div>

    <!-- Оповещения -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Основные настройки в сетке -->
    <div class="settings-grid">
        <!-- Telegram Support Bot -->
        <div class="setting-card fade-in">
            <div class="card-header">
                <h3><i class="fab fa-telegram"></i> Telegram Support Bot</h3>
                <span class="status-badge <?= !empty($supportBotSettings['bot_token']) ? 'status-active' : 'status-inactive' ?>">
                    <?= !empty($supportBotSettings['bot_token']) ? 'Настроен' : 'Не настроен' ?>
                </span>
            </div>
            <div class="card-body">
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Токен бота</label>
                            <input type="text" name="bot_token" class="form-input"
                                   value="<?= !empty($supportBotSettings['bot_token']) ? htmlspecialchars($supportBotSettings['bot_token']) : '' ?>"
                                   placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz">
                            <span class="form-hint">Получите у @BotFather в Telegram</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Имя бота</label>
                            <input type="text" name="bot_name" class="form-input"
                                   value="<?= !empty($supportBotSettings['bot_name']) ? htmlspecialchars($supportBotSettings['bot_name']) : '@имя бота' ?>">
                        </div>
                    </div>

                    <?php if (!empty($supportBotSettings['bot_token'])): ?>
                    <div class="info-block">
                        <h5><i class="fas fa-info-circle"></i> Информация о боте</h5>
                        <ul>
                            <li>Токен: <?= substr($supportBotSettings['bot_token'], 0, 15) ?>...</li>
                            <li>Обновлен: <?= date('d.m.Y H:i', strtotime($supportBotSettings['updated_at'])) ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button type="submit" name="update_support_bot" class="btn btn-telegram">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                        <?php if (!empty($supportBotSettings['bot_token'])): ?>
                        <button type="button" class="btn btn-sm" onclick="testTelegramBot('support')">
                            <i class="fas fa-test-tube"></i> Протестировать
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Telegram Chat Bot -->
        <div class="setting-card fade-in">
            <div class="card-header">
                <h3><i class="fas fa-comments"></i> Telegram Chat Bot</h3>
                <span class="status-badge <?= !empty($chatBotSettings['bot_token']) ? 'status-active' : 'status-inactive' ?>">
                    <?= !empty($chatBotSettings['bot_token']) ? 'Настроен' : 'Не настроен' ?>
                </span>
            </div>
            <div class="card-body">
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Токен бота</label>
                            <input type="text" name="bot_token" class="form-input"
                                   value="<?= !empty($chatBotSettings['bot_token']) ? htmlspecialchars($chatBotSettings['bot_token']) : '' ?>"
                                   placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz">
                            <span class="form-hint">Получите у @BotFather в Telegram</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Имя бота</label>
                            <input type="text" name="bot_name" class="form-input"
                                   value="<?= !empty($chatBotSettings['bot_name']) ? htmlspecialchars($chatBotSettings['bot_name']) : '@имя бота' ?>">
                        </div>
                    </div>

                    <?php if (!empty($chatBotSettings['bot_token'])): ?>
                    <div class="info-block">
                        <h5><i class="fas fa-info-circle"></i> Информация о боте</h5>
                        <ul>
                            <li>Токен: <?= substr($chatBotSettings['bot_token'], 0, 15) ?>...</li>
                            <li>Обновлен: <?= date('d.m.Y H:i', strtotime($chatBotSettings['updated_at'])) ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button type="submit" name="update_chat_bot" class="btn btn-telegram">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                        <?php if (!empty($chatBotSettings['bot_token'])): ?>
                        <button type="button" class="btn btn-sm" onclick="testTelegramBot('chat')">
                            <i class="fas fa-test-tube"></i> Протестировать
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- SMTP Настройки -->
        <div class="setting-card fade-in">
            <div class="card-header">
                <h3><i class="fas fa-envelope"></i> SMTP Настройки</h3>
                <span class="status-badge <?= !empty($smtpSettings['host']) ? 'status-active' : 'status-inactive' ?>">
                    <?= !empty($smtpSettings['host']) ? 'Настроен' : 'Не настроен' ?>
                </span>
            </div>
            <div class="card-body">
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SMTP сервер</label>
                            <input type="text" name="host" class="form-input"
                                   value="<?= !empty($smtpSettings['host']) ? htmlspecialchars($smtpSettings['host']) : 'smtp.mail.ru' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Порт</label>
                            <input type="number" name="port" class="form-input"
                                   value="<?= !empty($smtpSettings['port']) ? htmlspecialchars($smtpSettings['port']) : 465 ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Шифрование</label>
                            <select name="secure" class="form-input">
                                <option value="ssl" <?= (!empty($smtpSettings['secure']) && $smtpSettings['secure'] == 'ssl') ? 'selected' : '' ?>>SSL</option>
                                <option value="tls" <?= (!empty($smtpSettings['secure']) && $smtpSettings['secure'] == 'tls') ? 'selected' : '' ?>>TLS</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Пользователь</label>
                            <input type="text" name="user" class="form-input"
                                   value="<?= !empty($smtpSettings['user']) ? htmlspecialchars($smtpSettings['user']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Пароль</label>
                            <input type="password" name="pass" class="form-input"
                                   value="<?= !empty($smtpSettings['pass']) ? htmlspecialchars($smtpSettings['pass']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email отправителя</label>
                            <input type="email" name="from_email" class="form-input"
                                   value="<?= !empty($smtpSettings['from_email']) ? htmlspecialchars($smtpSettings['from_email']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Имя отправителя</label>
                            <input type="text" name="from_name" class="form-input"
                                   value="<?= !empty($smtpSettings['from_name']) ? htmlspecialchars($smtpSettings['from_name']) : 'HomeVlad Cloud Support' ?>">
                        </div>
                    </div>

                    <div class="info-block">
                        <h5><i class="fas fa-lightbulb"></i> Примеры настроек</h5>
                        <ul>
                            <li><strong>Mail.ru:</strong> smtp.mail.ru, порт 465, SSL</li>
                            <li><strong>Gmail:</strong> smtp.gmail.com, порт 465, SSL</li>
                            <li><strong>Yandex:</strong> smtp.yandex.ru, порт 465, SSL</li>
                        </ul>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button type="submit" name="update_smtp_settings" class="btn btn-email">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                        <?php if (!empty($smtpSettings['host'])): ?>
                        <button type="button" class="btn btn-sm" onclick="testSmtpSettings()">
                            <i class="fas fa-test-tube"></i> Протестировать
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Базовые цены на ресурсы -->
        <div class="setting-card fade-in">
            <div class="card-header">
                <h3><i class="fas fa-money-bill-wave"></i> Базовые цены на ресурсы</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <!-- Цены для QEMU (KVM) -->
                        <div class="form-group">
                            <label class="form-label">1 vCPU в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_cpu" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_cpu'] ?? '0.001000' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">1 MB RAM в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_ram" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_ram'] ?? '0.000010' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">1 ГБ Диска в час (QEMU)</label>
                            <input type="number" name="default_price_per_hour_disk" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_disk'] ?? '0.000050' ?>" required>
                        </div>

                        <!-- Цены для LXC -->
                        <div class="form-group">
                            <label class="form-label">1 vCPU в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_cpu" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_cpu'] ?? '0.000800' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">1 MB RAM в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_ram" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_ram'] ?? '0.000008' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">1 ГБ Диска в час (LXC)</label>
                            <input type="number" name="default_price_per_hour_lxc_disk" min="0.000001" step="0.000001" class="form-input"
                                   value="<?= $defaultPrices['price_per_hour_lxc_disk'] ?? '0.000030' ?>" required>
                        </div>
                    </div>

                    <div class="info-block">
                        <h5><i class="fas fa-info-circle"></i> Информация о ценах</h5>
                        <ul>
                            <li>QEMU (KVM) - полноценные виртуальные машины</li>
                            <li>LXC - легковесные контейнеры</li>
                            <li>Все цены в рублях за час использования</li>
                        </ul>
                    </div>

                    <button type="submit" name="set_default_prices" class="btn btn-primary">
                        <i class="fas fa-save"></i> Сохранить базовые цены
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Секция: Тарифы -->
    <div class="table-section fade-in">
        <div class="table-header">
            <h3><i class="fas fa-tags"></i> Управление тарифами</h3>
            <span class="status-badge status-active"><?= count($tariffs) ?> тарифов</span>
        </div>
        <div class="table-container">
            <!-- Форма добавления тарифа -->
            <div style="background: var(--db-hover); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h4 style="color: var(--db-text); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-plus-circle"></i> Добавить новый тариф
                </h4>
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Название тарифа*</label>
                            <input type="text" name="name" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Тип ВМ*</label>
                            <select name="vm_type" class="form-input" required>
                                <option value="qemu">QEMU (KVM)</option>
                                <option value="lxc">LXC (Контейнер)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Ядра CPU*</label>
                            <input type="number" name="cpu" min="1" max="8" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">RAM (MB)*</label>
                            <input type="number" name="ram" min="512" max="32768" step="512" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Диск (GB)*</label>
                            <input type="number" name="disk" min="10" max="300" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Цена (руб./месяц)</label>
                            <input type="number" name="price" min="0.00" step="0.01" class="form-input">
                        </div>

                        <!-- ДОБАВЛЕННЫЕ ПОЛЯ ИЗ СТАРОГО ФАЙЛА -->
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
                            <textarea name="description" class="form-input form-textarea" rows="1"></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Тип ОС</label>
                            <select name="os_type" class="form-input">
                                <option value="">Выбрать вручную</option>
                                <option value="linux">Linux</option>
                                <option value="windows">Windows</option>
                            </select>
                        </div>
                        <!-- КОНЕЦ ДОБАВЛЕННЫХ ПОЛЕЙ -->

                        <!-- Цены за час для кастомного тарифа -->
                        <div class="form-group">
                            <label class="form-label">Цена за 1 vCPU в час</label>
                            <input type="number" name="price_per_hour_cpu" min="0.0000" step="0.0001" class="form-input" value="0.0000" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Цена за 1 MB RAM в час</label>
                            <input type="number" name="price_per_hour_ram" min="0.000000" step="0.000001" class="form-input" value="0.000000" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Цена за 1 ГБ Диска в час</label>
                            <input type="number" name="price_per_hour_disk" min="0.000000" step="0.000001" class="form-input" value="0.000000" disabled>
                        </div>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_custom" id="isCustomCheckbox" onchange="toggleCustomPrices()">
                            <label for="isCustomCheckbox" class="checkbox-label">Кастомный тариф (в час)</label>
                        </div>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_popular" id="isPopularCheckbox">
                            <label for="isPopularCheckbox" class="checkbox-label">Популярный тариф</label>
                        </div>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_active" id="isActiveCheckbox" checked>
                            <label for="isActiveCheckbox" class="checkbox-label">Активный тариф</label>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <button type="submit" name="add_tariff" class="btn btn-success">
                            <i class="fas fa-plus"></i> Добавить тариф
                        </button>
                    </div>
                </form>
            </div>

            <!-- Список тарифов -->
            <?php if (!empty($tariffs)): ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Тип</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Диск</th>
                                <th>Цена</th>
                                <th>Тип ОС</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tariffs as $tariff): ?>
                            <tr>
                                <td><?= $tariff['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($tariff['name']) ?></strong>
                                    <?php if ($tariff['is_custom']): ?>
                                        <br><small style="color: var(--db-warning);">Почасовая оплата</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $tariff['vm_type'] == 'qemu' ? 'QEMU' : 'LXC' ?></td>
                                <td><?= $tariff['cpu'] ?> ядер</td>
                                <td><?= $tariff['ram'] ?> MB</td>
                                <td><?= $tariff['disk'] ?> GB</td>
                                <td>
                                    <strong><?= number_format($tariff['price'], 2) ?> руб.</strong>
                                    <?php if ($tariff['is_custom']): ?>
                                        <br><small>CPU: <?= number_format($tariff['price_per_hour_cpu'], 4) ?>/час</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $tariff['os_type'] ? htmlspecialchars($tariff['os_type']) : 'Ручной выбор' ?></td>
                                <td>
                                    <span class="status-badge <?= $tariff['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $tariff['is_active'] ? 'Активен' : 'Неактивен' ?>
                                        <?= $tariff['is_popular'] ? ' ★' : '' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <button type="button" class="action-btn action-btn-edit"
                                            onclick="openEditModal('tariff', <?= htmlspecialchars(json_encode($tariff)) ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline;">
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
                    <h4>Нет созданных тарифов</h4>
                    <p>Добавьте первый тариф, чтобы он отображался в панели пользователя</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Секция: Возможности -->
    <div class="table-section fade-in" style="margin-top: 25px;">
        <div class="table-header">
            <h3><i class="fas fa-star"></i> Управление возможностями</h3>
            <span class="status-badge status-active"><?= count($features) ?> возможностей</span>
        </div>
        <div class="table-container">
            <!-- Форма добавления возможности -->
            <div style="background: var(--db-hover); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h4 style="color: var(--db-text); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-plus-circle"></i> Добавить новую возможность
                </h4>
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Заголовок*</label>
                            <input type="text" name="title" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Описание*</label>
                            <textarea name="description" class="form-input form-textarea" rows="2" required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Иконка (Font Awesome)*</label>
                            <input type="text" name="icon" class="form-input" placeholder="fas fa-rocket" required>
                            <span class="form-hint">Например: fas fa-rocket, fas fa-shield-alt</span>
                        </div>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_active" id="featureActive" checked>
                            <label for="featureActive" class="checkbox-label">Активная возможность</label>
                        </div>
                    </div>

                    <button type="submit" name="add_feature" class="btn btn-success">
                        <i class="fas fa-plus"></i> Добавить возможность
                    </button>
                </form>
            </div>

            <!-- Список возможностей -->
            <?php if (!empty($features)): ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Иконка</th>
                            <th>Заголовок</th>
                            <th>Описание</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($features as $feature): ?>
                        <tr>
                            <td><?= $feature['id'] ?></td>
                            <td><i class="<?= htmlspecialchars($feature['icon']) ?> fa-lg" style="color: var(--db-accent);"></i></td>
                            <td><strong><?= htmlspecialchars($feature['title']) ?></strong></td>
                            <td><?= htmlspecialchars($feature['description']) ?></td>
                            <td>
                                <span class="status-badge <?= $feature['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $feature['is_active'] ? 'Активна' : 'Неактивна' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <button type="button" class="action-btn action-btn-edit"
                                        onclick="openEditModal('feature', <?= htmlspecialchars(json_encode($feature)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;">
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
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-star"></i>
                    <h4>Нет созданных возможностей</h4>
                    <p>Добавьте возможности, которые будут отображаться на главной странице</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Секция: Акции -->
    <div class="table-section fade-in" style="margin-top: 25px;">
        <div class="table-header">
            <h3><i class="fas fa-percentage"></i> Управление акциями</h3>
            <span class="status-badge status-active"><?= count($promotions) ?> акций</span>
        </div>
        <div class="table-container">
            <!-- Форма добавления акции -->
            <div style="background: var(--db-hover); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                <h4 style="color: var(--db-text); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-plus-circle"></i> Добавить новую акцию
                </h4>
                <form method="POST" class="setting-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Заголовок*</label>
                            <input type="text" name="title" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Описание*</label>
                            <textarea name="description" class="form-input form-textarea" rows="2" required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Изображение (URL)</label>
                            <input type="text" name="image" class="form-input" placeholder="https://example.com/image.jpg">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Дата начала*</label>
                            <input type="date" name="start_date" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Дата окончания*</label>
                            <input type="date" name="end_date" class="form-input" required>
                        </div>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_active" id="promotionActive" checked>
                            <label for="promotionActive" class="checkbox-label">Активная акция</label>
                        </div>
                    </div>

                    <button type="submit" name="add_promotion" class="btn btn-success">
                        <i class="fas fa-plus"></i> Добавить акцию
                    </button>
                </form>
            </div>

            <!-- Список акций -->
            <?php if (!empty($promotions)): ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Заголовок</th>
                            <th>Период действия</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promotions as $promo): ?>
                        <tr>
                            <td><?= $promo['id'] ?></td>
                            <td><strong><?= htmlspecialchars($promo['title']) ?></strong></td>
                            <td>
                                <?= date('d.m.Y', strtotime($promo['start_date'])) ?> -
                                <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
                                <?php
                                    $now = new DateTime();
                                    $startDate = new DateTime($promo['start_date']);
                                    $endDate = new DateTime($promo['end_date']);
                                    if ($now >= $startDate && $now <= $endDate && $promo['is_active'] == 1):
                                ?>
                                    <br><small style="color: var(--db-success);">Активна сейчас</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $promo['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $promo['is_active'] ? 'Активна' : 'Неактивна' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <button type="button" class="action-btn action-btn-edit"
                                        onclick="openEditModal('promotion', <?= htmlspecialchars(json_encode($promo)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;">
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
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-percentage"></i>
                    <h4>Нет созданных акций</h4>
                    <p>Добавьте акционные предложения для привлечения клиентов</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Секция: Кастомные конфигурации -->
    <div class="table-section fade-in" style="margin-top: 25px;">
        <div class="table-header">
            <h3><i class="fas fa-cogs"></i> Кастомные конфигурации</h3>
            <span class="status-badge status-active"><?= count($customResources) ?> конфигураций</span>
        </div>
        <div class="table-container">
            <?php if (!empty($customResources)): ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Виртуальная машина</th>
                            <th>Пользователь</th>
                            <th>Ресурсы</th>
                            <th>Цены за ресурсы</th>
                            <th>Итого/час</th>
                            <th>Дата создания</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customResources as $resource): ?>
                        <?php
                            $totalPerHour = $resource['cpu'] * $resource['price_per_hour_cpu'] +
                                          $resource['ram'] * $resource['price_per_hour_ram'] +
                                          $resource['disk'] * $resource['price_per_hour_disk'];
                        ?>
                        <tr>
                            <td><?= $resource['id'] ?></td>
                            <td><strong><?= htmlspecialchars($resource['vm_name']) ?></strong></td>
                            <td><?= htmlspecialchars($resource['user_fullname']) ?></td>
                            <td>
                                <div style="display: flex; gap: 15px;">
                                    <div>
                                        <small>CPU</small><br>
                                        <strong><?= $resource['cpu'] ?> ядер</strong>
                                    </div>
                                    <div>
                                        <small>RAM</small><br>
                                        <strong><?= $resource['ram'] ?> MB</strong>
                                    </div>
                                    <div>
                                        <small>Disk</small><br>
                                        <strong><?= $resource['disk'] ?> GB</strong>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <small>CPU: <?= number_format($resource['price_per_hour_cpu'], 6) ?></small><br>
                                <small>RAM: <?= number_format($resource['price_per_hour_ram'], 6) ?></small><br>
                                <small>Disk: <?= number_format($resource['price_per_hour_disk'], 6) ?></small>
                            </td>
                            <td>
                                <strong style="color: var(--db-success);">
                                    <?= number_format($totalPerHour, 6) ?> руб.
                                </strong>
                            </td>
                            <td><?= date('d.m.Y H:i', strtotime($resource['created_at'])) ?></td>
                            <td class="actions">
                                <button type="button" class="action-btn action-btn-edit"
                                        onclick="openEditCustomModal(<?= htmlspecialchars(json_encode($resource)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-cogs"></i>
                    <h4>Нет кастомных конфигураций</h4>
                    <p>Кастомные конфигурации создаются пользователями при создании ВМ с индивидуальными параметрами</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования тарифа -->
<div id="editTariffModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-edit"></i> Редактировать тариф</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" action="settings.php" id="editTariffForm">
            <input type="hidden" name="id" id="editTariffId">
            <input type="hidden" name="update_tariff" value="1">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Название тарифа*</label>
                        <input type="text" name="name" id="editTariffName" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Тип виртуальной машины*</label>
                        <select name="vm_type" id="editTariffVmType" class="form-input" required>
                            <option value="qemu">QEMU (KVM)</option>
                            <option value="lxc">LXC (Контейнер)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Ядра CPU*</label>
                        <input type="number" name="cpu" id="editTariffCpu" min="1" max="32" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">RAM (MB)*</label>
                        <input type="number" name="ram" id="editTariffRam" min="512" max="65536" step="512" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Диск (GB)*</label>
                        <input type="number" name="disk" id="editTariffDisk" min="10" max="2048" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Цена (руб./месяц)*</label>
                        <input type="number" name="price" id="editTariffPrice" min="0.01" step="0.01" class="form-input" required>
                    </div>

                    <!-- ДОБАВЛЕННЫЕ ПОЛЯ ИЗ СТАРОГО ФАЙЛА -->
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
                        <textarea name="description" id="editTariffDescription" class="form-input" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Тип ОС</label>
                        <select name="os_type" id="editTariffOsType" class="form-input">
                            <option value="">Выбрать вручную</option>
                            <option value="linux">Linux</option>
                            <option value="windows">Windows</option>
                        </select>
                    </div>
                    <!-- КОНЕЦ ДОБАВЛЕННЫХ ПОЛЕЙ -->

                    <div class="form-group">
                        <label class="form-label">Цена за 1 vCPU в час</label>
                        <input type="number" name="price_per_hour_cpu" id="editTariffPricePerHourCpu"
                               min="0.0000" step="0.0001" class="form-input" value="0.0000" disabled>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Цена за 1 MB RAM в час</label>
                        <input type="number" name="price_per_hour_ram" id="editTariffPricePerHourRam"
                               min="0.000000" step="0.000001" class="form-input" value="0.000000" disabled>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Цена за 1 ГБ Диска в час</label>
                        <input type="number" name="price_per_hour_disk" id="editTariffPricePerHourDisk"
                               min="0.000000" step="0.000001" class="form-input" value="0.000000" disabled>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_custom" id="editTariffIsCustom" onchange="toggleCustomPricesEdit()">
                        <label for="editTariffIsCustom" class="checkbox-label">Кастомный тариф (с почасовой оплатой)</label>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_popular" id="editTariffPopular">
                        <label for="editTariffPopular" class="checkbox-label">Популярный тариф</label>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" id="editTariffActive">
                        <label for="editTariffActive" class="checkbox-label">Активный тариф</label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" name="update_tariff" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования возможности -->
<div id="editFeatureModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-star"></i> Редактировать возможность</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editFeatureForm">
            <input type="hidden" name="id" id="editFeatureId">
            <input type="hidden" name="update_feature" value="1">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Заголовок*</label>
                        <input type="text" name="title" id="editFeatureTitle" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Описание*</label>
                        <textarea name="description" id="editFeatureDescription" class="form-input" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Иконка (Font Awesome)*</label>
                        <input type="text" name="icon" id="editFeatureIcon" class="form-input" required>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" id="editFeatureActive">
                        <label for="editFeatureActive" class="checkbox-label">Активная возможность</label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования акции -->
<div id="editPromotionModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-percentage"></i> Редактировать акцию</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editPromotionForm">
            <input type="hidden" name="id" id="editPromotionId">
            <input type="hidden" name="update_promotion" value="1">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Заголовок*</label>
                        <input type="text" name="title" id="editPromotionTitle" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Описание*</label>
                        <textarea name="description" id="editPromotionDescription" class="form-input" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Изображение (URL)</label>
                        <input type="text" name="image" id="editPromotionImage" class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Дата начала*</label>
                        <input type="date" name="start_date" id="editPromotionStartDate" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Дата окончания*</label>
                        <input type="date" name="end_date" id="editPromotionEndDate" class="form-input" required>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" id="editPromotionActive">
                        <label for="editPromotionActive" class="checkbox-label">Активная акция</label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования кастомных цен -->
<div id="editCustomModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-cogs"></i> Редактировать цены на кастомные ресурсы</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editCustomForm">
            <input type="hidden" name="id" id="editCustomId">
            <input type="hidden" name="update_custom_prices" value="1">

            <div class="modal-body">
                <div class="form-grid">
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
                        <label class="form-label">Цена за CPU (CPU/час)*</label>
                        <input type="number" name="price_per_hour_cpu" id="editCustomPriceCpu" min="0.000001" step="0.000001" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Цена за RAM (MB/час)*</label>
                        <input type="number" name="price_per_hour_ram" id="editCustomPriceRam" min="0.000001" step="0.000001" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Цена за Disk (GB/час)*</label>
                        <input type="number" name="price_per_hour_disk" id="editCustomPriceDisk" min="0.000001" step="0.000001" class="form-input" required>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Основные функции модальных окон
function openEditModal(type, data) {
    if (type === 'tariff') {
        // Заполняем основные поля
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
        document.getElementById('editTariffPopular').checked = data.is_popular == 1;
        document.getElementById('editTariffActive').checked = data.is_active == 1;
        document.getElementById('editTariffOsType').value = data.os_type || '';

        // Заполняем кастомные цены
        document.getElementById('editTariffIsCustom').checked = data.is_custom == 1;
        document.getElementById('editTariffPricePerHourCpu').value = data.price_per_hour_cpu || '0.0000';
        document.getElementById('editTariffPricePerHourRam').value = data.price_per_hour_ram || '0.000000';
        document.getElementById('editTariffPricePerHourDisk').value = data.price_per_hour_disk || '0.000000';

        // Показываем/скрываем поля кастомных цен
        toggleCustomPricesEdit();

        document.getElementById('editTariffModal').style.display = 'flex';
    }
    else if (type === 'feature') {
        document.getElementById('editFeatureId').value = data.id;
        document.getElementById('editFeatureTitle').value = data.title;
        document.getElementById('editFeatureDescription').value = data.description;
        document.getElementById('editFeatureIcon').value = data.icon;
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
        document.getElementById('editPromotionActive').checked = data.is_active == 1;

        document.getElementById('editPromotionModal').style.display = 'flex';
    }

    document.body.style.overflow = 'hidden';
}

// Открытие модального окна для кастомных цен
function openEditCustomModal(data) {
    document.getElementById('editCustomId').value = data.id;
    document.getElementById('editCustomVmName').value = data.vm_name;
    document.getElementById('editCustomUsername').value = data.user_fullname;
    document.getElementById('editCustomCpu').value = data.cpu + ' ядер';
    document.getElementById('editCustomRam').value = data.ram + ' MB';
    document.getElementById('editCustomDisk').value = data.disk + ' GB';
    document.getElementById('editCustomPriceCpu').value = data.price_per_hour_cpu;
    document.getElementById('editCustomPriceRam').value = data.price_per_hour_ram;
    document.getElementById('editCustomPriceDisk').value = data.price_per_hour_disk;

    document.getElementById('editCustomModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Закрытие модального окна
function closeEditModal() {
    const modals = [
        'editTariffModal',
        'editFeatureModal',
        'editPromotionModal',
        'editCustomModal'
    ];

    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    });

    document.body.style.overflow = 'auto';
}

// Переключение отображения полей кастомных цен при создании
function toggleCustomPrices() {
    const isCustom = document.getElementById('isCustomCheckbox').checked;
    const cpuInput = document.querySelector('input[name="price_per_hour_cpu"]');
    const ramInput = document.querySelector('input[name="price_per_hour_ram"]');
    const diskInput = document.querySelector('input[name="price_per_hour_disk"]');

    if (cpuInput) cpuInput.disabled = !isCustom;
    if (ramInput) ramInput.disabled = !isCustom;
    if (diskInput) diskInput.disabled = !isCustom;
}

// Переключение отображения полей кастомных цен при редактировании
function toggleCustomPricesEdit() {
    const isCustom = document.getElementById('editTariffIsCustom').checked;
    const cpuInput = document.getElementById('editTariffPricePerHourCpu');
    const ramInput = document.getElementById('editTariffPricePerHourRam');
    const diskInput = document.getElementById('editTariffPricePerHourDisk');

    if (cpuInput) cpuInput.disabled = !isCustom;
    if (ramInput) ramInput.disabled = !isCustom;
    if (diskInput) diskInput.disabled = !isCustom;
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
            closeEditModal();
        }
    });
}

// Функция для тестирования SMTP настроек
function testSmtpSettings() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Тестирование...';
    btn.disabled = true;

    fetch('test_smtp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'test_smtp=true'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('SMTP настройки работают корректно! Тестовое письмо отправлено.', 'success');
        } else {
            showNotification('Ошибка при тестировании SMTP: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сети при тестировании SMTP', 'error');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

// Функция для тестирования Telegram бота
function testTelegramBot(botType) {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Тестирование...';
    btn.disabled = true;

    fetch('test_telegram.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'test_telegram=true&bot_type=' + botType
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Telegram бот работает корректно! Тестовое сообщение отправлено.', 'success');
        } else {
            showNotification('Ошибка при тестировании Telegram бота: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сети при тестировании Telegram бота', 'error');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

// Функция для показа уведомлений
function showNotification(message, type) {
    // Создаем элемент уведомления
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
    `;

    // Добавляем уведомление в начало страницы
    const wrapper = document.querySelector('.settings-wrapper');
    wrapper.insertBefore(notification, wrapper.firstChild);

    // Автоматически удаляем уведомление через 5 секунд
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Анимация карточек при загрузке
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.fade-in');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const content = document.querySelector('.settings-wrapper');

    if (sidebar && content) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        content.style.marginLeft = '70px';
                    } else {
                        content.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }
});
</script>

<?php require 'admin_footer.php'; ?>
