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

// Создаем таблицы если не существуют
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

// Инициализация базовых цен, если таблица пуста
$pricesExist = safeQuery($pdo, "SELECT COUNT(*) as count FROM resource_prices")->fetch(PDO::FETCH_ASSOC);
if ($pricesExist['count'] == 0) {
    safeQuery($pdo, "INSERT INTO resource_prices 
        (price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk,
         price_per_hour_lxc_cpu, price_per_hour_lxc_ram, price_per_hour_lxc_disk) 
        VALUES (0.001000, 0.000010, 0.000050, 0.000800, 0.000008, 0.000030)");
}

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

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-cog"></i> Настройки сайта
            </h1>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Кастомные ресурсы -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-cogs"></i> Управление кастомными конфигурациями
                </h2>
                
                <!-- Форма добавления базовых цен -->
                <div class="form-section">
                    <h3 class="form-section-title">Базовые цены на ресурсы</h3>
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
                            <!-- Цены для QEMU (KVM) -->
                            <div class="form-group">
                                <label class="form-label">1 vCPU в час (QEMU)*</label>
                                <input type="number" name="default_price_per_hour_cpu" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_cpu'] ?? '0.001000' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">1 MB RAM в час (QEMU)*</label>
                                <input type="number" name="default_price_per_hour_ram" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_ram'] ?? '0.000010' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">1 ГБ Диска в час (QEMU)*</label>
                                <input type="number" name="default_price_per_hour_disk" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_disk'] ?? '0.000050' ?>" required>
                            </div>
                            
                            <!-- Цены для LXC -->
                            <div class="form-group">
                                <label class="form-label">1 vCPU в час (LXC)*</label>
                                <input type="number" name="default_price_per_hour_lxc_cpu" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_lxc_cpu'] ?? '0.000800' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">1 MB RAM в час (LXC)*</label>
                                <input type="number" name="default_price_per_hour_lxc_ram" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_lxc_ram'] ?? '0.000008' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">1 ГБ Диска в час (LXC)*</label>
                                <input type="number" name="default_price_per_hour_lxc_disk" min="0.000001" step="0.000001" class="form-input" 
                                       value="<?= $defaultPrices['price_per_hour_lxc_disk'] ?? '0.000030' ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="set_default_prices" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить базовые цены
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Список кастомных конфигураций -->
                <div class="table-section">
                    <h3 class="form-section-title">Список кастомных конфигураций</h3>
                    
                    <?php if (!empty($customResources)): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>ВМ</th>
                                        <th>Пользователь</th>
                                        <th>CPU</th>
                                        <th>RAM</th>
                                        <th>Диск</th>
                                        <th>Цены за ресурсы</th>
                                        <th>Итого/час</th>
                                        <th>Дата создания</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customResources as $resource): ?>
                                    <tr>
                                        <td><?= $resource['id'] ?></td>
                                        <td><?= htmlspecialchars($resource['vm_name']) ?></td>
                                        <td><?= htmlspecialchars($resource['user_fullname']) ?></td>
                                        <td><?= $resource['cpu'] ?> ядер</td>
                                        <td><?= $resource['ram'] ?> MB</td>
                                        <td><?= $resource['disk'] ?> GB</td>
                                        <td class="price-tooltip">
                                            <span class="price-summary">
                                                <?= number_format($resource['price_per_hour_cpu'], 6) ?> / <?= number_format($resource['price_per_hour_ram'], 6) ?> / <?= number_format($resource['price_per_hour_disk'], 6) ?>
                                            </span>
                                            <div class="tooltip-content">
                                                CPU: <?= number_format($resource['price_per_hour_cpu'], 6) ?> руб./час<br>
                                                RAM: <?= number_format($resource['price_per_hour_ram'], 6) ?> руб./час<br>
                                                Disk: <?= number_format($resource['price_per_hour_disk'], 6) ?> руб./час
                                            </div>
                                        </td>
                                        <td><?= number_format($resource['total_per_hour'], 6) ?> руб.</td>
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
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-info-circle"></i> Нет созданных кастомных конфигураций
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Тарифы -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-tags"></i> Управление тарифами
                </h2>
                
                <!-- Форма добавления тарифа -->
                <div class="form-section">
                    <h3 class="form-section-title">Добавить новый тариф</h3>
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
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
                            
                            <div class="form-group">
                                <label class="form-label">Цена за 1 vCPU в час </label>
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
                            
                            <div class="form-group">
                                <label class="form-label">Тип ОС</label>
                                <select name="os_type" class="form-input">
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
                                <textarea name="description" class="form-input" rows="1"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Кастомный тариф (в час)</label>
                                <label class="checkbox-container">
                                    <input type="checkbox" name="is_custom" id="isCustomCheckbox" onchange="toggleCustomPrices()">
                                    <span class="checkmark"></span>
                                    Выбрать
                                </label>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Популярный тариф</label>
                                <label class="checkbox-container">
                                    <input type="checkbox" name="is_popular">
                                    <span class="checkmark"></span>
                                    Выбрать
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Активный тариф</label>
                                <label class="checkbox-container">
                                    <input type="checkbox" name="is_active" checked>
                                    <span class="checkmark"></span>
                                    Выбрать
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="add_tariff" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Добавить тариф
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Список тарифов -->
                <div class="table-section">
                    <h3 class="form-section-title">Список тарифов</h3>
                    
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
                                        <th>Тип тарифа</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tariffs as $tariff): ?>
                                    <tr>
                                        <td><?= $tariff['id'] ?></td>
                                        <td><?= htmlspecialchars($tariff['name']) ?></td>
                                        <td><?= $tariff['vm_type'] == 'qemu' ? 'QEMU' : 'LXC' ?></td>
                                        <td><?= $tariff['cpu'] ?> ядер</td>
                                        <td><?= $tariff['ram'] ?> MB</td>
                                        <td><?= $tariff['disk'] ?> GB</td>
                                        <td class="price-tooltip">
                                            <?= number_format($tariff['price'], 2) ?> руб.
                                            <?php if ($tariff['is_custom']): ?>
                                                <div class="price-summary">
                                                    <small>CPU: <?= number_format($tariff['price_per_hour_cpu'], 4) ?>/час</small>
                                                </div>
                                                <div class="tooltip-content">
                                                    CPU: <?= number_format($tariff['price_per_hour_cpu'], 4) ?> руб./час<br>
                                                    RAM: <?= number_format($tariff['price_per_hour_ram'], 6) ?> руб./час<br>
                                                    Disk: <?= number_format($tariff['price_per_hour_disk'], 6) ?> руб./час
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $tariff['os_type'] ? htmlspecialchars($tariff['os_type']) : 'Ручной выбор' ?></td>
                                        <td><?= $tariff['is_custom'] ? 'Кастомный' : 'Обычный' ?></td>
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
                        <div class="no-data">
                            <i class="fas fa-info-circle"></i> Нет созданных тарифов
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Возможности -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-star"></i> Управление возможностями
                </h2>
                
                <!-- Форма добавления возможности -->
                <div class="form-section">
                    <h3 class="form-section-title">Добавить новую возможность</h3>
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Заголовок*</label>
                                <input type="text" name="title" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Описание*</label>
                                <textarea name="description" class="form-input" rows="1" required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Иконка (Font Awesome)*</label>
                                <input type="text" name="icon" class="form-input" placeholder="fas fa-icon" required>
                                <small class="form-hint">Например: fas fa-rocket, fas fa-shield-alt</small>
                            </div>
                            
                            <div class="form-group">
                            <label class="form-label">Активная возможность</label>
                                <label class="checkbox-container">
                                    <input type="checkbox" name="is_active" checked>
                                    <span class="checkmark"></span>
                                    Выбрать
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="add_feature" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Добавить возможность
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Список возможностей -->
                <div class="table-section">
                    <h3 class="form-section-title">Список возможностей</h3>
                    
                    <?php if (!empty($features)): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Заголовок</th>
                                        <th>Иконка</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($features as $feature): ?>
                                    <tr>
                                        <td><?= $feature['id'] ?></td>
                                        <td><?= htmlspecialchars($feature['title']) ?></td>
                                        <td><i class="<?= htmlspecialchars($feature['icon']) ?>"></i> <?= htmlspecialchars($feature['icon']) ?></td>
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
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-info-circle"></i> Нет созданных возможностей
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Акции -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-percentage"></i> Управление акциями
                </h2>
                
                <!-- Форма добавления акции -->
                <div class="form-section">
                    <h3 class="form-section-title">Добавить новую акцию</h3>
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Заголовок*</label>
                                <input type="text" name="title" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Описание*</label>
                                <textarea name="description" class="form-input" rows="1" required></textarea>
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
                            
                            <div class="form-group">
                            <label class="form-label">Активная акция</label>
                                <label class="checkbox-container">
                                    <input type="checkbox" name="is_active" checked>
                                    <span class="checkmark"></span>
                                    Выбрать
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="add_promotion" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Добавить акцию
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Список акций -->
                <div class="table-section">
                    <h3 class="form-section-title">Список акций</h3>
                    
                    <?php if (!empty($promotions)): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Заголовок</th>
                                        <th>Даты</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($promotions as $promo): ?>
                                    <tr>
                                        <td><?= $promo['id'] ?></td>
                                        <td><?= htmlspecialchars($promo['title']) ?></td>
                                        <td>
                                            <?= date('d.m.Y', strtotime($promo['start_date'])) ?> - 
                                            <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
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
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-info-circle"></i> Нет созданных акций
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Модальные окна редактирования -->
            <!-- Модальное окно редактирования тарифа -->
            <div id="editTariffModal" class="modal-overlay">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3 class="modal-title">Редактировать тариф</h3>
                        <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <form method="POST" action="settings.php" id="editTariffForm">
                        <input type="hidden" name="id" id="editTariffId">
                        <input type="hidden" name="update_tariff" value="1">
                        
                        <div class="modal-body">
                            <div class="form-grid">
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Название тарифа*</label>
                                    <input type="text" name="name" id="editTariffName" class="form-input" required>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Тип виртуальной машины*</label>
                                    <select name="vm_type" id="editTariffVmType" class="form-input" required>
                                        <option value="qemu">QEMU (KVM)</option>
                                        <option value="lxc">LXC (Контейнер)</option>
                                    </select>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Ядра CPU*</label>
                                    <input type="number" name="cpu" id="editTariffCpu" min="1" max="32" class="form-input" required>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">RAM (MB)*</label>
                                    <input type="number" name="ram" id="editTariffRam" min="512" max="65536" step="512" class="form-input" required>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Диск (GB)*</label>
                                    <input type="number" name="disk" id="editTariffDisk" min="10" max="2048" class="form-input" required>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Цена (руб./месяц)*</label>
                                    <input type="number" name="price" id="editTariffPrice" min="0.01" step="0.01" class="form-input" required>
                                </div>

                                <div class="form-group price-edit-group">
                                    <label class="form-label">Цена за 1 vCPU в час</label>
                                    <input type="number" name="price_per_hour_cpu" id="editTariffPricePerHourCpu" 
                                           min="0.0000" step="0.0001" class="form-input" value="0.0000">
                                </div>

                                <div class="form-group price-edit-group">
                                    <label class="form-label">Цена за 1 MB RAM в час</label>
                                    <input type="number" name="price_per_hour_ram" id="editTariffPricePerHourRam" 
                                           min="0.000000" step="0.000001" class="form-input" value="0.000000">
                                </div>

                                <div class="form-group price-edit-group">
                                    <label class="form-label">Цена за 1 ГБ Диска в час</label>
                                    <input type="number" name="price_per_hour_disk" id="editTariffPricePerHourDisk" 
                                           min="0.000000" step="0.000001" class="form-input" value="0.000000">
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Тип ОС</label>
                                    <select name="os_type" id="editTariffOsType" class="form-input">
                                        <option value="">Выбрать вручную</option>
                                        <option value="linux">Linux</option>
                                        <option value="windows">Windows</option>
                                    </select>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Трафик</label>
                                    <input type="text" name="traffic" id="editTariffTraffic" class="form-input">
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Бэкапы</label>
                                    <input type="text" name="backups" id="editTariffBackups" class="form-input">
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Поддержка</label>
                                    <input type="text" name="support" id="editTariffSupport" class="form-input">
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Описание</label>
                                    <textarea name="description" id="editTariffDescription" class="form-input" rows="1"></textarea>
                                </div>

                                <div class="form-group price-edit-group">
                                    <label class="form-label">Кастомный тариф</label>
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="is_custom" id="editTariffIsCustom" onchange="toggleCustomPricesEdit()">
                                        <span class="checkmark"></span>
                                        Выбрать
                                    </label>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Популярный тариф</label>
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="is_popular" id="editTariffPopular">
                                        <span class="checkmark"></span>
                                        Выбрать
                                    </label>
                                </div>
                                
                                <div class="form-group price-edit-group">
                                    <label class="form-label">Активный тариф</label>
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="is_active" id="editTariffActive">
                                        <span class="checkmark"></span>
                                        Выбрать
                                    </label>
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
                        <h3 class="modal-title">Редактировать возможность</h3>
                        <button class="modal-close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <form method="POST" id="editFeatureForm">
                        <input type="hidden" name="id" id="editFeatureId">
                        <input type="hidden" name="update_feature">
                        
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
                                
                                <div class="form-group">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="is_active" id="editFeatureActive">
                                        <span class="checkmark"></span>
                                        Активная возможность
                                    </label>
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
                        <h3 class="modal-title">Редактировать акцию</h3>
                        <button class="modal-close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <form method="POST" id="editPromotionForm">
                        <input type="hidden" name="id" id="editPromotionId">
                        <input type="hidden" name="update_promotion">
                        
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
                                
                                <div class="form-group">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="is_active" id="editPromotionActive">
                                        <span class="checkmark"></span>
                                        Активная акция
                                    </label>
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
                        <h3 class="modal-title">Редактировать цены на кастомные ресурсы</h3>
                        <button class="modal-close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <form method="POST" id="editCustomForm">
                        <input type="hidden" name="id" id="editCustomId">
                        <input type="hidden" name="update_custom_prices">
                        
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
        </main>
    </div>
</div>

<script>
// Открытие модального окна
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
    document.getElementById('editTariffModal').style.display = 'none';
    document.getElementById('editFeatureModal').style.display = 'none';
    document.getElementById('editPromotionModal').style.display = 'none';
    document.getElementById('editCustomModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Переключение отображения полей кастомных цен при создании
function toggleCustomPrices() {
    const isCustom = document.getElementById('isCustomCheckbox').checked;
    const customPriceGroups = document.querySelectorAll('.custom-price-group');
    
    customPriceGroups.forEach(group => {
        group.style.display = isCustom ? 'block' : 'none';
    });
}

// Переключение отображения полей кастомных цен при редактировании
function toggleCustomPricesEdit() {
    const isCustom = document.getElementById('editTariffIsCustom').checked;
    const customPriceGroups = document.querySelectorAll('.custom-price-edit-group');
    
    customPriceGroups.forEach(group => {
        group.style.display = isCustom ? 'block' : 'none';
    });
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
</script>

<style>
    <?php include '../admin/css/settings_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>