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

// Получаем список кластеров с количеством нод
$clusters = $pdo->query("
    SELECT c.*, COUNT(n.id) as nodes_count
    FROM proxmox_clusters c
    LEFT JOIN proxmox_nodes n ON n.cluster_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Получаем список нод с именами кластеров
$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Статистика для карточек
$totalClusters = count($clusters);
$totalNodes = count($nodes);
$activeNodes = count(array_filter($nodes, function($node) {
    return $node['is_active'] == 1;
}));
$inactiveNodes = $totalNodes - $activeNodes;

$title = "Управление нодами и кластерами | HomeVlad Cloud";
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

        /* ========== СТИЛИ ДЛЯ СТРАНИЦЫ НОД ========== */
        :root {
            --nodes-bg: #f8fafc;
            --nodes-card-bg: #ffffff;
            --nodes-border: #e2e8f0;
            --nodes-text: #1e293b;
            --nodes-text-secondary: #64748b;
            --nodes-text-muted: #94a3b8;
            --nodes-hover: #f1f5f9;
            --nodes-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --nodes-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --nodes-accent: #00bcd4;
            --nodes-accent-light: rgba(0, 188, 212, 0.1);
            --nodes-success: #10b981;
            --nodes-warning: #f59e0b;
            --nodes-danger: #ef4444;
            --nodes-info: #3b82f6;
            --nodes-purple: #8b5cf6;
        }

        [data-theme="dark"] {
            --nodes-bg: #0f172a;
            --nodes-card-bg: #1e293b;
            --nodes-border: #334155;
            --nodes-text: #ffffff;
            --nodes-text-secondary: #cbd5e1;
            --nodes-text-muted: #94a3b8;
            --nodes-hover: #2d3748;
            --nodes-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --nodes-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .nodes-wrapper {
            padding: 20px;
            background: var(--nodes-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .nodes-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .nodes-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .nodes-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .nodes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--nodes-card-bg);
            border-radius: 12px;
            border: 1px solid var(--nodes-border);
            box-shadow: var(--nodes-shadow);
        }

        .nodes-header-left h1 {
            color: var(--nodes-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nodes-header-left h1 i {
            color: var(--nodes-accent);
        }

        .nodes-header-left p {
            color: var(--nodes-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        /* ========== КАРТОЧКИ СТАТИСТИКИ ========== */
        .nodes-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .node-stat-card {
            background: var(--nodes-card-bg);
            border: 1px solid var(--nodes-border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: var(--nodes-shadow);
        }

        .node-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--stat-color);
        }

        .node-stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--nodes-accent);
            box-shadow: var(--nodes-shadow-hover);
        }

        .node-stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .node-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: var(--stat-color);
        }

        .node-stat-trend {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .node-stat-trend-positive {
            background: rgba(16, 185, 129, 0.2);
            color: var(--nodes-success);
        }

        .node-stat-trend-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--nodes-warning);
        }

        .node-stat-content h3 {
            color: var(--nodes-text-secondary);
            font-size: 14px;
            font-weight: 500;
            margin: 0 0 8px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .node-stat-value {
            color: var(--nodes-text);
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px 0;
        }

        .node-stat-subtext {
            color: var(--nodes-text-muted);
            font-size: 12px;
            margin: 0;
        }

        /* Цвета для карточек */
        .node-stat-card-clusters { --stat-color: var(--nodes-purple); }
        .node-stat-card-nodes { --stat-color: var(--nodes-info); }
        .node-stat-card-active { --stat-color: var(--nodes-success); }
        .node-stat-card-inactive { --stat-color: var(--nodes-warning); }

        /* ========== СЕКЦИИ С ТАБЛИЦАМИ ========== */
        .section-wrapper {
            background: var(--nodes-card-bg);
            border-radius: 12px;
            border: 1px solid var(--nodes-border);
            box-shadow: var(--nodes-shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .section-header {
            padding: 20px;
            border-bottom: 1px solid var(--nodes-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--nodes-hover);
        }

        .section-header h2 {
            color: var(--nodes-text);
            font-size: 18px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h2 i {
            color: var(--nodes-accent);
        }

        .section-actions {
            display: flex;
            gap: 10px;
        }

        /* ========== ТАБЛИЦЫ ========== */
        .nodes-table {
            width: 100%;
            border-collapse: collapse;
        }

        .nodes-table thead th {
            color: var(--nodes-text-secondary);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 16px;
            text-align: left;
            background: var(--nodes-hover);
            border-bottom: 2px solid var(--nodes-border);
        }

        .nodes-table tbody tr {
            border-bottom: 1px solid var(--nodes-border);
            transition: all 0.3s ease;
        }

        .nodes-table tbody tr:hover {
            background: var(--nodes-accent-light);
        }

        .nodes-table tbody td {
            color: var(--nodes-text);
            font-size: 14px;
            padding: 16px;
            vertical-align: middle;
        }

        /* ========== СТАТУС-БЭДЖИ ========== */
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

        .status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--nodes-success);
        }

        .status-active::before {
            background: var(--nodes-success);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
        }

        .status-inactive {
            background: rgba(148, 163, 184, 0.2);
            color: var(--nodes-text-muted);
        }

        .status-inactive::before {
            background: var(--nodes-text-muted);
            box-shadow: 0 0 0 2px rgba(148, 163, 184, 0.3);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--nodes-warning);
        }

        .status-warning::before {
            background: var(--nodes-warning);
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3);
        }

        .status-error {
            background: rgba(239, 68, 68, 0.2);
            color: var(--nodes-danger);
        }

        .status-error::before {
            background: var(--nodes-danger);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.3);
        }

        /* ========== КНОПКИ ДЕЙСТВИЙ ========== */
        .btn-group {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
        }

        .btn-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--nodes-info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .btn-info:hover {
            background: var(--nodes-info);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-chart {
            background: rgba(139, 92, 246, 0.1);
            color: var(--nodes-purple);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .btn-chart:hover {
            background: var(--nodes-purple);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .btn-edit {
            background: rgba(245, 158, 11, 0.1);
            color: var(--nodes-warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .btn-edit:hover {
            background: var(--nodes-warning);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--nodes-danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-delete:hover {
            background: var(--nodes-danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .inline-form {
            display: inline;
            margin: 0;
        }

        /* ========== КНОПКИ СОЗДАНИЯ ========== */
        .nodes-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .nodes-action-btn-primary {
            background: linear-gradient(135deg, var(--nodes-accent), #0097a7);
            color: white;
        }

        .nodes-action-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--nodes-shadow-hover);
        }

        .nodes-action-btn-secondary {
            background: var(--nodes-card-bg);
            color: var(--nodes-text);
            border: 1px solid var(--nodes-border);
        }

        .nodes-action-btn-secondary:hover {
            background: var(--nodes-border);
            transform: translateY(-2px);
        }

        /* ========== НЕТ ДАННЫХ ========== */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--nodes-text-secondary);
            font-size: 14px;
        }

        .no-data i {
            font-size: 32px;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 1024px) {
            .nodes-table {
                display: block;
                overflow-x: auto;
            }

            .nodes-table thead th,
            .nodes-table tbody td {
                white-space: nowrap;
                min-width: 120px;
            }
        }

        @media (max-width: 768px) {
            .nodes-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .nodes-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .section-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .section-actions {
                flex-wrap: wrap;
            }

            .btn-group {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .nodes-stats-grid {
                grid-template-columns: 1fr;
            }

            .nodes-table thead th,
            .nodes-table tbody td {
                padding: 12px 8px;
                font-size: 13px;
            }

            .status-badge {
                font-size: 10px;
                padding: 4px 8px;
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

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .nodes-table tbody tr {
            animation: slideIn 0.5s ease forwards;
        }

        .nodes-table tbody tr:nth-child(odd) {
            animation-delay: 0.1s;
        }

        .nodes-table tbody tr:nth-child(even) {
            animation-delay: 0.2s;
        }

        /* ========== СТИЛИ ДЛЯ МОДАЛЬНЫХ ОКОН ========== */
        .swal2-popup {
            background: var(--nodes-card-bg) !important;
            color: var(--nodes-text) !important;
            border: 1px solid var(--nodes-border) !important;
            border-radius: 12px !important;
        }

        .swal2-title {
            color: var(--nodes-text) !important;
        }

        .swal2-content {
            color: var(--nodes-text-secondary) !important;
        }

        /* ========== СТИЛИ ДЛЯ ГРАФИКОВ ========== */
        .charts-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .chart-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .chart-box {
            background: var(--nodes-card-bg);
            border: 1px solid var(--nodes-border);
            border-radius: 12px;
            padding: 20px;
            height: 300px;
        }

        .charts-controls {
            background: var(--nodes-hover);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--nodes-border);
        }

        .time-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .btn-time {
            padding: 8px 16px;
            border-radius: 20px;
            background: var(--nodes-card-bg);
            color: var(--nodes-text);
            border: 1px solid var(--nodes-border);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 12px;
            font-weight: 500;
        }

        .btn-time:hover {
            background: var(--nodes-accent-light);
            border-color: var(--nodes-accent);
        }

        .btn-time.active {
            background: linear-gradient(135deg, var(--nodes-accent), #0097a7);
            color: white;
            border-color: transparent;
        }

        .interval-filters {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .interval-select {
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--nodes-card-bg);
            color: var(--nodes-text);
            border: 1px solid var(--nodes-border);
            font-size: 14px;
            min-width: 150px;
        }

        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--nodes-text);
            cursor: pointer;
        }

        .auto-refresh input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* ========== СТИЛИ ДЛЯ ИНФОРМАЦИИ О НОДЕ ========== */
        .node-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            max-height: 60vh;
            overflow-y: auto;
            padding-right: 10px;
        }

        .node-info::-webkit-scrollbar {
            width: 6px;
        }

        .node-info::-webkit-scrollbar-track {
            background: var(--nodes-hover);
            border-radius: 3px;
        }

        .node-info::-webkit-scrollbar-thumb {
            background: var(--nodes-border);
            border-radius: 3px;
        }

        .info-section {
            background: var(--nodes-hover);
            border: 1px solid var(--nodes-border);
            border-radius: 8px;
            padding: 15px;
        }

        .info-section h3 {
            color: var(--nodes-text);
            font-size: 16px;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--nodes-border);
        }

        .info-section p {
            color: var(--nodes-text-secondary);
            font-size: 14px;
            margin: 8px 0;
        }

        .info-section strong {
            color: var(--nodes-text);
            font-weight: 600;
            min-width: 120px;
            display: inline-block;
        }

        .disk-info {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--nodes-border);
        }

        .disk-info:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="nodes-wrapper">
        <!-- Шапка страницы -->
        <div class="nodes-header">
            <div class="nodes-header-left">
                <h1><i class="fas fa-network-wired"></i> Управление нодами и кластерами</h1>
                <p>Мониторинг и управление серверами Proxmox</p>
            </div>
            <div class="section-actions">
                <a href="add_cluster.php" class="nodes-action-btn nodes-action-btn-primary">
                    <i class="fas fa-plus-circle"></i> Новый кластер
                </a>
                <a href="add_node.php" class="nodes-action-btn nodes-action-btn-secondary">
                    <i class="fas fa-server"></i> Добавить ноду
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; animation: slideIn 0.3s ease;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; animation: slideIn 0.3s ease;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Карточки статистики -->
        <div class="nodes-stats-grid">
            <div class="node-stat-card node-stat-card-clusters">
                <div class="node-stat-header">
                    <div class="node-stat-icon">
                        <i class="fas fa-network-wired"></i>
                    </div>
                </div>
                <div class="node-stat-content">
                    <h3>Кластеры</h3>
                    <div class="node-stat-value"><?= $totalClusters ?></div>
                    <p class="node-stat-subtext">Всего кластеров в системе</p>
                </div>
            </div>

            <div class="node-stat-card node-stat-card-nodes">
                <div class="node-stat-header">
                    <div class="node-stat-icon">
                        <i class="fas fa-server"></i>
                    </div>
                    <?php if ($totalNodes > 0): ?>
                    <span class="node-stat-trend node-stat-trend-positive"><?= $totalNodes ?> нод</span>
                    <?php endif; ?>
                </div>
                <div class="node-stat-content">
                    <h3>Всего нод</h3>
                    <div class="node-stat-value"><?= $totalNodes ?></div>
                    <p class="node-stat-subtext">Серверов Proxmox</p>
                </div>
            </div>

            <div class="node-stat-card node-stat-card-active">
                <div class="node-stat-header">
                    <div class="node-stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <?php if ($activeNodes > 0): ?>
                    <span class="node-stat-trend node-stat-trend-positive"><?= $activeNodes ?> активных</span>
                    <?php endif; ?>
                </div>
                <div class="node-stat-content">
                    <h3>Активные ноды</h3>
                    <div class="node-stat-value"><?= $activeNodes ?></div>
                    <p class="node-stat-subtext">Работают и доступны</p>
                </div>
            </div>

            <div class="node-stat-card node-stat-card-inactive">
                <div class="node-stat-header">
                    <div class="node-stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <?php if ($inactiveNodes > 0): ?>
                    <span class="node-stat-trend node-stat-trend-warning"><?= $inactiveNodes ?> неактивных</span>
                    <?php endif; ?>
                </div>
                <div class="node-stat-content">
                    <h3>Неактивные ноды</h3>
                    <div class="node-stat-value"><?= $inactiveNodes ?></div>
                    <p class="node-stat-subtext">Требуют внимания</p>
                </div>
            </div>
        </div>

        <!-- Секция кластеров -->
        <div class="section-wrapper">
            <div class="section-header">
                <h2><i class="fas fa-network-wired"></i> Список кластеров</h2>
                <div class="section-actions">
                    <a href="add_cluster.php" class="btn-icon btn-edit">
                        <i class="fas fa-plus"></i>
                    </a>
                </div>
            </div>

            <div class="section-body">
                <?php if (!empty($clusters)): ?>
                    <table class="nodes-table">
                        <thead>
                            <tr>
                                <th>Имя</th>
                                <th>Описание</th>
                                <th>Нод</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clusters as $cluster): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; flex-direction: column;">
                                        <strong><?= htmlspecialchars($cluster['name']) ?></strong>
                                        <small style="color: var(--nodes-text-muted);">ID: <?= $cluster['id'] ?></small>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($cluster['description']) ?></td>
                                <td>
                                    <span class="status-badge <?= $cluster['nodes_count'] > 0 ? 'status-active' : 'status-inactive' ?>">
                                        <?= $cluster['nodes_count'] ?> нод
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $cluster['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $cluster['is_active'] ? 'Активен' : 'Неактивен' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="edit_cluster.php?id=<?= $cluster['id'] ?>" class="btn-icon btn-edit" title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" action="delete_cluster.php" class="inline-form" onsubmit="return confirmDeleteCluster()">
                                            <input type="hidden" name="id" value="<?= $cluster['id'] ?>">
                                            <button type="submit" class="btn-icon btn-delete" title="Удалить">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-network-wired"></i>
                        <h3>Нет созданных кластеров</h3>
                        <p>Создайте первый кластер для управления нодами</p>
                        <a href="add_cluster.php" class="nodes-action-btn nodes-action-btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus-circle"></i> Создать кластер
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Секция нод -->
        <div class="section-wrapper">
            <div class="section-header">
                <h2><i class="fas fa-server"></i> Список нод</h2>
                <div class="section-actions">
                    <a href="add_node.php" class="btn-icon btn-edit">
                        <i class="fas fa-plus"></i>
                    </a>
                </div>
            </div>

            <div class="section-body">
                <?php if (!empty($nodes)): ?>
                    <table class="nodes-table">
                        <thead>
                            <tr>
                                <th>Кластер</th>
                                <th>Нода</th>
                                <th>Адрес</th>
                                <th>Порт</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nodes as $node): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; flex-direction: column;">
                                        <strong><?= htmlspecialchars($node['cluster_name']) ?></strong>
                                        <small style="color: var(--nodes-text-muted);">ID: <?= $node['cluster_id'] ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; flex-direction: column;">
                                        <strong><?= htmlspecialchars($node['node_name']) ?></strong>
                                        <small style="color: var(--nodes-text-muted);">ID: <?= $node['id'] ?></small>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($node['hostname']) ?></td>
                                <td><?= $node['api_port'] ?></td>
                                <td>
                                    <span class="status-badge <?= $node['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $node['is_active'] ? 'Активна' : 'Неактивна' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button onclick="showNodeInfo(<?= $node['id'] ?>)" class="btn-icon btn-info" title="Информация">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                        <button onclick="showNodeCharts(<?= $node['id'] ?>, '<?= addslashes($node['cluster_name']) ?>', '<?= addslashes($node['node_name']) ?>')" class="btn-icon btn-chart" title="Графики">
                                            <i class="fas fa-chart-line"></i>
                                        </button>
                                        <a href="edit_node.php?id=<?= $node['id'] ?>" class="btn-icon btn-edit" title="Редактировать">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" action="delete_node.php" class="inline-form" onsubmit="return confirmDeleteNode()">
                                            <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                            <button type="submit" class="btn-icon btn-delete" title="Удалить">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-server"></i>
                        <h3>Нет добавленных нод</h3>
                        <p>Добавьте первую ноду для начала работы</p>
                        <a href="add_node.php" class="nodes-action-btn nodes-action-btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus-circle"></i> Добавить ноду
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Подключаем библиотеки для модальных окон и графиков -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.8"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>

    <script>
    // Глобальные переменные для хранения графиков
    const nodeCharts = {};
    let autoRefreshInterval = null;
    let currentNodeId = null;
    let currentClusterName = null;
    let currentNodeName = null;
    let currentHours = 3;
    let currentInterval = 5;

    // Функции подтверждения удаления
    function confirmDeleteCluster() {
        return confirm('Удалить этот кластер? Все связанные ноды также будут удалены.');
    }

    function confirmDeleteNode() {
        return confirm('Удалить эту ноду?');
    }

    // Функция для отображения информации о ноде
    function showNodeInfo(nodeId) {
        Swal.fire({
            title: 'Получение данных...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('get_node_info.php?id=' + nodeId)
            .then(response => {
                if (!response.ok) throw new Error('Ошибка сети');
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }

                let html = `
                    <div class="node-info">
                        <div class="info-section">
                            <h3><i class="fas fa-microchip"></i> Процессор</h3>
                            <p><strong>Модель:</strong> ${data.cpu_model || 'Не указана'}</p>
                            <p><strong>Ядра/Потоки:</strong> ${data.cpu_physical || '0'} / ${data.cpu_threads || '0'}</p>
                            <p><strong>Сокетов:</strong> ${data.cpu_sockets || '0'}</p>
                        </div>

                        <div class="info-section">
                            <h3><i class="fas fa-memory"></i> Память</h3>
                            <p><strong>Всего:</strong> ${data.ram_total || '0 MB'}</p>
                            <p><strong>Использовано:</strong> ${data.ram_used || '0 MB'} (${data.ram_percent || '0%'})</p>
                        </div>

                        <div class="info-section">
                            <h3><i class="fas fa-network-wired"></i> Сеть</h3>
                            <p><strong>IP-адрес:</strong> ${data.ip || 'Не указан'}</p>
                            <p><strong>MAC-адрес:</strong> ${data.mac || 'Не указан'}</p>
                        </div>
                `;

                if (data.disks && data.disks.length > 0) {
                    html += `<div class="info-section">
                        <h3><i class="fas fa-hdd"></i> Диски</h3>`;

                    data.disks.forEach(disk => {
                        html += `
                            <div class="disk-info">
                                <p><strong>${disk.name || 'Диск'}:</strong> ${disk.size || '0 GB'} (${disk.percent || '0%'} использовано)</p>
                                <p><small>Монтирован в: ${disk.mount || 'не смонтирован'}</small></p>
                            </div>
                        `;
                    });

                    html += `</div>`;
                }

                html += `</div>`;

                Swal.fire({
                    title: 'Информация о ноде',
                    html: html,
                    width: '700px',
                    confirmButtonText: 'Закрыть',
                    showCloseButton: true
                });
            })
            .catch(error => {
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось получить данные: ' + error.message,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            });
    }

    // Функция для отображения графиков мониторинга
    function showNodeCharts(nodeId, clusterName, nodeName) {
        currentNodeId = nodeId;
        currentClusterName = clusterName;
        currentNodeName = nodeName;

        // Останавливаем предыдущее автообновление
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }

        // Создаем модальное окно
        Swal.fire({
            title: `Мониторинг: ${nodeName} (${clusterName})`,
            html: `
                <div class="charts-controls">
                    <div class="time-filters">
                        <button class="btn-time ${currentHours === 1 ? 'active' : ''}" data-hours="1">1 час</button>
                        <button class="btn-time ${currentHours === 3 ? 'active' : ''}" data-hours="3">3 часа</button>
                        <button class="btn-time ${currentHours === 6 ? 'active' : ''}" data-hours="6">6 часов</button>
                        <button class="btn-time ${currentHours === 24 ? 'active' : ''}" data-hours="24">24 часа</button>
                        <button class="btn-time ${currentHours === 72 ? 'active' : ''}" data-hours="72">3 дня</button>
                    </div>
                    <div class="interval-filters">
                        <select class="interval-select" id="intervalSelect">
                            <option value="5" ${currentInterval === 5 ? 'selected' : ''}>5 минут</option>
                            <option value="30" ${currentInterval === 30 ? 'selected' : ''}>30 минут</option>
                            <option value="180" ${currentInterval === 180 ? 'selected' : ''}>3 часа</option>
                            <option value="360" ${currentInterval === 360 ? 'selected' : ''}>6 часов</option>
                        </select>
                        <label class="auto-refresh">
                            <input type="checkbox" id="autoRefreshCheck" checked>
                            Автообновление (30 сек)
                        </label>
                    </div>
                </div>
                <div class="charts-container">
                    <div class="chart-row">
                        <div class="chart-box">
                            <canvas id="cpuChart-${nodeId}" height="200"></canvas>
                        </div>
                        <div class="chart-box">
                            <canvas id="ramChart-${nodeId}" height="200"></canvas>
                        </div>
                    </div>
                    <div class="chart-row">
                        <div class="chart-box">
                            <canvas id="networkChart-${nodeId}" height="200"></canvas>
                        </div>
                    </div>
                </div>
            `,
            width: '90%',
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: () => {
                // Инициализация элементов управления
                initChartControls(nodeId);
                // Загрузка данных
                loadChartData(nodeId, currentHours, currentInterval);
                // Запуск автообновления
                setupAutoRefresh(nodeId);
            },
            willClose: () => {
                // Очистка при закрытии
                destroyCharts(nodeId);
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
                currentNodeId = null;
            }
        });
    }

    // Инициализация элементов управления графиками
    function initChartControls(nodeId) {
        // Обработчики временного диапазона
        document.querySelectorAll('.btn-time').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.btn-time').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentHours = parseInt(this.dataset.hours);
                loadChartData(nodeId, currentHours, currentInterval);
            });
        });

        // Обработчик выбора интервала
        const intervalSelect = document.getElementById('intervalSelect');
        intervalSelect.addEventListener('change', function() {
            currentInterval = parseInt(this.value);
            loadChartData(nodeId, currentHours, currentInterval);
        });

        // Обработчик автообновления
        const autoRefreshCheck = document.getElementById('autoRefreshCheck');
        autoRefreshCheck.addEventListener('change', function() {
            if (this.checked) {
                setupAutoRefresh(nodeId);
            } else {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        });
    }

    // Настройка автообновления
    function setupAutoRefresh(nodeId) {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }

        autoRefreshInterval = setInterval(() => {
            loadChartData(nodeId, currentHours, currentInterval);
        }, 30000); // 30 секунд
    }

    // Загрузка данных для графиков
    function loadChartData(nodeId, hours, interval) {
        fetch(`get_node_stats.php?id=${nodeId}&hours=${hours}&interval=${interval}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (!data || data.length === 0) {
                    throw new Error('Нет данных для отображения');
                }

                updateOrCreateChart(nodeId, 'cpu', data);
                updateOrCreateChart(nodeId, 'ram', data);
                updateOrCreateChart(nodeId, 'network', data);

                // Обновляем время последнего обновления
                const now = new Date();
                Swal.getTitle().innerHTML = `Мониторинг: ${currentNodeName} (${currentClusterName}) <br><small>Последнее обновление: ${now.toLocaleTimeString()}</small>`;
            })
            .catch(error => {
                console.error('Ошибка загрузки данных:', error);
                if (currentNodeId === nodeId) {
                    Swal.showValidationMessage('Не удалось загрузить данные для графиков');
                    setTimeout(() => Swal.hideValidationMessage(), 2000);
                }
            });
    }

    // Функция обновления или создания графика
    function updateOrCreateChart(nodeId, type, data) {
        const ctx = document.getElementById(`${type}Chart-${nodeId}`).getContext('2d');
        const chartId = `${type}-${nodeId}`;

        // Если график уже существует - обновляем данные
        if (nodeCharts[chartId]) {
            updateChartData(nodeCharts[chartId], type, data);
            return;
        }

        // Создаем новый график
        nodeCharts[chartId] = new Chart(ctx, getChartConfig(type, data));
    }

    // Функция обновления данных графика
    function updateChartData(chart, type, data) {
        const { labels, datasets } = prepareChartData(type, data);

        chart.data.labels = labels;
        chart.data.datasets.forEach((dataset, i) => {
            dataset.data = datasets[i].data;
            if (datasets[i].label) {
                dataset.label = datasets[i].label;
            }
        });
        chart.update('none');
    }

    // Подготовка данных для графиков
    function prepareChartData(type, data) {
        const labels = data.map(item => item.timestamp);

        switch(type) {
            case 'cpu':
                return {
                    labels: labels,
                    datasets: [{
                        data: data.map(item => item.cpu),
                        label: 'Использование CPU (%)'
                    }]
                };

            case 'ram':
                const totalRamGB = data.length > 0 ? (data[data.length-1].memory_total / 1024).toFixed(1) : '0';
                return {
                    labels: labels,
                    datasets: [{
                        data: data.map(item => item.memory),
                        label: `Использование RAM (${totalRamGB} GB всего) (%)`
                    }]
                };

            case 'network':
                return {
                    labels: labels,
                    datasets: [
                        {
                            data: data.map(item => (item.network_rx || 0) / 100),
                            label: 'Входящий трафик (Mbit/s)'
                        },
                        {
                            data: data.map(item => (item.network_tx || 0) / 100),
                            label: 'Исходящий трафик (Mbit/s)'
                        }
                    ]
                };
        }
    }

    // Конфигурация графиков
    function getChartConfig(type, data) {
        const { labels, datasets } = prepareChartData(type, data);
        const gridColor = 'rgba(255, 255, 255, 0.1)';
        const textColor = getComputedStyle(document.documentElement).getPropertyValue('--nodes-text');

        return {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets.map(dataset => ({
                    ...dataset,
                    borderWidth: 2,
                    tension: 0.1,
                    fill: false,
                    backgroundColor: getBackgroundColor(type, datasets.indexOf(dataset)),
                    borderColor: getBorderColor(type, datasets.indexOf(dataset)),
                    pointRadius: 0,
                    pointHoverRadius: 3
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: textColor
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toFixed(2);
                                if (type === 'cpu' || type === 'ram') {
                                    label += '%';
                                } else {
                                    label += ' Mbit/s';
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            tooltipFormat: 'DD.MM HH:mm',
                            displayFormats: {
                                hour: 'HH:mm'
                            }
                        },
                        grid: {
                            display: false,
                            color: gridColor
                        },
                        ticks: {
                            color: textColor
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: type === 'network' ? 'Mbit/s' : '%',
                            color: textColor
                        },
                        grid: {
                            color: gridColor
                        },
                        ticks: {
                            color: textColor
                        }
                    }
                },
                animation: {
                    duration: 0
                }
            }
        };
    }

    // Цвета для графиков
    function getBorderColor(type, index) {
        const colors = {
            cpu: '#3b82f6', // Blue
            ram: '#10b981', // Green
            network: ['#8b5cf6', '#f59e0b'] // Purple, Orange
        };
        return type === 'network' ? colors.network[index] : colors[type];
    }

    function getBackgroundColor(type, index) {
        const color = getBorderColor(type, index);
        return color.replace(')', ', 0.2)').replace('rgb', 'rgba');
    }

    // Уничтожение графиков
    function destroyCharts(nodeId) {
        [`cpu-${nodeId}`, `ram-${nodeId}`, `network-${nodeId}`].forEach(chartId => {
            if (nodeCharts[chartId]) {
                nodeCharts[chartId].destroy();
                delete nodeCharts[chartId];
            }
        });
    }

    // Анимация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        // Анимация карточек статистики
        const statCards = document.querySelectorAll('.node-stat-card');
        statCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';

            setTimeout(() => {
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });

        // Анимация строк таблиц
        const tableRows = document.querySelectorAll('.nodes-table tbody tr');
        tableRows.forEach((row, index) => {
            row.style.animationDelay = `${index * 0.05}s`;
        });

        // Обновление отступа при изменении размера окна
        function updateWrapperMargin() {
            const wrapper = document.querySelector('.nodes-wrapper');
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
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>
