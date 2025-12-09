<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

session_start();
checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Получаем текущие цены на ресурсы
$resourcePrices = $pdo->query("SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1")->fetch();
if (!$resourcePrices) {
    $resourcePrices = [
        'price_per_hour_cpu' => 0.001000,
        'price_per_hour_ram' => 0.000010,
        'price_per_hour_disk' => 0.000050,
        'price_per_hour_lxc_cpu' => 0.000800,
        'price_per_hour_lxc_ram' => 0.000008,
        'price_per_hour_lxc_disk' => 0.000030
    ];
}

// Получаем квоты пользователя
$quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
if (!$quota) {
    $pdo->exec("INSERT INTO user_quotas (user_id) VALUES ($user_id)");
    $quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
}

// Получаем текущее использование ресурсов
$usage = $pdo->query("
    SELECT 
        COUNT(*) as vm_count,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram,
        SUM(disk) as total_disk
    FROM vms 
    WHERE user_id = $user_id AND status != 'deleted'
")->fetch();

// Проверяем превышение квот
$quota_exceeded = false;
$quota_errors = [];
if ($usage['vm_count'] >= $quota['max_vms']) {
    $quota_errors[] = "Превышено максимальное количество виртуальных машин ({$quota['max_vms']})";
    $quota_exceeded = true;
}
if ($usage['total_cpu'] > $quota['max_cpu']) {
    $quota_errors[] = "Превышена квота CPU (используется {$usage['total_cpu']} из {$quota['max_cpu']})";
    $quota_exceeded = true;
}
if ($usage['total_ram'] > $quota['max_ram']) {
    $quota_errors[] = "Превышена квота RAM (используется {$usage['total_ram']}MB из {$quota['max_ram']}MB)";
    $quota_exceeded = true;
}
if ($usage['total_disk'] > $quota['max_disk']) {
    $quota_errors[] = "Превышена квота дискового пространства (используется {$usage['total_disk']}GB из {$quota['max_disk']}GB)";
    $quota_exceeded = true;
}

// Получаем список нод и тарифов
$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name 
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    WHERE n.is_active = 1 AND n.available_for_users = 1
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vmType = $_POST['vm_type'] ?? 'qemu'; // 'qemu' или 'lxc'

// Получаем только тарифы для выбранного типа VM
$regular_tariffs = $pdo->query("SELECT * FROM tariffs WHERE is_active = 1 AND is_custom = 0 AND vm_type = '$vmType' ORDER BY price")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$custom_tariffs = $pdo->query("SELECT t.* FROM tariffs t JOIN vms v ON t.id = v.tariff_id WHERE t.is_active = 1 AND t.is_custom = 1 AND t.vm_type = '$vmType' AND v.user_id = $user_id ORDER BY t.created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$errors = [];
$success = false;
$vncUrl = '';
$creationTime = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $creationStart = time();
    
    try {
        $vmType = $_POST['vm_type'] ?? 'qemu';
        $nodeId = (int)($_POST['node_id'] ?? 0);
        $hostname = trim($_POST['hostname'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $network = $_POST['network'] ?? 'vmbr0';
        $sdn = $_POST['sdn'] ?? '';
        $storage = $_POST['storage'] ?? '';
        $template = $_POST['template'] ?? '';
        $osType = $_POST['os_type'] ?? 'linux';
        $osVersion = $vmType === 'qemu' ? ($_POST['os_version'] ?? '') : 'linux';
        
        $custom_cpu = (int)($_POST['custom_cpu'] ?? 0);
        $custom_ram = (int)($_POST['custom_ram'] ?? 0);
        $custom_disk = (int)($_POST['custom_disk'] ?? 0);
        $is_custom = isset($_POST['is_custom']) && $_POST['is_custom'] === '1';
        
        if (!$nodeId) throw new Exception('Не выбрана нода для размещения');
        if (empty($hostname)) throw new Exception('Укажите имя хоста');
        if (empty($password)) throw new Exception('Необходимо задать пароль администратора');
        if ($password !== $confirmPassword) throw new Exception('Пароли не совпадают');
        if (empty($storage)) throw new Exception('Не выбрано хранилище');
        if ($vmType === 'qemu' && empty($osVersion)) throw new Exception('Необходимо указать версию ОС');
        if ($vmType === 'lxc' && empty($template)) throw new Exception('Необходимо выбрать шаблон LXC');
        if (!preg_match('/^[a-z0-9\-]+$/', $hostname)) {
            throw new Exception('Имя хоста может содержать только латинские буквы (a-z), цифры (0-9) и дефисы');
        }

        $stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch();
        if (!$node) throw new Exception('Выбранная нода не найдена');
        
        $user = $pdo->query("SELECT balance, bonus_balance FROM users WHERE id = $user_id")->fetch();
        if (!$user) throw new Exception('Ошибка получения данных пользователя');
        
        $tariff = null;
        $tariffId = null;
        
        if (!$is_custom) {
            $tariffId = (int)($_POST['tariff_id'] ?? 0);
            if (!$tariffId) throw new Exception('Не выбран тарифный план');
            
            $stmt = $pdo->prepare("SELECT * FROM tariffs WHERE id = ?");
            $stmt->execute([$tariffId]);
            $tariff = $stmt->fetch();
            if (!$tariff) throw new Exception('Выбранный тарифный план не найден');
            
            $total_cpu = $tariff['cpu'];
            $total_ram = $tariff['ram'];
            $total_disk = $tariff['disk'];
            
            $daily_cost = $tariff['price'] / 30;
            if (($user['balance'] + $user['bonus_balance']) < $daily_cost) {
                throw new Exception("Недостаточно средств на балансе для создания. Требуется минимум: " . number_format($daily_cost, 6) . " ₽");
            }
        } else {
            if ($custom_cpu < 1 || $custom_cpu > 8) throw new Exception('Количество CPU должно быть от 1 до 8');
            if ($custom_ram < 512 || $custom_ram > 16384) throw new Exception('RAM должна быть от 512MB до 16GB');
            if ($custom_disk < 1 || $custom_disk > 100) throw new Exception('Диск должен быть от 1GB до 100GB');
            
            $total_cpu = $custom_cpu;
            $total_ram = $custom_ram;
            $total_disk = $custom_disk;
            
            $price_per_hour_cpu = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_cpu'] : $resourcePrices['price_per_hour_cpu'];
            $price_per_hour_ram = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_ram'] : $resourcePrices['price_per_hour_ram'];
            $price_per_hour_disk = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_disk'] : $resourcePrices['price_per_hour_disk'];
            
            $hourly_cost = ($custom_cpu * $price_per_hour_cpu) +
                          ($custom_ram * $price_per_hour_ram) +
                          ($custom_disk * $price_per_hour_disk);
            
            if (($user['balance'] + $user['bonus_balance']) < $hourly_cost) {
                throw new Exception("Недостаточно средств на балансе для создания. Требуется минимум: " . number_format($hourly_cost, 6) . " ₽");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tariffs 
                (name, cpu, ram, disk, price, is_active, is_custom, created_at, vm_type) 
                VALUES 
                (:name, :cpu, :ram, :disk, :price, 1, 1, NOW(), :vm_type)
            ");
            
            $monthlyPrice = round($hourly_cost * 24 * 30, 2);
            
            $stmt->execute([
                ':name' => "Свой",
                ':cpu' => $custom_cpu,
                ':ram' => $custom_ram,
                ':disk' => $custom_disk,
                ':price' => $monthlyPrice,
                ':vm_type' => $vmType
            ]);
            
            $tariffId = $pdo->lastInsertId();
        }

        $quota_errors = [];
        if ($usage['vm_count'] >= $quota['max_vms']) {
            $quota_errors[] = "Превышено максимальное количество виртуальных машин ({$quota['max_vms']})";
        }
        
        if (($usage['total_cpu'] + $total_cpu) > $quota['max_cpu']) {
            $quota_errors[] = "Превышена квота CPU (используется {$usage['total_cpu']} из {$quota['max_cpu']})";
        }
        if (($usage['total_ram'] + $total_ram) > $quota['max_ram']) {
            $quota_errors[] = "Превышена квота RAM (используется {$usage['total_ram']}MB из {$quota['max_ram']}MB)";
        }
        if (($usage['total_disk'] + $total_disk) > $quota['max_disk']) {
            $quota_errors[] = "Превышена квота дискового пространства (используется {$usage['total_disk']}GB из {$quota['max_disk']}GB)";
        }
        
        if (!empty($quota_errors)) {
            $quota_message = "Превышены квоты вашего аккаунта:<br><br><ul>";
            foreach ($quota_errors as $error) {
                $quota_message .= "<li>{$error}</li>";
            }
            $quota_message .= "</ul><br>Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.";
            throw new Exception($quota_message);
        }

        $proxmoxApi = new ProxmoxAPI(
            $node['hostname'],
            $node['username'],
            $node['password'],
            $node['ssh_port'] ?? 22,
            $node['node_name'],
            $node['id'],
            $pdo
        );
        
        $vmParams = [
            'vm_type' => $vmType,
            'hostname' => $hostname,
            'password' => $password,
            'cpu' => $is_custom ? $custom_cpu : $tariff['cpu'],
            'ram' => $is_custom ? $custom_ram : $tariff['ram'],
            'disk' => $is_custom ? $custom_disk : $tariff['disk'],
            'network' => $network,
            'sdn' => $sdn,
            'storage' => $storage,
            'template' => $template,
            'os_type' => $osType,
            'os_version' => $osVersion,
            'user_id' => $user_id,
            'tariff_id' => $tariffId,
            'is_custom' => $is_custom ? 1 : 0
        ];
        
        if ($vmType === 'qemu') {
            $iso = $_POST['iso'] ?? '';
            $vmParams['iso'] = $iso;
            
            // Добавляем virtio-win.iso для Windows VM
            $cdroms = [];
            if (!empty($iso)) {
                $cdroms[] = $iso;
            }
            if ($osType === 'windows') {
                array_unshift($cdroms, 'virtio-win.iso');
            }
            $vmParams['cdroms'] = $cdroms;
            
            $creationResult = $proxmoxApi->createVM($vmParams);
            $vncUrl = "https://{$node['hostname']}:8006/?console=kvm&novnc=1&vmid={$creationResult['vmid']}&vmname=" . 
                     urlencode($hostname) . "&node={$node['node_name']}&resize=scale&cmd=";
        } else {
            $creationResult = $proxmoxApi->createLXC($vmParams);
            // Добавляем VNC консоль и для LXC
            $vncUrl = "https://{$node['hostname']}:8006/?console=lxc&novnc=1&vmid={$creationResult['vmid']}&vmname=" . 
                     urlencode($hostname) . "&node={$node['node_name']}&resize=scale&cmd=";
        }
        
        $vmId = $creationResult['vmid'];
        
        $success = true;
        $successMessage = $vmType === 'qemu' 
            ? "Виртуальная машина #{$vmId} успешно создана на ноде {$node['node_name']}!" 
            : "LXC контейнер #{$vmId} успешно создан на ноде {$node['node_name']}!";
        $creationTime = time() - $creationStart;
        
    } catch (Exception $e) {
        if (isset($tariffId) && $is_custom) {
            $pdo->exec("DELETE FROM tariffs WHERE id = $tariffId");
        }
        
        error_log("[Creation Error] " . date('Y-m-d H:i:s') . " - " . $e->getMessage());
        $errors[] = $e->getMessage();
    }
}

$title = "Заказ виртуальной машины | HomeVlad Cloud";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/order_vm_styles.css'; ?>
        <?php include '../css/header_styles.css'; ?>
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <main class="admin-main">
                <div class="admin-header-container">
                    <h1 class="admin-title">
                        <i class="fas fa-plus-circle"></i> Заказ виртуальной машины
                    </h1>
                </div>
                
                <?php if ($quota_exceeded): ?>
                    <div class="quota-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="quota-warning-content">
                            <h3>Превышены квоты вашего аккаунта</h3>
                            <ul>
                                <?php foreach ($quota_errors as $error): ?>
                                    <li><?= $error ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p>Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Ошибка при создании:</strong>
                        <?php foreach ($errors as $error): ?>
                            <p><?= $error ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success fade-in">
                        <i class="fas fa-check-circle"></i>
                        <strong><?= htmlspecialchars($successMessage) ?></strong>
                        
                        <div class="creation-status-wrapper">
                            <?php if (!empty($vncUrl)): ?>
                                <div class="vnc-section">
                                    <a href="<?= $vncUrl ?>" target="_blank" class="vnc-button">
                                        <i class="fas fa-desktop"></i> Открыть VNC консоль
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <div class="timer-section">
                                <div class="status-ready">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Готова к работе!</span>
                                </div>
                                <div class="timer-card">
                                    <i class="fas fa-stopwatch timer-icon"></i>
                                    <span class="timer-value">
                                        <?= sprintf("%02d:%02d:%02d", 
                                            floor($creationTime / 3600), 
                                            floor(($creationTime % 3600) / 60), 
                                            $creationTime % 60) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div id="creation-progress" style="display: none;">
                    <div class="alert alert-info">
                        <i class="fas fa-cog fa-spin"></i>
                        <strong>Идет создание...</strong>
                        <div class="progress-bar">
                            <div class="progress-bar-inner"></div>
                        </div>
                        <div style="margin-top: 15px; text-align: center;">
                            <div class="timer-card" style="display: inline-flex;">
                                <i class="fas fa-stopwatch timer-icon"></i>
                                <span class="timer-value" id="live-timer">00:00:00</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <form method="POST" class="order-form" id="vm-order-form">
                    <input type="hidden" name="vm_type" id="vm-type" value="<?= $vmType ?>">
                    
                    <!-- 0. Выбор типа виртуальной машины -->
                    <div class="form-section">
                        <h3><i class="fas fa-server"></i> 0. Выберите тип виртуальной машины</h3>
                        <div class="vm-type-selector">
                            <button type="button" class="vm-type-btn <?= $vmType === 'qemu' ? 'active' : '' ?>" data-vm-type="qemu">
                                <i class="fas fa-desktop"></i> Виртуальная машина (KVM)
                            </button>
                            <button type="button" class="vm-type-btn <?= $vmType === 'lxc' ? 'active' : '' ?>" data-vm-type="lxc">
                                <i class="fas fa-box"></i> Контейнер (LXC)
                            </button>
                        </div>
                    </div>
                    
                    <!-- 1. Выбор типа тарифа -->
                    <div class="form-section">
                        <h3><i class="fas fa-tags"></i> 1. Выберите тип тарифного плана</h3>
                        
                        <div class="tariff-type-selector">
                            <button type="button" class="tariff-type-btn active" data-tariff-type="regular">
                                <i class="fas fa-box"></i> Готовый тариф
                            </button>
                            <button type="button" class="tariff-type-btn" data-tariff-type="custom">
                                <i class="fas fa-sliders-h"></i> Кастомный тариф
                            </button>
                        </div>
                        
                        <input type="hidden" name="is_custom" id="is-custom" value="0">
                        
                        <!-- Готовые тарифы -->
                        <div class="tariff-section active" id="regular-tariffs">
                            <div class="tariffs-grid">
                                <?php foreach ($regular_tariffs as $tariff): ?>
                                    <div class="tariff-card">
                                        <input type="radio" name="tariff_id" id="tariff_<?= $tariff['id'] ?>" 
                                               value="<?= $tariff['id'] ?>"
                                               <?= ($tariff['id'] == ($_POST['tariff_id'] ?? 0)) ? 'checked' : '' ?>
                                               data-os-type="<?= htmlspecialchars($tariff['os_type'] ?? '') ?>"
                                               data-cpu="<?= $tariff['cpu'] ?>"
                                               data-ram="<?= $tariff['ram'] ?>"
                                               data-disk="<?= $tariff['disk'] ?>">
                                        <label for="tariff_<?= $tariff['id'] ?>">
                                            <div class="tariff-name"><?= htmlspecialchars($tariff['name']) ?></div>
                                            <div class="tariff-specs">
                                                <p><i class="fas fa-microchip"></i> <?= $tariff['cpu'] ?> vCPU</p>
                                                <p><i class="fas fa-memory"></i> <?= $tariff['ram'] ?> MB RAM</p>
                                                <p><i class="fas fa-hdd"></i> <?= $tariff['disk'] ?> GB SSD</p>
                                                <?php if (!empty($tariff['ipv4'])): ?>
                                                    <p><i class="fas fa-network-wired"></i> <?= $tariff['ipv4'] ?> IPv4</p>
                                                <?php endif; ?>
                                                <?php if (!empty($tariff['backup'])): ?>
                                                    <p><i class="fas fa-cloud-upload-alt"></i> <?= $tariff['backup'] ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($tariff['os_type'])): ?>
                                                    <p><i class="fas fa-desktop"></i> OS: <?= strtoupper($tariff['os_type']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="tariff-price">
                                                <?= number_format($tariff['price'], 2) ?> ₽/мес
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($regular_tariffs)): ?>
                                    <div class="no-tariffs">
                                        <i class="fas fa-info-circle"></i> Нет доступных готовых тарифов для выбранного типа
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Кастомные тарифы -->
                        <div class="tariff-section" id="custom-tariffs">
                            <?php if (!empty($custom_tariffs)): ?>
                                <div class="tariffs-grid" style="margin-bottom: 20px;">
                                    <?php foreach ($custom_tariffs as $tariff): ?>
                                        <div class="tariff-card">
                                            <input type="radio" name="tariff_id" id="custom_tariff_<?= $tariff['id'] ?>" 
                                                   value="<?= $tariff['id'] ?>"
                                                   <?= ($tariff['id'] == ($_POST['tariff_id'] ?? 0)) ? 'checked' : '' ?>
                                                   data-os-type="<?= htmlspecialchars($tariff['os_type'] ?? '') ?>"
                                                   data-cpu="<?= $tariff['cpu'] ?>"
                                                   data-ram="<?= $tariff['ram'] ?>"
                                                   data-disk="<?= $tariff['disk'] ?>">
                                            <label for="custom_tariff_<?= $tariff['id'] ?>">
                                                <div class="tariff-name"><?= htmlspecialchars($tariff['name']) ?></div>
                                                <div class="tariff-specs">
                                                    <p><i class="fas fa-microchip"></i> <?= $tariff['cpu'] ?> vCPU</p>
                                                    <p><i class="fas fa-memory"></i> <?= $tariff['ram'] ?> MB RAM</p>
                                                    <p><i class="fas fa-hdd"></i> <?= $tariff['disk'] ?> GB SSD</p>
                                                    <?php if (!empty($tariff['os_type'])): ?>
                                                        <p><i class="fas fa-desktop"></i> OS: <?= strtoupper($tariff['os_type']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="tariff-price">
                                                    <?= number_format($tariff['price'], 2) ?> ₽/мес
                                                </div>
                                                <div class="tariff-date">
                                                    <small><i class="far fa-clock"></i> <?= date('d.m.Y', strtotime($tariff['created_at'])) ?></small>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-center" style="margin-bottom: 20px;">
                                    <strong>ИЛИ</strong>
                                </div>
                            <?php endif; ?>
                            
                            <div class="custom-configurator">
                                <h4><i class="fas fa-sliders-h"></i> Соберите свой тариф</h4>
                                
                                <div class="custom-config-row">
                                    <div class="custom-config-group">
                                        <label for="custom_cpu">Процессор (ядра)</label>
                                        <input type="range" id="custom_cpu" name="custom_cpu" min="1" max="8" step="1" 
                                               value="<?= $_POST['custom_cpu'] ?? 2 ?>">
                                        <div class="custom-config-value" id="custom_cpu_value"><?= $_POST['custom_cpu'] ?? 2 ?> ядер</div>
                                    </div>
                                    
                                    <div class="custom-config-group">
                                        <label for="custom_ram">Оперативная память (MB)</label>
                                        <input type="range" id="custom_ram" name="custom_ram" min="512" max="32768" step="512" 
                                               value="<?= $_POST['custom_ram'] ?? 2048 ?>">
                                        <div class="custom-config-value" id="custom_ram_value"><?= $_POST['custom_ram'] ?? 2048 ?> MB</div>
                                    </div>
                                    
                                    <div class="custom-config-group">
                                        <label for="custom_disk">Дисковое пространство (GB)</label>
                                        <input type="range" id="custom_disk" name="custom_disk" min="1" max="300" step="1" 
                                               value="<?= $_POST['custom_disk'] ?? 20 ?>">
                                        <div class="custom-config-value" id="custom_disk_value"><?= $_POST['custom_disk'] ?? 20 ?> GB</div>
                                    </div>
                                </div>
                                
                                <div class="custom-price-summary">
                                    <h4><i class="fas fa-calculator"></i> Расчет стоимости</h4>
                                    
                                    <?php 
                                        $price_cpu = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_cpu'] : $resourcePrices['price_per_hour_cpu'];
                                        $price_ram = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_ram'] : $resourcePrices['price_per_hour_ram'];
                                        $price_disk = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_disk'] : $resourcePrices['price_per_hour_disk'];
                                    ?>
                                    
                                    <div class="custom-price-item">
                                        <span>CPU (<?= $_POST['custom_cpu'] ?? 2 ?> ядер × <?= number_format($price_cpu, 6) ?> ₽/час)</span>
                                        <span><?= number_format(($_POST['custom_cpu'] ?? 2) * $price_cpu, 6) ?> ₽/час</span>
                                    </div>
                                    
                                    <div class="custom-price-item">
                                        <span>RAM (<?= $_POST['custom_ram'] ?? 2048 ?> MB × <?= number_format($price_ram, 6) ?> ₽/час)</span>
                                        <span><?= number_format(($_POST['custom_ram'] ?? 2048) * $price_ram, 6) ?> ₽/час</span>
                                    </div>
                                    
                                    <div class="custom-price-item">
                                        <span>Диск (<?= $_POST['custom_disk'] ?? 20 ?> GB × <?= number_format($price_disk, 6) ?> ₽/час)</span>
                                        <span><?= number_format(($_POST['custom_disk'] ?? 20) * $price_disk, 6) ?> ₽/час</span>
                                    </div>
                                    
                                    <div class="custom-price-total">
                                        Итого: <span id="custom-total-price">
                                            <?= number_format(
                                                (($_POST['custom_cpu'] ?? 2) * $price_cpu) + 
                                                (($_POST['custom_ram'] ?? 2048) * $price_ram) + 
                                                (($_POST['custom_disk'] ?? 20) * $price_disk), 
                                            6
                                            ) ?>
                                        </span> ₽/час (~<span id="custom-month-price">
                                            <?= number_format(
                                                ((($_POST['custom_cpu'] ?? 2) * $price_cpu) + 
                                                (($_POST['custom_ram'] ?? 2048) * $price_ram) + 
                                                (($_POST['custom_disk'] ?? 20) * $price_disk)) * 24 * 30, 
                                                2
                                            ) ?>
                                        </span> ₽/мес)
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 2. Выбор ноды -->
                    <div class="form-section">
                        <h3><i class="fas fa-server"></i> 2. Выберите ноду размещения</h3>
                        <div class="form-group">
                            <label class="form-label">Сервер размещения:</label>
                            <select name="node_id" class="form-control" required id="node-select">
                                <option value="">-- Выберите ноду --</option>
                                <?php foreach ($nodes as $node): ?>
                                    <option value="<?= $node['id'] ?>" 
                                        <?= ($node['id'] == ($_POST['node_id'] ?? 0)) ? 'selected' : '' ?>
                                        data-hostname="<?= htmlspecialchars($node['hostname']) ?>">
                                        <?= htmlspecialchars($node['node_name']) ?> 
                                        (<?= htmlspecialchars($node['cluster_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Доступные ресурсы ноды отобразятся после выбора</small>
                        </div>
                        
                        <!-- Прогресс-бар загрузки данных ноды -->
                        <div id="node-loading-container" class="node-loading-container" style="display: none;">
                            <div class="node-loading-text" id="node-loading-text">Загрузка данных ноды...</div>
                            <div class="node-loading-progress" id="node-loading-progress"></div>
                        </div>
                        
                        <div id="node-resources" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">Доступные ресурсы:</label>
                                <div class="resources-info">
                                    <p><i class="fas fa-memory"></i> <span id="free-ram">0</span> GB свободной памяти</p>
                                    <p><i class="fas fa-hdd"></i> <span id="free-disk">0</span> GB свободного места</p>
                                    <p><i class="fas fa-microchip"></i> <span id="cpu-load">0</span>% загрузки CPU</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 3. Настройка сети -->
                    <div class="form-section">
                        <h3><i class="fas fa-network-wired"></i> 3. Настройка сети</h3>
                        <div class="form-group">
                            <label class="form-label">Основной сетевой интерфейс:</label>
                            <select name="network" class="form-control" required id="network-select">
                                <option value="vmbr0">vmbr0</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Дополнительная SDN сеть:</label>
                            <select name="sdn" class="form-control" id="sdn-select">
                                <option value="">-- Не использовать SDN --</option>
                            </select>
                            <small class="text-muted">SDN сети работают как обычные bridge-интерфейсы</small>
                        </div>
                    </div>
                    
                    <!-- 4. Настройка диска -->
                    <div class="form-section">
                        <h3><i class="fas fa-database"></i> 4. Настройка диска</h3>
                        <div class="form-group">
                            <label class="form-label">Хранилище:</label>
                            <select name="storage" class="form-control" required id="storage-select">
                                <option value="">-- Выберите хранилище --</option>
                            </select>
                            <small class="text-muted" id="storage-info"></small>
                        </div>
                    </div>
                    
                    <!-- 5. Установка ОС / Шаблон -->
                    <div class="form-section" id="os-section">
                        <h3><i class="fas fa-compact-disc"></i> 5. Установка ОС</h3>
                        <div class="form-group" id="iso-container" style="<?= $vmType === 'lxc' ? 'display: none;' : '' ?>">
                            <label class="form-label">ISO образ:</label>
                            <select name="iso" class="form-control" id="iso-select">
                                <option value="">-- Не подключать --</option>
                            </select>
                            <small class="text-muted">Для всех Windows VM автоматически будет подключен virtio-win.iso с драйверами</small>
                        </div>
                        
                        <div class="form-group" id="template-container" style="<?= $vmType === 'qemu' ? 'display: none;' : '' ?>">
                            <label class="form-label">Шаблон LXC:</label>
                            <select name="template" class="form-control" id="template-select" <?= $vmType === 'lxc' ? 'required' : '' ?>>
                                <option value="">-- Выберите шаблон --</option>
                            </select>
                        </div>
                        
                        <div class="form-group os-type-selector" id="os-type-container" style="<?= $vmType === 'lxc' ? 'display: none;' : '' ?>">
                            <label class="form-label">Тип операционной системы:</label>
                            <select name="os_type" class="form-control" id="os-type-select" <?= $vmType === 'qemu' ? 'required' : '' ?>>
                                <option value="linux" <?= (!isset($_POST['os_type']) || $_POST['os_type'] === 'linux') ? 'selected' : '' ?>>Linux</option>
                                <option value="windows" <?= (isset($_POST['os_type']) && $_POST['os_type'] === 'windows') ? 'selected' : '' ?>>Windows</option>
                            </select>
                            <small class="text-muted">Для некоторых тарифов тип ОС предопределен</small>
                        </div>
                        
                        <div class="form-group os-version-selector" id="os-version-container" style="<?= $vmType === 'lxc' ? 'display: none;' : '' ?>">
                            <label class="form-label">Версия операционной системы:</label>
                            <select name="os_version" class="form-control" id="os-version-select" <?= $vmType === 'qemu' ? 'required' : '' ?>>
                                <option value="">-- Выберите версию ОС --</option>
                                <optgroup label="Linux" id="linux-versions">
                                    <option value="6.x" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '6.x') ? 'selected' : '' ?>>6.x - 2.6 Kernel</option>
                                    <option value="5.x" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '5.x') ? 'selected' : '' ?>>5.x</option>
                                    <option value="4.x" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '4.x') ? 'selected' : '' ?>>4.x</option>
                                </optgroup>
                                <optgroup label="Windows" id="windows-versions">
                                    <option value="11" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '11') ? 'selected' : '' ?>>Windows 11</option>
                                    <option value="2022" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '2022') ? 'selected' : '' ?>>Windows Server 2022</option>
                                    <option value="2019" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '2019') ? 'selected' : '' ?>>Windows Server 2019</option>
                                    <option value="2016" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '2016') ? 'selected' : '' ?>>Windows Server 2016</option>
                                    <option value="10" <?= (isset($_POST['os_version']) && $_POST['os_version'] === '10') ? 'selected' : '' ?>>Windows 10</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    
                    <!-- 6. Настройка VM -->
                    <div class="form-section">
                        <h3><i class="fas fa-cog"></i> 6. Настройка</h3>
                        <div class="form-group">
                            <label class="form-label">Имя хоста (hostname):</label>
                            <input type="text" name="hostname" class="form-control" 
                                   value="<?= htmlspecialchars($_POST['hostname'] ?? '') ?>" required
                                   placeholder="myvm" pattern="[a-z0-9\-]+"
                                   title="Только латинские буквы (a-z), цифры (0-9) и дефисы">
                            <div class="hostname-generator">
                                <button type="button" id="generate-hostname">
                                    <i class="fas fa-random"></i> Сгенерировать
                                </button>
                                <small>Используется для идентификации в системе</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Пароль администратора (root):</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                            <small class="text-muted">Минимум 8 символов</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Подтверждение пароля:</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">
                        <i class="fas fa-shopping-cart"></i> Создать <?= $vmType === 'qemu' ? 'виртуальную машину' : 'контейнер' ?>
                    </button>
                    
                    <div id="quota-alert" class="alert alert-warning mt-3 quota-alert" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong id="quota-error-title">Превышены квоты вашего аккаунта:</strong>
                        <ul id="quota-errors-list" class="mt-2"></ul>
                        <p class="mt-2">Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.</p>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    const submitBtn = document.getElementById('submit-btn');
    const quotaAlert = document.getElementById('quota-alert');
    const quotaErrorsList = document.getElementById('quota-errors-list');
    const quotaErrorTitle = document.getElementById('quota-error-title');
    const vmTypeInput = document.getElementById('vm-type');
    const generateHostnameBtn = document.getElementById('generate-hostname');
    const hostnameInput = document.querySelector('input[name="hostname"]');
    
    // Элементы прогресс-бара загрузки ноды
    const nodeLoadingContainer = document.getElementById('node-loading-container');
    const nodeLoadingProgress = document.getElementById('node-loading-progress');
    const nodeLoadingText = document.getElementById('node-loading-text');

    // Функция для генерации случайного имени хоста
    function generateRandomHostname() {
        const prefixes = ['vm', 'server', 'node', 'cloud', 'host', 'instance'];
        const suffixes = ['01', '02', '03', '04', '05', 'prod', 'test', 'dev'];
        
        const randomPrefix = prefixes[Math.floor(Math.random() * prefixes.length)];
        const randomSuffix = suffixes[Math.floor(Math.random() * suffixes.length)];
        const randomNum = Math.floor(Math.random() * 100);
        
        return `${randomPrefix}-${randomSuffix}-${randomNum}`;
    }

    // Генерация имени хоста при клике на кнопку
    generateHostnameBtn.addEventListener('click', function() {
        hostnameInput.value = generateRandomHostname();
    });

    // Функция для обновления прогресс-бара
    function updateNodeProgress(percent, text = '') {
        nodeLoadingProgress.style.width = percent + '%';
        if (text) nodeLoadingText.textContent = text;
    }

    // Функция для загрузки тарифов
