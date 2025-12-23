<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

checkAuth();

$db = new Database();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$user = $db->getConnection()->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Получаем статистику по ВМ
$all_vms = $db->getConnection()->query("SELECT COUNT(*) as total,
                                      SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running
                                      FROM vms WHERE user_id = $user_id")->fetch();

// Получаем последние платежи
$last_payment = $db->getConnection()->query("SELECT amount, created_at FROM payments WHERE user_id = $user_id ORDER BY id DESC LIMIT 1")->fetch();

// Получаем ВМ пользователя
$vms = $db->getConnection()->query("SELECT * FROM vms WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5")->fetchAll();

// Получаем квоты пользователя
$quota = $db->getConnection()->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
if (!$quota) {
    $db->getConnection()->exec("INSERT INTO user_quotas (user_id) VALUES ($user_id)");
    $quota = $db->getConnection()->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
}

// Получаем текущее использование ресурсов
$usage = $db->getConnection()->query("
    SELECT
        COUNT(*) as vm_count,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram,
        SUM(disk) as total_disk
    FROM vms
    WHERE user_id = $user_id AND status != 'deleted'
")->fetch();

// Рассчитываем процент использования
$cpu_percent = $quota['max_cpu'] > 0 ? round(($usage['total_cpu'] / $quota['max_cpu']) * 100) : 0;
$ram_percent = $quota['max_ram'] > 0 ? round(($usage['total_ram'] / $quota['max_ram']) * 100) : 0;
$disk_percent = $quota['max_disk'] > 0 ? round(($usage['total_disk'] / $quota['max_disk']) * 100) : 0;
$vms_percent = $quota['max_vms'] > 0 ? round(($usage['vm_count'] / $quota['max_vms']) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель управления | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <!-- Подключаем Chart.js для графиков -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .stat-card.bonus::before {
            background: var(--purple-gradient);
        }

        .stat-card.admin::before {
            background: var(--danger-gradient);
        }

        .stat-card.quota::before {
            background: var(--success-gradient);
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

        .stat-icon.bonus {
            background: var(--purple-gradient);
        }

        .stat-icon.admin {
            background: var(--danger-gradient);
        }

        .stat-icon.quota {
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

        /* Прогресс бар для квот */
        .quota-progress {
            margin-top: 16px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .progress-label {
            font-size: 12px;
            font-weight: 500;
            color: #64748b;
        }

        body.dark-theme .progress-label {
            color: #94a3b8;
        }

        .progress-percentage {
            font-size: 12px;
            font-weight: 700;
            color: #10b981;
        }

        .progress-bar {
            height: 8px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(135deg, #10b981, #059669);
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .progress-fill.high {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .progress-fill.medium {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        /* Быстрые действия */
        .quick-actions-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .quick-actions-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        .quick-actions-gridd {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .quick-action-btnn {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 11px;
            padding: 16px;
            background: rgba(14, 165, 233, 0.1);
            border: 1px solid rgba(14, 165, 233, 0.2);
            border-radius: 12px;
            color: #0ea5e9;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .quick-action-btnn:hover {
            background: rgba(14, 165, 233, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        }

        .quick-action-btnn.warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }

        .quick-action-btnn.warning:hover {
            background: rgba(245, 158, 11, 0.2);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
        }

        .quick-action-btnn.admin {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .quick-action-btnn.admin:hover {
            background: rgba(239, 68, 68, 0.2);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }

        .quick-action-btnn i {
            font-size: 20px;
        }

        /* Список ВМ */
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

        .vm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

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

        .vm-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .vm-name {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .vm-name {
            color: #f1f5f9;
        }

        .vm-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-running {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-stopped {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .vm-specs {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .vm-spec {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #64748b;
        }

        body.dark-theme .vm-spec {
            color: #94a3b8;
        }

        .vm-spec i {
            color: #00bcd4;
            font-size: 14px;
        }

        .vm-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
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
            flex-direction: column;
        }

        .vm-action-btn:hover {
            background: rgba(14, 165, 233, 0.2);
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

        /* Последние действия */
        .activity-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .activity-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
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

            .quick-actions-grid {
                grid-template-columns: 1fr;
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

            .vm-section,
            .activity-section,
            .quick-actions-section {
                padding: 20px;
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
        .stat-card:nth-child(6) { animation-delay: 0.5s; }
        .stat-card:nth-child(7) { animation-delay: 0.6s; }
        .stat-card:nth-child(8) { animation-delay: 0.7s; }
        .stat-card:nth-child(9) { animation-delay: 0.8s; }

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

        /* Кнопка вверх (адаптированная для dashboard) */
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

        /* Модальное окно для детальной информации ВМ */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            overflow-y: auto;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 1000px;
            margin: 30px auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.4s ease;
        }

        body.dark-theme .modal-content {
            background: rgba(30, 41, 59, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        body.dark-theme .modal-title {
            color: #f1f5f9;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #64748b;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .modal-body {
            padding: 24px;
        }

        /* Сетка информации ВМ */
        .vm-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .vm-info-section {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .vm-info-section {
            background: rgba(30, 41, 59, 0.5);
        }

        .info-section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
            padding-bottom: 12px;
            border-bottom: 2px solid #00bcd4;
        }

        body.dark-theme .info-section-title {
            color: #f1f5f9;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed rgba(148, 163, 184, 0.2);
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
        }

        body.dark-theme .info-label {
            color: #94a3b8;
        }

        .info-value {
            color: #1e293b;
            font-weight: 600;
        }

        body.dark-theme .info-value {
            color: #f1f5f9;
        }

        /* Графики статистики */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-top: 32px;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            height: 300px;
        }

        body.dark-theme .chart-container {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1e293b;
            text-align: center;
        }

        body.dark-theme .chart-title {
            color: #f1f5f9;
        }

        /* Стили для загрузки */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 200px;
            flex-direction: column;
            gap: 16px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #00bcd4;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Адаптивность для модального окна */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10px auto;
            }

            .modal-header {
                padding: 16px;
            }

            .modal-body {
                padding: 16px;
            }

            .vm-info-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .charts-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <?php
    // Подключаем обновленную шапку
    include '../templates/headers/user_header.php';
    ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <!-- Модальное окно для детальной информации ВМ -->
    <div id="vmDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-server"></i> <span id="vmModalTitle">Информация о ВМ</span>
                </h2>
                <button class="modal-close" onclick="closeVmModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="vmModalBody">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    <p>Загрузка информации...</p>
                </div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <?php
        // Подключаем обновленный сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-tachometer-alt"></i> Панель управления
                </h1>
                <div class="header-actions">
                    <button class="btn-refresh" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <!-- Статистика -->
            <div class="stats-grid">
                <!-- Запущенные ВМ -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <div class="stat-label">Запущенные ВМ</div>
                    </div>
                    <div class="stat-value"><?= $all_vms['running'] ?? 0 ?></div>
                    <div class="stat-details">из <?= $all_vms['total'] ?? 0 ?> всего</div>
                </div>

                <!-- Всего ВМ -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="stat-label">Всего ВМ</div>
                    </div>
                    <div class="stat-value"><?= $all_vms['total'] ?? 0 ?></div>
                    <div class="stat-details">на вашем аккаунте</div>
                </div>

                <!-- Баланс -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-label">Баланс</div>
                    </div>
                    <div class="stat-value"><?= number_format($user['balance'], 2) ?> ₽</div>
                    <div class="stat-details"><?= $user['balance'] >= 0 ? 'Доступно' : 'Задолженность' ?></div>
                </div>

                <!-- Последний платёж -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-ruble-sign"></i>
                        </div>
                        <div class="stat-label">Последний платёж</div>
                    </div>
                    <div class="stat-value"><?= $last_payment ? number_format($last_payment['amount'], 2) : '0.00' ?> ₽</div>
                    <div class="stat-details"><?= $last_payment ? date('d.m.Y', strtotime($last_payment['created_at'])) : 'нет данных' ?></div>
                </div>

                <!-- CPU квота -->
                <div class="stat-card quota">
                    <div class="stat-header">
                        <div class="stat-icon quota">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <div class="stat-label">CPU квота</div>
                    </div>
                    <div class="stat-value"><?= $usage['total_cpu'] ?? 0 ?> / <?= $quota['max_cpu'] ?></div>
                    <div class="quota-progress">
                        <div class="progress-header">
                            <span class="progress-label">Использовано</span>
                            <span class="progress-percentage"><?= $cpu_percent ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $cpu_percent > 80 ? 'high' : ($cpu_percent > 50 ? 'medium' : '') ?>"
                                 style="width: <?= $cpu_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- RAM квота -->
                <div class="stat-card quota">
                    <div class="stat-header">
                        <div class="stat-icon quota">
                            <i class="fas fa-memory"></i>
                        </div>
                        <div class="stat-label">RAM квота</div>
                    </div>
                    <div class="stat-value"><?= ($usage['total_ram'] /1024 ?? 0) ?>GB / <?= ($quota['max_ram'] / 1024)?>GB</div>
                    <div class="quota-progress">
                        <div class="progress-header">
                            <span class="progress-label">Использовано</span>
                            <span class="progress-percentage"><?= $ram_percent ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $ram_percent > 80 ? 'high' : ($ram_percent > 50 ? 'medium' : '') ?>"
                                 style="width: <?= $ram_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Диск квота -->
                <div class="stat-card quota">
                    <div class="stat-header">
                        <div class="stat-icon quota">
                            <i class="fas fa-hdd"></i>
                        </div>
                        <div class="stat-label">Диск квота</div>
                    </div>
                    <div class="stat-value"><?= $usage['total_disk'] ?? 0 ?>GB / <?= $quota['max_disk'] ?>GB</div>
                    <div class="quota-progress">
                        <div class="progress-header">
                            <span class="progress-label">Использовано</span>
                            <span class="progress-percentage"><?= $disk_percent ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $disk_percent > 80 ? 'high' : ($disk_percent > 50 ? 'medium' : '') ?>"
                                 style="width: <?= $disk_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Квота ВМ -->
                <div class="stat-card quota">
                    <div class="stat-header">
                        <div class="stat-icon quota">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="stat-label">Квота ВМ</div>
                    </div>
                    <div class="stat-value"><?= $usage['vm_count'] ?? 0 ?> / <?= $quota['max_vms'] ?></div>
                    <div class="quota-progress">
                        <div class="progress-header">
                            <span class="progress-label">Использовано</span>
                            <span class="progress-percentage"><?= $vms_percent ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $vms_percent > 80 ? 'high' : ($vms_percent > 50 ? 'medium' : '') ?>"
                                 style="width: <?= $vms_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Бонусный баланс -->
                <div class="stat-card bonus">
                    <div class="stat-header">
                        <div class="stat-icon bonus">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="stat-label">Бонусный баланс</div>
                    </div>
                    <div class="stat-value"><?= number_format($user['bonus_balance'], 2) ?> ₽</div>
                    <div class="stat-details"><?= $user['bonus_balance'] > 0 ? 'Доступно' : 'Нет бонусов' ?></div>
                </div>

                <!-- Админ Панель -->
                <?php if ($user['is_admin']): ?>
                <div class="stat-card admin">
                    <div class="stat-header">
                        <div class="stat-icon admin">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-label">Админ Панель</div>
                    </div>
                    <div class="stat-value">Доступно</div>
                    <div class="stat-details">Управление системой</div>
                    <a href="/admin/" class="quick-action-btnn admin" style="margin-top: 12px;">
                        <i class="fas fa-cog"></i> Перейти в админку
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Быстрые действия -->
            <div class="quick-actions-section">
                <h2 class="section-title">
                    <i class="fas fa-bolt"></i> Быстрые действия
                </h2>
                <div class="quick-actions-gridd">
                    <a href="/templates/order_vm.php" class="quick-action-btnn">
                        <i class="fas fa-plus"></i> Создать ВМ
                    </a>
                    <a href="/templates/billing.php" class="quick-action-btnn">
                        <i class="fas fa-credit-card"></i> Пополнить баланс
                    </a>
                    <?php if ($user['bonus_balance'] > 0): ?>
                        <a href="/templates/billing.php#bonuses" class="quick-action-btnn warning">
                            <i class="fas fa-coins"></i> Использовать бонусы
                        </a>
                    <?php endif; ?>
                    <a href="/templates/support.php" class="quick-action-btnn">
                        <i class="fas fa-headset"></i> Поддержка
                    </a>
                </div>
            </div>

            <!-- Виртуальные машины -->
            <div class="vm-section">
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 class="section-title">
                        <i class="fas fa-server"></i> Ваши виртуальные машины
                    </h2>
                    <a href="/templates/my_vms.php" class="quick-action-btnn" style="padding: 8px 16px;">
                        <i class="fas fa-list"></i> Все ВМ
                    </a>
                </div>

                <?php if (count($vms) > 0): ?>
                    <div class="vm-grid">
                        <?php foreach ($vms as $vm): ?>
                            <div class="vm-card">
                                <div class="vm-header">
                                    <h3 class="vm-name"><?= htmlspecialchars($vm['hostname']) ?></h3>
                                    <span class="vm-status <?= $vm['status'] === 'running' ? 'status-running' : 'status-stopped' ?>">
                                        <?= $vm['status'] === 'running' ? 'Запущена' : 'Остановлена' ?>
                                    </span>
                                </div>

                                <div class="vm-specs">
                                    <div class="vm-spec">
                                        <i class="fas fa-microchip"></i>
                                        <span><?= $vm['cpu'] ?> vCPU</span>
                                    </div>
                                    <div class="vm-spec">
                                        <i class="fas fa-memory"></i>
                                        <span><?= ($vm['ram'] / 1024)?> GB RAM</span>
                                    </div>
                                    <div class="vm-spec">
                                        <i class="fas fa-hdd"></i>
                                        <span><?= $vm['disk'] ?> GB SSD</span>
                                    </div>
                                    <div class="vm-spec">
                                        <i class="fas fa-network-wired"></i>
                                        <span><?= $vm['ip_address'] ?? 'DHCP' ?></span>
                                    </div>
                                </div>

                                <div class="vm-actions">
                                    <?php if ($vm['status'] !== 'running'): ?>
                                        <button class="vm-action-btn" onclick="showNotification('Функция запуска будет реализована в следующих версиях', 'info')">
                                            <i class="fas fa-play"></i> Запустить
                                        </button>
                                    <?php else: ?>
                                        <button class="vm-action-btn warning" onclick="showNotification('Функция перезагрузки будет реализована в следующих версиях', 'info')">
                                            <i class="fas fa-sync-alt"></i> Перезагрузить
                                        </button>
                                        <button class="vm-action-btn danger" onclick="showNotification('Функция остановки будет реализована в следующих версиях', 'info')">
                                            <i class="fas fa-stop"></i> Остановить
                                        </button>
                                    <?php endif; ?>
                                    <button class="vm-action-btn" onclick="openVmDetails(<?= $vm['id'] ?>)">
                                        <i class="fas fa-info-circle"></i> Подробнее
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-cloud"></i>
                        </div>
                        <p class="empty-text">У вас пока нет виртуальных машин</p>
                        <a href="/templates/order_vm.php" class="quick-action-btnn">
                            <i class="fas fa-plus"></i> Заказать ВМ
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Последние действия -->
            <div class="activity-section">
                <h2 class="section-title">
                    <i class="fas fa-history"></i> Последние действия
                </h2>

                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <p class="empty-text">Здесь будет отображаться история ваших действий</p>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>

    <script>
        // Глобальные переменные для хранения чартов
        let cpuChart = null;
        let ramChart = null;
        let networkChart = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Анимация прогресс-баров при загрузке
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });

            // Обработка кнопок управления ВМ
            document.querySelectorAll('.vm-action-btn').forEach(btn => {
                if (btn.onclick === null && btn.tagName === 'BUTTON') {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        showNotification('Эта функция будет реализована в следующем обновлении', 'info');
                    });
                }
            });

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

            // Плавная прокрутка для внутренних ссылок
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    if (this.getAttribute('href') === '#') return;

                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    if (targetId.startsWith('#')) {
                        const targetElement = document.querySelector(targetId);
                        if (targetElement) {
                            window.scrollTo({
                                top: targetElement.offsetTop - 100,
                                behavior: 'smooth'
                            });
                        }
                    } else {
                        window.location.href = this.getAttribute('href');
                    }
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

            // Проверяем при загрузке
            handleSidebarCollapse();

            // И при изменении размера окна
            window.addEventListener('resize', handleSidebarCollapse);

            // Обработка уведомлений из сессии
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?= addslashes($_SESSION['message']) ?>", "<?= $_SESSION['message_type'] ?? 'info' ?>");
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            // Закрытие модального окна по клику вне его
            document.getElementById('vmDetailsModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeVmModal();
                }
            });

            // Закрытие модального окна по клавише ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeVmModal();
                }
            });
        });

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               type === 'warning' ? 'fa-exclamation-triangle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Функция открытия модального окна с детальной информацией о ВМ
function openVmDetails(vmId) {
    const modal = document.getElementById('vmDetailsModal');
    const modalTitle = document.getElementById('vmModalTitle');
    const modalBody = document.getElementById('vmModalBody');

    // Показываем индикатор загрузки
    modalBody.innerHTML = `
        <div class="loading">
            <div class="loading-spinner"></div>
            <p>Загрузка информации о виртуальной машине...</p>
        </div>
    `;

    // Открываем модальное окно
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    // Загружаем данные о ВМ через AJAX
    fetch(`../templates/ajax/get_vm_details.php?id=${vmId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            /*console.log('Получены данные от сервера:', data);*/ // Отладка

            if (data.success) {
                // Обновляем заголовок
                modalTitle.textContent = data.vm.hostname;

                // Формируем HTML с информацией о ВМ
                modalBody.innerHTML = generateVmDetailsHTML(data);

                // Инициализируем графики
                setTimeout(() => {
                    initializeCharts(data);
                }, 100);
            } else {
                modalBody.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <p class="empty-text">${data.message || 'Ошибка при загрузке информации о ВМ'}</p>
                        <p style="color: #64748b; font-size: 12px; margin-top: 10px;">
                            VM ID: ${vmId}<br>
                            User ID: ${<?= $user_id ?>}
                        </p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Ошибка при загрузке данных:', error);
            modalBody.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p class="empty-text">Ошибка при загрузке информации о ВМ: ${error.message}</p>
                    <p style="color: #64748b; font-size: 12px; margin-top: 10px;">
                        Проверьте консоль браузера для получения подробной информации.
                    </p>
                </div>
            `;
        });
}

        // Функция для генерации HTML с детальной информацией о ВМ
function generateVmDetailsHTML(data) {
    const vm = data.vm;
    const tariff = data.tariff;
    const lastCharge = data.lastCharge;
    const metrics = data.metrics;

    // Форматирование даты
    const formatDate = (dateString) => {
        if (!dateString) return 'Нет данных';
        const date = new Date(dateString);
        return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
    };

    // Определение типа ВМ
    const vmTypeText = vm.vm_type === 'qemu' ? 'KVM (QEMU)' : 'LXC (Контейнер)';

    // Определение типа тарифа
    const tariffTypeText = tariff.is_custom == 1 ? 'Кастомный (почасовая оплата)' : 'Фиксированный тариф';

    // Генерация HTML для тарифной информации
    let chargeHTML = '';
    if (tariff.is_custom == 1) {
        // Для кастомного тарифа показываем почасовые цены
        chargeHTML = `
            <div class="vm-info-section">
                <h3 class="info-section-title">Тарифная информация</h3>
                <div class="info-item">
                    <span class="info-label">Тип тарифа:</span>
                    <span class="info-value">${tariffTypeText}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Название тарифа:</span>
                    <span class="info-value">${tariff.name}</span>
                </div>`;

        // Если есть последнее списание, показываем детали
        if (lastCharge) {
            chargeHTML += `
                <div class="info-item">
                    <span class="info-label" style="font-weight: 700; color: #ef4444;">Последнее списание:</span>
                    <span class="info-value" style="font-weight: 700; color: #ef4444;">${formatDate(lastCharge.created_at)}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">CPU (${lastCharge.cpu} ядер):</span>
                    <span class="info-value">${parseFloat(lastCharge.price_per_hour_cpu).toFixed(6)} ₽/час × ${lastCharge.cpu} = ${(lastCharge.price_per_hour_cpu * lastCharge.cpu).toFixed(6)} ₽/час</span>
                </div>
                <div class="info-item">
                    <span class="info-label">RAM (${lastCharge.ram} MB):</span>
                    <span class="info-value">${parseFloat(lastCharge.price_per_hour_ram).toFixed(6)} ₽/час × ${lastCharge.ram} = ${(lastCharge.price_per_hour_ram * lastCharge.ram).toFixed(6)} ₽/час</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Disk (${lastCharge.disk} GB):</span>
                    <span class="info-value">${parseFloat(lastCharge.price_per_hour_disk).toFixed(6)} ₽/час × ${lastCharge.disk} = ${(lastCharge.price_per_hour_disk * lastCharge.disk).toFixed(6)} ₽/час</span>
                </div>
                <div class="info-item">
                    <span class="info-label" style="font-weight: 700;">Итого в час:</span>
                    <span class="info-value" style="font-weight: 700; color: #10b981;">${parseFloat(lastCharge.total_per_hour).toFixed(6)} ₽</span>
                </div>`;
        } else {
            // Если нет списаний, показываем базовые цены
            chargeHTML += `
                <div class="info-item">
                    <span class="info-label">Цена за CPU (за ядро/час):</span>
                    <span class="info-value">${parseFloat(tariff.price_per_hour_cpu).toFixed(6)} ₽</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Цена за RAM (за MB/час):</span>
                    <span class="info-value">${parseFloat(tariff.price_per_hour_ram).toFixed(6)} ₽</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Цена за Disk (за GB/час):</span>
                    <span class="info-value">${parseFloat(tariff.price_per_hour_disk).toFixed(6)} ₽</span>
                </div>`;
        }

        chargeHTML += `</div>`;
    } else if (tariff.price > 0) {
        // Для фиксированного тарифа
        chargeHTML = `
            <div class="vm-info-section">
                <h3 class="info-section-title">Тарифная информация</h3>
                <div class="info-item">
                    <span class="info-label">Тип тарифа:</span>
                    <span class="info-value">${tariffTypeText}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Название тарифа:</span>
                    <span class="info-value">${tariff.name}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Стоимость:</span>
                    <span class="info-value" style="font-weight: 700; color: #10b981;">${parseFloat(tariff.price).toFixed(2)} ₽/месяц</span>
                </div>
            </div>`;
    } else {
        // Если нет тарифа
        chargeHTML = `
            <div class="vm-info-section">
                <h3 class="info-section-title">Тарифная информация</h3>
                <div class="info-item">
                    <span class="info-label">Тариф:</span>
                    <span class="info-value" style="color: #f59e0b;">Не назначен</span>
                </div>
            </div>`;
    }

    // Информация о ноде
    let nodeHTML = '';
    if (data.node_info) {
        nodeHTML = `
            <div class="info-item">
                <span class="info-label">Нода:</span>
                <span class="info-value">${data.node_info.node_name}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Статус ноды:</span>
                <span class="info-value" style="color: ${data.node_info.status === 'online' ? '#10b981' : '#ef4444'}">
                    ${data.node_info.status === 'online' ? 'Онлайн' : 'Офлайн'}
                </span>
            </div>`;
    }

    return `
        <div class="vm-info-grid">
            <div class="vm-info-section">
                <h3 class="info-section-title">Основная информация</h3>
                <div class="info-item">
                    <span class="info-label">Имя хоста:</span>
                    <span class="info-value">${vm.hostname}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Статус:</span>
                    <span class="info-value" style="color: ${vm.status === 'running' ? '#10b981' : '#ef4444'};">
                        ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Тип ВМ:</span>
                    <span class="info-value">${vmTypeText}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Операционная система:</span>
                    <span class="info-value">${vm.os_type === 'windows' ? 'Windows' : 'Linux'} ${vm.os_version || ''}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Дата создания:</span>
                    <span class="info-value">${formatDate(vm.created_at)}</span>
                </div>
                ${nodeHTML}
            </div>

            <div class="vm-info-section">
                <h3 class="info-section-title">Ресурсы</h3>
                <div class="info-item">
                    <span class="info-label">vCPU (ядра):</span>
                    <span class="info-value">${vm.cpu}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">RAM:</span>
                    <span class="info-value">${(vm.ram / 1024).toFixed(1)} GB (${vm.ram} MB)</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Диск (SSD):</span>
                    <span class="info-value">${vm.disk} GB</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Хранилище:</span>
                    <span class="info-value">${vm.storage}</span>
                </div>
            </div>

            <div class="vm-info-section">
                <h3 class="info-section-title">Сеть</h3>
                <div class="info-item">
                    <span class="info-label">IP адрес:</span>
                    <span class="info-value">${vm.ip_address || 'DHCP'}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Сетевой интерфейс:</span>
                    <span class="info-value">${vm.network}</span>
                </div>
                ${vm.sdn ? `
                <div class="info-item">
                    <span class="info-label">SDN сеть:</span>
                    <span class="info-value">${vm.sdn}</span>
                </div>
                ` : ''}
            </div>

            ${chargeHTML}
        </div>

        <div class="charts-grid" id="chartsContainer">
            <div class="chart-container">
                <h4 class="chart-title">Использование CPU (%)</h4>
                <canvas id="cpuChart"></canvas>
            </div>
            <div class="chart-container">
                <h4 class="chart-title">Использование RAM (GB)</h4>
                <canvas id="ramChart"></canvas>
            </div>
            <div class="chart-container">
                <h4 class="chart-title">Сетевая активность (Mbit/s)</h4>
                <canvas id="networkChart"></canvas>
            </div>
        </div>
    `;
}


        // Функция инициализации графиков
        function initializeCharts(data) {
            const metrics = data.metrics;

            // Уничтожаем предыдущие графики, если они существуют
            if (cpuChart) cpuChart.destroy();
            if (ramChart) ramChart.destroy();
            if (networkChart) networkChart.destroy();

            // Подготавливаем данные для графиков
            const timestamps = metrics.map(m => {
                const date = new Date(m.timestamp);
                return date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
            });

            const cpuData = metrics.map(m => m.cpu_usage);
            const ramData = metrics.map(m => m.mem_usage);
            const netInData = metrics.map(m => m.net_in);
            const netOutData = metrics.map(m => m.net_out);

            // Определяем цветовую схему в зависимости от темы
            const isDarkTheme = document.body.classList.contains('dark-theme');
            const gridColor = isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
            const textColor = isDarkTheme ? '#f1f5f9' : '#1e293b';

            // График CPU
            const cpuCtx = document.getElementById('cpuChart').getContext('2d');
            cpuChart = new Chart(cpuCtx, {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [{
                        label: 'Использование CPU',
                        data: cpuData,
                        borderColor: '#00bcd4',
                        backgroundColor: 'rgba(0, 188, 212, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        },
                        x: {
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: textColor
                            }
                        }
                    }
                }
            });

            // График RAM
            const ramCtx = document.getElementById('ramChart').getContext('2d');
            ramChart = new Chart(ramCtx, {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [{
                        label: 'Использование RAM',
                        data: ramData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        },
                        x: {
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: textColor
                            }
                        }
                    }
                }
            });

            // График сети
            const networkCtx = document.getElementById('networkChart').getContext('2d');
            networkChart = new Chart(networkCtx, {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [
                        {
                            label: 'Входящий трафик',
                            data: netInData,
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Исходящий трафик',
                            data: netOutData,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        },
                        x: {
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: textColor
                            }
                        }
                    }
                }
            });
        }

        // Функция закрытия модального окна
        function closeVmModal() {
            const modal = document.getElementById('vmDetailsModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';

            // Уничтожаем графики при закрытии окна
            if (cpuChart) {
                cpuChart.destroy();
                cpuChart = null;
            }
            if (ramChart) {
                ramChart.destroy();
                ramChart = null;
            }
            if (networkChart) {
                networkChart.destroy();
                networkChart = null;
            }
        }

        // Добавляем стили для анимаций уведомлений
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Автоматическое обновление статистики каждые 30 секунд
        setInterval(function() {
            // Обновляем только элементы, которые должны меняться
            const refreshBtn = document.querySelector('.btn-refresh');
            if (refreshBtn) {
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Обновление...';

                // Можно добавить AJAX запрос для обновления данных
                setTimeout(() => {
                    refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Обновить';
                }, 1000);
            }
        }, 30000); // 30 секунд
    </script>
</body>
</html>
