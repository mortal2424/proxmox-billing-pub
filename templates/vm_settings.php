<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем ID VM из параметров
$vm_db_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$vm_db_id) {
    header("Location: my_vms.php");
    exit;
}

// Получаем базовые данные о VM из базы
$stmt = $pdo->prepare("
    SELECT
        v.*,
        n.hostname,
        n.username,
        n.password,
        n.node_name,
        t.name as tariff_name,
        t.cpu as tariff_cpu,
        t.ram as tariff_ram,
        t.disk as tariff_disk
    FROM vms v
    JOIN proxmox_nodes n ON v.node_id = n.id
    LEFT JOIN tariffs t ON v.tariff_id = t.id
    WHERE v.id = ? AND v.user_id = ?
");
$stmt->execute([$vm_db_id, $user_id]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    header("Location: my_vms.php");
    exit;
}

// Получаем список тарифов для соответствующего типа VM
$plans = $pdo->prepare("
    SELECT id, name, cpu, ram, disk
    FROM tariffs
    WHERE is_active = 1 AND vm_type = ?
");
$plans->execute([$vm['vm_type']]);
$plans = $plans->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Инициализация Proxmox API для POST-запросов
        $proxmox = new ProxmoxAPI(
            $vm['hostname'],
            $vm['username'],
            $vm['password'],
            $vm['ssh_port'] ?? 22,
            $vm['node_name'],
            $vm['node_id'],
            $pdo
        );

        // Обработка изменения тарифа/ресурсов
        if (isset($_POST['change_resources'])) {
            $new_plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : null;
            $custom_cpu = isset($_POST['custom_cpu']) ? intval($_POST['custom_cpu']) : null;
            $custom_ram = isset($_POST['custom_ram']) ? intval($_POST['custom_ram']) : null;
            $custom_disk = isset($_POST['custom_disk']) ? intval($_POST['custom_disk']) : null;

            $result = $proxmox->changeVmResources(
                $vm['vm_id'],
                $vm['vm_type'],
                $new_plan_id,
                $custom_cpu,
                $custom_ram,
                $custom_disk
            );

            if ($result['status'] === 'success') {
                $success_message = $result['message'];
                // Обновляем данные VM после изменения
                $stmt = $pdo->prepare("SELECT * FROM vms WHERE id = ?");
                $stmt->execute([$vm_db_id]);
                $vm = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                throw new Exception($result['message']);
            }
        }

        // Обработка изменения/добавления диска
        if (isset($_POST['change_disk'])) {
            $disk_id = $_POST['disk_id'];
            $new_size = $_POST['disk_size'];
            $new_storage = $_POST['disk_storage'];

            if ($vm['vm_type'] === 'qemu') {
                $result = $proxmox->changeVmDisk(
                    $vm['vm_id'],
                    $vm['vm_type'],
                    $disk_id,
                    $new_size,
                    $new_storage
                );
            } else {
                // Для LXC меняем только размер rootfs
                $result = $proxmox->changeVmResources(
                    $vm['vm_id'],
                    $vm['vm_type'],
                    null,
                    null,
                    null,
                    $new_size
                );
            }

            if ($result['status'] === 'success') {
                $success_message = $result['message'];
            } else {
                throw new Exception($result['message']);
            }
        }

        // Обработка добавления сети
        if (isset($_POST['add_network'])) {
            $network_id = $_POST['network_id'];
            $bridge = $_POST['bridge'];

            $result = $proxmox->addVmNetwork(
                $vm['vm_id'],
                $vm['vm_type'],
                $network_id,
                $bridge
            );

            if ($result['status'] === 'success') {
                $success_message = $result['message'];
            } else {
                throw new Exception($result['message']);
            }
        }

        // Обработка удаления VM
        if (isset($_POST['delete_vm'])) {
            $result = $proxmox->deleteVm($vm['vm_id'], $vm['vm_type']);

            if ($result['status'] === 'success') {
                header("Location: my_vms.php");
                exit();
            } else {
                throw new Exception($result['message']);
            }
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнера' ?> | <?= htmlspecialchars($_ENV['APP_NAME']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
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
            margin-bottom: 24px;
            padding-bottom: 16px;
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

        .page-title small {
            font-size: 18px;
            font-weight: 400;
            background: none;
            -webkit-background-clip: initial;
            background-clip: initial;
            color: #64748b;
        }

        body.dark-theme .page-title small {
            color: #94a3b8;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Карточки настроек */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        .settings-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .settings-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .settings-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 16px 16px 0 0;
        }

        .settings-card.danger::before {
            background: var(--danger-gradient);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .card-header {
            border-bottom-color: rgba(255, 255, 255, 0.1);
        }

        .card-header h5 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .card-header h5 {
            color: #f1f5f9;
        }

        .card-header i {
            color: #00bcd4;
            font-size: 20px;
        }

        .settings-card.danger .card-header {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            margin: -24px -24px 20px -24px;
            padding: 24px;
            color: white;
        }

        .settings-card.danger .card-header h5 {
            color: white;
        }

        .settings-card.danger .card-header i {
            color: white;
        }

        /* Формы */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1e293b;
            font-size: 14px;
        }

        body.dark-theme .form-group label {
            color: #f1f5f9;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.5);
            color: #1e293b;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        body.dark-theme .form-control {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.2);
            color: #f1f5f9;
        }

        .form-control:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        .tariff-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.5);
            color: #1e293b;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        body.dark-theme .tariff-select {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.2);
            color: #f1f5f9;
        }

        .tariff-select option {
            background: white;
            color: #1e293b;
        }

        body.dark-theme .tariff-select option {
            background: #1e293b;
            color: #f1f5f9;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        /* Кнопки */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
        }

        /* Уведомления */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .alert i {
            font-size: 18px;
        }

        /* Диски и сети */
        .disk-form, .network-form {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .disk-form,
        body.dark-theme .network-form {
            background: rgba(30, 41, 59, 0.5);
        }

        .disk-info, .network-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .disk-info-item, .network-info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .disk-info-item strong,
        .network-info-item strong {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        body.dark-theme .disk-info-item strong,
        body.dark-theme .network-info-item strong {
            color: #94a3b8;
        }

        .disk-info-item div,
        .network-info-item div {
            font-size: 14px;
            color: #1e293b;
            font-weight: 500;
        }

        body.dark-theme .disk-info-item div,
        body.dark-theme .network-info-item div {
            color: #f1f5f9;
        }

        .sdn-alias {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(124, 58, 237, 0.1));
            border-radius: 20px;
            font-size: 12px;
            color: #8b5cf6;
            margin-top: 8px;
        }

        /* Загрузка */
        .loading-placeholder {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 200px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(148, 163, 184, 0.1);
            border-top: 3px solid #00bcd4;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Предупреждение об удалении */
        .delete-warning {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05), rgba(220, 38, 38, 0.05));
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .delete-warning p {
            color: #ef4444;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Кнопка обновления */
        .btn-refresh {
            padding: 8px 16px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-refresh:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        /* Стили для темы */
        .text-muted {
            color: #94a3b8;
            font-size: 12px;
        }

        small.text-muted {
            display: block;
            margin-top: 4px;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .settings-card {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
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
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <div class="main-container">
        <?php include '../templates/headers/user_sidebar.php'; ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-cog"></i> Настройки <?= $vm['vm_type'] === 'qemu' ? 'Виртуальной машины' : 'Контейнера' ?> #<?= $vm['vm_id'] ?>
                    <small><?= htmlspecialchars($vm['name'] ?? '') ?></small>
                </h1>
                <div class="header-actions">
                    <button class="btn-refresh" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div class="settings-grid">
                <!-- Блок изменения ресурсов -->
                <div class="settings-card">
                    <div class="card-header">
                        <h5><i class="fas fa-sliders-h"></i> Изменение ресурсов</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label>Текущий тариф</label>
                                <select class="tariff-select" name="plan_id" id="planSelect">
                                    <option value="0" <?= empty($vm['tariff_id']) ? 'selected' : '' ?>>Кастомный</option>
                                    <?php foreach ($plans as $plan): ?>
                                    <option value="<?= $plan['id'] ?>" <?= ($plan['id'] == $vm['tariff_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($plan['name']) ?>
                                        (CPU: <?= $plan['cpu'] ?>, RAM: <?= $plan['ram'] ?>MB,
                                        Disk: <?= $plan['disk'] ?>GB)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="custom_resources" style="<?= empty($vm['tariff_id']) ? '' : 'display:none;' ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>CPU (ядра)</label>
                                        <input type="number" class="form-control" name="custom_cpu"
                                               value="<?= $vm['cpu_cores'] ?? 1 ?>" min="1" max="<?= $vm['vm_type'] === 'qemu' ? '32' : '16' ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>RAM (MB)</label>
                                        <input type="number" class="form-control" name="custom_ram"
                                               value="<?= $vm['ram_mb'] ?? 1024 ?>" min="128" max="<?= $vm['vm_type'] === 'qemu' ? '131072' : '65536' ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Диск (GB)</label>
                                        <input type="number" class="form-control" name="custom_disk"
                                               value="<?= $vm['disk_gb'] ?? 10 ?>" min="1" max="2048" <?= $vm['vm_type'] === 'lxc' ? 'required' : '' ?>>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="change_resources" class="btn btn-primary">
                                <i class="fas fa-save"></i> Изменить ресурсы
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Блок управления дисками -->
                <div class="settings-card" id="disks-section">
                    <div class="card-header">
                        <h5><i class="fas fa-hdd"></i> Управление дисками</h5>
                    </div>
                    <div class="card-body">
                        <div class="loading-placeholder">
                            <div class="loading-spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Блок управления сетями -->
                <div class="settings-card" id="networks-section">
                    <div class="card-header">
                        <h5><i class="fas fa-network-wired"></i> Управление сетями</h5>
                    </div>
                    <div class="card-body">
                        <div class="loading-placeholder">
                            <div class="loading-spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Блок удаления VM -->
                <div class="settings-card danger">
                    <div class="card-header">
                        <h5><i class="fas fa-trash-alt"></i> Удаление <?= $vm['vm_type'] === 'qemu' ? 'Виртуальной машины' : 'Контейнера' ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="delete-warning">
                            <p><i class="fas fa-exclamation-triangle"></i> <strong>Внимание!</strong> Это действие невозможно отменить. <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнер' ?> будет полностью удален с гипервизора вместе со всеми данными.</p>
                        </div>
                        <form method="POST" id="deleteForm">
                            <button type="button" onclick="confirmDelete()" class="btn btn-danger">
                                <i class="fas fa-trash-alt"></i> Удалить <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнер' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
    // Показываем/скрываем кастомные ресурсы при изменении тарифа
    document.getElementById('planSelect').addEventListener('change', function() {
        document.getElementById('custom_resources').style.display = this.value == '0' ? 'block' : 'none';
    });

    // Инициализация состояния при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        const planSelect = document.getElementById('planSelect');
        if (planSelect.value == '0') {
            document.getElementById('custom_resources').style.display = 'block';
        }

        // Асинхронная загрузка данных
        loadVmData();

        // Кнопка "Наверх"
        const scrollToTopBtn = document.getElementById('scrollToTop');

        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                scrollToTopBtn.classList.add('visible');
            } else {
                scrollToTopBtn.classList.remove('visible');
            }
        });

        scrollToTopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        // Адаптивность для сайдбара
        function handleSidebarCollapse() {
            const sidebar = document.querySelector('.modern-sidebar');
            const mainContent = document.querySelector('.main-content');

            if (window.innerWidth <= 992) {
                if (sidebar && mainContent) {
                    sidebar.style.transform = 'translateX(-100%)';
                    mainContent.style.marginLeft = '0';
                }
            } else {
                if (sidebar && mainContent) {
                    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                    if (isCollapsed) {
                        sidebar.classList.add('collapsed');
                        mainContent.style.marginLeft = '80px';
                    } else {
                        sidebar.classList.remove('collapsed');
                        mainContent.style.marginLeft = '280px';
                    }
                }
            }
        }

        handleSidebarCollapse();
        window.addEventListener('resize', handleSidebarCollapse);
    });

    // Подтверждение удаления
    function confirmDelete() {
        const vmType = '<?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'контейнер' ?>';
        const vmName = '<?= htmlspecialchars($vm['name'] ?? '') ?>';

        if (confirm(`Вы уверены что хотите удалить ${vmType} "${vmName}"? Это действие нельзя отменить!`)) {
            const form = document.getElementById('deleteForm');
            const submitBtn = document.createElement('button');
            submitBtn.type = 'submit';
            submitBtn.name = 'delete_vm';
            submitBtn.style.display = 'none';
            form.appendChild(submitBtn);
            submitBtn.click();
        }
    }

    // Функция для асинхронной загрузки данных
    function loadVmData() {
        const vmId = <?= $vm['id'] ?>;
        const vmType = '<?= $vm['vm_type'] ?>';

        // Загрузка данных о дисках
        fetch(`/api/vm_settings.php?action=get_disks&id=${vmId}`)
            .then(response => response.json())
            .then(data => {
                const disksSection = document.getElementById('disks-section');
                disksSection.querySelector('.card-body').innerHTML = '';

                if (vmType === 'qemu') {
                    // Для KVM отображаем все диски
                    if (data.disks && data.disks.length > 0) {
                        data.disks.forEach(disk => {
                            const diskForm = createDiskForm(disk);
                            disksSection.querySelector('.card-body').appendChild(diskForm);
                        });
                    } else {
                        disksSection.querySelector('.card-body').innerHTML = '<p class="text-muted">Диски не найдены</p>';
                    }
                } else {
                    // Для LXC отображаем только rootfs
                    const currentSize = data.disk_size || <?= $vm['disk_gb'] ?? 10 ?>;
                    const diskForm = createLxcDiskForm(currentSize);
                    disksSection.querySelector('.card-body').appendChild(diskForm);
                }
            })
            .catch(error => {
                console.error('Error loading disks:', error);
                document.getElementById('disks-section').querySelector('.card-body').innerHTML =
                    '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Ошибка загрузки данных о дисках</div>';
            });

        // Загрузка данных о сетях
        fetch(`/api/vm_settings.php?action=get_networks&id=${vmId}`)
            .then(response => response.json())
            .then(data => {
                const networksSection = document.getElementById('networks-section');
                networksSection.querySelector('.card-body').innerHTML = '';

                if (data.networks && data.networks.length > 0) {
                    data.networks.forEach(net => {
                        const netElement = createNetworkElement(net, vmType);
                        networksSection.querySelector('.card-body').appendChild(netElement);
                    });
                } else {
                    networksSection.querySelector('.card-body').innerHTML += '<p class="text-muted">Сетевые интерфейсы не найдены</p>';
                }

                // Добавляем форму для добавления новой сети
                const addNetworkForm = createAddNetworkForm(data.availableNetworks || [],
                    data.networks ? data.networks.length : 0, vmType);
                networksSection.querySelector('.card-body').appendChild(addNetworkForm);
            })
            .catch(error => {
                console.error('Error loading networks:', error);
                document.getElementById('networks-section').querySelector('.card-body').innerHTML =
                    '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Ошибка загрузки данных о сетях</div>';
            });
    }

    // Создание формы для диска KVM
    function createDiskForm(disk) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'disk-form';

        form.innerHTML = `
            <input type="hidden" name="disk_id" value="${disk.id}">
            <div class="disk-info">
                <div class="disk-info-item">
                    <strong>Диск ${disk.id}</strong>
                    <div>${disk.size} GB</div>
                </div>
                <div class="disk-info-item">
                    <strong>Хранилище</strong>
                    <div>${disk.storage}</div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Новый размер (GB)</label>
                    <input type="number" class="form-control" name="disk_size"
                           value="${parseInt(disk.size) + 1}" min="${parseInt(disk.size) + 1}" max="2048" required>
                    <small class="text-muted">Минимальный размер: ${parseInt(disk.size) + 1}GB</small>
                </div>
                <div class="form-group">
                    <label>Хранилище</label>
                    <select class="form-control" name="disk_storage" required>
                        ${disk.storages.map(storage => `
                            <option value="${storage.name}" ${storage.selected ? 'selected' : ''}>
                                ${storage.name} (${storage.available}GB свободно)
                            </option>
                        `).join('')}
                    </select>
                </div>
            </div>
            <button type="submit" name="change_disk" class="btn btn-primary">
                <i class="fas fa-hdd"></i> Изменить диск
            </button>
        `;

        return form;
    }

    // Создание формы для диска LXC (только изменение размера)
    function createLxcDiskForm(currentSize) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'disk-form';

        // Для LXC минимальный размер = текущий + 1GB
        const minSize = parseInt(currentSize) + 1;

        form.innerHTML = `
            <div class="disk-info">
                <div class="disk-info-item">
                    <strong>RootFS</strong>
                    <div>${currentSize} GB</div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Новый размер (GB)</label>
                    <input type="number" class="form-control" name="disk_size"
                           value="${minSize}" min="${minSize}" max="2048" required>
                    <small class="text-muted">Минимальный размер: ${minSize}GB</small>
                </div>
            </div>
            <input type="hidden" name="disk_id" value="0">
            <input type="hidden" name="disk_storage" value="local">
            <button type="submit" name="change_disk" class="btn btn-primary">
                <i class="fas fa-hdd"></i> Изменить размер
            </button>
        `;

        return form;
    }

    // Создание элемента сети
    function createNetworkElement(net, vmType) {
        const div = document.createElement('div');
        div.className = 'network-form';

        let networkContent = `
            <h6 style="margin-bottom: 12px; color: #1e293b; font-size: 14px; font-weight: 600;">
                <i class="fas fa-ethernet"></i> Интерфейс ${net.id}
            </h6>
            <div class="network-info">
                <div class="network-info-item">
                    <strong>${vmType === 'qemu' ? 'MAC' : 'Имя'}</strong>
                    <div>${net.mac || net.name || 'N/A'}</div>
                </div>
                <div class="network-info-item">
                    <strong>Bridge</strong>
                    <div>${net.bridge}</div>
                </div>
        `;

        if (net.alias) {
            networkContent += `
                <div class="network-info-item">
                    <strong>Описание</strong>
                    <div>${net.alias}</div>
                </div>
            `;
        }

        networkContent += `</div>`;

        if (net.alias) {
            networkContent += `<div class="sdn-alias">${net.alias}</div>`;
        }

        div.innerHTML = networkContent;
        return div;
    }

    // Создание формы для добавления сети
    function createAddNetworkForm(availableNetworks, currentNetworksCount, vmType) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'network-form';

        let networkIdField = '';
        if (vmType === 'lxc') {
            // Для LXC имя обязательно (net0, net1 и т.д.)
            const nextId = currentNetworksCount;
            networkIdField = `
                <div class="form-group">
                    <label>ID интерфейса</label>
                    <input type="text" class="form-control" name="network_id"
                           value="net${nextId}" pattern="net[0-9]" maxlength="4" required>
                    <small class="text-muted">Формат: net0, net1, ..., net9</small>
                </div>
            `;
        } else {
            // Для KVM имя не обязательно
            networkIdField = `
                <input type="hidden" name="network_id" value="net${currentNetworksCount}">
            `;
        }

        let networkOptions = '';
        if (availableNetworks.length > 0) {
            availableNetworks.forEach(net => {
                if (typeof net === 'object') {
                    // SDN сеть с alias
                    networkOptions += `
                        <option value="${net.name}">
                            ${net.name}${net.alias ? ' (' + net.alias + ')' : ''}
                        </option>
                    `;
                } else {
                    // Обычная сеть
                    networkOptions += `<option value="${net}">${net}</option>`;
                }
            });
        } else {
            networkOptions = '<option value="vmbr0">vmbr0</option>';
        }

        form.innerHTML = `
            <h6 style="margin-bottom: 12px; color: #1e293b; font-size: 14px; font-weight: 600;">
                <i class="fas fa-plus-circle"></i> Добавить интерфейс
            </h6>
            <div class="form-row">
                ${networkIdField}
                <div class="form-group">
                    <label>Bridge</label>
                    <select class="form-control" name="bridge" required>
                        ${networkOptions}
                    </select>
                </div>
            </div>
            <button type="submit" name="add_network" class="btn btn-primary">
                <i class="fas fa-network-wired"></i> Добавить интерфейс
            </button>
        `;

        return form;
    }
    </script>
</body>
</html>
