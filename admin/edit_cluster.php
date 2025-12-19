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

// Получаем данные кластера
$cluster = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM proxmox_clusters WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $cluster = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$cluster) {
    $_SESSION['error'] = "Кластер не найден";
    header("Location: nodes.php");
    exit;
}

// Получаем статистику кластера
$cluster_stats = $pdo->prepare("
    SELECT
        COUNT(*) as total_nodes,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_nodes,
        SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_nodes,
        SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_nodes,
        SUM(CASE WHEN is_cluster_master = 1 THEN 1 ELSE 0 END) as master_nodes,
        MAX(last_check) as last_node_check
    FROM proxmox_nodes
    WHERE cluster_id = ?
");
$cluster_stats->execute([$cluster['id']]);
$cluster_stats = $cluster_stats->fetch(PDO::FETCH_ASSOC);

// Получаем список нод в кластере
$cluster_nodes = $pdo->prepare("
    SELECT
        n.*,
        (SELECT COUNT(*) FROM vms WHERE node_id = n.id) as vm_count,
        (SELECT COUNT(*) FROM vms WHERE node_id = n.id AND status = 'running') as running_vms
    FROM proxmox_nodes n
    WHERE n.cluster_id = ?
    ORDER BY n.is_active DESC, n.node_name
");
$cluster_nodes->execute([$cluster['id']]);
$cluster_nodes = $cluster_nodes->fetchAll(PDO::FETCH_ASSOC);

// Получаем историю изменений кластера
$cluster_history = $pdo->prepare("
    SELECT
        'created' as type,
        created_at as date,
        CONCAT('Кластер создан') as description
    FROM proxmox_clusters
    WHERE id = ?

    UNION ALL

    SELECT
        'updated' as type,
        NOW() as date,
        CONCAT('Кластер обновлен') as description
    FROM proxmox_clusters
    WHERE id = ?

    ORDER BY date DESC
    LIMIT 10
");
$cluster_history->execute([$cluster['id'], $cluster['id']]);
$cluster_history = $cluster_history->fetchAll(PDO::FETCH_ASSOC);

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверяем наличие кнопки обновления или скрытого поля
    if (isset($_POST['update_cluster']) || isset($_POST['cluster_form'])) {
        try {
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $enable_auto_healing = isset($_POST['enable_auto_healing']) ? 1 : 0;
            $enable_vm_migration = isset($_POST['enable_vm_migration']) ? 1 : 0;
            $max_vms_per_node = intval($_POST['max_vms_per_node'] ?? 50);
            $load_balancing_threshold = intval($_POST['load_balancing_threshold'] ?? 80);
            $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;

            // Валидация
            if (empty($name)) {
                throw new Exception("Имя кластера обязательно");
            }

            // Проверка уникальности имени кластера
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_clusters WHERE name = ? AND id != ?");
            $stmt->execute([$name, $cluster['id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Кластер с таким именем уже существует");
            }

            // Валидация длины имени
            if (strlen($name) > 50) {
                throw new Exception("Имя кластера не должно превышать 50 символов");
            }

            // Валидация длины описания
            if (strlen($description) > 500) {
                throw new Exception("Описание не должно превышать 500 символов");
            }

            // Валидация числовых значений
            if ($max_vms_per_node < 1 || $max_vms_per_node > 1000) {
                throw new Exception("Максимальное количество ВМ на ноду должно быть от 1 до 1000");
            }

            if ($load_balancing_threshold < 1 || $load_balancing_threshold > 100) {
                throw new Exception("Порог балансировки нагрузки должен быть от 1 до 100%");
            }

            // Проверка, можно ли деактивировать кластер
            if ($cluster['is_active'] == 1 && $is_active == 0) {
                // Проверяем, есть ли активные ВМ в кластере
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM vms v
                    JOIN proxmox_nodes n ON n.id = v.node_id
                    WHERE n.cluster_id = ? AND v.status = 'running'
                ");
                $stmt->execute([$cluster['id']]);
                $running_vms = $stmt->fetchColumn();

                if ($running_vms > 0) {
                    throw new Exception("Невозможно деактивировать кластер: в нем есть работающие ВМ ($running_vms шт.)");
                }
            }

            // Подготавливаем SQL запрос с проверкой наличия полей
            $sql = "UPDATE proxmox_clusters SET 
                    name = ?, 
                    description = ?, 
                    is_active = ?,
                    updated_at = NOW()";
            
            $params = [$name, $description, $is_active];
            
            // Добавляем дополнительные поля, если они существуют в базе
            $additional_fields = [
                'enable_auto_healing',
                'enable_vm_migration', 
                'max_vms_per_node',
                'load_balancing_threshold',
                'maintenance_mode'
            ];
            
            foreach ($additional_fields as $field) {
                if (isset($$field)) {
                    $sql .= ", $field = ?";
                    $params[] = $$field;
                }
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $cluster['id'];
            
            $stmt = $pdo->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса: " . implode(", ", $pdo->errorInfo()));
            }
            
            // Выполнение запроса
            $result = $stmt->execute($params);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Ошибка выполнения запроса: " . $errorInfo[2]);
            }
            
            // Логируем успешное обновление
            error_log("Кластер обновлен: ID = {$cluster['id']}, Name = $name");

            // Если кластер переведен в режим обслуживания, останавливаем все ноды
            if ($maintenance_mode && !($cluster['maintenance_mode'] ?? 0)) {
                $stmt = $pdo->prepare("UPDATE proxmox_nodes SET is_active = 0 WHERE cluster_id = ?");
                $stmt->execute([$cluster['id']]);

                // Записываем в историю
                try {
                    $log_stmt = $pdo->prepare("
                        INSERT INTO cluster_logs (cluster_id, action, details, created_at)
                        VALUES (?, 'maintenance_mode', 'Кластер переведен в режим обслуживания', NOW())
                    ");
                    $log_stmt->execute([$cluster['id']]);
                } catch (Exception $e) {
                    // Игнорируем ошибку лога, если таблица не существует
                    error_log("Не удалось записать лог: " . $e->getMessage());
                }
            }

            // Если режим обслуживания снят, активируем доступные ноды
            if (!$maintenance_mode && ($cluster['maintenance_mode'] ?? 0)) {
                $stmt = $pdo->prepare("
                    UPDATE proxmox_nodes
                    SET is_active = 1
                    WHERE cluster_id = ? AND status = 'online'
                ");
                $stmt->execute([$cluster['id']]);

                try {
                    $log_stmt = $pdo->prepare("
                        INSERT INTO cluster_logs (cluster_id, action, details, created_at)
                        VALUES (?, 'maintenance_mode', 'Режим обслуживания снят', NOW())
                    ");
                    $log_stmt->execute([$cluster['id']]);
                } catch (Exception $e) {
                    // Игнорируем ошибку лога
                    error_log("Не удалось записать лог: " . $e->getMessage());
                }
            }

            $_SESSION['success'] = "Кластер '{$name}' успешно обновлен";
            header("Location: nodes.php");
            exit;

        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            // Логирование ошибки
            error_log("Ошибка при обновлении кластера: " . $e->getMessage());
        }
    }

    // Обработка принудительной проверки всех нод кластера
    if (isset($_POST['check_all_nodes'])) {
        try {
            // Обновляем время последней проверки для всех нод
            $stmt = $pdo->prepare("
                UPDATE proxmox_nodes
                SET last_check = NOW()
                WHERE cluster_id = ?
            ");
            $stmt->execute([$cluster['id']]);

            // Добавляем запись в логи кластера
            try {
                $log_stmt = $pdo->prepare("
                    INSERT INTO cluster_logs (cluster_id, action, details, created_at)
                    VALUES (?, 'check_nodes', 'Запущена проверка всех нод кластера', NOW())
                ");
                $log_stmt->execute([$cluster['id']]);
            } catch (Exception $e) {
                // Игнорируем ошибку лога
            }

            $_SESSION['success'] = "Запущена проверка всех нод кластера. Результаты будут доступны через несколько минут.";
            header("Location: edit_cluster.php?id=" . $cluster['id']);
            exit;

        } catch (Exception $e) {
            $_SESSION['error'] = "Ошибка при запуске проверки: " . $e->getMessage();
        }
    }
}

$title = "Редактирование кластера | HomeVlad Cloud";
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

        /* ========== СТИЛИ ДЛЯ РЕДАКТИРОВАНИЯ КЛАСТЕРА ========== */
        :root {
            --cluster-bg: #f8fafc;
            --cluster-card-bg: #ffffff;
            --cluster-border: #e2e8f0;
            --cluster-text: #1e293b;
            --cluster-text-secondary: #64748b;
            --cluster-text-muted: #94a3b8;
            --cluster-hover: #f1f5f9;
            --cluster-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --cluster-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --cluster-accent: #00bcd4;
            --cluster-accent-light: rgba(0, 188, 212, 0.1);
            --cluster-success: #10b981;
            --cluster-warning: #f59e0b;
            --cluster-danger: #ef4444;
            --cluster-info: #3b82f6;
            --cluster-purple: #8b5cf6;
        }

        [data-theme="dark"] {
            --cluster-bg: #0f172a;
            --cluster-card-bg: #1e293b;
            --cluster-border: #334155;
            --cluster-text: #ffffff;
            --cluster-text-secondary: #cbd5e1;
            --cluster-text-muted: #94a3b8;
            --cluster-hover: #2d3748;
            --cluster-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --cluster-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .cluster-wrapper {
            padding: 20px;
            background: var(--cluster-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .cluster-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .cluster-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .cluster-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .cluster-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--cluster-card-bg);
            border-radius: 12px;
            border: 1px solid var(--cluster-border);
            box-shadow: var(--cluster-shadow);
        }

        .cluster-header-left h1 {
            color: var(--cluster-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .cluster-header-left h1 i {
            color: var(--cluster-accent);
        }

        .cluster-header-left p {
            color: var(--cluster-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        .cluster-header-right {
            display: flex;
            gap: 10px;
        }

        /* ========== СЕТКА СТРАНИЦЫ ========== */
        .cluster-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .cluster-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ========== КАРТОЧКИ ========== */
        .cluster-card {
            background: var(--cluster-card-bg);
            border-radius: 12px;
            border: 1px solid var(--cluster-border);
            box-shadow: var(--cluster-shadow);
            overflow: hidden;
            animation: slideIn 0.5s ease;
        }

        .cluster-card-header {
            padding: 20px;
            border-bottom: 1px solid var(--cluster-border);
            background: var(--cluster-hover);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cluster-card-header h2 {
            color: var(--cluster-text);
            font-size: 18px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cluster-card-header h2 i {
            color: var(--cluster-accent);
        }

        .cluster-card-body {
            padding: 24px;
        }

        /* ========== СТАТУС КЛАСТЕРА ========== */
        .cluster-status-card {
            background: linear-gradient(135deg, var(--cluster-purple), #7c3aed);
            color: white;
            border: none;
        }

        .cluster-status-header {
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .status-indicators {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .status-indicatorr {
            text-align: center;
            padding: 16px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .indicator-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .indicator-value {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        .indicator-subtext {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 4px;
        }

        .cluster-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .cluster-status-badge::before {
            content: '';
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.9);
        }

        .status-active::before {
            background: white;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.3);
        }

        .status-inactive {
            background: rgba(148, 163, 184, 0.9);
        }

        .status-inactive::before {
            background: white;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.3);
        }

        .status-maintenance {
            background: rgba(245, 158, 11, 0.9);
        }

        .status-maintenance::before {
            background: white;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.3);
        }

        /* ========== ФОРМА ========== */
        .cluster-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            color: var(--cluster-text);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: var(--cluster-danger);
            margin-left: 4px;
        }

        .form-input {
            padding: 12px 16px;
            border: 2px solid var(--cluster-border);
            border-radius: 8px;
            background: var(--cluster-card-bg);
            color: var(--cluster-text);
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--cluster-accent);
            box-shadow: 0 0 0 3px var(--cluster-accent-light);
        }

        textarea.form-input {
            min-height: 100px;
            resize: vertical;
            line-height: 1.5;
        }

        /* ========== ЧЕКБОКСЫ ========== */
        .checkbox-container {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .checkbox-container:hover {
            background: var(--cluster-hover);
        }

        .checkbox-container input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid var(--cluster-border);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .checkmark::after {
            content: '';
            position: absolute;
            display: none;
            width: 10px;
            height: 10px;
            border-radius: 2px;
            background: var(--cluster-accent);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark {
            border-color: var(--cluster-accent);
            background: var(--cluster-accent-light);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark::after {
            display: block;
        }

        .checkbox-label {
            color: var(--cluster-text);
            font-size: 14px;
            font-weight: 500;
        }

        .checkbox-hint {
            display: block;
            color: var(--cluster-text-muted);
            font-size: 12px;
            font-weight: normal;
            margin-top: 4px;
        }

        /* ========== СЕТКА ЧЕКБОКСОВ ========== */
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }

        /* ========== СЛАЙДЕР ========== */
        .slider-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .slider-value {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--cluster-text);
        }

        .slider-value span {
            font-weight: 600;
            color: var(--cluster-accent);
        }

        input[type="range"] {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            background: var(--cluster-border);
            border-radius: 3px;
            outline: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--cluster-accent);
            cursor: pointer;
            border: 2px solid var(--cluster-card-bg);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--cluster-accent);
            cursor: pointer;
            border: 2px solid var(--cluster-card-bg);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* ========== СПИСОК НОД ========== */
        .nodes-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .nodes-list::-webkit-scrollbar {
            width: 6px;
        }

        .nodes-list::-webkit-scrollbar-track {
            background: var(--cluster-hover);
            border-radius: 3px;
        }

        .nodes-list::-webkit-scrollbar-thumb {
            background: var(--cluster-border);
            border-radius: 3px;
        }

        .node-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--cluster-hover);
            border: 1px solid var(--cluster-border);
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .node-item:hover {
            background: var(--cluster-accent-light);
            border-color: var(--cluster-accent);
            transform: translateX(5px);
        }

        .node-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .node-icon-online {
            background: rgba(16, 185, 129, 0.1);
            color: var(--cluster-success);
        }

        .node-icon-offline {
            background: rgba(239, 68, 68, 0.1);
            color: var(--cluster-danger);
        }

        .node-icon-unknown {
            background: rgba(148, 163, 184, 0.1);
            color: var(--cluster-text-muted);
        }

        .node-icon-master {
            background: rgba(245, 158, 11, 0.1);
            color: var(--cluster-warning);
        }

        .node-content {
            flex: 1;
            min-width: 0;
        }

        .node-title {
            color: var(--cluster-text);
            font-size: 14px;
            font-weight: 500;
            margin: 0 0 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .node-title span {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            background: rgba(139, 92, 246, 0.1);
            color: var(--cluster-purple);
        }

        .node-subtitle {
            color: var(--cluster-text-secondary);
            font-size: 12px;
            margin: 0;
        }

        .node-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }

        .node-status {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 10px;
            text-transform: uppercase;
        }

        .node-status-online {
            background: rgba(16, 185, 129, 0.2);
            color: var(--cluster-success);
        }

        .node-status-offline {
            background: rgba(239, 68, 68, 0.2);
            color: var(--cluster-danger);
        }

        .node-status-unknown {
            background: rgba(148, 163, 184, 0.2);
            color: var(--cluster-text-muted);
        }

        .node-vms {
            color: var(--cluster-text-muted);
            font-size: 11px;
            white-space: nowrap;
        }

        /* ========== КНОПКИ ========== */
        .form-actions {
            display: flex;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--cluster-border);
            margin-top: 16px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .cluster-btn {
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
            font-family: inherit;
        }

        .cluster-btn-primary {
            background: linear-gradient(135deg, var(--cluster-accent), #0097a7);
            color: white;
        }

        .cluster-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--cluster-shadow-hover);
        }

        .cluster-btn-secondary {
            background: var(--cluster-hover);
            color: var(--cluster-text);
            border: 1px solid var(--cluster-border);
        }

        .cluster-btn-secondary:hover {
            background: var(--cluster-border);
            transform: translateY(-2px);
            box-shadow: var(--cluster-shadow-hover);
        }

        .cluster-btn-success {
            background: linear-gradient(135deg, var(--cluster-success), #059669);
            color: white;
        }

        .cluster-btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
        }

        .cluster-btn-warning {
            background: linear-gradient(135deg, var(--cluster-warning), #d97706);
            color: white;
        }

        .cluster-btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-2px);
        }

        .cluster-btn-icon {
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

        .cluster-btn-back {
            background: rgba(148, 163, 184, 0.1);
            color: var(--cluster-text-muted);
            border: 1px solid var(--cluster-border);
        }

        .cluster-btn-back:hover {
            background: var(--cluster-hover);
            color: var(--cluster-text);
            transform: translateY(-2px);
        }

        /* ========== УВЕДОМЛЕНИЯ ========== */
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--cluster-danger);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--cluster-success);
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content p {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }

        /* ========== ПУСТОЕ СОСТОЯНИЕ ========== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--cluster-text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--cluster-text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--cluster-text);
            font-size: 16px;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--cluster-text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 768px) {
            .cluster-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .form-actions,
            .action-buttons {
                flex-direction: column;
            }

            .cluster-btn {
                justify-content: center;
                width: 100%;
            }

            .status-indicators {
                grid-template-columns: repeat(2, 1fr);
            }

            .checkbox-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .cluster-wrapper {
                padding: 10px;
            }

            .cluster-header {
                padding: 16px;
            }

            .cluster-header-left h1 {
                font-size: 20px;
            }

            .cluster-card-body {
                padding: 16px;
            }

            .status-indicators {
                grid-template-columns: 1fr;
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

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ========== ЗАГРУЗКА ========== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 3px solid var(--cluster-border);
            border-top-color: var(--cluster-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* ========== ПОДСКАЗКИ ========== */
        .form-hint {
            display: block;
            color: var(--cluster-text-muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .field-error {
            color: var(--cluster-danger);
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ========== СЧЕТЧИК СИМВОЛОВ ========== */
        .char-counter {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
            font-size: 12px;
            color: var(--cluster-text-muted);
        }

        .char-counter.warning {
            color: var(--cluster-warning);
        }

        .char-counter.danger {
            color: var(--cluster-danger);
        }

        /* ========== ИКОНКА КЛАСТЕРА ========== */
        .cluster-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, var(--cluster-purple), #7c3aed);
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="cluster-wrapper">
        <!-- Шапка страницы -->
        <div class="cluster-header">
            <div class="cluster-header-left">
                <h1><i class="fas fa-edit"></i> Редактирование кластера</h1>
                <p>Изменение параметров кластера <?= htmlspecialchars($cluster['name']) ?></p>
            </div>
            <div class="cluster-header-right">
                <a href="nodes.php" class="cluster-btn-icon cluster-btn-back" title="Вернуться к списку">
                    <i class="fas fa-arrow-left"></i>
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($_SESSION['error']) ?></p>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($_SESSION['success']) ?></p>
                </div>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="cluster-grid">
            <!-- Левая колонка: Форма редактирования -->
            <div class="left-column">
                <!-- Форма редактирования -->
                <div class="cluster-card">
                    <div class="cluster-card-header">
                        <h2><i class="fas fa-cog"></i> Основные параметры</h2>
                    </div>
                    <div class="cluster-card-body">
                        <form method="POST" class="cluster-form" id="clusterForm">
                            <!-- Добавляем скрытое поле для идентификации формы -->
                            <input type="hidden" name="cluster_form" value="1">
                            
                            <div class="form-group">
                                <label class="form-label required">
                                    <i class="fas fa-tag"></i> Имя кластера
                                </label>
                                <input type="text"
                                       name="name"
                                       class="form-input"
                                       value="<?= htmlspecialchars($cluster['name']) ?>"
                                       required
                                       maxlength="50"
                                       id="clusterName">
                                <div class="char-counter" id="nameCounter">
                                    <span>Макс. 50 символов</span>
                                    <span><?= strlen($cluster['name']) ?>/50</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-align-left"></i> Описание кластера
                                </label>
                                <textarea name="description"
                                          class="form-input"
                                          maxlength="500"
                                          id="clusterDescription"><?= htmlspecialchars($cluster['description']) ?></textarea>
                                <div class="char-counter" id="descCounter">
                                    <span>Макс. 500 символов</span>
                                    <span><?= strlen($cluster['description'] ?? '') ?>/500</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-server"></i> Макс. ВМ на ноду
                                </label>
                                <div class="slider-container">
                                    <div class="slider-value">
                                        <span>Значение:</span>
                                        <span id="maxVmsValue"><?= $cluster['max_vms_per_node'] ?? 50 ?></span>
                                    </div>
                                    <input type="range"
                                           name="max_vms_per_node"
                                           min="1"
                                           max="1000"
                                           value="<?= $cluster['max_vms_per_node'] ?? 50 ?>"
                                           class="slider"
                                           id="maxVmsSlider">
                                    <span class="form-hint">Максимальное количество виртуальных машин на одной ноде</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-balance-scale"></i> Порог балансировки нагрузки
                                </label>
                                <div class="slider-container">
                                    <div class="slider-value">
                                        <span>Значение:</span>
                                        <span id="loadThresholdValue"><?= $cluster['load_balancing_threshold'] ?? 80 ?>%</span>
                                    </div>
                                    <input type="range"
                                           name="load_balancing_threshold"
                                           min="1"
                                           max="100"
                                           value="<?= $cluster['load_balancing_threshold'] ?? 80 ?>"
                                           class="slider"
                                           id="loadThresholdSlider">
                                    <span class="form-hint">При достижении этого порога загрузки ноды, ВМ будут мигрировать на другие ноды</span>
                                </div>
                            </div>

                            <div class="checkbox-grid">
                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="is_active"
                                           <?= $cluster['is_active'] ? 'checked' : '' ?>
                                           id="isActive">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-power-off"></i> Активный кластер
                                        <span class="checkbox-hint">Кластер доступен для создания новых ВМ</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="enable_auto_healing"
                                           <?= ($cluster['enable_auto_healing'] ?? 0) ? 'checked' : '' ?>
                                           id="enableAutoHealing">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-heartbeat"></i> Автовосстановление
                                        <span class="checkbox-hint">Автоматический перезапуск упавших ВМ</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="enable_vm_migration"
                                           <?= ($cluster['enable_vm_migration'] ?? 1) ? 'checked' : '' ?>
                                           id="enableVmMigration">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-exchange-alt"></i> Миграция ВМ
                                        <span class="checkbox-hint">Разрешить автоматическую миграцию ВМ между нодами</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="maintenance_mode"
                                           <?= ($cluster['maintenance_mode'] ?? 0) ? 'checked' : '' ?>
                                           id="maintenanceMode">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-tools"></i> Режим обслуживания
                                        <span class="checkbox-hint">Временно отключает кластер для технических работ</span>
                                    </span>
                                </label>
                            </div>

                            <div class="form-actions">
                                <button type="submit"
                                        name="update_cluster"
                                        class="cluster-btn cluster-btn-primary"
                                        id="submitBtn">
                                    <i class="fas fa-save"></i> Сохранить изменения
                                </button>
                                <a href="nodes.php" class="cluster-btn cluster-btn-secondary">
                                    <i class="fas fa-times"></i> Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Статистика и ноды -->
            <div class="right-column">
                <!-- Карточка статуса -->
                <div class="cluster-card cluster-status-card">
                    <div class="cluster-card-header cluster-status-header">
                        <h2><i class="fas fa-chart-bar"></i> Статистика кластера</h2>
                    </div>
                    <div class="cluster-card-body">
                        <div class="cluster-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>

                        <?php
                        $status_class = '';
                        $status_text = '';
                        if ($cluster['maintenance_mode'] ?? 0) {
                            $status_class = 'status-maintenance';
                            $status_text = 'Обслуживание';
                        } elseif ($cluster['is_active']) {
                            $status_class = 'status-active';
                            $status_text = 'Активен';
                        } else {
                            $status_class = 'status-inactive';
                            $status_text = 'Неактивен';
                        }
                        ?>

                        <div class="cluster-status-badge <?= $status_class ?>">
                            <?= $status_text ?>
                        </div>

                        <div class="status-indicators">
                            <div class="status-indicatorr">
                                <div class="indicator-label">Всего нод</div>
                                <div class="indicator-value"><?= $cluster_stats['total_nodes'] ?? 0 ?></div>
                                <div class="indicator-subtext">в кластере</div>
                            </div>

                            <div class="status-indicatorr">
                                <div class="indicator-label">Активных</div>
                                <div class="indicator-value"><?= $cluster_stats['active_nodes'] ?? 0 ?></div>
                                <div class="indicator-subtext">нод</div>
                            </div>

                            <div class="status-indicatorr">
                                <div class="indicator-label">Доступно</div>
                                <div class="indicator-value"><?= $cluster_stats['online_nodes'] ?? 0 ?></div>
                                <div class="indicator-subtext">нод онлайн</div>
                            </div>

                            <div class="status-indicatorr">
                                <div class="indicator-label">Главных</div>
                                <div class="indicator-value"><?= $cluster_stats['master_nodes'] ?? 0 ?></div>
                                <div class="indicator-subtext">нод</div>
                            </div>
                        </div>

                        <div class="action-buttons" style="margin-top: 16px;">
                            <form method="POST" style="width: 100%;">
                                <input type="hidden" name="check_all_nodes" value="1">
                                <button type="submit" class="cluster-btn cluster-btn-success" style="width: 100%;">
                                    <i class="fas fa-sync-alt"></i> Проверить все ноды
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Список нод кластера -->
                <div class="cluster-card" style="margin-top: 24px;">
                    <div class="cluster-card-header">
                        <h2><i class="fas fa-server"></i> Ноды кластера</h2>
                        <span style="font-size: 12px; color: var(--cluster-text-muted);">
                            <?= $cluster_stats['total_nodes'] ?? 0 ?> нод
                        </span>
                    </div>
                    <div class="cluster-card-body">
                        <?php if (!empty($cluster_nodes)): ?>
                            <div class="nodes-list">
                                <?php foreach ($cluster_nodes as $node): ?>
                                    <a href="edit_node.php?id=<?= $node['id'] ?>" class="node-item">
                                        <?php
                                        $icon_class = '';
                                        if ($node['is_cluster_master']) {
                                            $icon_class = 'node-icon-master';
                                            $icon = 'fas fa-crown';
                                        } elseif ($node['status'] === 'online') {
                                            $icon_class = 'node-icon-online';
                                            $icon = 'fas fa-server';
                                        } elseif ($node['status'] === 'offline') {
                                            $icon_class = 'node-icon-offline';
                                            $icon = 'fas fa-server';
                                        } else {
                                            $icon_class = 'node-icon-unknown';
                                            $icon = 'fas fa-server';
                                        }

                                        $status_class = '';
                                        $status_text = '';
                                        if ($node['status'] === 'online') {
                                            $status_class = 'node-status-online';
                                            $status_text = 'Доступна';
                                        } elseif ($node['status'] === 'offline') {
                                            $status_class = 'node-status-offline';
                                            $status_text = 'Недоступна';
                                        } else {
                                            $status_class = 'node-status-unknown';
                                            $status_text = 'Неизвестно';
                                        }
                                        ?>

                                        <div class="node-icon <?= $icon_class ?>">
                                            <i class="<?= $icon ?>"></i>
                                        </div>
                                        <div class="node-content">
                                            <div class="node-title">
                                                <?= htmlspecialchars($node['node_name']) ?>
                                                <?php if ($node['is_active']): ?>
                                                    <span>Активна</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="node-subtitle">
                                                <?= htmlspecialchars($node['hostname']) ?>
                                            </div>
                                        </div>
                                        <div class="node-meta">
                                            <span class="node-status <?= $status_class ?>">
                                                <?= $status_text ?>
                                            </span>
                                            <span class="node-vms">
                                                <?= $node['running_vms'] ?? 0 ?>/<?= $node['vm_count'] ?? 0 ?> ВМ
                                            </span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <div style="text-align: center; margin-top: 16px;">
                                <a href="nodes.php?cluster=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-secondary" style="padding: 8px 16px; font-size: 12px;">
                                    <i class="fas fa-list"></i> Все ноды кластера
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-server"></i>
                                <h3>Нет нод в кластере</h3>
                                <p>Добавьте ноды для начала работы с кластером</p>
                                <a href="add_node.php?cluster_id=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-primary" style="margin-top: 10px;">
                                    <i class="fas fa-plus-circle"></i> Добавить ноду
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Быстрые действия -->
                <div class="cluster-card" style="margin-top: 24px;">
                    <div class="cluster-card-header">
                        <h2><i class="fas fa-bolt"></i> Быстрые действия</h2>
                    </div>
                    <div class="cluster-card-body">
                        <div class="action-buttons">
                            <a href="add_node.php?cluster_id=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-secondary" style="flex: 1;">
                                <i class="fas fa-plus"></i> Добавить ноду
                            </a>
                            <a href="cluster_monitor.php?id=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-secondary" style="flex: 1;">
                                <i class="fas fa-chart-line"></i> Мониторинг
                            </a>
                        </div>
                        <div class="action-buttons" style="margin-top: 10px;">
                            <a href="cluster_backup.php?id=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-secondary" style="flex: 1;">
                                <i class="fas fa-database"></i> Бэкап
                            </a>
                            <a href="cluster_logs.php?id=<?= $cluster['id'] ?>" class="cluster-btn cluster-btn-secondary" style="flex: 1;">
                                <i class="fas fa-clipboard-list"></i> Логи
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Оверлей загрузки -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Элементы формы
        const clusterForm = document.getElementById('clusterForm');
        const clusterName = document.getElementById('clusterName');
        const clusterDescription = document.getElementById('clusterDescription');
        const nameCounter = document.getElementById('nameCounter');
        const descCounter = document.getElementById('descCounter');
        const maxVmsSlider = document.getElementById('maxVmsSlider');
        const maxVmsValue = document.getElementById('maxVmsValue');
        const loadThresholdSlider = document.getElementById('loadThresholdSlider');
        const loadThresholdValue = document.getElementById('loadThresholdValue');
        const submitBtn = document.getElementById('submitBtn');
        const maintenanceMode = document.getElementById('maintenanceMode');
        const isActive = document.getElementById('isActive');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Инициализация счетчиков символов
        updateCharCounter(clusterName, nameCounter, 50);
        updateCharCounter(clusterDescription, descCounter, 500);

        // Обработчики изменения полей
        clusterName.addEventListener('input', function() {
            updateCharCounter(this, nameCounter, 50);
            validateName();
        });

        clusterDescription.addEventListener('input', function() {
            updateCharCounter(this, descCounter, 500);
        });

        // Обработчики слайдеров
        maxVmsSlider.addEventListener('input', function() {
            maxVmsValue.textContent = this.value;
        });

        loadThresholdSlider.addEventListener('input', function() {
            loadThresholdValue.textContent = this.value + '%';
        });

        // Взаимодействие чекбоксов
        maintenanceMode.addEventListener('change', function() {
            if (this.checked) {
                // При включении режима обслуживания показываем предупреждение
                if (!confirm('Режим обслуживания отключит все ноды кластера. Продолжить?')) {
                    this.checked = false;
                    return;
                }
                // Автоматически отключаем активность кластера
                isActive.checked = false;
            }
        });

        isActive.addEventListener('change', function() {
            if (this.checked && maintenanceMode.checked) {
                // Нельзя активировать кластер в режиме обслуживания
                alert('Нельзя активировать кластер в режиме обслуживания');
                this.checked = false;
            }
        });

        // Валидация формы
        clusterForm.addEventListener('submit', function(e) {
            // Не отменяем отправку формы по умолчанию
            if (!validateForm()) {
                e.preventDefault();
                return;
            }

            // Показываем загрузку
            loadingOverlay.classList.add('active');
            
            // Форма отправится стандартным способом
        });

        // Функция обновления счетчика символов
        function updateCharCounter(input, counter, maxLength) {
            const length = input.value.length;
            const counterText = counter.querySelector('span:last-child');
            counterText.textContent = `${length}/${maxLength}`;

            // Меняем цвет при приближении к лимиту
            counter.classList.remove('warning', 'danger');
            if (length > maxLength * 0.8 && length <= maxLength * 0.9) {
                counter.classList.add('warning');
            } else if (length > maxLength * 0.9) {
                counter.classList.add('danger');
            }
        }

        // Валидация имени кластера
        function validateName() {
            const name = clusterName.value.trim();
            const errorMessages = [];

            if (name.length === 0) {
                errorMessages.push('Имя кластера обязательно');
            }

            if (name.length > 50) {
                errorMessages.push('Имя не должно превышать 50 символов');
            }

            // Проверка на специальные символы
            const invalidChars = /[<>:"\/\\|?*]/;
            if (invalidChars.test(name)) {
                errorMessages.push('Имя не должно содержать символы: < > : " / \\ | ? *');
            }

            // Показываем ошибки под полем
            showFieldError(clusterName, errorMessages);

            return errorMessages.length === 0;
        }

        // Показ ошибок для поля
        function showFieldError(field, messages) {
            // Удаляем старые сообщения об ошибках
            const oldError = field.parentNode.querySelector('.field-error');
            if (oldError) {
                oldError.remove();
            }

            // Если есть ошибки - показываем их
            if (messages.length > 0) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'field-error';
                errorDiv.style.cssText = `
                    color: var(--cluster-danger);
                    font-size: 12px;
                    margin-top: 4px;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                `;

                errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i>
                    <span>${messages[0]}</span>
                `;

                field.parentNode.appendChild(errorDiv);
                field.style.borderColor = 'var(--cluster-danger)';
            } else {
                field.style.borderColor = '';
            }
        }

        // Общая валидация формы
        function validateForm() {
            const nameValid = validateName();
            const description = clusterDescription.value.trim();

            if (!nameValid) {
                // Фокусируемся на поле с ошибкой
                clusterName.focus();
                return false;
            }

            // Проверка описания
            if (description.length > 500) {
                clusterDescription.focus();
                showFieldError(clusterDescription, ['Описание не должно превышать 500 символов']);
                return false;
            }

            // Проверка, что кластер не деактивируется с работающими ВМ
            const originalIsActive = <?= $cluster['is_active'] ? 'true' : 'false' ?>;
            const newIsActive = isActive.checked;

            if (originalIsActive && !newIsActive) {
                if (!confirm('Деактивация кластера может повлиять на работу виртуальных машин. Продолжить?')) {
                    return false;
                }
            }

            return true;
        }

        // Анимация при загрузке страницы
        const clusterCards = document.querySelectorAll('.cluster-card');
        clusterCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';

            setTimeout(() => {
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });

        // Обновление отступа при изменении размера окна
        function updateWrapperMargin() {
            const wrapper = document.querySelector('.cluster-wrapper');
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

        // Сохранение данных формы при случайном обновлении
        function saveFormData() {
            sessionStorage.setItem('clusterEditFormData', JSON.stringify({
                name: clusterName.value,
                description: clusterDescription.value,
                max_vms_per_node: maxVmsSlider.value,
                load_balancing_threshold: loadThresholdSlider.value,
                is_active: isActive.checked,
                enable_auto_healing: document.getElementById('enableAutoHealing').checked,
                enable_vm_migration: document.getElementById('enableVmMigration').checked,
                maintenance_mode: maintenanceMode.checked
            }));
        }

        // Восстанавливаем данные из sessionStorage
        function restoreFormData() {
            const savedData = sessionStorage.getItem('clusterEditFormData');
            if (savedData) {
                const data = JSON.parse(savedData);
                clusterName.value = data.name || '';
                clusterDescription.value = data.description || '';
                maxVmsSlider.value = data.max_vms_per_node || 50;
                maxVmsValue.textContent = maxVmsSlider.value;
                loadThresholdSlider.value = data.load_balancing_threshold || 80;
                loadThresholdValue.textContent = loadThresholdSlider.value + '%';
                isActive.checked = data.is_active !== false;
                document.getElementById('enableAutoHealing').checked = data.enable_auto_healing || false;
                document.getElementById('enableVmMigration').checked = data.enable_vm_migration !== false;
                maintenanceMode.checked = data.maintenance_mode || false;

                // Обновляем счетчики
                updateCharCounter(clusterName, nameCounter, 50);
                updateCharCounter(clusterDescription, descCounter, 500);
            }
        }

        // Очищаем сохраненные данные при успешной отправке
        clusterForm.addEventListener('submit', function() {
            sessionStorage.removeItem('clusterEditFormData');
        });

        // Сохраняем данные при изменении
        clusterName.addEventListener('input', saveFormData);
        clusterDescription.addEventListener('input', saveFormData);
        maxVmsSlider.addEventListener('input', saveFormData);
        loadThresholdSlider.addEventListener('input', saveFormData);
        isActive.addEventListener('change', saveFormData);
        document.getElementById('enableAutoHealing').addEventListener('change', saveFormData);
        document.getElementById('enableVmMigration').addEventListener('change', saveFormData);
        maintenanceMode.addEventListener('change', saveFormData);

        // Восстанавливаем данные при загрузке
        restoreFormData();
    });
    </script>
<?php require 'admin_footer.php'; ?>
</body>
</html>