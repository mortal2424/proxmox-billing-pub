<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/guest_order.php';

session_start();
checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = (int) $_SESSION['user']['id'];
$_SESSION['order_csrf'] ??= bin2hex(random_bytes(32));
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

[$resourcePrices, $quota, $usage] = guestOrderContext($pdo, $user_id);

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
$zones = guestOrderZones($pdo, $user_id);

$requestedVmType = $_POST['vm_type'] ?? $_GET['vm_type'] ?? 'qemu';
$vmType = in_array($requestedVmType, ['qemu', 'lxc'], true) ? $requestedVmType : 'qemu';

// Получаем только тарифы для выбранного типа VM
$regular_tariffs = $pdo->query("SELECT * FROM tariffs WHERE is_active = 1 AND is_custom = 0 AND vm_type = '$vmType' ORDER BY price")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$custom_tariffs = $pdo->query("SELECT t.* FROM tariffs t JOIN vms v ON t.id = v.tariff_id WHERE t.is_active = 1 AND t.is_custom = 1 AND t.vm_type = '$vmType' AND v.user_id = $user_id ORDER BY t.created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$installMethod = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['install_method'] ?? 'iso') : 'cloud';
$errors = [];
$success = false;
$vncUrl = '';
$creationTime = 0;

// Проверяем, является ли запрос переключением типа VM
$is_switching_vm_type = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['vm_type']);

