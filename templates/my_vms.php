<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем количество IP-адресов
$stmt = $pdo->prepare("SELECT
    SUM(CASE WHEN vm_type = 'qemu' AND ip_address IS NOT NULL THEN 1 ELSE 0 END) as vm_ip_count,
    SUM(CASE WHEN vm_type = 'lxc' AND ip_address IS NOT NULL THEN 1 ELSE 0 END) as lxc_ip_count
    FROM vms WHERE user_id = ?");
$stmt->execute([$user_id]);
$ip_counts = $stmt->fetch();
$ip_count = $ip_counts['vm_ip_count'];
$container_ip_count = $ip_counts['lxc_ip_count'];

// Максимальное количество IP-адресов
$max_ip = 10; // Можно изменить или взять из настроек пользователя

// Процент использованных IP
$ip_percent = round(($ip_count + $container_ip_count) / $max_ip * 100);

$title = "Мои виртуальные машины | HomeVlad Cloud";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
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

        .page-title i {
            font-size: 32px;
        }

        /* Прогресс бар загрузки */
        .progress-container {
            width: 100%;
            height: 4px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 30px;
            transition: opacity 0.5s ease;
        }

        body.dark-theme .progress-container {
            background: rgba(255, 255, 255, 0.1);
        }

        .progress-bar {
            height: 100%;
            background: var(--secondary-gradient);
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 16px 16px 0 0;
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .stat-icon.warning {
            background: var(--warning-gradient);
        }

        .stat-icon.success {
            background: var(--success-gradient);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 8px 0;
            color: #1e293b;
        }

        body.dark-theme .stat-value {
            color: #f1f5f9;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 4px;
            font-weight: 500;
        }

        body.dark-theme .stat-label {
            color: #94a3b8;
        }

        .stat-details {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* Мини прогресс бары в статистике */
        .mini-progress-container {
            margin-top: 10px;
        }

        .mini-progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: 11px;
            color: #64748b;
        }

        .mini-progress-bar {
            height: 6px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .mini-progress-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--secondary-gradient);
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .mini-progress-fill.ip-progress {
            background: var(--info-gradient);
        }

        /* Секции с ВМ и контейнерами */
        .vm-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .vm-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        /* Сетка ВМ */
        .vm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        /* Карточка ВМ */
        .vm-card {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        body.dark-theme .vm-card {
            background: rgba(30, 41, 59, 0.5);
        }

        .vm-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            border-color: rgba(14, 165, 233, 0.3);
        }

        .vm-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .vm-name {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }

        body.dark-theme .vm-name {
            color: #f1f5f9;
        }

        .vm-id {
            font-size: 12px;
            color: #64748b;
            background: rgba(148, 163, 184, 0.1);
            padding: 2px 8px;
            border-radius: 10px;
        }

        body.dark-theme .vm-id {
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.1);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        /* Спецификации ВМ */
        .vm-specs {
            margin-bottom: 16px;
        }

        .vm-spec {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .vm-spec:last-child {
            border-bottom: none;
        }

        .spec-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .spec-icon {
            width: 24px;
            height: 24px;
            background: rgba(0, 188, 212, 0.1);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #00bcd4;
            font-size: 12px;
        }

        .spec-label {
            font-size: 13px;
            color: #64748b;
            flex: 1;
        }

        body.dark-theme .spec-label {
            color: #94a3b8;
        }

        .spec-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }

        body.dark-theme .spec-value {
            color: #f1f5f9;
        }

        /* Мини-прогресс бары в карточках ВМ */
        .vm-mini-progress-container {
            width: 80px;
        }

        .vm-mini-progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
            font-size: 10px;
            color: #64748b;
        }

        .vm-mini-progress-bar {
            height: 4px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 2px;
            overflow: hidden;
        }

        .vm-mini-progress-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.5s ease;
        }

        .cpu-progress {
            background: var(--warning-gradient);
        }

        .ram-progress {
            background: var(--success-gradient);
        }

        .disk-progress {
            background: var(--info-gradient);
        }

        .ip-progress {
            background: var(--purple-gradient);
        }

        /* Сеть в карточках ВМ */
        .network-progress-container {
            margin-top: 5px;
            /*width: 100%;*/
        }

        .network-progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
            margin-bottom: 2px;
        }

        .network-progress-bars {
            display: flex;
            gap: 1px;
            height: 4px;
            border-radius: 2px;
            overflow: hidden;
        }

        .network-progress-in {
            height: 100%;
            background: var(--info-gradient);
            transition: width 0.3s ease;
        }

        .network-progress-out {
            height: 100%;
            background: var(--warning-gradient);
            transition: width 0.3s ease;
        }

        .network-speed {
            font-size: 9px;
            color: #64748b;
            text-align: right;
            margin-top: 1px;
        }

        /* Дополнительные спецификации */
        .vm-specs-tf {
            margin-bottom: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        .vm-spec-tf {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
            font-size: 12px;
            color: #64748b;
        }

        .vm-spec-tf i {
            color: #00bcd4;
            font-size: 12px;
            width: 16px;
        }

        /* Действия с ВМ */
        .vm-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .vm-action-btn {
            flex: 1;
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            background: rgba(14, 165, 233, 0.1);
            color: #0ea5e9;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
        }

        .vm-action-btn:hover {
            background: rgba(14, 165, 233, 0.2);
            transform: translateY(-1px);
        }

        .vm-action-btn.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .vm-action-btn.warning:hover {
            background: rgba(245, 158, 11, 0.2);
        }

        .vm-action-btn.danger {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .vm-action-btn.danger:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        .vm-action-btn.info {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .vm-action-btn.info:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        .vm-action-btn.secondary {
            background: rgba(148, 163, 184, 0.1);
            color: #64748b;
        }

        .vm-action-btn.secondary:hover {
            background: rgba(148, 163, 184, 0.2);
        }

        /* Поиск и переключение вида */
        .vm-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .view-toggle-btn {
            padding: 8px 12px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            background: white;
            border-radius: 8px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        body.dark-theme .view-toggle-btn {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .view-toggle-btn.active {
            background: rgba(0, 188, 212, 0.1);
            border-color: rgba(0, 188, 212, 0.2);
            color: #00bcd4;
        }

        .view-toggle-btn:hover {
            border-color: #00bcd4;
            color: #00bcd4;
        }

        .search-box {
            position: relative;
            margin-left: 15px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }

        .search-box input {
            padding: 8px 12px 8px 35px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 8px;
            background: white;
            color: #1e293b;
            font-size: 14px;
            width: 200px;
        }

        body.dark-theme .search-box input {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        /* Пустое состояние */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-icon {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .empty-text {
            color: #64748b;
            margin-bottom: 20px;
        }

        body.dark-theme .empty-text {
            color: #94a3b8;
        }

        /* Кнопка заказать */
        .btn-order {
            padding: 12px 24px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-order:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
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

        /* Скелетон для загрузки */
        .skeleton {
            background: linear-gradient(90deg, rgba(248, 250, 252, 0.8) 25%, rgba(241, 245, 249, 0.9) 50%, rgba(248, 250, 252, 0.8) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 12px;
        }

        body.dark-theme .skeleton {
            background: linear-gradient(90deg, rgba(30, 41, 59, 0.8) 25%, rgba(15, 23, 42, 0.9) 50%, rgba(30, 41, 59, 0.8) 75%);
            background-size: 200% 100%;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .vm-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .vm-grid {
                grid-template-columns: 1fr;
            }

            .vm-list-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .view-toggle {
                width: 100%;
                justify-content: flex-end;
            }

            .search-box {
                margin-left: 0;
                width: 100%;
            }

            .search-box input {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .stat-card {
                padding: 20px;
            }

            .vm-section {
                padding: 20px;
            }

            .vm-actions {
                flex-wrap: wrap;
            }

            .vm-action-btn {
                min-width: calc(50% - 4px);
            }
        }

        /* Анимации */
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

        .stat-card {
            animation: slideIn 0.5s ease forwards;
        }

        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.3s; }
        .stat-card:nth-child(5) { animation-delay: 0.4s; }

        /* Модальное окно метрик */
        .metrics-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .metrics-modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 1400px;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        body.dark-theme .metrics-modal-content {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .metrics-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .metrics-modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }

        body.dark-theme .metrics-modal-title {
            color: #f1f5f9;
        }

        .metrics-modal-close {
            background: none;
            border: none;
            font-size: 32px;
            color: #64748b;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .metrics-modal-close:hover {
            color: #ef4444;
        }

        .timeframe-filter {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .timeframe-filter label {
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .timeframe-filter label {
            color: #f1f5f9;
        }

        .timeframe-filter select {
            padding: 10px 15px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #1e293b;
            font-size: 14px;
        }

        body.dark-theme .timeframe-filter select {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-top: 25px;
        }

        @media (max-width: 1200px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }

        .metric-card {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .metric-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .metric-chart {
            height: 300px;
            position: relative;
        }

        /* Кнопка вверх */
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 999;
        }

        .scroll-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.5);
        }

        /* Уведомления */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 400px;
        }

        .notification.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .notification.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .notification.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .notification.info {
            background: linear-gradient(135deg, #00bcd4, #0097a7);
        }

        /* Кнопка меню для мобильных */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 18px;
            cursor: pointer;
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: flex;
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
    <?php
    // Подключаем общую шапку
    include '../templates/headers/user_header.php';
    ?>

    <!-- Кнопка меню для мобильных -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <div class="main-container">
        <?php
        // Подключаем общий сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Прогресс бар загрузки -->
            <div class="progress-container">
                <div class="progress-bar" id="loading-progress"></div>
            </div>

            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-server"></i> Мои ВМ и контейнеры
                </h1>
                <div class="header-actions">
                    <a href="order_vm.php" class="btn-order" style="margin-right: 10px;">
                        <i class="fas fa-plus"></i> Заказать ВМ
                    </a>
                    <button class="btn-refresh" onclick="refreshAll()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <!-- Статистика будет загружена через AJAX -->
            <div class="stats-grid" id="stats-grid">
                <!-- Заполнится через AJAX -->
                <div class="stat-card skeleton"></div>
                <div class="stat-card skeleton"></div>
                <div class="stat-card skeleton"></div>
                <div class="stat-card skeleton"></div>
                <div class="stat-card skeleton"></div>
            </div>

            <!-- Виртуальные машины -->
            <section class="vm-section">
                <div class="vm-list-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i> Список виртуальных машин (QEMU)
                    </h2>

                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" data-view="grid" title="Плиточный вид">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button class="view-toggle-btn" data-view="compact" title="Компактный вид">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Поиск ВМ..." id="vm-search">
                        </div>
                    </div>
                </div>

                <div class="vm-grid" id="vm-list">
                    <!-- ВМ будут загружены через AJAX -->
                    <div class="vm-card skeleton" style="height: 300px;"></div>
                    <div class="vm-card skeleton" style="height: 300px;"></div>
                    <div class="vm-card skeleton" style="height: 300px;"></div>
                </div>
            </section>

            <!-- Контейнеры -->
            <section class="vm-section">
                <div class="vm-list-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i> Список виртуальных контейнеров (LXC)
                    </h2>

                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" data-view="grid" title="Плиточный вид">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button class="view-toggle-btn" data-view="compact" title="Компактный вид">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Поиск контейнеров..." id="container-search">
                        </div>
                    </div>
                </div>

                <div class="vm-grid" id="container-list">
                    <!-- Контейнеры будут загружены через AJAX -->
                    <div class="vm-card skeleton" style="height: 300px;"></div>
                    <div class="vm-card skeleton" style="height: 300px;"></div>
                </div>
            </section>

            <!-- Пустое состояние -->
            <div class="empty-state" id="noVms" style="display: none;">
                <div class="empty-icon">
                    <i class="fas fa-cloud"></i>
                </div>
                <h3 class="empty-text">У вас пока нет виртуальных серверов</h3>
                <div style="display: flex; gap: 15px; justify-content: center; margin-top: 25px;">
                    <a href="order_vm.php" class="btn-order">
                        <i class="fas fa-plus"></i> Создать ВМ
                    </a>
                    <a href="order_container.php" class="btn-order">
                        <i class="fas fa-box"></i> Создать контейнер
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для метрик -->
    <div id="metricsModal" class="metrics-modal">
        <div class="metrics-modal-content">
            <div class="metrics-modal-header">
                <h3 class="metrics-modal-title" id="metricsModalTitle">Метрики</h3>
                <button class="metrics-modal-close" id="metricsModalClose">&times;</button>
            </div>

            <div class="timeframe-filter">
                <label for="timeframe">Период:</label>
                <select id="timeframe">
                    <option value="hour">1 час</option>
                    <option value="day">1 день</option>
                    <option value="week">1 неделя</option>
                    <option value="month">1 месяц</option>
                    <option value="year">1 год</option>
                </select>
            </div>

            <div class="progress-container">
                <div class="progress-bar" id="metrics-loading-progress"></div>
            </div>

            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="cpuChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="memoryChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="networkChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="diskChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Подключаем общий футер
    include '../templates/headers/user_footer.php';
    ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.8"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
        // Глобальные переменные из оригинального файла
        let vmActionInProgress = false;
        let allVmsLoaded = false;
        let allContainersLoaded = false;
        let currentVmId = null;
        let currentVmName = null;
        let currentVmType = null;
        let cpuChart = null;
        let memoryChart = null;
        let networkChart = null;
        let diskChart = null;
        let compactViewHtml = {
            vm: '',
            container: ''
        };
        let gridViewHtml = {
            vm: '',
            container: ''
        };

        // Функция для обновления прогресс-бара
        function updateProgress(percent) {
            document.getElementById('loading-progress').style.width = percent + '%';
        }

        function updateMetricsProgress(percent) {
            document.getElementById('metrics-loading-progress').style.width = percent + '%';
        }

        // Функция для обновления всех данных
        function refreshAll() {
            updateProgress(0);
            loadStats().then(loadVms);
        }

        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            // Настройка кнопки "Наверх"
            setupScrollToTop();

            // Настройка мобильного меню
            setupMobileMenu();

            // Загружаем данные
            loadStats().then(loadVms).then(() => {
                setTimeout(() => {
                    document.querySelector('.progress-container').style.opacity = '0';
                    setTimeout(() => {
                        document.querySelector('.progress-container').style.display = 'none';
                    }, 500);
                }, 1000);
            });

            // Обработчики для модального окна метрик
            document.getElementById('metricsModalClose').addEventListener('click', function() {
                document.getElementById('metricsModal').style.display = 'none';
            });

            // Закрытие модального окна при клике вне его
            window.addEventListener('click', function(event) {
                const modal = document.getElementById('metricsModal');
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });

            // Обработчик изменения временного диапазона
            document.getElementById('timeframe').addEventListener('change', function() {
                if (currentVmId) {
                    loadVmMetrics(currentVmId, this.value, currentVmType);
                }
            });

            // Переключение вида
            document.querySelectorAll('.view-toggle-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const viewType = this.dataset.view;
                    toggleView(viewType);
                });
            });

            // Поиск VM
            document.getElementById('vm-search').addEventListener('input', function(e) {
                searchVMs(e.target.value, 'vm');
            });

            // Поиск контейнеров
            document.getElementById('container-search').addEventListener('input', function(e) {
                searchVMs(e.target.value, 'container');
            });

            // Обработка уведомлений из сессии
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?= addslashes($_SESSION['message']) ?>", "<?= $_SESSION['message_type'] ?? 'info' ?>");
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
        });

        // Настройка кнопки "Наверх"
        function setupScrollToTop() {
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
        }

        // Настройка мобильного меню
        function setupMobileMenu() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.querySelector('.admin-sidebar');

            if (mobileMenuToggle && sidebar) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.style.display = sidebar.style.display === 'none' ? 'block' : 'none';
                });

                // Проверка размера экрана при загрузке и изменении размера
                function checkScreenSize() {
                    if (window.innerWidth <= 992) {
                        sidebar.style.display = 'none';
                        mobileMenuToggle.style.display = 'flex';
                    } else {
                        sidebar.style.display = 'block';
                        mobileMenuToggle.style.display = 'none';
                    }
                }

                checkScreenSize();
                window.addEventListener('resize', checkScreenSize);
            }
        }

        // Функция отображения уведомлений
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            // Удаляем уведомление через 5 секунд
            setTimeout(() => {
                notification.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }

        // Загрузка статистики из оригинального файла
        async function loadStats() {
            try {
                updateProgress(10);

                const response = await fetch('/api/get_user_stats.php?user_id=<?= $user_id ?>');
                const stats = await response.json();

                if (stats.success) {
                    const statsGrid = document.getElementById('stats-grid');
                    statsGrid.innerHTML = `
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-play-circle"></i>
                                </div>
                                <div class="stat-label">Запущенные ВМ</div>
                            </div>
                            <div class="stat-value">${stats.vm_running}</div>
                            <div class="stat-details">из ${stats.vm_total} всего</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-play-circle"></i>
                                </div>
                                <div class="stat-label">Запущенные контейнеры</div>
                            </div>
                            <div class="stat-value">${stats.lxc_running}</div>
                            <div class="stat-details">из ${stats.lxc_total} всего</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-microchip"></i>
                                </div>
                                <div class="stat-label">Всего vCPU</div>
                            </div>
                            <div class="stat-value">${stats.total_cpu}</div>
                            <div class="stat-details">ядер</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-memory"></i>
                                </div>
                                <div class="stat-label">Всего RAM</div>
                            </div>
                            <div class="stat-value">${stats.total_ram}</div>
                            <div class="stat-details">GB</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-network-wired"></i>
                                </div>
                                <div class="stat-label">IP-адреса</div>
                            </div>
                            <div class="stat-value"><?= $ip_count + $container_ip_count ?></div>
                            <div class="stat-details">шт</div>
                            <div class="mini-progress-container">
                                <div class="mini-progress-label">
                                    <span>Использовано</span>
                                    <span><?= $ip_percent ?>%</span>
                                </div>
                                <div class="mini-progress-bar">
                                    <div class="mini-progress-fill ip-progress" style="width: <?= $ip_percent ?>%"></div>
                                </div>
                            </div>
                        </div>
                    `;
                    updateProgress(30);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
                showNotification('Ошибка загрузки статистики', 'error');
            }
        }

        // Загрузка списка виртуальных машин и контейнеров из оригинального файла
        async function loadVms() {
            try {
                // Параллельная загрузка VM и контейнеров
                const [vmsResponse, containersResponse] = await Promise.all([
                    fetch('/api/get_user_vms.php?user_id=<?= $user_id ?>'),
                    fetch('/api/get_user_containers.php?user_id=<?= $user_id ?>')
                ]);

                const [vmsData, containersData] = await Promise.all([
                    vmsResponse.json(),
                    containersResponse.json()
                ]);

                // Обработка VM (qemu)
                if (vmsData.success && vmsData.vms.length > 0) {
                    gridViewHtml.vm = '';
                    compactViewHtml.vm = '';

                    // Загружаем метрики для всех VM
                    const metricsPromises = vmsData.vms.map(vm =>
                        fetch(`/api/get_latest_metrics.php?vm_id=${vm.vm_id}&type=qemu`)
                            .then(res => res.json())
                    );
                    const metricsResults = await Promise.all(metricsPromises);

                    vmsData.vms.forEach((vm, index) => {
                        const percent = 30 + Math.floor((index / vmsData.vms.length) * 35);
                        updateProgress(percent);

                        const metrics = metricsResults[index].success ? metricsResults[index].data : {
                            cpu_usage: 0,
                            mem_usage: 0,
                            mem_total: vm.ram / 1024,
                            net_in: 0,
                            net_out: 0,
                            disk_read: 0,
                            disk_write: 0
                        };

                        const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
                        const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
                        const netIn = metrics.net_in || 0;
                        const netOut = metrics.net_out || 0;
                        const netTotal = (netIn + netOut).toFixed(2);

                        // Стандартный вид (плитки)
                        const gridHtml = `
                            <div class="vm-card" data-status="${vm.status}" data-vmid="${vm.vm_id}" data-nodeid="${vm.node_id}" data-id="${vm.id}" data-vmtype="qemu">
                                <div class="vm-card-header">
                                    <div>
                                        <span class="status-badge ${vm.status === 'running' ? 'status-active' : 'status-inactive'}">
                                            ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                                        </span>
                                        <span class="vm-id">VMID: ${vm.vm_id}</span>
                                    </div>
                                    <h3 class="vm-name">${escapeHtml(vm.hostname)}</h3>
                                </div>

                                <div class="vm-specs">
                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-microchip"></i>
                                            <span class="spec-label">${vm.cpu} vCPU</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>CPU</span>
                                                <span>${Math.round(metrics.cpu_usage)}%</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-memory"></i>
                                            <span class="spec-label">${vm.ram / 1024} GB RAM</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>RAM</span>
                                                <span>${ramUsagePercent}%</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-hdd"></i>
                                            <span class="spec-label">${vm.disk} GB SSD</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>Disk IO</span>
                                                <span>${diskTotal} MB/s</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-network-wired"></i>
                                            <span class="spec-label">${vm.ip_address || 'Не назначен'}</span>
                                        </div>
                                        <div class="network-progress-container">
                                            <div class="network-progress-label">
                                                <span>Сеть</span>
                                                <span>${netTotal} Mbit/s</span>
                                            </div>
                                            <div class="network-progress-bars network-progress">
                                                <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                                <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                            </div>
                                            <div class="network-speed">▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="vm-specs-tf">
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-tag"></i>
                                        <span>Тариф: ${escapeHtml(vm.tariff_name)}</span>
                                    </div>
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-server"></i>
                                        <span>Сервер: ${escapeHtml(vm.node_name)}</span>
                                    </div>
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span>Дата: ${formatDate(vm.created_at)}</span>
                                    </div>
                                </div>

                                <div class="vm-actions">
                                    ${vm.status !== 'running' ? `
                                        <button class="vm-action-btn vm-start" title="Запустить">
                                            <i class="fas fa-play"></i> Запустить
                                        </button>` : `
                                        <button class="vm-action-btn warning vm-reboot" title="Перезагрузить">
                                            <i class="fas fa-sync-alt"></i> Перезагрузить
                                        </button>
                                        <button class="vm-action-btn danger vm-stop" title="Остановить">
                                            <i class="fas fa-stop"></i> Остановить
                                        </button>`}
                                    <button class="vm-action-btn info vm-console" title="Консоль">
                                        <i class="fas fa-terminal"></i> Консоль
                                    </button>
                                    <button class="vm-action-btn secondary vm-metrics" title="Метрики">
                                        <i class="fas fa-chart-line"></i> Метрики
                                    </button>
                                    <a href="vm_settings.php?id=${vm.id}" class="vm-action-btn secondary" title="Настройки">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                </div>
                            </div>
                        `;

                        // Компактный вид
                        const compactHtml = `
                            <div class="vm-card compact" data-status="${vm.status}" data-vmid="${vm.vm_id}" data-nodeid="${vm.node_id}" data-id="${vm.id}" data-vmtype="qemu">
                                <div class="vm-card-header">
                                    <h3 class="vm-name">${escapeHtml(vm.hostname)}</h3>
                                    <div>
                                        <span class="status-badge ${vm.status === 'running' ? 'status-active' : 'status-inactive'}">
                                            ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                                        </span>
                                        <span class="vm-id">VMID: ${vm.vm_id}</span>
                                    </div>
                                </div>

                                <div class="vm-specs compact">
                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-microchip"></i>
                                            <span>${vm.cpu} vCPU</span>
                                            <span style="margin-left: auto;">${Math.round(metrics.cpu_usage)}%</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-memory"></i>
                                            <span>${vm.ram / 1024} GB RAM</span>
                                            <span style="margin-left: auto;">${ramUsagePercent}%</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-hdd"></i>
                                            <span>${vm.disk} GB SSD</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-network-wired"></i>
                                            <span>${vm.ip_address || 'Не назначен'}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="vm-actions">
                                    ${vm.status !== 'running' ? `
                                        <button class="vm-action-btn vm-start" title="Запустить">
                                            <i class="fas fa-play"></i>
                                        </button>` : `
                                        <button class="vm-action-btn warning vm-reboot" title="Перезагрузить">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                        <button class="vm-action-btn danger vm-stop" title="Остановить">
                                            <i class="fas fa-stop"></i>
                                        </button>`}
                                    <button class="vm-action-btn info vm-console" title="Консоль">
                                        <i class="fas fa-terminal"></i>
                                    </button>
                                    <button class="vm-action-btn secondary vm-metrics" title="Метрики">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                    <a href="vm_settings.php?id=${vm.id}" class="vm-action-btn secondary" title="Настройки">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                </div>
                            </div>
                        `;

                        gridViewHtml.vm += gridHtml;
                        compactViewHtml.vm += compactHtml;
                    });

                    document.getElementById('vm-list').innerHTML = gridViewHtml.vm;
                    allVmsLoaded = true;
                    addVmActionHandlers();
                } else {
                    document.getElementById('vm-list').innerHTML = `
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="empty-icon">
                                <i class="fas fa-cloud"></i>
                            </div>
                            <p class="empty-text">У вас пока нет виртуальных машин</p>
                            <a href="order_vm.php" class="btn-order">
                                <i class="fas fa-plus"></i> Создать первую ВМ
                            </a>
                        </div>
                    `;
                }

                // Обработка контейнеров (lxc)
                if (containersData.success && containersData.containers.length > 0) {
                    gridViewHtml.container = '';
                    compactViewHtml.container = '';

                    // Загружаем метрики для всех контейнеров
                    const metricsPromises = containersData.containers.map(ct =>
                        fetch(`/api/get_latest_metrics.php?vm_id=${ct.vm_id}&type=lxc`)
                            .then(res => res.json())
                    );
                    const metricsResults = await Promise.all(metricsPromises);

                    containersData.containers.forEach((ct, index) => {
                        const percent = 65 + Math.floor((index / containersData.containers.length) * 35);
                        updateProgress(percent);

                        const metrics = metricsResults[index].success ? metricsResults[index].data : {
                            cpu_usage: 0,
                            mem_usage: 0,
                            mem_total: ct.ram / 1024,
                            net_in: 0,
                            net_out: 0,
                            disk_read: 0,
                            disk_write: 0
                        };

                        const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
                        const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
                        const netIn = metrics.net_in || 0;
                        const netOut = metrics.net_out || 0;
                        const netTotal = (netIn + netOut).toFixed(2);

                        // Стандартный вид (плитки)
                        const gridHtml = `
                            <div class="vm-card" data-status="${ct.status}" data-vmid="${ct.vm_id}" data-nodeid="${ct.node_id}" data-id="${ct.id}" data-vmtype="lxc">
                                <div class="vm-card-header">
                                    <div>
                                        <span class="status-badge ${ct.status === 'running' ? 'status-active' : 'status-inactive'}">
                                            ${ct.status === 'running' ? 'Запущен' : 'Остановлен'}
                                        </span>
                                        <span class="vm-id">CTID: ${ct.vm_id}</span>
                                    </div>
                                    <h3 class="vm-name">${escapeHtml(ct.hostname)}</h3>
                                </div>

                                <div class="vm-specs">
                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-microchip"></i>
                                            <span class="spec-label">${ct.cpu} vCPU</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>CPU</span>
                                                <span>${Math.round(metrics.cpu_usage)}%</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-memory"></i>
                                            <span class="spec-label">${ct.ram / 1024} GB RAM</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>RAM</span>
                                                <span>${ramUsagePercent}%</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-hdd"></i>
                                            <span class="spec-label">${ct.disk} GB SSD</span>
                                        </div>
                                        <div class="vm-mini-progress-container">
                                            <div class="vm-mini-progress-label">
                                                <span>Disk IO</span>
                                                <span>${diskTotal} MB/s</span>
                                            </div>
                                            <div class="vm-mini-progress-bar">
                                                <div class="vm-mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="vm-spec">
                                        <div class="spec-info">
                                            <i class="fas fa-network-wired"></i>
                                            <span class="spec-label">${ct.ip_address || 'Не назначен'}</span>
                                        </div>
                                        <div class="network-progress-container">
                                            <div class="network-progress-label">
                                                <span>Сеть</span>
                                                <span>${netTotal} Mbit/s</span>
                                            </div>
                                            <div class="network-progress-bars network-progress">
                                                <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                                <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                            </div>
                                            <div class="network-speed">▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="vm-specs-tf">
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-tag"></i>
                                        <span>Тариф: ${escapeHtml(ct.tariff_name)}</span>
                                    </div>
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-server"></i>
                                        <span>Сервер: ${escapeHtml(ct.node_name)}</span>
                                    </div>
                                    <div class="vm-spec-tf">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span>Дата: ${formatDate(ct.created_at)}</span>
                                    </div>
                                </div>

                                <div class="vm-actions">
                                    ${ct.status !== 'running' ? `
                                        <button class="vm-action-btn container-start" title="Запустить">
                                            <i class="fas fa-play"></i> Запустить
                                        </button>` : `
                                        <button class="vm-action-btn warning container-reboot" title="Перезагрузить">
                                            <i class="fas fa-sync-alt"></i> Перезагрузить
                                        </button>
                                        <button class="vm-action-btn danger container-stop" title="Остановить">
                                            <i class="fas fa-stop"></i> Остановить
                                        </button>`}
                                    <button class="vm-action-btn info container-console" title="Консоль">
                                        <i class="fas fa-terminal"></i> Консоль
                                    </button>
                                    <button class="vm-action-btn secondary container-metrics" title="Метрики">
                                        <i class="fas fa-chart-line"></i> Метрики
                                    </button>
                                    <a href="vm_settings.php?id=${ct.id}" class="vm-action-btn secondary" title="Настройки">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                </div>
                            </div>
                        `;

                        // Компактный вид
                        const compactHtml = `
                            <div class="vm-card compact" data-status="${ct.status}" data-vmid="${ct.vm_id}" data-nodeid="${ct.node_id}" data-id="${ct.id}" data-vmtype="lxc">
                                <div class="vm-card-header">
                                    <h3 class="vm-name">${escapeHtml(ct.hostname)}</h3>
                                    <div>
                                        <span class="status-badge ${ct.status === 'running' ? 'status-active' : 'status-inactive'}">
                                            ${ct.status === 'running' ? 'Запущен' : 'Остановлен'}
                                        </span>
                                        <span class="vm-id">CTID: ${ct.vm_id}</span>
                                    </div>
                                </div>

                                <div class="vm-specs compact">
                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-microchip"></i>
                                            <span>${ct.cpu} vCPU</span>
                                            <span style="margin-left: auto;">${Math.round(metrics.cpu_usage)}%</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-memory"></i>
                                            <span>${ct.ram / 1024} GB RAM</span>
                                            <span style="margin-left: auto;">${ramUsagePercent}%</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-hdd"></i>
                                            <span>${ct.disk} GB SSD</span>
                                        </div>
                                    </div>

                                    <div class="vm-spec compact">
                                        <div class="spec-info">
                                            <i class="fas fa-network-wired"></i>
                                            <span>${ct.ip_address || 'Не назначен'}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="vm-actions">
                                    ${ct.status !== 'running' ? `
                                        <button class="vm-action-btn container-start" title="Запустить">
                                            <i class="fas fa-play"></i>
                                        </button>` : `
                                        <button class="vm-action-btn warning container-reboot" title="Перезагрузить">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                        <button class="vm-action-btn danger container-stop" title="Остановить">
                                            <i class="fas fa-stop"></i>
                                        </button>`}
                                    <button class="vm-action-btn info container-console" title="Консоль">
                                        <i class="fas fa-terminal"></i>
                                    </button>
                                    <button class="vm-action-btn secondary container-metrics" title="Метрики">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                    <a href="vm_settings.php?id=${ct.id}" class="vm-action-btn secondary" title="Настройки">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                </div>
                            </div>
                        `;

                        gridViewHtml.container += gridHtml;
                        compactViewHtml.container += compactHtml;
                    });

                    document.getElementById('container-list').innerHTML = gridViewHtml.container;
                    allContainersLoaded = true;
                    addContainerActionHandlers();
                } else {
                    document.getElementById('container-list').innerHTML = `
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="empty-icon">
                                <i class="fas fa-box"></i>
                            </div>
                            <p class="empty-text">У вас пока нет контейнеров</p>
                            <a href="order_container.php" class="btn-order">
                                <i class="fas fa-plus"></i> Создать первый контейнер
                            </a>
                        </div>
                    `;
                }

                updateProgress(100);

                // Проверяем пустое состояние
                checkEmptyState();

                // Запускаем периодическое обновление метрик
                setInterval(updateAllMetrics, 30000);
            } catch (error) {
                console.error('Error loading VMs and containers:', error);
                showNotification('Ошибка загрузки виртуальных машин', 'error');
                updateProgress(100);
            }
        }

        // Проверка пустого состояния
        function checkEmptyState() {
            const vmList = document.getElementById('vm-list');
            const containerList = document.getElementById('container-list');

            const hasVms = vmList.querySelector('.vm-card:not(.skeleton)') || vmList.querySelector('.empty-state');
            const hasContainers = containerList.querySelector('.vm-card:not(.skeleton)') || containerList.querySelector('.empty-state');

            if (!hasVms && !hasContainers) {
                document.getElementById('noVms').style.display = 'block';
            } else {
                document.getElementById('noVms').style.display = 'none';
            }
        }

        // Добавление обработчиков для ВМ из оригинального файла
        function addVmActionHandlers() {
            document.querySelectorAll('.vm-start').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('start', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
                });
            });

            document.querySelectorAll('.vm-stop').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('stop', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
                });
            });

            document.querySelectorAll('.vm-reboot').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('reboot', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
                });
            });

            document.querySelectorAll('.vm-console').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    const vm_id = card.dataset.vmid;
                    const nodeId = card.dataset.nodeid;
                    openVncConsole(nodeId, vm_id, 'qemu');
                });
            });

            document.querySelectorAll('.vm-metrics').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    const vm_id = card.dataset.vmid;
                    const vm_name = card.querySelector('.vm-name').textContent;
                    openMetricsModal(vm_id, vm_name, 'qemu');
                });
            });
        }

        // Добавление обработчиков для контейнеров из оригинального файла
        function addContainerActionHandlers() {
            document.querySelectorAll('.container-start').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('start', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
                });
            });

            document.querySelectorAll('.container-stop').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('stop', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
                });
            });

            document.querySelectorAll('.container-reboot').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    handleVmAction('reboot', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
                });
            });

            document.querySelectorAll('.container-console').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    const vm_id = card.dataset.vmid;
                    const nodeId = card.dataset.nodeid;
                    openVncConsole(nodeId, vm_id, 'lxc');
                });
            });

            document.querySelectorAll('.container-metrics').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.vm-card');
                    const vm_id = card.dataset.vmid;
                    const vm_name = card.querySelector('.vm-name').textContent;
                    openMetricsModal(vm_id, vm_name, 'lxc');
                });
            });
        }

        // Функция для обработки действий с ВМ из оригинального файла
        function handleVmAction(action, nodeId, vm_id, card, vmType) {
            if (vmActionInProgress) return;
            vmActionInProgress = true;

            const actionText = {
                'start': vmType === 'lxc' ? 'запуск контейнера' : 'запуск VM',
                'stop': vmType === 'lxc' ? 'остановка контейнера' : 'остановка VM',
                'reboot': vmType === 'lxc' ? 'перезагрузка контейнера' : 'перезагрузка VM'
            }[action];

            Swal.fire({
                title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)}...`,
                html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
                showConfirmButton: false,
                allowOutsideClick: false
            });

            fetch(vmType === 'lxc' ? 'container_action.php' : 'vm_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: action,
                    node_id: nodeId,
                    vm_id: vm_id
                })
            })
            .then(response => response.json())
            .then(data => {
                vmActionInProgress = false;

                if (data.success) {
                    Swal.fire({
                        title: 'Успех',
                        text: `${vmType === 'lxc' ? 'Контейнер' : 'Виртуальная машина'} #${vm_id} успешно ${
                            action === 'start' ? 'запущена' :
                            action === 'stop' ? 'остановлена' : 'перезагружена'
                        }`,
                        icon: 'success',
                        timer: 2000,
                        didClose: () => {
                            refreshAll();
                        }
                    });
                } else {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error || 'Произошла ошибка',
                        icon: 'error'
                    });
                }
            })
            .catch(error => {
                vmActionInProgress = false;
                Swal.fire({
                    title: 'Ошибка',
                    text: error.message,
                    icon: 'error'
                });
            });
        }

        // Функция для открытия VNC консоли из оригинального файла
        async function openVncConsole(nodeId, vmId, vmType) {
            if (vmActionInProgress) return;
            vmActionInProgress = true;

            try {
                const swalInstance = Swal.fire({
                    title: 'Подготовка консоли...',
                    html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся...</div>',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Формируем URL с учетом типа ВМ
                const url = new URL('vnc_console.php', window.location.href);
                url.searchParams.append('node_id', nodeId);
                url.searchParams.append('vm_id', vmId);

                if (vmType === 'lxc' || vmType === 'qemu') {
                    url.searchParams.append('type', vmType);
                }

                const response = await fetch(url);

                // Проверяем, что ответ содержит данные
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const text = await response.text();
                let data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON:', text);
                    throw new Error('Неверный формат ответа от сервера');
                }

                if (!data.success) {
                    throw new Error(data.error || 'Не удалось подключиться');
                }

                // Проверка типа ВМ
                if (vmType && data.data.vm_type && vmType !== data.data.vm_type) {
                    console.warn(`Тип ВМ не совпадает: ожидался ${vmType}, получен ${data.data.vm_type}`);
                }

                // Упрощенная установка cookie (без iframe)
                if (data.data.cookie) {
                    const cookie = data.data.cookie;
                    const cookieStr = `${cookie.name}=${encodeURIComponent(cookie.value)}; ` +
                        `domain=${cookie.domain || window.location.hostname}; ` +
                        `path=${cookie.path || '/'}; ` +
                        `secure=${cookie.secure !== false}; ` +
                        `samesite=${cookie.samesite || 'Lax'}`;

                    document.cookie = cookieStr;
                    console.log('Cookie set:', cookieStr);
                }

                // Проверка URL консоли
                if (!data.data.url) {
                    throw new Error('Не получен URL консоли');
                }

                const consoleUrl = new URL(data.data.url);
                if (!consoleUrl.searchParams.get('console') ||
                    !['lxc', 'kvm'].includes(consoleUrl.searchParams.get('console'))) {
                    throw new Error('Некорректный URL консоли');
                }

                // Открываем VNC консоль
                const vncWindow = window.open(
                    data.data.url,
                    `vnc_${nodeId}_${vmId}`,
                    'width=1024,height=768,scrollbars=yes,resizable=yes,location=yes'
                );

                if (!vncWindow || vncWindow.closed) {
                    throw new Error('Не удалось открыть окно VNC. Пожалуйста, разрешите всплывающие окна для этого сайта.');
                }

                swalInstance.close();

            } catch (error) {
                Swal.fire({
                    title: 'Ошибка подключения',
                    text: error.message,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                console.error('VNC Error:', error);
            } finally {
                vmActionInProgress = false;
            }
        }

        // Функция для открытия модального окна с метриками
        function openMetricsModal(vm_id, vm_name, vm_type) {
            currentVmId = vm_id;
            currentVmName = vm_name;
            currentVmType = vm_type;

            // Устанавливаем заголовок
            document.getElementById('metricsModalTitle').textContent =
                `Метрики ${vm_type === 'lxc' ? 'контейнера' : 'VM'} #${vm_id} (${vm_name})`;

            // Сбрасываем прогресс-бар
            document.querySelector('#metricsModal .progress-container').style.display = 'block';
            document.querySelector('#metricsModal .progress-container').style.opacity = '1';
            updateMetricsProgress(0);

            // Показываем модальное окно
            const modal = document.getElementById('metricsModal');
            modal.style.display = 'block';

            // Загружаем метрики для текущего диапазона времени
            const timeframe = document.getElementById('timeframe').value;
            loadVmMetrics(vm_id, timeframe, vm_type);
        }

        // Функция для загрузки метрик
        async function loadVmMetrics(vm_id, timeframe, vm_type) {
            try {
                updateMetricsProgress(10);

                const response = await fetch(`/api/${vm_type === 'lxc' ? 'get_lxc_metrics' : 'get_vm_metrics'}.php?vm_id=${vm_id}&timeframe=${timeframe}`);
                if (!response.ok) throw new Error('Ошибка сервера');

                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');

                updateMetricsProgress(30);

                // Уничтожаем старые графики, если они есть
                if (cpuChart) cpuChart.destroy();
                if (memoryChart) memoryChart.destroy();
                if (networkChart) networkChart.destroy();
                if (diskChart) diskChart.destroy();

                // Создаем графики
                createCharts(data);

                updateMetricsProgress(100);

                // Через 1 секунду скрываем прогресс-бар
                setTimeout(() => {
                    document.querySelector('#metricsModal .progress-container').style.opacity = '0';
                    setTimeout(() => {
                        document.querySelector('#metricsModal .progress-container').style.display = 'none';
                    }, 500);
                }, 1000);

            } catch (error) {
                console.error('Ошибка загрузки метрик:', error);
                updateMetricsProgress(100);

                const metricsGrid = document.querySelector('#metricsModal .metrics-grid');
                metricsGrid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <div class="empty-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <p class="empty-text">${error.message}</p>
                    </div>
                `;

                setTimeout(() => {
                    document.querySelector('#metricsModal .progress-container').style.opacity = '0';
                    setTimeout(() => {
                        document.querySelector('#metricsModal .progress-container').style.display = 'none';
                    }, 500);
                }, 1000);
            }
        }

        // Функция для создания графиков
        function createCharts(data) {
            // График CPU Usage
            const cpuCtx = document.getElementById('cpuChart').getContext('2d');
            cpuChart = new Chart(cpuCtx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Использование CPU',
                        data: data.cpuData,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' %';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Использование CPU (%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(0) + ' %';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            });

            updateMetricsProgress(50);

            // График Memory Usage
            const memoryCtx = document.getElementById('memoryChart').getContext('2d');
            memoryChart = new Chart(memoryCtx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Используемая память',
                            data: data.memData,
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' ГБ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Память (ГБ)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1) + ' ГБ';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            });

            updateMetricsProgress(70);

            // График Network Traffic
            const networkCtx = document.getElementById('networkChart').getContext('2d');
            networkChart = new Chart(networkCtx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Входящий трафик',
                            data: data.netInData,
                            borderColor: 'rgba(153, 102, 255, 1)',
                            backgroundColor: 'rgba(153, 102, 255, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: 'Исходящий трафик',
                            data: data.netOutData,
                            borderColor: 'rgba(255, 159, 64, 1)',
                            backgroundColor: 'rgba(255, 159, 64, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' Mbit/s';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Скорость передачи (Mbit/s)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' Mbit/s';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            });

            updateMetricsProgress(90);

            // График Disk IO
            const diskCtx = document.getElementById('diskChart').getContext('2d');
            diskChart = new Chart(diskCtx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Чтение с диска',
                            data: data.diskReadData,
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: 'Запись на диск',
                            data: data.diskWriteData,
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' МБ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Дисковые операции (МБ)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' МБ';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            });
        }

        // Обновление метрик всех VM и контейнеров
        async function updateAllMetrics() {
            if (!allVmsLoaded && !allContainersLoaded) return;

            // Обновляем метрики VM
            if (allVmsLoaded) {
                const vmCards = document.querySelectorAll('#vm-list .vm-card:not(.skeleton)');

                for (const card of vmCards) {
                    const vmId = card.dataset.vmid;

                    try {
                        const response = await fetch(`/api/get_latest_metrics.php?vm_id=${vmId}&type=qemu`);
                        const data = await response.json();

                        if (data.success) {
                            updateVmCardMetrics(card, data.data, 'qemu');
                        }
                    } catch (error) {
                        console.error(`Error updating metrics for VM ${vmId}:`, error);
                    }
                }
            }

            // Обновляем метрики контейнеров
            if (allContainersLoaded) {
                const containerCards = document.querySelectorAll('#container-list .vm-card:not(.skeleton)');

                for (const card of containerCards) {
                    const vmId = card.dataset.vmid;

                    try {
                        const response = await fetch(`/api/get_latest_metrics.php?vm_id=${vmId}&type=lxc`);
                        const data = await response.json();

                        if (data.success) {
                            updateVmCardMetrics(card, data.data, 'lxc');
                        }
                    } catch (error) {
                        console.error(`Error updating metrics for container ${vmId}:`, error);
                    }
                }
            }
        }

        // Обновление метрик на карточке
        function updateVmCardMetrics(card, metrics, vmType) {
            const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
            const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
            const netIn = metrics.net_in || 0;
            const netOut = metrics.net_out || 0;
            const netTotal = (netIn + netOut).toFixed(2);

            // Обновляем прогресс-бары
            const cpuFill = card.querySelector('.cpu-progress');
            const ramFill = card.querySelector('.ram-progress');
            const diskFill = card.querySelector('.disk-progress');

            if (cpuFill) {
                cpuFill.style.width = `${metrics.cpu_usage}%`;
                const cpuLabel = card.querySelector('.vm-mini-progress-label span:last-child');
                if (cpuLabel) cpuLabel.textContent = `${Math.round(metrics.cpu_usage)}%`;
            }

            if (ramFill) {
                ramFill.style.width = `${ramUsagePercent}%`;
                const ramLabel = card.querySelectorAll('.vm-mini-progress-label span:last-child')[1];
                if (ramLabel) ramLabel.textContent = `${ramUsagePercent}%`;
            }

            if (diskFill) {
                const diskPercent = Math.min(100, diskTotal * 10);
                diskFill.style.width = `${diskPercent}%`;
                const diskLabel = card.querySelectorAll('.vm-mini-progress-label span:last-child')[2];
                if (diskLabel) diskLabel.textContent = `${diskTotal} MB/s`;
            }

            // Обновляем прогресс-бары сети
            const networkProgress = card.querySelector('.network-progress');
            if (networkProgress) {
                const netInPercent = Math.min(100, netIn * 2);
                const netOutPercent = Math.min(100, netOut * 2);

                networkProgress.querySelector('.network-progress-in').style.width = `${netInPercent}%`;
                networkProgress.querySelector('.network-progress-out').style.width = `${netOutPercent}%`;

                const networkLabel = card.querySelector('.network-progress-label span:last-child');
                if (networkLabel) networkLabel.textContent = `${netTotal} Mbit/s`;

                const networkSpeed = card.querySelector('.network-speed');
                if (networkSpeed) networkSpeed.textContent = `▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s`;
            }
        }

        // Переключение вида карточек
        function toggleView(viewType) {
            // Обновляем активные кнопки
            document.querySelectorAll('.view-toggle-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.view === viewType);
            });

            if (viewType === 'compact') {
                document.getElementById('vm-list').innerHTML = compactViewHtml.vm;
                document.getElementById('container-list').innerHTML = compactViewHtml.container;

                // Применяем компактный стиль
                document.querySelectorAll('.vm-card').forEach(card => {
                    card.classList.add('compact');
                });
            } else {
                document.getElementById('vm-list').innerHTML = gridViewHtml.vm;
                document.getElementById('container-list').innerHTML = gridViewHtml.container;

                // Убираем компактный стиль
                document.querySelectorAll('.vm-card').forEach(card => {
                    card.classList.remove('compact');
                });
            }

            // Обновляем обработчики событий
            addVmActionHandlers();
            addContainerActionHandlers();

            // Сохраняем выбор пользователя в localStorage
            localStorage.setItem('vmViewType', viewType);
        }

        // Функция поиска
        function searchVMs(searchTerm, type) {
            const listId = type === 'vm' ? 'vm-list' : 'container-list';
            const list = document.getElementById(listId);
            const cards = list.querySelectorAll('.vm-card:not(.skeleton)');

            if (searchTerm.trim() === '') {
                // Показываем все карточки
                cards.forEach(card => {
                    card.style.display = '';
                });
                return;
            }

            const term = searchTerm.toLowerCase();
            let hasResults = false;

            cards.forEach(card => {
                const vmName = card.querySelector('.vm-name').textContent.toLowerCase();
                const vmId = card.querySelector('.vm-id')?.textContent.toLowerCase() || '';

                if (vmName.includes(term) || vmId.includes(term)) {
                    card.style.display = '';
                    hasResults = true;
                } else {
                    card.style.display = 'none';
                }
            });

            // Показываем сообщение, если ничего не найдено
            const noResults = list.querySelector('.no-results');
            if (!hasResults && cards.length > 0) {
                if (!noResults) {
                    const noResultsDiv = document.createElement('div');
                    noResultsDiv.className = 'empty-state';
                    noResultsDiv.innerHTML = `
                        <div class="empty-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h3 class="empty-text">Ничего не найдено</h3>
                        <p class="empty-text">Попробуйте изменить запрос</p>
                    `;
                    list.appendChild(noResultsDiv);
                }
            } else if (noResults) {
                noResults.remove();
            }
        }

        // Вспомогательные функции
        function escapeHtml(unsafe) {
            return unsafe?.replace(/&/g, "&amp;")
                         .replace(/</g, "&lt;")
                         .replace(/>/g, "&gt;")
                         .replace(/"/g, "&quot;")
                         .replace(/'/g, "&#039;") || '';
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const day = date.getDate().toString().padStart(2, '0');
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const year = date.getFullYear();
            return `${day}.${month}.${year}`;
        }
    </script>
</body>
</html>
