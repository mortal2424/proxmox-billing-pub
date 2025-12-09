<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// ВАЖНО: Убрал проверку !$quota_exceeded из условия, чтобы форма обрабатывалась всегда
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        /* Основной контейнер */
        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Заголовок страницы */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Секции формы */
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .form-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .form-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .form-section h3 {
            color: #f1f5f9;
        }

        .form-section h3 i {
            color: #00bcd4;
            font-size: 20px;
        }

        /* Выбор типа ВМ */
        .vm-type-selector {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .vm-type-btn {
            flex: 1;
            padding: 20px;
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            background: white;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        body.dark-theme .vm-type-btn {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .vm-type-btn i {
            font-size: 32px;
            color: #64748b;
        }

        body.dark-theme .vm-type-btn i {
            color: #94a3b8;
        }

        .vm-type-btn:hover {
            border-color: #00bcd4;
            color: #00bcd4;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.1);
        }

        .vm-type-btn:hover i {
            color: #00bcd4;
        }

        .vm-type-btn.active {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.05);
            color: #00bcd4;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.15);
        }

        .vm-type-btn.active i {
            color: #00bcd4;
        }

        /* Выбор типа тарифа */
        .tariff-type-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .tariff-type-btn {
            padding: 12px 24px;
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-theme .tariff-type-btn {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .tariff-type-btn:hover {
            border-color: #00bcd4;
            color: #00bcd4;
        }

        .tariff-type-btn.active {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        /* Тарифы */
        .tariff-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tariff-section.active {
            display: block;
        }

        .tariffs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .tariff-card {
            position: relative;
            border: 2px solid rgba(148, 163, 184, 0.1);
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        body.dark-theme .tariff-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .tariff-card:hover {
            border-color: #00bcd4;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.15);
        }

        .tariff-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .tariff-card input[type="radio"]:checked + label {
            color: #00bcd4;
        }

        .tariff-card input[type="radio"]:checked ~ label:before {
            content: '✓';
            position: absolute;
            top: -10px;
            right: -10px;
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .tariff-card label {
            cursor: pointer;
            display: block;
            width: 100%;
        }

        .tariff-name {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
        }

        body.dark-theme .tariff-name {
            color: #f1f5f9;
        }

        .tariff-specs p {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            margin-bottom: 8px;
            font-size: 14px;
        }

        body.dark-theme .tariff-specs p {
            color: #94a3b8;
        }

        .tariff-specs i {
            color: #00bcd4;
            width: 16px;
        }

        .tariff-price {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .tariff-price {
            color: #f1f5f9;
        }

        .tariff-date {
            margin-top: 10px;
            color: #94a3b8;
            font-size: 12px;
        }

        .no-tariffs {
            text-align: center;
            padding: 40px;
            color: #64748b;
            font-size: 16px;
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            border: 2px dashed rgba(148, 163, 184, 0.2);
        }

        body.dark-theme .no-tariffs {
            background: rgba(30, 41, 59, 0.3);
            color: #94a3b8;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .no-tariffs i {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
            color: #cbd5e1;
        }

        /* Кастомный конфигуратор */
        .custom-configurator {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .custom-configurator {
            background: rgba(30, 41, 59, 0.3);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .custom-configurator h4 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-theme .custom-configurator h4 {
            color: #f1f5f9;
        }

        .custom-config-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        @media (max-width: 768px) {
            .custom-config-row {
                grid-template-columns: 1fr;
            }
        }

        .custom-config-group {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .custom-config-group {
            background: rgba(30, 41, 59, 0.5);
        }

        .custom-config-group label {
            display: block;
            margin-bottom: 15px;
            color: #1e293b;
            font-weight: 500;
        }

        body.dark-theme .custom-config-group label {
            color: #f1f5f9;
        }

        .custom-config-group input[type="range"] {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: rgba(148, 163, 184, 0.2);
            outline: none;
            -webkit-appearance: none;
        }

        .custom-config-group input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0, 188, 212, 0.3);
        }

        .custom-config-group input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            cursor: pointer;
            border: none;
            box-shadow: 0 4px 8px rgba(0, 188, 212, 0.3);
        }

        .custom-config-value {
            margin-top: 15px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            color: #00bcd4;
            background: rgba(0, 188, 212, 0.1);
            padding: 10px;
            border-radius: 8px;
        }

        /* Расчет стоимости */
        .custom-price-summary {
            background: white;
            border-radius: 12px;
            padding: 25px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            margin-top: 25px;
        }

        body.dark-theme .custom-price-summary {
            background: rgba(30, 41, 59, 0.5);
        }

        .custom-price-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .custom-price-item:last-child {
            border-bottom: none;
        }

        .custom-price-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            margin-top: 15px;
            border-top: 2px solid rgba(148, 163, 184, 0.2);
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }

        body.dark-theme .custom-price-total {
            color: #f1f5f9;
        }

        .custom-price-total span {
            color: #00bcd4;
            font-size: 20px;
        }

        /* Форма */
        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #1e293b;
            font-weight: 500;
        }

        body.dark-theme .form-label {
            color: #f1f5f9;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #1e293b;
            font-size: 15px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-theme .form-control {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        .form-control:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        .text-muted {
            display: block;
            margin-top: 5px;
            color: #64748b;
            font-size: 13px;
        }

        body.dark-theme .text-muted {
            color: #94a3b8;
        }

        /* Генератор hostname */
        .hostname-generator {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .hostname-generator button {
            padding: 8px 16px;
            background: rgba(0, 188, 212, 0.1);
            border: 1px solid rgba(0, 188, 212, 0.2);
            border-radius: 8px;
            color: #00bcd4;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hostname-generator button:hover {
            background: rgba(0, 188, 212, 0.2);
            transform: translateY(-1px);
        }

        /* Кнопка отправки */
        .btn-submit {
            display: block;
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-submit i.fa-spin {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Уведомления */
        .notification {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid;
            animation: slideIn 0.3s ease;
        }

        .notification-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border-color: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .notification-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            border-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .notification-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            border-color: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }

        .notification i {
            font-size: 24px;
            margin-right: 12px;
        }

        .notification strong {
            font-size: 18px;
            margin-bottom: 10px;
            display: block;
        }

        /* Статус создания */
        .creation-status-wrapper {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .timer-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .status-ready {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 600;
            color: #10b981;
        }

        .timer-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.1);
            padding: 12px 20px;
            border-radius: 10px;
        }

        .timer-icon {
            color: #00bcd4;
            font-size: 18px;
        }

        .timer-value {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }

        body.dark-theme .timer-value {
            color: #f1f5f9;
        }

        /* VNC кнопка */
        .vnc-section {
            text-align: center;
            margin-bottom: 20px;
        }

        .vnc-button {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 30px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .vnc-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }

        /* Прогресс загрузки ноды */
        .node-loading-container {
            margin: 20px 0;
            padding: 20px;
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        body.dark-theme .node-loading-container {
            background: rgba(30, 41, 59, 0.3);
        }

        .node-loading-text {
            margin-bottom: 10px;
            color: #64748b;
            font-weight: 500;
        }

        body.dark-theme .node-loading-text {
            color: #94a3b8;
        }

        .node-loading-progress {
            height: 6px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }

        .node-loading-progress:after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Ресурсы ноды */
        .resources-info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .resources-info {
                grid-template-columns: 1fr;
            }
        }

        .resources-info p {
            background: rgba(248, 250, 252, 0.5);
            padding: 15px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            color: #1e293b;
        }

        body.dark-theme .resources-info p {
            background: rgba(30, 41, 59, 0.3);
            color: #f1f5f9;
        }

        .resources-info i {
            color: #00bcd4;
            font-size: 18px;
        }

        /* Предупреждение о квотах */
        .quota-alert {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .quota-alert i {
            font-size: 24px;
            color: #f59e0b;
            margin-right: 12px;
        }

        .quota-alert strong {
            display: block;
            margin-bottom: 10px;
            color: #f59e0b;
            font-size: 16px;
        }

        .quota-alert ul {
            margin: 10px 0 10px 20px;
            color: #f59e0b;
        }

        .quota-alert a {
            color: #00bcd4;
            text-decoration: none;
            font-weight: 500;
        }

        .quota-alert a:hover {
            text-decoration: underline;
        }

        /* Анимации */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Мобильное меню */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 12px;
            color: white;
            font-size: 24px;
            cursor: pointer;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: flex;
            }
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .tariffs-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .form-section {
                padding: 20px;
            }

            .page-title {
                font-size: 24px;
            }

            .tariffs-grid {
                grid-template-columns: 1fr;
            }

            .vm-type-selector {
                flex-direction: column;
            }

            .tariff-type-selector {
                flex-direction: column;
            }
        }
        /* === ОБЩИЙ ФУТЕР === */
        /* Исправляем футер для правильного отображения */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 80px 0 30px;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
            margin-top: auto;
            width: 100%;
        }

        .modern-footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .modern-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 188, 212, 0.5), transparent);
        }
    </style>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <!-- Кнопка мобильного меню -->
    <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="main-container">
        <?php include '../templates/headers/user_sidebar.php'; ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-plus-circle"></i> Заказ виртуальной машины
                </h1>
            </div>

            <!-- Предупреждение о квотах -->
            <?php if ($quota_exceeded): ?>
                <div class="notification notification-warning quota-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Превышены квоты вашего аккаунта</strong>
                    <ul>
                        <?php foreach ($quota_errors as $error): ?>
                            <li><?= $error ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top: 10px;">Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.</p>
                </div>
            <?php endif; ?>

            <!-- Сообщения об ошибках -->
            <?php if (!empty($errors)): ?>
                <div class="notification notification-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Ошибка при создании:</strong>
                    <?php foreach ($errors as $error): ?>
                        <p><?= $error ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Сообщение об успехе -->
            <?php if ($success): ?>
                <div class="notification notification-success fade-in">
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

            <!-- Прогресс создания -->
            <div id="creation-progress" class="notification" style="display: none; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                <i class="fas fa-cog fa-spin"></i>
                <strong>Идет создание...</strong>
                <div style="margin-top: 15px; text-align: center;">
                    <div class="timer-card" style="display: inline-flex;">
                        <i class="fas fa-stopwatch timer-icon"></i>
                        <span class="timer-value" id="live-timer">00:00:00</span>
                    </div>
                </div>
            </div>

            <!-- Основная форма -->
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
                            <div class="text-center" style="margin-bottom: 20px; color: #64748b; font-weight: 500;">
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

                    <div id="node-resources" style="display: none;"></div>
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
                            <small class="text-muted">Используется для идентификации в системе</small>
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

                <!-- Предупреждение о квотах -->
                <div id="quota-alert" class="quota-alert" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong id="quota-error-title">Превышены квоты вашего аккаунта:</strong>
                    <ul id="quota-errors-list" class="mt-2"></ul>
                    <p class="mt-2">Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.</p>
                </div>

                <button type="submit" class="btn-submit" id="submit-btn">
                    <i class="fas fa-shopping-cart"></i> Создать <?= $vmType === 'qemu' ? 'виртуальную машину' : 'контейнер' ?>
                </button>
            </form>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Скрипт загружен'); // Для отладки

            const submitBtn = document.getElementById('submit-btn');
            const quotaAlert = document.getElementById('quota-alert');
            const quotaErrorsList = document.getElementById('quota-errors-list');
            const vmTypeInput = document.getElementById('vm-type');
            const generateHostnameBtn = document.getElementById('generate-hostname');
            const hostnameInput = document.querySelector('input[name="hostname"]');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');

            // Элементы прогресс-бара загрузки ноды
            const nodeLoadingContainer = document.getElementById('node-loading-container');
            const nodeLoadingProgress = document.getElementById('node-loading-progress');
            const nodeLoadingText = document.getElementById('node-loading-text');

            <?php if ($quota_exceeded): ?>
                // Если превышены квоты, блокируем форму
                document.querySelectorAll('.form-control, select, input, button[type="submit"]').forEach(el => {
                    if (el.id !== 'generate-hostname' && el.id !== 'mobileMenuToggle' &&
                        !el.classList.contains('vm-type-btn') && !el.classList.contains('tariff-type-btn')) {
                        el.disabled = true;
                    }
                });
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-ban"></i> Создание невозможно (превышены квоты)';
                }
            <?php endif; ?>

            // Настройка мобильного меню
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    document.body.classList.toggle('sidebar-open');
                });

                function checkScreenSize() {
                    if (window.innerWidth <= 992) {
                        document.body.classList.add('sidebar-closed');
                    } else {
                        document.body.classList.remove('sidebar-closed', 'sidebar-open');
                    }
                }

                checkScreenSize();
                window.addEventListener('resize', checkScreenSize);
            }

            // Функция для генерации случайного имени хоста
            function generateRandomHostname() {
                const prefixes = ['vm', 'server', 'node', 'cloud', 'host', 'instance'];
                const suffixes = ['01', '02', '03', '04', '05', 'prod', 'test', 'dev'];

                const randomPrefix = prefixes[Math.floor(Math.random() * prefixes.length)];
                const randomSuffix = suffixes[Math.floor(Math.random() * suffixes.length)];
                const randomNum = Math.floor(Math.random() * 100);

                return `${randomPrefix}-${randomSuffix}-${randomNum}`;
            }

            // Генерация имени хоста
            if (generateHostnameBtn && hostnameInput) {
                generateHostnameBtn.addEventListener('click', function() {
                    hostnameInput.value = generateRandomHostname();
                });

                // Генерируем случайное имя при загрузке, если поле пустое
                if (!hostnameInput.value) {
                    hostnameInput.value = generateRandomHostname();
                }
            }

            // Функция для обновления прогресс-бара
            function updateNodeProgress(percent, text = '') {
                if (nodeLoadingProgress && nodeLoadingText) {
                    nodeLoadingProgress.style.width = percent + '%';
                    if (text) nodeLoadingText.textContent = text;
                }
            }

            // Загрузка данных ноды
            async function loadNodeResources(nodeId, vmType) {
                if (!nodeId) return;

                // Показываем контейнер загрузки
                if (nodeLoadingContainer) {
                    nodeLoadingContainer.style.display = 'block';
                }
                updateNodeProgress(10, 'Загрузка данных ноды...');

                try {
                    const response = await fetch(`get_node_networks.php?node_id=${nodeId}&vm_type=${vmType}`);
                    if (!response.ok) throw new Error('Ошибка сервера');

                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');

                    updateNodeProgress(30, 'Загрузка сетевых интерфейсов...');

                    // Обновляем список сетей
                    const networkSelect = document.getElementById('network-select');
                    if (networkSelect) {
                        networkSelect.innerHTML = data.networks.map(net =>
                            `<option value="${net}">${net}</option>`
                        ).join('');
                    }

                    // Обновляем список SDN сетей
                    const sdnSelect = document.getElementById('sdn-select');
                    if (sdnSelect) {
                        sdnSelect.innerHTML = '<option value="">-- Не использовать SDN --</option>' +
                            (data.sdnNetworks?.map(sdn =>
                                `<option value="${sdn.name}">${sdn.name}${sdn.alias ? ' (' + sdn.alias + ')' : ''}</option>`
                            ).join('') || '');
                    }

                    updateNodeProgress(50, 'Загрузка информации о ресурсах...');

                    // Обновляем информацию о ресурсах ноды
                    const nodeResources = document.getElementById('node-resources');
                    if (nodeResources) {
                        nodeResources.innerHTML = `
                            <div class="form-group">
                                <label class="form-label">Доступные ресурсы:</label>
                                <div class="resources-info">
                                    <p><i class="fas fa-memory"></i> ${data.resources?.free_memory || 0} GB свободной памяти</p>
                                    <p><i class="fas fa-hdd"></i> ${data.resources?.free_disk || 0} GB свободного места</p>
                                    <p><i class="fas fa-microchip"></i> ${data.resources?.cpu_usage || 0}% загрузки CPU</p>
                                </div>
                            </div>
                        `;
                        nodeResources.style.display = 'block';
                    }

                    updateNodeProgress(70, 'Загрузка хранилищ...');

                    // Обновляем список хранилищ
                    const storageSelect = document.getElementById('storage-select');
                    if (storageSelect) {
                        storageSelect.innerHTML = '<option value="">-- Выберите хранилище --</option>' +
                            (data.storages?.map(storage =>
                                `<option value="${storage.name}">${storage.name} (${storage.type}${storage.type === 'lvmthin' ? ', LVM-Thin' : ''}, ${storage.available || 0}GB свободно)</option>`
                            ).join('') || '<option value="" disabled>Нет доступных хранилищ</option>');
                    }

                    updateNodeProgress(90, vmType === 'qemu' ? 'Загрузка ISO образов...' : 'Загрузка шаблонов LXC...');

                    if (vmType === 'qemu') {
                        // Обновляем список ISO образов
                        const isoSelect = document.getElementById('iso-select');
                        if (isoSelect) {
                            isoSelect.innerHTML = '<option value="">-- Не подключать --</option>' +
                                (data.isos?.map(iso =>
                                    `<option value="${iso.volid}">${iso.name} (${iso.storage})</option>`
                                ).join('') || '<option value="" disabled>Нет доступных ISO образов</option>');
                        }

                        // Показываем секцию ISO, скрываем шаблоны
                        const isoContainer = document.getElementById('iso-container');
                        const templateContainer = document.getElementById('template-container');
                        const osTypeContainer = document.getElementById('os-type-container');
                        const osVersionContainer = document.getElementById('os-version-container');

                        if (isoContainer) isoContainer.style.display = 'block';
                        if (templateContainer) templateContainer.style.display = 'none';
                        if (osTypeContainer) osTypeContainer.style.display = 'block';
                        if (osVersionContainer) osVersionContainer.style.display = 'block';

                        // Устанавливаем required для полей QEMU
                        const osTypeSelect = document.getElementById('os-type-select');
                        const osVersionSelect = document.getElementById('os-version-select');
                        const templateSelect = document.getElementById('template-select');

                        if (osTypeSelect) osTypeSelect.required = true;
                        if (osVersionSelect) osVersionSelect.required = true;
                        if (templateSelect) templateSelect.required = false;
                    } else {
                        // Обновляем список шаблонов LXC
                        const templateSelect = document.getElementById('template-select');
                        if (templateSelect) {
                            templateSelect.innerHTML = '<option value="">-- Выберите шаблон --</option>' +
                                (data.templates?.map(tpl =>
                                    `<option value="${tpl.volid}">${tpl.name} (${tpl.storage})</option>`
                                ).join('') || '<option value="" disabled>Нет доступных шаблонов</option>');
                        }

                        // Показываем секцию шаблонов, скрываем ISO
                        const isoContainer = document.getElementById('iso-container');
                        const templateContainer = document.getElementById('template-container');
                        const osTypeContainer = document.getElementById('os-type-container');
                        const osVersionContainer = document.getElementById('os-version-container');

                        if (isoContainer) isoContainer.style.display = 'none';
                        if (templateContainer) templateContainer.style.display = 'block';
                        if (osTypeContainer) osTypeContainer.style.display = 'none';
                        if (osVersionContainer) osVersionContainer.style.display = 'none';

                        // Устанавливаем required для полей LXC
                        const osTypeSelect = document.getElementById('os-type-select');
                        const osVersionSelect = document.getElementById('os-version-select');
                        /*const templateSelect = document.getElementById('template-select');*/

                        if (osTypeSelect) osTypeSelect.required = false;
                        if (osVersionSelect) osVersionSelect.required = false;
                        if (templateSelect) templateSelect.required = true;
                    }

                    updateNodeProgress(100, 'Загрузка завершена');

                    // Через 1 секунду скрываем прогресс-бар
                    setTimeout(() => {
                        if (nodeLoadingContainer) {
                            nodeLoadingContainer.style.opacity = '0';
                            setTimeout(() => {
                                nodeLoadingContainer.style.display = 'none';
                                nodeLoadingContainer.style.opacity = '1';
                            }, 500);
                        }
                    }, 1000);

                } catch (error) {
                    console.error('Ошибка загрузки ресурсов ноды:', error);
                    updateNodeProgress(100, 'Ошибка загрузки данных');

                    const nodeResources = document.getElementById('node-resources');
                    if (nodeResources) {
                        nodeResources.innerHTML = `
                            <div class="notification notification-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                ${error.message}
                            </div>
                        `;
                        nodeResources.style.display = 'block';
                    }

                    const storageSelect = document.getElementById('storage-select');
                    const isoSelect = document.getElementById('iso-select');
                    const templateSelect = document.getElementById('template-select');

                    if (storageSelect) storageSelect.innerHTML = '<option value="">Ошибка загрузки</option>';
                    if (isoSelect) isoSelect.innerHTML = '<option value="">Ошибка загрузки</option>';
                    if (templateSelect) templateSelect.innerHTML = '<option value="">Ошибка загрузки</option>';

                    setTimeout(() => {
                        if (nodeLoadingContainer) {
                            nodeLoadingContainer.style.opacity = '0';
                            setTimeout(() => {
                                nodeLoadingContainer.style.display = 'none';
                                nodeLoadingContainer.style.opacity = '1';
                            }, 500);
                        }
                    }, 2000);
                }
            }

            // Переключение между типами виртуальных машин
            const vmTypeBtns = document.querySelectorAll('.vm-type-btn');
            vmTypeBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();

                    const type = this.dataset.vmType;
                    console.log('Выбран тип VM:', type);

                    vmTypeBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');

                    vmTypeInput.value = type;

                    // Обновляем текст кнопки submit
                    if (submitBtn) {
                        submitBtn.innerHTML = type === 'qemu'
                            ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину'
                            : '<i class="fas fa-shopping-cart"></i> Создать контейнер';
                    }

                    // Перезагружаем страницу с новым типом VM
                    const form = document.getElementById('vm-order-form');
                    if (form) {
                        const tempInput = document.createElement('input');
                        tempInput.type = 'hidden';
                        tempInput.name = 'vm_type';
                        tempInput.value = type;
                        form.appendChild(tempInput);
                        form.submit();
                    }
                });
            });

            // Переключение между типами тарифов
            const tariffTypeBtns = document.querySelectorAll('.tariff-type-btn');
            const tariffSections = document.querySelectorAll('.tariff-section');
            const isCustomInput = document.getElementById('is-custom');

            if (tariffTypeBtns.length > 0) {
                tariffTypeBtns.forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();

                        const type = this.dataset.tariffType;
                        console.log('Выбран тип тарифа:', type);

                        tariffTypeBtns.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');

                        tariffSections.forEach(section => section.classList.remove('active'));
                        const targetSection = document.getElementById(`${type}-tariffs`);
                        if (targetSection) targetSection.classList.add('active');

                        if (isCustomInput) {
                            isCustomInput.value = type === 'custom' ? '1' : '0';
                        }

                        document.querySelectorAll('input[name="tariff_id"]').forEach(radio => {
                            radio.checked = false;
                        });

                        checkQuotas();
                    });
                });
            }

            // Обновление значений для кастомного конфигуратора
            function updateCustomConfigValues() {
                const cpuValue = document.getElementById('custom_cpu_value');
                const ramValue = document.getElementById('custom_ram_value');
                const diskValue = document.getElementById('custom_disk_value');
                const cpuInput = document.getElementById('custom_cpu');
                const ramInput = document.getElementById('custom_ram');
                const diskInput = document.getElementById('custom_disk');

                if (cpuValue && cpuInput) cpuValue.textContent = cpuInput.value + ' ядер';
                if (ramValue && ramInput) ramValue.textContent = ramInput.value + ' MB';
                if (diskValue && diskInput) diskValue.textContent = diskInput.value + ' GB';

                updateCustomPrice();
            }

            // Обновление стоимости кастомного тарифа
            function updateCustomPrice() {
                const vmType = document.getElementById('vm-type').value;
                const cpu = parseInt(document.getElementById('custom_cpu').value) || 2;
                const ram = parseInt(document.getElementById('custom_ram').value) || 2048;
                const disk = parseInt(document.getElementById('custom_disk').value) || 20;

                // Получаем цены для выбранного типа VM
                const cpuPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_cpu'] ?? 0.000800 ?> : <?= $resourcePrices['price_per_hour_cpu'] ?? 0.001000 ?>;
                const ramPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_ram'] ?? 0.000008 ?> : <?= $resourcePrices['price_per_hour_ram'] ?? 0.000010 ?>;
                const diskPricePerHour = vmType === 'lxc' ? <?= $resourcePrices['price_per_hour_lxc_disk'] ?? 0.000030 ?> : <?= $resourcePrices['price_per_hour_disk'] ?? 0.000050 ?>;

                const cpuCost = cpu * cpuPricePerHour;
                const ramCost = ram * ramPricePerHour;
                const diskCost = disk * diskPricePerHour;
                const totalPerHour = cpuCost + ramCost + diskCost;
                const totalPerMonth = totalPerHour * 24 * 30;

                const priceItems = document.querySelectorAll('.custom-price-item');
                if (priceItems.length >= 3) {
                    priceItems[0].innerHTML =
                        `<span>CPU (${cpu} ядер × ${cpuPricePerHour.toFixed(6)} ₽/час)</span>
                         <span>${cpuCost.toFixed(6)} ₽/час</span>`;

                    priceItems[1].innerHTML =
                        `<span>RAM (${ram} MB × ${ramPricePerHour.toFixed(6)} ₽/час)</span>
                         <span>${ramCost.toFixed(6)} ₽/час</span>`;

                    priceItems[2].innerHTML =
                        `<span>Диск (${disk} GB × ${diskPricePerHour.toFixed(6)} ₽/час)</span>
                         <span>${diskCost.toFixed(6)} ₽/час</span>`;
                }

                const totalPrice = document.getElementById('custom-total-price');
                const monthPrice = document.getElementById('custom-month-price');

                if (totalPrice) totalPrice.textContent = totalPerHour.toFixed(6);
                if (monthPrice) monthPrice.textContent = totalPerMonth.toFixed(2);
            }

            // Обработчики для слайдеров кастомного тарифа
            const cpuSlider = document.getElementById('custom_cpu');
            const ramSlider = document.getElementById('custom_ram');
            const diskSlider = document.getElementById('custom_disk');

            if (cpuSlider) cpuSlider.addEventListener('input', updateCustomConfigValues);
            if (ramSlider) ramSlider.addEventListener('input', updateCustomConfigValues);
            if (diskSlider) diskSlider.addEventListener('input', updateCustomConfigValues);

            // Инициализация значений
            updateCustomConfigValues();

            // Функция для проверки квот
            function checkQuotas() {
                const isCustomInput = document.getElementById('is-custom');
                const isCustom = isCustomInput ? isCustomInput.value === '1' : false;
                const vmType = document.getElementById('vm-type').value;
                let cpu = 0, ram = 0, disk = 0;

                if (isCustom) {
                    const selectedCustomTariff = document.querySelector('#custom-tariffs input[name="tariff_id"]:checked');

                    if (selectedCustomTariff) {
                        cpu = parseInt(selectedCustomTariff.dataset.cpu) || 0;
                        ram = parseInt(selectedCustomTariff.dataset.ram) || 0;
                        disk = parseInt(selectedCustomTariff.dataset.disk) || 0;
                    } else {
                        const cpuSlider = document.getElementById('custom_cpu');
                        const ramSlider = document.getElementById('custom_ram');
                        const diskSlider = document.getElementById('custom_disk');

                        cpu = cpuSlider ? parseInt(cpuSlider.value) : 2;
                        ram = ramSlider ? parseInt(ramSlider.value) : 2048;
                        disk = diskSlider ? parseInt(diskSlider.value) : 20;
                    }
                } else {
                    const selectedTariff = document.querySelector('#regular-tariffs input[name="tariff_id"]:checked');
                    if (!selectedTariff) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        if (quotaAlert) {
                            quotaAlert.style.display = 'none';
                        }
                        return;
                    }

                    cpu = parseInt(selectedTariff.dataset.cpu) || 0;
                    ram = parseInt(selectedTariff.dataset.ram) || 0;
                    disk = parseInt(selectedTariff.dataset.disk) || 0;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка квот...';
                }

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
                    if (submitBtn) {
                        submitBtn.innerHTML = vmType === 'qemu'
                            ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину'
                            : '<i class="fas fa-shopping-cart"></i> Создать контейнер';

                        if (data.quota_exceeded) {
                            submitBtn.disabled = true;
                            if (quotaErrorsList) {
                                quotaErrorsList.innerHTML = '';
                                data.errors.forEach(error => {
                                    const li = document.createElement('li');
                                    li.textContent = error;
                                    quotaErrorsList.appendChild(li);
                                });
                            }
                            if (quotaAlert) {
                                quotaAlert.style.display = 'block';
                            }
                        } else {
                            submitBtn.disabled = false;
                            if (quotaAlert) {
                                quotaAlert.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Ошибка проверки квот:', error);
                    if (submitBtn) {
                        submitBtn.innerHTML = vmType === 'qemu'
                            ? '<i class="fas fa-shopping-cart"></i> Создать виртуальную машину'
                            : '<i class="fas fa-shopping-cart"></i> Создать контейнер';
                        submitBtn.disabled = false;
                    }
                    if (quotaAlert) {
                        quotaAlert.style.display = 'none';
                    }
                });
            }

            // Обработчики для проверки квот
            document.addEventListener('change', function(e) {
                if (e.target.name === 'tariff_id') {
                    checkQuotas();

                    // Обновляем тип ОС при выборе тарифа
                    const osType = e.target.dataset.osType;
                    const osTypeSelect = document.getElementById('os-type-select');
                    if (osType && osTypeSelect) {
                        osTypeSelect.value = osType;
                        osTypeSelect.disabled = true;
                    } else if (osTypeSelect) {
                        osTypeSelect.disabled = false;
                    }
                }
            });

            const cpuSliderCheck = document.getElementById('custom_cpu');
            const ramSliderCheck = document.getElementById('custom_ram');
            const diskSliderCheck = document.getElementById('custom_disk');

            if (cpuSliderCheck) cpuSliderCheck.addEventListener('change', checkQuotas);
            if (ramSliderCheck) ramSliderCheck.addEventListener('change', checkQuotas);
            if (diskSliderCheck) diskSliderCheck.addEventListener('change', checkQuotas);

            // Инициализация проверки квот при загрузке
            if (document.querySelector('input[name="tariff_id"]:checked')) {
                checkQuotas();
            }

            // Обработчик отправки формы
            const form = document.getElementById('vm-order-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const isCustom = document.getElementById('is-custom').value === '1';
                    const vmType = document.getElementById('vm-type').value;
                    const nodeId = document.querySelector('select[name="node_id"]')?.value;
                    const storage = document.querySelector('select[name="storage"]')?.value;
                    const tariffId = !isCustom ? document.querySelector('input[name="tariff_id"]:checked')?.value : null;
                    const osVersion = vmType === 'qemu' ? document.querySelector('select[name="os_version"]')?.value : null;
                    const template = vmType === 'lxc' ? document.querySelector('select[name="template"]')?.value : null;

                    if (!nodeId || (!isCustom && !tariffId) || !storage ||
                        (vmType === 'qemu' && !osVersion) ||
                        (vmType === 'lxc' && !template)) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Ошибка',
                            text: 'Пожалуйста, заполните все обязательные поля',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    const btn = document.getElementById('submit-btn');
                    const progressElement = document.getElementById('creation-progress');

                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-cog fa-spin"></i> Обработка запроса...';
                    }

                    if (progressElement) {
                        progressElement.style.display = 'block';
                    }

                    const startTime = new Date().getTime();
                    const timerElement = document.getElementById('live-timer');

                    function updateTimer() {
                        const now = new Date().getTime();
                        const elapsed = Math.floor((now - startTime) / 1000);

                        const hours = Math.floor(elapsed / 3600);
                        const minutes = Math.floor((elapsed % 3600) / 60);
                        const seconds = elapsed % 60;

                        if (timerElement) {
                            timerElement.textContent =
                                `${hours.toString().padStart(2, '0')}:` +
                                `${minutes.toString().padStart(2, '0')}:` +
                                `${seconds.toString().padStart(2, '0')}`;
                        }

                        if (document.getElementById('creation-progress')) {
                            requestAnimationFrame(updateTimer);
                        }
                    }

                    updateTimer();
                });
            }

            // Обработчик изменения ноды
            const nodeSelect = document.querySelector('select[name="node_id"]');
            if (nodeSelect) {
                nodeSelect.addEventListener('change', function() {
                    const nodeId = this.value;
                    const vmType = document.getElementById('vm-type').value;

                    if (!nodeId) {
                        const nodeResources = document.getElementById('node-resources');
                        const storageSelect = document.getElementById('storage-select');
                        const isoSelect = document.getElementById('iso-select');
                        const templateSelect = document.getElementById('template-select');

                        if (nodeResources) nodeResources.style.display = 'none';
                        if (storageSelect) storageSelect.innerHTML = '<option value="">-- Выберите хранилище --</option>';
                        if (isoSelect) isoSelect.innerHTML = '<option value="">-- Не подключать --</option>';
                        if (templateSelect) templateSelect.innerHTML = '<option value="">-- Выберите шаблон --</option>';
                        return;
                    }

                    loadNodeResources(nodeId, vmType);
                });
            }

            // Обновление списка версий ОС при изменении типа ОС
            const osTypeSelect = document.getElementById('os-type-select');
            if (osTypeSelect) {
                osTypeSelect.addEventListener('change', function() {
                    const osType = this.value;
                    const linuxVersions = document.getElementById('linux-versions');
                    const windowsVersions = document.getElementById('windows-versions');
                    const osVersionSelect = document.getElementById('os-version-select');

                    if (osType === 'linux' && linuxVersions) {
                        linuxVersions.style.display = '';
                        if (windowsVersions) windowsVersions.style.display = 'none';
                    } else if (osType === 'windows' && windowsVersions) {
                        if (linuxVersions) linuxVersions.style.display = 'none';
                        windowsVersions.style.display = '';
                    }

                    // Сбрасываем выбор версии
                    if (osVersionSelect) {
                        osVersionSelect.value = '';
                    }
                });
            }

            // Обработчик SDN сети
            const sdnSelect = document.getElementById('sdn-select');
            if (sdnSelect) {
                sdnSelect.addEventListener('change', function() {
                    const networkSelect = document.getElementById('network-select');
                    if (networkSelect) {
                        if (this.value) {
                            networkSelect.disabled = true;
                            networkSelect.value = 'vmbr0';
                        } else {
                            networkSelect.disabled = false;
                        }
                    }
                });
            }

            // Загружаем ресурсы ноды, если она уже выбрана
            const selectedNode = document.querySelector('select[name="node_id"]')?.value;
            if (selectedNode) {
                loadNodeResources(selectedNode, vmTypeInput.value);
            }
        });
    </script>
</body>
</html>