// ВАЖНО: Убрал проверку !$quota_exceeded из условия, чтобы форма обрабатывалась всегда
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_switching_vm_type) {
    $creationStart = time();

    try {
        if (!is_string($_POST['order_csrf'] ?? null) ||
            !hash_equals($_SESSION['order_csrf'], $_POST['order_csrf'])) {
            throw new RuntimeException('Сессия формы истекла. Обновите страницу и повторите отправку');
        }
        [$creationResult, $node] = submitGuestOrder($user_id, $_POST);
        $vncUrl = '/templates/vnc_console.php?' . http_build_query([
            'node_id'=>$node['id'], 'vm_id'=>$creationResult['vmid'], 'redirect'=>1,
        ]);

        $vmId = $creationResult['vmid'];
        $guestDetailsUrl = 'vm_settings.php?id=' . (int) $creationResult['guest_id'] . '#guest-password';

        $success = true;
        $successMessage = $vmType === 'qemu'
            ? "Виртуальная машина #{$vmId} успешно создана на ноде {$node['node_name']}!"
            : "LXC контейнер #{$vmId} успешно создан на ноде {$node['node_name']}!";
        if (isset($creationResult['warning'])) {
            $successMessage .= ' ' . $creationResult['warning'];
        }
        $creationTime = time() - $creationStart;

    } catch (Throwable $e) {
        error_log("[Creation Error] " . date('Y-m-d H:i:s') . " - " . $e->getMessage());
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isLoggedIn()) {
    if (empty($_SESSION['user'])) {
        header('Location: /login/login.php', true, 303);
    } else {
        http_response_code(503);
        echo 'Не удалось проверить сессию. Проверьте заказ в разделе «Мои ВМ» перед повторной отправкой.';
    }
    exit;
}

$title = "Заказ виртуальной машины | HomeVlad Cloud";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/user-palette.css">
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <?php require __DIR__ . '/headers/workspace_preference.php'; ?>
    <?php if ($workspaceLite): ?>
        <link rel="stylesheet" href="/css/workspace.css?v=6">
    <?php endif; ?>
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0e1727 0%, #172438 100%);
            --secondary-gradient: linear-gradient(135deg, #007b72, #00635f);
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
            background: linear-gradient(135deg, #f7fafc 0%, #edf4f7 100%);
            color: #172438;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0e1727 0%, #172438 100%);
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
            background: linear-gradient(135deg, #007b72, #00635f);
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
            color: #172438;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .form-section h3 {
            color: #edf4f7;
        }

        .form-section h3 i {
            color: var(--mint-text);
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
            border-color: #007b72;
            color: var(--mint-text);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13, 148, 136, 0.1);
        }

        .vm-type-btn:hover i {
            color: var(--mint-text);
        }

        .vm-type-btn.active {
            border-color: #007b72;
            background: rgba(13, 148, 136, 0.05);
            color: var(--mint-text);
            box-shadow: 0 8px 25px rgba(13, 148, 136, 0.15);
        }

        .vm-type-btn.active i {
            color: var(--mint-text);
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
            border-color: #007b72;
            color: var(--mint-text);
        }

        .tariff-type-btn.active {
            border-color: #007b72;
            background: rgba(13, 148, 136, 0.1);
            color: var(--mint-text);
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
            border-color: #007b72;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(13, 148, 136, 0.15);
        }

        .tariff-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .tariff-card input[type="radio"]:checked + label {
            color: var(--mint-text);
        }

        .tariff-card input[type="radio"]:checked ~ label:before {
            content: '✓';
            position: absolute;
            top: -10px;
            right: -10px;
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #007b72, #00635f);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
        }

        .tariff-card label {
            cursor: pointer;
            display: block;
            width: 100%;
        }

        .tariff-name {
            font-size: 18px;
            font-weight: 600;
            color: #172438;
            margin-bottom: 15px;
        }

        body.dark-theme .tariff-name {
            color: #edf4f7;
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
            color: var(--mint-text);
            width: 16px;
        }

        .tariff-price {
            font-size: 24px;
            font-weight: 700;
            color: #172438;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .tariff-price {
            color: #edf4f7;
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
            color: #172438;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-theme .custom-configurator h4 {
            color: #edf4f7;
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
            color: #172438;
            font-weight: 500;
        }

        body.dark-theme .custom-config-group label {
            color: #edf4f7;
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
            background: linear-gradient(135deg, #007b72, #00635f);
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(13, 148, 136, 0.3);
        }

        .custom-config-group input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #007b72, #00635f);
            cursor: pointer;
            border: none;
            box-shadow: 0 4px 8px rgba(13, 148, 136, 0.3);
        }

        .custom-config-value {
            margin-top: 15px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            color: var(--mint-text);
            background: rgba(13, 148, 136, 0.1);
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
            color: #172438;
        }

        body.dark-theme .custom-price-total {
            color: #edf4f7;
        }

        .custom-price-total span {
            color: var(--mint-text);
            font-size: 20px;
        }

        /* Форма */
        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #172438;
            font-weight: 500;
        }

        body.dark-theme .form-label {
            color: #edf4f7;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #172438;
            font-size: 15px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-theme .form-control {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #edf4f7;
        }

        .form-control:focus {
            outline: none;
            border-color: #007b72;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
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
            background: rgba(13, 148, 136, 0.1);
            border: 1px solid rgba(13, 148, 136, 0.2);
            border-radius: 8px;
            color: var(--mint-text);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hostname-generator button:hover {
            background: rgba(13, 148, 136, 0.2);
            transform: translateY(-1px);
        }

        /* Кнопка отправки */
        .btn-submit {
            display: block;
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #007b72, #00635f);
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
            box-shadow: 0 12px 30px rgba(13, 148, 136, 0.4);
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
            color: var(--mint-text);
            font-size: 18px;
        }

        .timer-value {
            font-size: 20px;
            font-weight: 700;
            color: #172438;
        }

        body.dark-theme .timer-value {
            color: #edf4f7;
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
            color: #172438;
        }

        body.dark-theme .resources-info p {
            background: rgba(30, 41, 59, 0.3);
            color: #edf4f7;
        }

        .resources-info i {
            color: var(--mint-text);
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
            color: var(--mint-text);
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
            background: linear-gradient(135deg, #007b72, #00635f);
            border-radius: 12px;
            color: white;
            font-size: 24px;
            cursor: pointer;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(13, 148, 136, 0.3);
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
            background: linear-gradient(90deg, transparent, rgba(13, 148, 136, 0.5), transparent);
        }
        /* Стили для выпадающего списка хранилищ */
.storages-select {
    background-color: var(--node-card-bg);
    color: var(--node-text);
    border: 2px solid var(--node-border);
    border-radius: 8px;
    padding: 10px;
    font-family: inherit;
}

.storages-select option {
    padding: 6px 10px;
    background-color: var(--node-card-bg);
    color: var(--node-text);
    white-space: normal;
    word-break: break-word;
}

.storages-select option:hover {
    background-color: var(--node-accent-light);
}

/* Адаптивность для мобильных устройств */
@media (max-width: 768px) {
    .storages-select {
        font-size: 13px;
    }
}
/* Стили для кликабельной карточки хранилищ */
.stat-card {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--node-shadow-hover);
}
#storage-stats-card {
    cursor: pointer;
    position: relative;
}
#storage-stats-card::after {
    content: '▼';
    font-size: 12px;
    position: absolute;
    bottom: 10px;
    right: 12px;
    opacity: 0.6;
    transition: transform 0.3s;
}
#storage-stats-card.active::after {
    transform: rotate(180deg);
}

