<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/admin_functions.php';

$db = new Database();
$pdo = $db->getConnection();

// Функция для получения реального использования ресурсов из таблицы vms
function getCurrentUsage2($pdo, $userId) {
    // Получаем сумму ресурсов всех ВМ пользователя
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as vms,
            SUM(cpu) as cpu,
            SUM(ram) as ram,
            SUM(disk) as disk
        FROM vms
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $usage = $stmt->fetch(PDO::FETCH_ASSOC);

    // Если у пользователя нет ВМ, возвращаем нули
    return $usage ?: ['vms' => 0, 'cpu' => 0, 'ram' => 0, 'disk' => 0];
}

// Создаем квоты для пользователей, у которых их нет
$pdo->beginTransaction();
$allUsers = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allUsers as $user) {
    $stmt = $pdo->prepare("SELECT id FROM user_quotas WHERE user_id = ?");
    $stmt->execute([$user['id']]);

    if (!$stmt->fetch()) {
        $insert = $pdo->prepare("
            INSERT INTO user_quotas (user_id, max_vms, max_cpu, max_ram, max_disk)
            VALUES (?, 3, 10, 10240, 200)
        ");
        $insert->execute([$user['id']]);
    }
}
$pdo->commit();

// Получаем список пользователей с их квотами
$users = $pdo->query("
    SELECT u.id, u.full_name, u.email,
           q.max_vms, q.max_cpu, q.max_ram, q.max_disk
    FROM users u
    JOIN user_quotas q ON u.id = q.user_id
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// Обработка формы обновления квот
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quotas'])) {
    try {
        $pdo->beginTransaction();

        foreach ($_POST['quotas'] as $userId => $quota) {
            $stmt = $pdo->prepare("
                UPDATE user_quotas
                SET max_vms = :max_vms,
                    max_cpu = :max_cpu,
                    max_ram = :max_ram,
                    max_disk = :max_disk
                WHERE user_id = :user_id
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':max_vms' => (int)$quota['max_vms'],
                ':max_cpu' => (int)$quota['max_cpu'],
                ':max_ram' => (int)$quota['max_ram'],
                ':max_disk' => (int)$quota['max_disk']
            ]);
        }

        $pdo->commit();
        $_SESSION['success_message'] = 'Квоты успешно обновлены!';
        header('Location: quotas.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = 'Ошибка при обновлении квот: ' . $e->getMessage();
    }
}

$title = "Управление квотами | HomeVlad Cloud";
require 'admin_header.php';
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
    <style>
        <?php include __DIR__ . '/../admin/css/admin_style.css'; ?>
        
        /* ========== СТИЛИ ДЛЯ СТРАНИЦЫ КВОТ ========== */
        :root {
            --quota-bg: #f8fafc;
            --quota-card-bg: #ffffff;
            --quota-border: #e2e8f0;
            --quota-text: #1e293b;
            --quota-text-secondary: #64748b;
            --quota-text-muted: #94a3b8;
            --quota-hover: #f1f5f9;
            --quota-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --quota-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --quota-accent: #00bcd4;
            --quota-accent-light: rgba(0, 188, 212, 0.1);
            --quota-success: #10b981;
            --quota-warning: #f59e0b;
            --quota-danger: #ef4444;
            --quota-info: #3b82f6;
        }

        [data-theme="dark"] {
            --quota-bg: #0f172a;
            --quota-card-bg: #1e293b;
            --quota-border: #334155;
            --quota-text: #ffffff;
            --quota-text-secondary: #cbd5e1;
            --quota-text-muted: #94a3b8;
            --quota-hover: #2d3748;
            --quota-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --quota-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .quotas-wrapper {
            padding: 20px;
            background: var(--quota-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .quotas-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .quotas-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .quotas-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .quotas-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--quota-card-bg);
            border-radius: 12px;
            border: 1px solid var(--quota-border);
            box-shadow: var(--quota-shadow);
        }

        .quotas-header-left h1 {
            color: var(--quota-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .quotas-header-left h1 i {
            color: var(--quota-accent);
        }

        .quotas-header-left p {
            color: var(--quota-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        /* ========== ТАБЛИЦА КВОТ ========== */
        .quotas-table-container {
            background: var(--quota-card-bg);
            border-radius: 12px;
            border: 1px solid var(--quota-border);
            box-shadow: var(--quota-shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .quotas-table {
            width: 100%;
            border-collapse: collapse;
        }

        .quotas-table thead th {
            color: var(--quota-text-secondary);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 20px 16px;
            text-align: left;
            background: var(--quota-hover);
            border-bottom: 2px solid var(--quota-border);
        }

        .quotas-table tbody tr {
            border-bottom: 1px solid var(--quota-border);
            transition: all 0.3s ease;
        }

        .quotas-table tbody tr:hover {
            background: var(--quota-accent-light);
        }

        .quotas-table tbody td {
            color: var(--quota-text);
            font-size: 14px;
            padding: 20px 16px;
            vertical-align: top;
        }

        /* ========== СТИЛИ ДЛЯ ЯЧЕЕК ========== */
        .quota-user-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .quota-user-name {
            color: var(--quota-text);
            font-weight: 600;
            font-size: 14px;
        }

        .quota-user-email {
            color: var(--quota-text-secondary);
            font-size: 12px;
            font-family: 'Consolas', 'Monaco', monospace;
        }

        .quota-input-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .quota-input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--quota-border);
            border-radius: 8px;
            background: var(--quota-card-bg);
            color: var(--quota-text);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .quota-input:focus {
            outline: none;
            border-color: var(--quota-accent);
            box-shadow: 0 0 0 3px var(--quota-accent-light);
        }

        /* ========== ПРОГРЕСС-БАР ========== */
        .quota-progress {
            margin-top: 8px;
        }

        .quota-progress-bar {
            height: 6px;
            background: var(--quota-border);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .quota-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--quota-success), var(--quota-accent));
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        .quota-progress-fill.warning {
            background: linear-gradient(90deg, var(--quota-warning), #ff9800);
        }

        .quota-progress-fill.danger {
            background: linear-gradient(90deg, var(--quota-danger), #f44336);
        }

        .quota-progress-text {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: var(--quota-text-secondary);
        }

        .quota-usage {
            font-weight: 600;
            color: var(--quota-text);
        }

        .quota-limit {
            color: var(--quota-text-muted);
        }

        /* ========== КНОПКИ ========== */
        .quotas-actions {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            margin-top: 30px;
            padding: 20px;
            background: var(--quota-card-bg);
            border-radius: 12px;
            border: 1px solid var(--quota-border);
            box-shadow: var(--quota-shadow);
        }

        .quota-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            outline: none;
        }

        .quota-btn-primary {
            background: linear-gradient(135deg, var(--quota-accent), #0097a7);
            color: white;
        }

        .quota-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--quota-shadow-hover);
        }

        .quota-btn-secondary {
            background: var(--quota-hover);
            color: var(--quota-text);
            border: 1px solid var(--quota-border);
        }

        .quota-btn-secondary:hover {
            background: var(--quota-border);
            transform: translateY(-2px);
        }

        /* ========== СТАТИСТИКА КВОТ ========== */
        .quotas-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .quota-stat-card {
            background: var(--quota-card-bg);
            border: 1px solid var(--quota-border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .quota-stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--quota-accent);
            box-shadow: var(--quota-shadow-hover);
        }

        .quota-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            margin-bottom: 16px;
            background: linear-gradient(135deg, var(--quota-accent), #0097a7);
        }

        .quota-stat-content h3 {
            color: var(--quota-text-secondary);
            font-size: 14px;
            font-weight: 500;
            margin: 0 0 8px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .quota-stat-value {
            color: var(--quota-text);
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px 0;
        }

        .quota-stat-subtext {
            color: var(--quota-text-muted);
            font-size: 12px;
            margin: 0;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 1024px) {
            .quotas-table {
                display: block;
                overflow-x: auto;
            }
            
            .quotas-table thead th,
            .quotas-table tbody td {
                white-space: nowrap;
                min-width: 150px;
            }
        }

        @media (max-width: 768px) {
            .quotas-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            
            .quotas-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quotas-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .quotas-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quotas-table thead th,
            .quotas-table tbody td {
                padding: 12px 8px;
                font-size: 13px;
            }
            
            .quota-input {
                padding: 8px 10px;
                font-size: 13px;
            }
        }

        /* ========== АНИМАЦИИ ========== */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .quotas-table tbody tr {
            animation: slideIn 0.5s ease forwards;
        }

        .quotas-table tbody tr:nth-child(odd) {
            animation-delay: 0.1s;
        }

        .quotas-table tbody tr:nth-child(even) {
            animation-delay: 0.2s;
        }

        /* ========== ПУСТОЙ СОСТОЯНИЕ ========== */
        .quotas-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--quota-text-secondary);
        }

        .quotas-empty-icon {
            font-size: 48px;
            color: var(--quota-text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .quotas-empty-state h3 {
            color: var(--quota-text);
            font-size: 18px;
            margin-bottom: 10px;
        }

        .quotas-empty-state p {
            color: var(--quota-text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* ========== ВАЛИДАЦИЯ ========== */
        .quota-input.invalid {
            border-color: var(--quota-danger);
            background: rgba(239, 68, 68, 0.05);
        }

        .quota-input.valid {
            border-color: var(--quota-success);
        }

        .validation-message {
            font-size: 11px;
            margin-top: 4px;
            display: none;
        }

        .validation-message.error {
            color: var(--quota-danger);
            display: block;
        }

        .validation-message.success {
            color: var(--quota-success);
            display: block;
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="quotas-wrapper">
        <!-- Шапка страницы -->
        <div class="quotas-header">
            <div class="quotas-header-left">
                <h1><i class="fas fa-chart-pie"></i> Управление квотами</h1>
                <p>Настройка лимитов ресурсов для пользователей</p>
            </div>
            <div class="quotas-header-right">
                <button type="button" class="quota-btn quota-btn-secondary" onclick="resetToDefaults()">
                    <i class="fas fa-redo"></i> Сбросить по умолчанию
                </button>
            </div>
        </div>

        <?php if (!empty($_SESSION['error_message'])): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; animation: slideIn 0.3s ease;">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($_SESSION['error_message']) ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; animation: slideIn 0.3s ease;">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success_message']) ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Статистика квот -->
        <div class="quotas-stats-grid">
            <div class="quota-stat-card">
                <div class="quota-stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="quota-stat-content">
                    <h3>Пользователей</h3>
                    <div class="quota-stat-value"><?= count($users) ?></div>
                    <p class="quota-stat-subtext">Всего с квотами</p>
                </div>
            </div>
            
            <div class="quota-stat-card">
                <div class="quota-stat-icon" style="background: linear-gradient(135deg, var(--quota-warning), #f59e0b);">
                    <i class="fas fa-server"></i>
                </div>
                <div class="quota-stat-content">
                    <h3>Общий лимит ВМ</h3>
                    <?php 
                    $totalVMs = array_sum(array_column($users, 'max_vms'));
                    $usedVMs = 0;
                    foreach ($users as $user) {
                        $usage = getCurrentUsage2($pdo, $user['id']);
                        $usedVMs += $usage['vms'];
                    }
                    ?>
                    <div class="quota-stat-value"><?= $usedVMs ?>/<?= $totalVMs ?></div>
                    <p class="quota-stat-subtext">Использовано / Всего</p>
                </div>
            </div>
            
            <div class="quota-stat-card">
                <div class="quota-stat-icon" style="background: linear-gradient(135deg, var(--quota-info), #3b82f6);">
                    <i class="fas fa-microchip"></i>
                </div>
                <div class="quota-stat-content">
                    <h3>Общий CPU</h3>
                    <?php 
                    $totalCPU = array_sum(array_column($users, 'max_cpu'));
                    $usedCPU = 0;
                    foreach ($users as $user) {
                        $usage = getCurrentUsage2($pdo, $user['id']);
                        $usedCPU += $usage['cpu'];
                    }
                    ?>
                    <div class="quota-stat-value"><?= $usedCPU ?>/<?= $totalCPU ?> ядер</div>
                    <p class="quota-stat-subtext">Использовано / Всего</p>
                </div>
            </div>
            
            <div class="quota-stat-card">
                <div class="quota-stat-icon" style="background: linear-gradient(135deg, var(--quota-success), #10b981);">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="quota-stat-content">
                    <h3>Общий диск</h3>
                    <?php 
                    $totalDisk = array_sum(array_column($users, 'max_disk'));
                    $usedDisk = 0;
                    foreach ($users as $user) {
                        $usage = getCurrentUsage2($pdo, $user['id']);
                        $usedDisk += $usage['disk'];
                    }
                    ?>
                    <div class="quota-stat-value"><?= $usedDisk ?>/<?= $totalDisk ?> GB</div>
                    <p class="quota-stat-subtext">Использовано / Всего</p>
                </div>
            </div>
        </div>

        <form method="POST" action="quotas.php">
            <!-- Таблица квот -->
            <div class="quotas-table-container">
                <table class="quotas-table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Макс. ВМ</th>
                            <th>Макс. CPU</th>
                            <th>Макс. RAM (MB)</th>
                            <th>Макс. Диск (GB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user):
                            $usage = getCurrentUsage2($pdo, $user['id']);
                            $vmPercentage = $user['max_vms'] > 0 ? min(100, ($usage['vms'] / $user['max_vms'] * 100)) : 0;
                            $cpuPercentage = $user['max_cpu'] > 0 ? min(100, ($usage['cpu'] / $user['max_cpu'] * 100)) : 0;
                            $ramPercentage = $user['max_ram'] > 0 ? min(100, ($usage['ram'] / $user['max_ram'] * 100)) : 0;
                            $diskPercentage = $user['max_disk'] > 0 ? min(100, ($usage['disk'] / $user['max_disk'] * 100)) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="quota-user-info">
                                        <div class="quota-user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                                        <div class="quota-user-email"><?= htmlspecialchars($user['email']) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quota-input-group">
                                        <input type="number" 
                                               name="quotas[<?= $user['id'] ?>][max_vms]"
                                               value="<?= $user['max_vms'] ?>" 
                                               min="1" 
                                               max="100"
                                               class="quota-input"
                                               data-usage="<?= $usage['vms'] ?>">
                                        
                                        <div class="quota-progress">
                                            <div class="quota-progress-bar">
                                                <div class="quota-progress-fill <?= $vmPercentage > 80 ? 'danger' : ($vmPercentage > 50 ? 'warning' : '') ?>" 
                                                     style="width: <?= $vmPercentage ?>%"></div>
                                            </div>
                                            <div class="quota-progress-text">
                                                <span class="quota-usage"><?= $usage['vms'] ?></span>
                                                <span class="quota-limit">из <?= $user['max_vms'] ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quota-input-group">
                                        <input type="number" 
                                               name="quotas[<?= $user['id'] ?>][max_cpu]"
                                               value="<?= $user['max_cpu'] ?>" 
                                               min="1" 
                                               max="100"
                                               class="quota-input"
                                               data-usage="<?= $usage['cpu'] ?>">
                                        
                                        <div class="quota-progress">
                                            <div class="quota-progress-bar">
                                                <div class="quota-progress-fill <?= $cpuPercentage > 80 ? 'danger' : ($cpuPercentage > 50 ? 'warning' : '') ?>" 
                                                     style="width: <?= $cpuPercentage ?>%"></div>
                                            </div>
                                            <div class="quota-progress-text">
                                                <span class="quota-usage"><?= $usage['cpu'] ?></span>
                                                <span class="quota-limit">из <?= $user['max_cpu'] ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quota-input-group">
                                        <input type="number" 
                                               name="quotas[<?= $user['id'] ?>][max_ram]"
                                               value="<?= $user['max_ram'] ?>" 
                                               min="256" 
                                               step="256"
                                               class="quota-input"
                                               data-usage="<?= $usage['ram'] ?>">
                                        
                                        <div class="quota-progress">
                                            <div class="quota-progress-bar">
                                                <div class="quota-progress-fill <?= $ramPercentage > 80 ? 'danger' : ($ramPercentage > 50 ? 'warning' : '') ?>" 
                                                     style="width: <?= $ramPercentage ?>%"></div>
                                            </div>
                                            <div class="quota-progress-text">
                                                <span class="quota-usage"><?= round($usage['ram'] / 1024, 1) ?> GB</span>
                                                <span class="quota-limit">из <?= round($user['max_ram'] / 1024, 1) ?> GB</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quota-input-group">
                                        <input type="number" 
                                               name="quotas[<?= $user['id'] ?>][max_disk]"
                                               value="<?= $user['max_disk'] ?>" 
                                               min="10" 
                                               step="10"
                                               class="quota-input"
                                               data-usage="<?= $usage['disk'] ?>">
                                        
                                        <div class="quota-progress">
                                            <div class="quota-progress-bar">
                                                <div class="quota-progress-fill <?= $diskPercentage > 80 ? 'danger' : ($diskPercentage > 50 ? 'warning' : '') ?>" 
                                                     style="width: <?= $diskPercentage ?>%"></div>
                                            </div>
                                            <div class="quota-progress-text">
                                                <span class="quota-usage"><?= $usage['disk'] ?> GB</span>
                                                <span class="quota-limit">из <?= $user['max_disk'] ?> GB</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Кнопки действий -->
            <div class="quotas-actions">
                <a href="javascript:history.back()" class="quota-btn quota-btn-secondary">
                    <i class="fas fa-arrow-left"></i> Назад
                </a>
                <button type="submit" name="update_quotas" class="quota-btn quota-btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Валидация ввода квот
        const quotaInputs = document.querySelectorAll('.quota-input');
        
        quotaInputs.forEach(input => {
            const usage = parseInt(input.dataset.usage) || 0;
            
            input.addEventListener('change', function() {
                const value = parseInt(this.value);
                const min = parseInt(this.min);
                const max = parseInt(this.max);
                
                // Проверка минимального значения
                if (value < min) {
                    this.value = min;
                    showValidationMessage(this, `Минимальное значение: ${min}`, 'error');
                    return;
                }
                
                // Проверка максимального значения
                if (max && value > max) {
                    this.value = max;
                    showValidationMessage(this, `Максимальное значение: ${max}`, 'error');
                    return;
                }
                
                // Проверка, что новое значение не меньше текущего использования
                if (value < usage) {
                    showValidationMessage(this, `Текущее использование: ${usage}. Установите значение ≥ ${usage}`, 'error');
                    this.classList.add('invalid');
                } else {
                    showValidationMessage(this, '✓ Значение корректно', 'success');
                    this.classList.remove('invalid');
                    this.classList.add('valid');
                }
                
                // Обновление прогресс-бара
                updateProgressBar(this, value, usage);
            });
            
            input.addEventListener('input', function() {
                // Скрываем сообщение при начале ввода
                hideValidationMessage(this);
                this.classList.remove('invalid', 'valid');
            });
        });
        
        // Анимация прогресс-баров при загрузке
        const progressBars = document.querySelectorAll('.quota-progress-fill');
        progressBars.forEach(bar => {
            const originalWidth = bar.style.width;
            bar.style.width = '0%';
            
            setTimeout(() => {
                bar.style.transition = 'width 1.5s cubic-bezier(0.4, 0, 0.2, 1)';
                bar.style.width = originalWidth;
            }, 100);
        });
        
        // Анимация строк таблицы при загрузке
        const tableRows = document.querySelectorAll('.quotas-table tbody tr');
        tableRows.forEach((row, index) => {
            row.style.animationDelay = `${index * 0.05}s`;
        });
        
        // Обновление отступа при изменении размера окна
        function updateWrapperMargin() {
            const wrapper = document.querySelector('.quotas-wrapper');
            const sidebar = document.querySelector('.admin-sidebar');
            
            if (window.innerWidth <= 768) {
                wrapper.style.marginLeft = '0';
            } else if (sidebar.classList.contains('compact')) {
                wrapper.style.marginLeft = '70px';
            } else {
                wrapper.style.marginLeft = '280px';
            }
        }
        
        window.addEventListener('resize', updateWrapperMargin);
        
        // Наблюдатель за изменением класса сайдбара
        const sidebar = document.querySelector('.admin-sidebar');
        if (sidebar) {
            const observer = new MutationObserver(updateWrapperMargin);
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }
    });
    
    function updateProgressBar(input, limit, usage) {
        const row = input.closest('tr');
        const resourceType = input.name.includes('max_vms') ? 'vms' :
                            input.name.includes('max_cpu') ? 'cpu' :
                            input.name.includes('max_ram') ? 'ram' : 'disk';
        
        const progressFill = row.querySelector(`td:nth-child(${getColumnIndex(resourceType)}) .quota-progress-fill`);
        const usageSpan = row.querySelector(`td:nth-child(${getColumnIndex(resourceType)}) .quota-usage`);
        const limitSpan = row.querySelector(`td:nth-child(${getColumnIndex(resourceType)}) .quota-limit`);
        
        if (progressFill && usageSpan && limitSpan) {
            const percentage = limit > 0 ? Math.min(100, (usage / limit * 100)) : 0;
            
            // Обновляем прогресс-бар
            progressFill.style.width = percentage + '%';
            
            // Обновляем классы для цвета
            progressFill.className = 'quota-progress-fill';
            if (percentage > 80) {
                progressFill.classList.add('danger');
            } else if (percentage > 50) {
                progressFill.classList.add('warning');
            }
            
            // Обновляем текст
            if (resourceType === 'ram') {
                usageSpan.textContent = `${(usage / 1024).toFixed(1)} GB`;
                limitSpan.textContent = `из ${(limit / 1024).toFixed(1)} GB`;
            } else if (resourceType === 'disk') {
                usageSpan.textContent = `${usage} GB`;
                limitSpan.textContent = `из ${limit} GB`;
            } else {
                usageSpan.textContent = usage;
                limitSpan.textContent = `из ${limit}`;
            }
        }
    }
    
    function getColumnIndex(resourceType) {
        switch(resourceType) {
            case 'vms': return 2;
            case 'cpu': return 3;
            case 'ram': return 4;
            case 'disk': return 5;
            default: return 2;
        }
    }
    
    function showValidationMessage(input, message, type) {
        // Удаляем старое сообщение
        hideValidationMessage(input);
        
        // Создаем новое сообщение
        const messageDiv = document.createElement('div');
        messageDiv.className = `validation-message ${type}`;
        messageDiv.textContent = message;
        
        // Вставляем после инпута
        input.parentNode.insertBefore(messageDiv, input.nextSibling);
        
        // Автоматически скрываем через 5 секунд
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.style.opacity = '0';
                messageDiv.style.transition = 'opacity 0.3s ease';
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.parentNode.removeChild(messageDiv);
                    }
                }, 300);
            }
        }, 5000);
    }
    
    function hideValidationMessage(input) {
        const existingMessage = input.parentNode.querySelector('.validation-message');
        if (existingMessage) {
            existingMessage.parentNode.removeChild(existingMessage);
        }
    }
    
    function resetToDefaults() {
        if (confirm('Вы уверены, что хотите сбросить все квоты к значениям по умолчанию?\n\nПо умолчанию:\n• Макс. ВМ: 3\n• Макс. CPU: 10\n• Макс. RAM: 10240 MB\n• Макс. Диск: 200 GB')) {
            const inputs = document.querySelectorAll('.quota-input');
            inputs.forEach(input => {
                if (input.name.includes('max_vms')) {
                    input.value = 3;
                } else if (input.name.includes('max_cpu')) {
                    input.value = 10;
                } else if (input.name.includes('max_ram')) {
                    input.value = 10240;
                } else if (input.name.includes('max_disk')) {
                    input.value = 200;
                }
                
                // Триггерим изменение для обновления прогресс-баров
                input.dispatchEvent(new Event('change'));
            });
            
            // Показываем уведомление
            showNotification('Квоты сброшены к значениям по умолчанию', 'success');
        }
    }
    
    function showNotification(message, type = 'info') {
        // Создаем уведомление
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            ${message}
        `;
        
        document.body.appendChild(notification);
        
        // Автоматически скрываем через 5 секунд
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.3s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 5000);
    }
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>