function loadTariffs(vmType) {
    // Получаем контейнеры, убеждаясь, что они существуют
    const regularTariffsContainer = document.querySelector('#regular-tariffs .tariffs-grid');
    const customTariffsContainer = document.querySelector('#custom-tariffs .tariffs-grid');
    
    if (!regularTariffsContainer || !customTariffsContainer) {
        console.error('Контейнеры тарифов не найдены!');
        return;
    }

    fetch(`/api/get_tariffs.php?vm_type=${vmType}`)
        .then(response => {
            if (!response.ok) throw new Error('Ошибка сервера');
            return response.json();
        })
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Ошибка загрузки тарифов');
            
            // Очищаем контейнеры
            regularTariffsContainer.innerHTML = '';
            customTariffsContainer.innerHTML = '';
            
            // Обрабатываем обычные тарифы
            if (data.regular_tariffs && data.regular_tariffs.length > 0) {
                data.regular_tariffs.forEach(tariff => {
                    const price = parseFloat(tariff.price);
                    const tariffCard = document.createElement('div');
                    tariffCard.className = 'tariff-card';
                    tariffCard.innerHTML = `
                        <input type="radio" name="tariff_id" id="tariff_${tariff.id}" 
                               value="${tariff.id}"
                               data-os-type="${tariff.os_type || ''}"
                               data-cpu="${tariff.cpu}"
                               data-ram="${tariff.ram}"
                               data-disk="${tariff.disk}">
                        <label for="tariff_${tariff.id}">
                            <div class="tariff-name">${tariff.name}</div>
                            <div class="tariff-specs">
                                <p><i class="fas fa-microchip"></i> ${tariff.cpu} vCPU</p>
                                <p><i class="fas fa-memory"></i> ${tariff.ram} MB RAM</p>
                                <p><i class="fas fa-hdd"></i> ${tariff.disk} GB SSD</p>
                                ${tariff.ipv4 ? `<p><i class="fas fa-network-wired"></i> ${tariff.ipv4} IPv4</p>` : ''}
                                ${tariff.backup ? `<p><i class="fas fa-cloud-upload-alt"></i> ${tariff.backup}</p>` : ''}
                                ${tariff.os_type ? `<p><i class="fas fa-desktop"></i> OS: ${tariff.os_type.toUpperCase()}</p>` : ''}
                            </div>
                            <div class="tariff-price">
                                ${price.toFixed(2)} ₽/мес
                            </div>
                        </label>
                    `;
                    regularTariffsContainer.appendChild(tariffCard);
                });
            } else {
                regularTariffsContainer.innerHTML = `
                    <div class="no-tariffs">
                        <i class="fas fa-info-circle"></i> Нет доступных готовых тарифов
                    </div>
                `;
            }
            
            // Обрабатываем кастомные тарифы
            if (data.custom_tariffs && data.custom_tariffs.length > 0) {
                data.custom_tariffs.forEach(tariff => {
                    const price = parseFloat(tariff.price);
                    const tariffCard = document.createElement('div');
                    tariffCard.className = 'tariff-card';
                    tariffCard.innerHTML = `
                        <input type="radio" name="tariff_id" id="custom_tariff_${tariff.id}" 
                               value="${tariff.id}"
                               data-os-type="${tariff.os_type || ''}"
                               data-cpu="${tariff.cpu}"
                               data-ram="${tariff.ram}"
                               data-disk="${tariff.disk}">
                        <label for="custom_tariff_${tariff.id}">
                            <div class="tariff-name">${tariff.name}</div>
                            <div class="tariff-specs">
                                <p><i class="fas fa-microchip"></i> ${tariff.cpu} vCPU</p>
                                <p><i class="fas fa-memory"></i> ${tariff.ram} MB RAM</p>
                                <p><i class="fas fa-hdd"></i> ${tariff.disk} GB SSD</p>
                                ${tariff.os_type ? `<p><i class="fas fa-desktop"></i> OS: ${tariff.os_type.toUpperCase()}</p>` : ''}
                            </div>
                            <div class="tariff-price">
                                ${price.toFixed(2)} ₽/мес
                            </div>
                            <div class="tariff-date">
                                <small><i class="far fa-clock"></i> ${new Date(tariff.created_at).toLocaleDateString()}</small>
                            </div>
                        </label>
                    `;
                    customTariffsContainer.appendChild(tariffCard);
                });
            }
            
            updateCustomConfigValues();
            checkQuotas();
        })
        .catch(error => {
            console.error('Ошибка загрузки тарифов:', error);
            if (regularTariffsContainer) {
                regularTariffsContainer.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Ошибка загрузки тарифов: ${error.message}
                    </div>
                `;
            }
        });
}

    // Загрузка данных ноды (сетей, хранилищ, ISO)
    async function loadNodeResources(nodeId, vmType) {
        if (!nodeId) return;
        
        // Показываем контейнер загрузки
        nodeLoadingContainer.style.display = 'block';
        updateNodeProgress(10, 'Загрузка данных ноды...');
        
        try {
            const response = await fetch(`get_node_networks.php?node_id=${nodeId}&vm_type=${vmType}`);
            if (!response.ok) throw new Error('Ошибка сервера');
            
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');
            
            updateNodeProgress(30, 'Загрузка сетевых интерфейсов...');
            
            // Обновляем список сетей
            const networkSelect = document.getElementById('network-select');
            networkSelect.innerHTML = data.networks.map(net => 
                `<option value="${net}">${net}</option>`
            ).join('');
            
            // Обновляем список SDN сетей
            const sdnSelect = document.getElementById('sdn-select');
            if (sdnSelect) {
                sdnSelect.innerHTML = '<option value="">-- Не использовать SDN --</option>' + 
                    (data.sdnNetworks?.map(sdn => 
                        `<option value="${sdn.name}">${sdn.name}${sdn.alias ? ' <span class="sdn-alias">(' + sdn.alias + ')</span>' : ''}</option>`
                    ).join('') || '');
            }
            
            updateNodeProgress(50, 'Загрузка информации о ресурсах...');
            
            // Обновляем информацию о ресурсах ноды
            const nodeResources = document.getElementById('node-resources');
            nodeResources.innerHTML = `
                <div class="form-group">
                    <label class="form-label">Доступные ресурсы:</label>
                    <div class="resources-info">
                        <p><i class="fas fa-memory"></i> ${data.resources.free_memory} GB свободной памяти</p>
                        <p><i class="fas fa-hdd"></i> ${data.resources.free_disk} GB свободного места</p>
                        <p><i class="fas fa-microchip"></i> ${data.resources.cpu_usage}% загрузки CPU</p>
                    </div>
                </div>
            `;
            nodeResources.style.display = 'block';
            
            updateNodeProgress(70, 'Загрузка хранилищ...');
            
            // Обновляем список хранилищ
            const storageSelect = document.getElementById('storage-select');
            storageSelect.innerHTML = '<option value="">-- Выберите хранилище --</option>' +
                (data.storages?.map(storage => 
                    `<option value="${storage.name}">${storage.name} (${storage.type}${storage.type === 'lvmthin' ? ', LVM-Thin' : ''}, ${storage.available}GB свободно)</option>`
                ).join('') || '<option value="" disabled>Нет доступных хранилищ</option>');
            
            updateNodeProgress(90, vmType === 'qemu' ? 'Загрузка ISO образов...' : 'Загрузка шаблонов LXC...');
            
            if (vmType === 'qemu') {
                // Обновляем список ISO образов
                const isoSelect = document.getElementById('iso-select');
                isoSelect.innerHTML = '<option value="">-- Не подключать --</option>' +
                    (data.isos?.map(iso => 
                        `<option value="${iso.volid}">${iso.name} (${iso.storage})</option>`
                    ).join('') || '<option value="" disabled>Нет доступных ISO образов</option>');
                
                // Показываем секцию ISO, скрываем шаблоны
                document.getElementById('iso-container').style.display = 'block';
                document.getElementById('template-container').style.display = 'none';
                document.getElementById('os-type-container').style.display = 'block';
                document.getElementById('os-version-container').style.display = 'block';
                
                // Устанавливаем required для полей QEMU
                document.getElementById('os-type-select').required = true;
                document.getElementById('os-version-select').required = true;
                document.getElementById('template-select').required = false;
            } else {
                // Обновляем список шаблонов LXC
                const templateSelect = document.getElementById('template-select');
                templateSelect.innerHTML = '<option value="">-- Выберите шаблон --</option>' +
                    (data.templates?.map(tpl => 
                        `<option value="${tpl.volid}">${tpl.name} (${tpl.storage})</option>`
                    ).join('') || '<option value="" disabled>Нет доступных шаблонов</option>');
                
                // Показываем секцию шаблонов, скрываем ISO
                document.getElementById('iso-container').style.display = 'none';
                document.getElementById('template-container').style.display = 'block';
                document.getElementById('os-type-container').style.display = 'none';
                document.getElementById('os-version-container').style.display = 'none';
                
                // Устанавливаем required для полей LXC
                document.getElementById('template-select').required = true;
                document.getElementById('os-type-select').required = false;
                document.getElementById('os-version-select').required = false;
            }
            
            updateNodeProgress(100, 'Загрузка завершена');
            
            // Через 1 секунду скрываем прогресс-бар
            setTimeout(() => {
                nodeLoadingContainer.style.opacity = '0';
                setTimeout(() => {
                    nodeLoadingContainer.style.display = 'none';
                    nodeLoadingContainer.style.opacity = '1';
                }, 500);
            }, 1000);
            
        } catch (error) {
            console.error('Ошибка загрузки ресурсов ноды:', error);
            updateNodeProgress(100, 'Ошибка загрузки данных');
            
            const nodeResources = document.getElementById('node-resources');
            nodeResources.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    ${error.message}
                </div>
            `;
            nodeResources.style.display = 'block';
            
            document.getElementById('storage-select').innerHTML = '<option value="">Ошибка загрузки</option>';
            document.getElementById('iso-select').innerHTML = '<option value="">Ошибка загрузки</option>';
            document.getElementById('template-select').innerHTML = '<option value="">Ошибка загрузки</option>';
            
            setTimeout(() => {
                nodeLoadingContainer.style.opacity = '0';
                setTimeout(() => {
                    nodeLoadingContainer.style.display = 'none';
                    nodeLoadingContainer.style.opacity = '1';
                }, 500);
            }, 2000);
        }
    }

    // Переключение между типами виртуальных машин
    const vmTypeBtns = document.querySelectorAll('.vm-type-btn');
    vmTypeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const type = this.dataset.vmType;
            
            vmTypeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            vmTypeInput.value = type;
            
            // Обновляем текст кнопки submit
            submitBtn.innerHTML = type === 'qemu' 
                ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину' 
                : '<i class="fas fa-shopping-cart"></i> Создать контейнер';
            
            // Загружаем тарифы для выбранного типа
            loadTariffs(type);
            
            // Перезагружаем ресурсы ноды, если она уже выбрана
            const nodeSelect = document.getElementById('node-select');
            if (nodeSelect.value) {
                loadNodeResources(nodeSelect.value, type);
            }
        });
    });

    // Переключение между типами тарифов
    const tariffTypeBtns = document.querySelectorAll('.tariff-type-btn');
    const tariffSections = document.querySelectorAll('.tariff-section');
    const isCustomInput = document.getElementById('is-custom');
    
    tariffTypeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const type = this.dataset.tariffType;
            
            tariffTypeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            tariffSections.forEach(section => section.classList.remove('active'));
            document.getElementById(`${type}-tariffs`).classList.add('active');
            
            isCustomInput.value = type === 'custom' ? '1' : '0';
            
            document.querySelectorAll('input[name="tariff_id"]').forEach(radio => {
                radio.checked = false;
            });
            
            checkQuotas();
        });
    });
    
    // Обновление значений для кастомного конфигуратора
    function updateCustomConfigValues() {
        document.getElementById('custom_cpu_value').textContent = 
            document.getElementById('custom_cpu').value + ' ядер';
        
        document.getElementById('custom_ram_value').textContent = 
            document.getElementById('custom_ram').value + ' MB';
        
        document.getElementById('custom_disk_value').textContent = 
            document.getElementById('custom_disk').value + ' GB';
        
        updateCustomPrice();
    }
    
    // Обновление стоимости кастомного тарифа
    function updateCustomPrice() {
        const vmType = document.getElementById('vm-type').value;
        const cpu = parseInt(document.getElementById('custom_cpu').value);
        const ram = parseInt(document.getElementById('custom_ram').value);
        const disk = parseInt(document.getElementById('custom_disk').value);
        
        // Получаем цены для выбранного типа VM
        const cpuPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_cpu'] ?? 0.000800 ?> : <?= $resourcePrices['price_per_hour_cpu'] ?? 0.001000 ?>;
        const ramPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_ram'] ?? 0.000008 ?> : <?= $resourcePrices['price_per_hour_ram'] ?? 0.000010 ?>;
        const diskPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_disk'] ?? 0.000030 ?> : <?= $resourcePrices['price_per_hour_disk'] ?? 0.000050 ?>;
        
        const cpuCost = cpu * cpuPricePerHour;
        const ramCost = ram * ramPricePerHour;
        const diskCost = disk * diskPricePerHour;
        const totalPerHour = cpuCost + ramCost + diskCost;
        const totalPerMonth = totalPerHour * 24 * 30;
        
        document.querySelectorAll('.custom-price-item')[0].innerHTML = 
            `<span>CPU (${cpu} ядер × ${cpuPricePerHour.toFixed(6)} ₽/час)</span>
             <span>${cpuCost.toFixed(6)} ₽/час</span>`;
        
        document.querySelectorAll('.custom-price-item')[1].innerHTML = 
            `<span>RAM (${ram} MB × ${ramPricePerHour.toFixed(6)} ₽/час)</span>
             <span>${ramCost.toFixed(6)} ₽/час</span>`;
        
        document.querySelectorAll('.custom-price-item')[2].innerHTML = 
            `<span>Диск (${disk} GB × ${diskPricePerHour.toFixed(6)} ₽/час)</span>
             <span>${diskCost.toFixed(6)} ₽/час</span>`;
        
        document.getElementById('custom-total-price').textContent = totalPerHour.toFixed(6);
        document.getElementById('custom-month-price').textContent = totalPerMonth.toFixed(2);
    }
    
    // Обработчики для слайдеров кастомного тарифа
    document.getElementById('custom_cpu').addEventListener('input', updateCustomConfigValues);
    document.getElementById('custom_ram').addEventListener('input', updateCustomConfigValues);
    document.getElementById('custom_disk').addEventListener('input', updateCustomConfigValues);
    
    // Инициализация значений
    updateCustomConfigValues();
    
    // Функция для проверки квот
    function checkQuotas() {
        const isCustom = isCustomInput.value === '1';
        const vmType = document.getElementById('vm-type').value;
        let cpu, ram, disk;
        
        if (isCustom) {
            const selectedCustomTariff = document.querySelector('#custom-tariffs input[name="tariff_id"]:checked');
            
            if (selectedCustomTariff) {
                cpu = parseInt(selectedCustomTariff.dataset.cpu);
                ram = parseInt(selectedCustomTariff.dataset.ram);
                disk = parseInt(selectedCustomTariff.dataset.disk);
            } else {
                cpu = parseInt(document.getElementById('custom_cpu').value);
                ram = parseInt(document.getElementById('custom_ram').value);
                disk = parseInt(document.getElementById('custom_disk').value);
            }
        } else {
            const selectedTariff = document.querySelector('#regular-tariffs input[name="tariff_id"]:checked');
            if (!selectedTariff) {
                submitBtn.disabled = false;
                quotaAlert.style.display = 'none';
                return;
            }
            
            cpu = parseInt(selectedTariff.dataset.cpu);
            ram = parseInt(selectedTariff.dataset.ram);
            disk = parseInt(selectedTariff.dataset.disk);
        }
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка квот...';
        
        fetch('check_quotas.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `user_id=<?= $user_id ?>&cpu=${cpu}&ram=${ram}&disk=${disk}`
        })
        .then(response => {
            if (!response.ok) throw new Error('Ошибка сети');
            return response.json();
        })
        .then(data => {
            submitBtn.innerHTML = vmType === 'qemu' 
                ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину' 
                : '<i class="fas fa-shopping-cart"></i> Создать контейнер';
            
            if (data.quota_exceeded) {
                submitBtn.disabled = true;
                quotaErrorsList.innerHTML = '';
                data.errors.forEach(error => {
                    const li = document.createElement('li');
                    li.textContent = error;
                    quotaErrorsList.appendChild(li);
                });
                quotaAlert.style.display = 'block';
            } else {
                submitBtn.disabled = false;
                quotaAlert.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Ошибка проверки квот:', error);
            submitBtn.innerHTML = vmType === 'qemu' 
                ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину' 
                : '<i class="fas fa-shopping-cart"></i> Создать контейнер';
            submitBtn.disabled = false;
            quotaAlert.style.display = 'none';
        });
    }
    
    document.querySelectorAll('input[name="tariff_id"]').forEach(radio => {
        radio.addEventListener('change', checkQuotas);
    });
    
    document.getElementById('custom_cpu').addEventListener('change', checkQuotas);
    document.getElementById('custom_ram').addEventListener('change', checkQuotas);
    document.getElementById('custom_disk').addEventListener('change', checkQuotas);
    
    if (document.querySelector('input[name="tariff_id"]:checked')) {
        checkQuotas();
    }

    document.getElementById('vm-order-form').addEventListener('submit', function(e) {
        const isCustom = document.getElementById('is-custom').value === '1';
        const vmType = document.getElementById('vm-type').value;
        const nodeId = document.querySelector('select[name="node_id"]').value;
        const storage = document.querySelector('select[name="storage"]').value;
        const tariffId = !isCustom ? document.querySelector('input[name="tariff_id"]:checked')?.value : null;
        const osVersion = vmType === 'qemu' ? document.querySelector('select[name="os_version"]').value : null;
        const template = vmType === 'lxc' ? document.querySelector('select[name="template"]').value : null;
        
        if (!nodeId || (!isCustom && !tariffId) || !storage || 
            (vmType === 'qemu' && !osVersion) || 
            (vmType === 'lxc' && !template)) {
            e.preventDefault();
            alert('Пожалуйста, заполните все обязательные поля');
            return;
        }

        const btn = document.getElementById('submit-btn');
        const progressElement = document.getElementById('creation-progress');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-cog fa-spin"></i> Обработка запроса...';
        progressElement.style.display = 'block';
        
        const startTime = new Date().getTime();
        const timerElement = document.getElementById('live-timer');
        
        function updateTimer() {
            const now = new Date().getTime();
            const elapsed = Math.floor((now - startTime) / 1000);
            
            const hours = Math.floor(elapsed / 3600);
            const minutes = Math.floor((elapsed % 3600) / 60);
            const seconds = elapsed % 60;
            
            timerElement.textContent = 
                `${hours.toString().padStart(2, '0')}:` +
                `${minutes.toString().padStart(2, '0')}:` +
                `${seconds.toString().padStart(2, '0')}`;
                
            if (document.getElementById('creation-progress')) {
                requestAnimationFrame(updateTimer);
            }
        }
        
        updateTimer();
    });

    document.querySelector('select[name="node_id"]').addEventListener('change', function() {
        const nodeId = this.value;
        const vmType = document.getElementById('vm-type').value;
        
        if (!nodeId) {
            document.getElementById('node-resources').style.display = 'none';
            document.getElementById('storage-select').innerHTML = '<option value="">-- Выберите хранилище --</option>';
            document.getElementById('iso-select').innerHTML = '<option value="">-- Не подключать --</option>';
            document.getElementById('template-select').innerHTML = '<option value="">-- Выберите шаблон --</option>';
            return;
        }
        
        loadNodeResources(nodeId, vmType);
    });

    document.querySelectorAll('input[name="tariff_id"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const osType = this.dataset.osType;
            const osTypeSelect = document.getElementById('os-type-select');
            
            if (osType) {
                osTypeSelect.value = osType;
                osTypeSelect.disabled = true;
            } else {
                osTypeSelect.disabled = false;
            }
        });
    });

    // Обновление списка версий ОС при изменении типа ОС
    document.getElementById('os-type-select').addEventListener('change', function() {
        const osType = this.value;
        const linuxVersions = document.getElementById('linux-versions');
        const windowsVersions = document.getElementById('windows-versions');
        
        if (osType === 'linux') {
            linuxVersions.style.display = '';
            windowsVersions.style.display = 'none';
        } else if (osType === 'windows') {
            linuxVersions.style.display = 'none';
            windowsVersions.style.display = '';
        }
        
        // Сбрасываем выбор версии
        document.getElementById('os-version-select').value = '';
    });

    document.getElementById('sdn-select')?.addEventListener('change', function() {
        const networkSelect = document.getElementById('network-select');
        if (this.value) {
            networkSelect.disabled = true;
            networkSelect.value = 'vmbr0';
        } else {
            networkSelect.disabled = false;
        }
    });

    const menuToggle = document.createElement('div');
    menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    menuToggle.style.position = 'fixed';
    menuToggle.style.top = '15px';
    menuToggle.style.left = '15px';
    menuToggle.style.zIndex = '1000';
    menuToggle.style.fontSize = '1.5rem';
    menuToggle.style.color = 'var(--primary)';
    menuToggle.style.cursor = 'pointer';
    menuToggle.style.display = 'none';
    document.body.appendChild(menuToggle);

    function checkScreenSize() {
        if (window.innerWidth <= 992) {
            menuToggle.style.display = 'block';
            document.body.classList.add('sidebar-closed');
        } else {
            menuToggle.style.display = 'none';
            document.body.classList.remove('sidebar-closed');
        }
    }

    menuToggle.addEventListener('click', function() {
        document.body.classList.toggle('sidebar-open');
    });

    window.addEventListener('resize', checkScreenSize);
    checkScreenSize();
    
    // Генерируем случайное имя хоста при загрузке страницы
    if (!hostnameInput.value) {
        hostnameInput.value = generateRandomHostname();
    }
    
    // Загружаем тарифы при старте страницы
    loadTariffs(vmTypeInput.value);
    
    // Загружаем ресурсы ноды, если она уже выбрана
    const selectedNode = document.querySelector('select[name="node_id"]').value;
    if (selectedNode) {
        loadNodeResources(selectedNode, vmTypeInput.value);
    }
});
</script>
</body>
</html>