/* Стили для сетки хранилищ */
.storages-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    margin-top: 20px;
    animation: fadeInUp 0.3s ease;
}
.storage-item {
    background: var(--node-hover);
    border-radius: 10px;
    padding: 12px;
    border: 1px solid var(--node-border);
    transition: all 0.2s;
}
.storage-item:hover {
    background: var(--node-accent-light);
    transform: translateX(4px);
}
.storage-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.storage-header i {
    color: #8b5cf6;
    font-size: 18px;
}
.storage-name {
    flex: 1;
    font-weight: 600;
    color: var(--node-text);
}
.storage-type {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 20px;
    background: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
}
.storage-details {
    font-size: 13px;
    color: var(--node-text-secondary);
}
.storage-details strong {
    color: var(--node-text);
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.order-details-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
}
.order-details-grid > .form-section { grid-column: span 2; margin: 0; min-width: 0; }
.order-details-grid > .form-section:nth-child(n+4) { grid-column: span 3; }
@media (max-width: 850px) {
    .order-details-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .order-details-grid > .form-section:nth-child(n) { grid-column: span 1; }
}
@media (max-width: 580px) {
    .order-details-grid { grid-template-columns: minmax(0, 1fr); }
}

    </style>
</head>
<body<?= $workspaceLite ? ' class="workspace"' : '' ?>>
    <?php if ($workspaceLite): ?>
        <?php require __DIR__ . '/headers/workspace_nav.php'; ?>
    <?php else: ?>
        <?php include '../templates/headers/user_header.php'; ?>
    <?php endif; ?>

    <!-- Кнопка мобильного меню -->
    <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <?php if ($workspaceLite): ?>
    <div class="workspace-layout">
        <?php require __DIR__ . '/headers/workspace_sidebar.php'; ?>
    <?php endif; ?>
    <div class="main-container">
        <?php if (!$workspaceLite) { include '../templates/headers/user_sidebar.php'; } ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <div class="workspace-title">
                    <h1 class="page-title">
                    <i class="fas fa-plus-circle"></i> Заказ виртуальной машины
                </h1>
                    <p>Выберите тип сервера, тариф, образ и параметры сети.</p>
                </div>
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
            <?php if (!empty($errors) && !$is_switching_vm_type): ?>
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
                        <?php if (!empty($guestDetailsUrl)): ?>
                            <p><a id="created-guest-link" href="<?= htmlspecialchars($guestDetailsUrl, ENT_QUOTES) ?>">Открыть карточку и пароль</a></p>
                            <p id="created-guest-redirect" hidden>Карточка откроется через 10 секунд.</p>
                        <?php endif; ?>
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
            <div id="creation-progress" class="notification" style="display: none; scroll-margin-top: 90px; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
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
                <input type="hidden" name="order_csrf" value="<?= htmlspecialchars($_SESSION['order_csrf']) ?>">
                <input type="hidden" name="vm_type" id="vm-type" value="<?= $vmType ?>">

                <!-- 0. Выбор типа виртуальной машины -->
                <div class="form-section">
                    <h3><i class="fas fa-server"></i> 0. Выберите тип виртуальной машины</h3>
                    <div class="vm-type-selector">
                        <a href="?vm_type=qemu" class="vm-type-btn <?= $vmType === 'qemu' ? 'active' : '' ?>">
                            <i class="fas fa-desktop"></i> Виртуальная машина (KVM)
                        </a>
                        <a href="?vm_type=lxc" class="vm-type-btn <?= $vmType === 'lxc' ? 'active' : '' ?>">
                            <i class="fas fa-box"></i> Контейнер (LXC)
                        </a>
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

                <div class="order-details-grid">
                <!-- 2. Выбор ноды -->
                <div class="form-section">
                    <h3><i class="fas fa-server"></i> 2. Выберите зону доступности</h3>
                    <div class="form-group">
                        <label class="form-label" for="node-select">Зона доступности</label>
                        <select name="zone_id" class="form-control" required id="node-select">
                            <option value="">Выберите зону</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?= (int)$zone['id'] ?>" <?= $zone['id'] == ($_POST['zone_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($zone['name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Панель подберёт хост после проверки ресурсов и выбранных пулов.</small>
                    </div>

                    <p id="zone-resource-error" role="status" hidden></p>
                </div>

                <!-- 3. Настройка сети -->
                <div class="form-section">
                    <h3><i class="fas fa-network-wired"></i> 3. Настройка сети</h3>
                    <div class="form-group">
                        <label class="form-label" for="network-select">Пул сети:</label>
                        <select name="network_pool" class="form-control" required id="network-select">
                            <option value="">Выберите пул</option>
                        </select>
                    </div>

                </div>

                <!-- 4. Настройка диска -->
                <div class="form-section">
                    <h3><i class="fas fa-database"></i> 4. Настройка диска</h3>
                    <div class="form-group">
                        <label class="form-label">Хранилище:</label>
                        <select name="storage_pool" class="form-control" required id="storage-select">
                            <option value="">-- Выберите хранилище --</option>
                        </select>
                        <small class="text-muted" id="storage-info"></small>
                    </div>
                </div>

                <!-- 5. Установка ОС / Шаблон -->
                <div class="form-section" id="os-section">
                    <h3><i class="fas fa-compact-disc"></i> 5. Установка ОС</h3>
                    <?php if ($vmType === 'qemu'): ?>
                    <div class="form-group">
                        <label class="form-label" for="install-method">Способ установки</label>
                        <select class="form-control" name="install_method" id="install-method">
                            <option value="cloud" <?= $installMethod !== 'iso' ? 'selected' : '' ?>>Cloud-образ · автоматическая установка</option>
                            <option value="iso" <?= $installMethod === 'iso' ? 'selected' : '' ?>>ISO · ручная установка</option>
                        </select>
                    </div>
                    <div class="form-group" id="cloud-image-container" <?= $installMethod === 'iso' ? 'hidden' : '' ?>>
                        <label class="form-label" for="cloud-image-select">Cloud-образ</label>
                        <select class="form-control" id="cloud-image-select" name="cloud_image" data-selected="<?= htmlspecialchars(is_string($_POST['cloud_image'] ?? null) ? $_POST['cloud_image'] : '') ?>">
                            <option value="">Выберите зону и образ</option>
                        </select>
                        <small class="text-muted" id="cloud-image-description" aria-live="polite">Панель настроит учётную запись и сеть через cloud-init. Сеть должна выдавать адрес по DHCP.</small>
                    </div>
                    <?php endif; ?>
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
                                <option value="2025" <?= (($_POST['os_version'] ?? '') === '2025') ? 'selected' : '' ?>>Windows Server 2025</option>
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

                    <p>Панель сгенерирует пароль. Он будет доступен в карточке гостя 24 часа. При установке из ISO введите его в установщике.</p>
                    <details>
                        <summary>Задать свой пароль</summary>
                        <div class="form-group">
                            <label class="form-label" id="guest-password-label" for="guest-password-input">Пароль администратора</label>
                            <input type="password" name="password" id="guest-password-input" class="form-control" minlength="8" maxlength="1024" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="guest-password-confirm">Подтверждение пароля</label>
                            <input type="password" name="confirm_password" id="guest-password-confirm" class="form-control" minlength="8" maxlength="1024" autocomplete="new-password">
                            <small class="text-muted">Оставьте оба поля пустыми для автоматической генерации. Собственный пароль в карточке не сохраняется.</small>
                        </div>
                    </details>
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
    <?php if ($workspaceLite): ?></div><?php endif; ?>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const createdGuestLink = document.getElementById('created-guest-link');
        if (createdGuestLink) {
            document.getElementById('created-guest-redirect').hidden = false;
            window.setTimeout(() => window.location.replace(createdGuestLink.href), 10000);
        }

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

            // Загрузка данных ноды
            let zoneRequest = 0;
            async function loadNodeResources(zoneId, vmType) {
                const requestId = ++zoneRequest;
                const fields = {
                    network: document.getElementById('network-select'),
                    storage: document.getElementById('storage-select'),
                    media: document.getElementById(vmType === 'qemu' ? 'iso-select' : 'template-select')
                };
                if (vmType === 'qemu') fields.cloud = document.getElementById('cloud-image-select');
                const placeholders = {cloud:'Выберите cloud-образ', network:'Выберите пул', storage:'Выберите пул',
                    media: vmType === 'qemu' ? 'Без ISO' : 'Выберите шаблон'};
                for (const [key, select] of Object.entries(fields)) select.replaceChildren(new Option(placeholders[key], ''));
                if (fields.cloud) updateInstallMethod();
                const resourceError = document.getElementById('zone-resource-error');
                resourceError.hidden = true;
                if (!zoneId) return;
                const query = new URLSearchParams({zone_id: zoneId, vm_type: vmType});
                try {
                    const response = await fetch('get_zone_placement.php?' + query);
                    if (!response.ok) throw new Error('Ресурсы зоны недоступны');
                    const data = await response.json();
                    if (requestId !== zoneRequest) return;
                    for (const [key, select] of Object.entries(fields)) {
                        for (const value of data[key] ?? []) {
                            const option = typeof value === 'string' ? new Option(value, value) : new Option(value.label, value.id);
                            if (key === 'cloud') {
                                option.dataset.user = value.user;
                                option.dataset.os = value.os_type;
                                option.dataset.minimum = value.minimum_disk;
                            }
                            select.add(option);
                        }
                    }
                    if (fields.cloud) {
                        if (fields.cloud.options.length === 1) fields.cloud.options[0].text = 'В зоне нет cloud-образов';
                        if (fields.cloud.dataset.selected) {
                            fields.cloud.value = fields.cloud.dataset.selected;
                            if (fields.cloud.selectedIndex === -1) fields.cloud.selectedIndex = 0;
                            fields.cloud.dataset.selected = '';
                        }
                        updateInstallMethod();
                    }
                } catch (error) {
                    if (requestId === zoneRequest) {
                        resourceError.textContent = 'Не удалось получить ресурсы зоны. Выберите зону ещё раз.';
                        resourceError.hidden = false;
                    }
                }
            }

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
                        osTypeSelect.dispatchEvent(new Event('change'));
                        updateInstallMethod();
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
                    const zoneId = document.querySelector('select[name="zone_id"]')?.value;
                    const network = document.getElementById('network-select').value;
                    const storage = document.querySelector('select[name="storage_pool"]')?.value;
                    const tariffId = !isCustom ? document.querySelector('input[name="tariff_id"]:checked')?.value : null;
                    const cloud = vmType === 'qemu' && installMethodSelect?.value === 'cloud';
                    const osVersion = vmType === 'qemu' ? document.querySelector('select[name="os_version"]')?.value : null;
                    const template = vmType === 'lxc' ? document.querySelector('select[name="template"]')?.value : null;

                    if (!zoneId || !network || (!isCustom && !tariffId) || !storage ||
                        (vmType === 'qemu' && (cloud ? !cloudImageSelect?.value : !osVersion)) ||
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
                        progressElement.scrollIntoView({ block: 'start', behavior: 'instant' });
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

            const installMethodSelect = document.getElementById('install-method');
            const cloudImageSelect = document.getElementById('cloud-image-select');
            function updateInstallMethod() {
                if (!installMethodSelect) return;
                const tariffOs = document.querySelector('input[name="tariff_id"]:checked')?.dataset.osType;
                const fixedOs = document.getElementById('is-custom').value !== '1' && ['windows', 'linux'].includes(tariffOs) ? tariffOs : '';
                let available = 0;
                for (const option of cloudImageSelect.options) {
                    if (!option.value) continue;
                    option.hidden = Boolean(fixedOs && option.dataset.os !== fixedOs);
                    option.disabled = option.hidden;
                    if (!option.disabled) available++;
                }
                if (cloudImageSelect.selectedOptions[0]?.disabled) cloudImageSelect.selectedIndex = 0;
                cloudImageSelect.options[0].text = available
                    ? 'Выберите cloud-образ' : 'Нет cloud-образов для выбранной зоны и тарифа';
                const cloud = installMethodSelect.value === 'cloud';
                document.getElementById('cloud-image-container').hidden = !cloud;
                cloudImageSelect.disabled = !cloud;
                cloudImageSelect.required = cloud;
                for (const id of ['iso', 'os-type', 'os-version']) {
                    const container = document.getElementById(id + '-container');
                    const field = document.getElementById(id + '-select');
                    container.style.display = cloud ? 'none' : '';
                    field.disabled = cloud;
                    field.required = !cloud && id !== 'iso';
                }
                const user = cloudImageSelect.selectedOptions[0]?.dataset.user;
                const minimum = cloudImageSelect.selectedOptions[0]?.dataset.minimum;
                document.getElementById('guest-password-label').textContent = cloud && user ? 'Пароль пользователя ' + user + ':' : 'Пароль администратора:';
                document.getElementById('cloud-image-description').textContent = user
                    ? 'Пользователь ' + user + ' · диск от ' + minimum + ' ГБ · сеть по DHCP.' +
                        (cloudImageSelect.selectedOptions[0]?.dataset.os === 'windows' ? ' Используйте временный пароль и смените его после первого входа.' : '')
                    : 'После выбора зоны появятся доступные cloud-образы. Сеть настраивается по DHCP.';
            }

            if (installMethodSelect) {
                installMethodSelect.addEventListener('change', updateInstallMethod);
                cloudImageSelect.addEventListener('change', updateInstallMethod);
                document.querySelectorAll('.tariff-type-btn').forEach(button => button.addEventListener('click', updateInstallMethod));
                updateInstallMethod();
            }
            const nodeSelect = document.querySelector('select[name="zone_id"]');
            if (nodeSelect) {
                const refresh = () => loadNodeResources(nodeSelect.value, document.getElementById('vm-type').value);
                nodeSelect.addEventListener('change', refresh);
                if (nodeSelect.value) refresh();
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



        });
    </script>
</body>
</html>
