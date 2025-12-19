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

// Получаем данные ноды
$node = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT n.*, c.name as cluster_name
        FROM proxmox_nodes n
        LEFT JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.id = ?
    ");
    $stmt->execute([$_GET['id']]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$node) {
    $_SESSION['error'] = "Нода не найдена";
    header("Location: nodes.php");
    exit;
}

// Получаем список кластеров
$clusters = $pdo->query("SELECT id, name FROM proxmox_clusters WHERE is_active = 1 ORDER BY name")->fetchAll();

// Получаем историю проверок ноды (последние 10)
$check_history = $pdo->prepare("
    SELECT * FROM node_checks
    WHERE node_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$check_history->execute([$node['id']]);
$check_history = $check_history->fetchAll(PDO::FETCH_ASSOC);

// Получаем статистику ноды
$node_stats = $pdo->prepare("
    SELECT
        COUNT(*) as total_checks,
        SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_checks,
        SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_checks,
        SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_checks,
        AVG(response_time) as avg_response_time,
        MAX(created_at) as last_check_time
    FROM node_checks
    WHERE node_id = ?
");
$node_stats->execute([$node['id']]);
$node_stats = $node_stats->fetch(PDO::FETCH_ASSOC);

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверяем наличие кнопки обновления или скрытого поля
    if (isset($_POST['update_node']) || isset($_POST['node_form'])) {
        try {
            $cluster_id = intval($_POST['cluster_id']);
            $node_name = trim($_POST['node_name']);
            $hostname = trim($_POST['hostname']);
            $ssh_port = intval($_POST['ssh_port'] ?? 22);
            $api_port = intval($_POST['api_port'] ?? 8006);
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $description = trim($_POST['description'] ?? '');
            $is_cluster_master = isset($_POST['is_cluster_master']) ? 1 : 0;
            $skip_verification = isset($_POST['skip_verification']) ? 1 : 0;
            $force_status_update = isset($_POST['force_status_update']) ? 1 : 0;

            // Валидация
            if (empty($cluster_id)) {
                throw new Exception("Кластер должен быть выбран");
            }

            if (empty($node_name)) {
                throw new Exception("Имя ноды обязательно");
            }

            if (empty($hostname)) {
                throw new Exception("Адрес сервера обязателен");
            }

            // Проверяем, что для кластера нет уже главной ноды (кроме текущей)
            if ($is_cluster_master) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                                     WHERE cluster_id = ? AND is_cluster_master = 1 AND id != ?");
                $stmt->execute([$cluster_id, $node['id']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("В этом кластере уже есть главная нода");
                }
            }

            // Проверка уникальности имени ноды в кластере (кроме текущей)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                                  WHERE cluster_id = ? AND node_name = ? AND id != ?");
            $stmt->execute([$cluster_id, $node_name, $node['id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Нода с таким именем уже существует в этом кластере");
            }

            // Проверка уникальности хоста и порта (кроме текущей)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                                  WHERE hostname = ? AND api_port = ? AND id != ?");
            $stmt->execute([$hostname, $api_port, $node['id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Нода с таким адресом и портом уже существует");
            }

            $new_status = $node['status'];
            $verification_result = null;

            // Проверяем доступность ноды, если не пропущена и требуется
            if (!$skip_verification && $force_status_update) {
                $verification_result = verifyNodeAvailability($hostname, $ssh_port, $api_port);
                if ($verification_result['success']) {
                    $new_status = 'online';
                    $is_active = 1;
                } else {
                    $new_status = 'offline';
                    $is_active = 0;
                }

                // Записываем результат проверки
                try {
                    $stmt = $pdo->prepare("INSERT INTO node_checks (node_id, check_type, status, response_time, details, created_at)
                                          VALUES (?, 'manual', ?, ?, ?, NOW())");
                    $stmt->execute([$node['id'], $new_status, $verification_result['response_time'], json_encode($verification_result)]);
                } catch (Exception $e) {
                    // Игнорируем ошибку записи лога
                    error_log("Не удалось записать лог проверки: " . $e->getMessage());
                }
            }

            // Подготавливаем данные для обновления
            $update_data = [
                'cluster_id' => $cluster_id,
                'node_name' => $node_name,
                'hostname' => $hostname,
                'ssh_port' => $ssh_port,
                'api_port' => $api_port,
                'username' => $username,
                'is_active' => $is_active,
                'description' => $description,
                'is_cluster_master' => $is_cluster_master,
                'status' => $new_status,
                'last_check' => $force_status_update ? date('Y-m-d H:i:s') : $node['last_check'],
                'id' => $node['id']
            ];

            // Если пароль не пустой, обновляем его
            $sql_parts = [];
            $params = [];

            foreach ($update_data as $key => $value) {
                if ($key !== 'id') {
                    $sql_parts[] = "{$key} = ?";
                    $params[] = $value;
                }
            }

            // Если есть пароль, добавляем его
            if (!empty($password)) {
                $sql_parts[] = "password = ?";
                $params[] = $password; // В реальной системе здесь должно быть шифрование
            }

            $params[] = $node['id']; // Для WHERE условия

            $sql = "UPDATE proxmox_nodes SET " . implode(', ', $sql_parts) . " WHERE id = ?";

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
            error_log("Нода обновлена: ID = {$node['id']}, Name = $node_name");

            $success_message = "Нода '{$node_name}' успешно обновлена";
            if ($force_status_update) {
                $success_message .= ". Статус: " . ($new_status === 'online' ? 'доступна' : 'недоступна');
            }

            $_SESSION['success'] = $success_message;
            header("Location: nodes.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            // Логирование ошибки
            error_log("Ошибка при обновлении ноды: " . $e->getMessage());
        }
    }

    // Обработка ручной проверки доступности
    if (isset($_POST['check_availability'])) {
        try {
            $verification_result = verifyNodeAvailability($node['hostname'], $node['ssh_port'], $node['api_port']);

            // Обновляем статус ноды
            $new_status = $verification_result['success'] ? 'online' : 'offline';
            $is_active = $verification_result['success'] ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE proxmox_nodes SET status = ?, is_active = ?, last_check = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $is_active, $node['id']]);

            // Записываем результат проверки
            try {
                $stmt = $pdo->prepare("INSERT INTO node_checks (node_id, check_type, status, response_time, details, created_at)
                                      VALUES (?, 'manual', ?, ?, ?, NOW())");
                $stmt->execute([$node['id'], $new_status, $verification_result['response_time'], json_encode($verification_result)]);
            } catch (Exception $e) {
                // Игнорируем ошибку записи лога
                error_log("Не удалось записать лог проверки: " . $e->getMessage());
            }

            $_SESSION['success'] = "Проверка завершена. Статус: " . ($new_status === 'online' ? 'доступна' : 'недоступна');
            header("Location: edit_node.php?id=" . $node['id']);
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = "Ошибка при проверке: " . $e->getMessage();
        }
    }
}

/**
 * Функция проверки доступности ноды (упрощенная версия)
 */
function verifyNodeAvailability($hostname, $ssh_port = 22, $api_port = 8006) {
    $result = [
        'success' => false,
        'message' => '',
        'response_time' => 0,
        'checks' => []
    ];

    $start_time = microtime(true);

    try {
        // 1. Проверка доступности API порта Proxmox - основной критерий
        $api_result = checkPort($hostname, $api_port, 5);
        $result['checks']['api'] = $api_result;

        if ($api_result['success']) {
            $result['success'] = true;
            $result['message'] = "API порт доступен";

            // 2. Дополнительная проверка веб-интерфейса (необязательная)
            $web_result = checkProxmoxWeb($hostname, $api_port);
            $result['checks']['web'] = $web_result;

            if ($web_result['success']) {
                $result['message'] = "Proxmox веб-интерфейс доступен";
            }
        } else {
            $result['message'] = "API порт ($api_port) недоступен. Ошибка: " . ($api_result['error'] ?? 'Неизвестная ошибка');
        }

    } catch (Exception $e) {
        $result['message'] = "Ошибка при проверке: " . $e->getMessage();
    }

    $result['response_time'] = round((microtime(true) - $start_time) * 1000, 2);

    return $result;
}

/**
 * Проверка доступности порта (упрощенная версия)
 */
function checkPort($hostname, $port, $timeout = 5) {
    $result = ['success' => false];

    // Для портов Proxmox API (8006, 443) используем SSL
    $protocol = (in_array($port, [443, 8443, 8006])) ? "ssl://" : "";

    $socket = @fsockopen($protocol . $hostname, $port, $errno, $errstr, $timeout);

    if ($socket) {
        $result['success'] = true;
        fclose($socket);
    } else {
        $result['error'] = $errstr;
        $result['errno'] = $errno;

        // Попытка без SSL для порта 8006
        if ($port == 8006 && $protocol === "ssl://") {
            $socket2 = @fsockopen($hostname, $port, $errno2, $errstr2, $timeout);
            if ($socket2) {
                $result['success'] = true;
                $result['warning'] = "Порт доступен, но не по SSL";
                fclose($socket2);
            }
        }
    }

    return $result;
}

/**
 * Проверка веб-интерфейса Proxmox
 */
function checkProxmoxWeb($hostname, $port) {
    $result = ['success' => false];

    $url = "https://{$hostname}:{$port}";

    // Используем curl если доступен
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY => true, // HEAD запрос
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Proxmox Check)'
            ]
        ]);

        @curl_exec($ch);

        if (!curl_errno($ch)) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Proxmox возвращает 401 для неавторизованных запросов, что нормально
            if ($http_code == 200 || $http_code == 401 || $http_code == 403) {
                $result['success'] = true;
                $result['http_code'] = $http_code;
            }
        } else {
            $result['error'] = curl_error($ch);
        }

        curl_close($ch);
    } else {
        // Альтернатива через stream_context
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ],
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 (Proxmox Check)\r\n"
            ]
        ]);

        $headers = @get_headers($url, 0, $context);

        if ($headers && is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, 'HTTP/') === 0) {
                    $response_code = substr($header, 9, 3);
                    if ($response_code == '200' || $response_code == '401' || $response_code == '403') {
                        $result['success'] = true;
                        $result['http_code'] = $response_code;
                        break;
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * Простая проверка доступности хоста
 */
function checkHostSimple($hostname, $timeout = 2) {
    $result = ['success' => false];

    // Просто проверяем, разрешается ли DNS имя
    $ip = gethostbyname($hostname);
    if ($ip !== $hostname) {
        $result['success'] = true;
        $result['ip'] = $ip;
    }

    return $result;
}

$title = "Редактирование ноды | HomeVlad Cloud";
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

        /* ========== СТИЛИ ДЛЯ РЕДАКТИРОВАНИЯ НОДЫ ========== */
        :root {
            --edit-bg: #f8fafc;
            --edit-card-bg: #ffffff;
            --edit-border: #e2e8f0;
            --edit-text: #1e293b;
            --edit-text-secondary: #64748b;
            --edit-text-muted: #94a3b8;
            --edit-hover: #f1f5f9;
            --edit-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --edit-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --edit-accent: #00bcd4;
            --edit-accent-light: rgba(0, 188, 212, 0.1);
            --edit-success: #10b981;
            --edit-warning: #f59e0b;
            --edit-danger: #ef4444;
            --edit-info: #3b82f6;
            --edit-purple: #8b5cf6;
        }

        [data-theme="dark"] {
            --edit-bg: #0f172a;
            --edit-card-bg: #1e293b;
            --edit-border: #334155;
            --edit-text: #ffffff;
            --edit-text-secondary: #cbd5e1;
            --edit-text-muted: #94a3b8;
            --edit-hover: #2d3748;
            --edit-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --edit-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .edit-wrapper {
            padding: 20px;
            background: var(--edit-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .edit-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .edit-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .edit-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .edit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--edit-card-bg);
            border-radius: 12px;
            border: 1px solid var(--edit-border);
            box-shadow: var(--edit-shadow);
        }

        .edit-header-left h1 {
            color: var(--edit-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .edit-header-left h1 i {
            color: var(--edit-accent);
        }

        .edit-header-left p {
            color: var(--edit-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        .edit-header-right {
            display: flex;
            gap: 10px;
        }

        /* ========== СЕТКА СТРАНИЦЫ ========== */
        .edit-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .edit-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ========== КАРТОЧКИ ========== */
        .edit-card {
            background: var(--edit-card-bg);
            border-radius: 12px;
            border: 1px solid var(--edit-border);
            box-shadow: var(--edit-shadow);
            overflow: hidden;
            animation: slideIn 0.5s ease;
        }

        .edit-card-header {
            padding: 20px;
            border-bottom: 1px solid var(--edit-border);
            background: var(--edit-hover);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .edit-card-header h2 {
            color: var(--edit-text);
            font-size: 18px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-card-header h2 i {
            color: var(--edit-accent);
        }

        .edit-card-body {
            padding: 24px;
        }

        /* ========== СТАТУС НОДЫ ========== */
        .node-status-card {
            background: linear-gradient(135deg, var(--edit-info), #2563eb);
            color: white;
            border: none;
        }

        .node-status-header {
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .status-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .status-item {
            text-align: center;
            padding: 16px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .status-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-value {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        .status-subtext {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 4px;
        }

        .status-badge-large {
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
        }

        .status-badge-large::before {
            content: '';
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-online {
            background: rgba(16, 185, 129, 0.2);
            color: var(--edit-success);
        }

        .status-online::before {
            background: var(--edit-success);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
        }

        .status-offline {
            background: rgba(239, 68, 68, 0.2);
            color: var(--edit-danger);
        }

        .status-offline::before {
            background: var(--edit-danger);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.3);
        }

        .status-unknown {
            background: rgba(148, 163, 184, 0.2);
            color: var(--edit-text-muted);
        }

        .status-unknown::before {
            background: var(--edit-text-muted);
            box-shadow: 0 0 0 2px rgba(148, 163, 184, 0.3);
        }

        /* ========== ФОРМА ========== */
        .node-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            color: var(--edit-text);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: var(--edit-danger);
            margin-left: 4px;
        }

        .form-input {
            padding: 12px 16px;
            border: 2px solid var(--edit-border);
            border-radius: 8px;
            background: var(--edit-card-bg);
            color: var(--edit-text);
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--edit-accent);
            box-shadow: 0 0 0 3px var(--edit-accent-light);
        }

        select.form-input {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
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
            background: var(--edit-hover);
        }

        .checkbox-container input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid var(--edit-border);
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
            background: var(--edit-accent);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark {
            border-color: var(--edit-accent);
            background: var(--edit-accent-light);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark::after {
            display: block;
        }

        .checkbox-label {
            color: var(--edit-text);
            font-size: 14px;
            font-weight: 500;
        }

        .checkbox-hint {
            display: block;
            color: var(--edit-text-muted);
            font-size: 12px;
            font-weight: normal;
            margin-top: 4px;
        }

        /* ========== КНОПКИ ========== */
        .form-actions {
            display: flex;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--edit-border);
            margin-top: 16px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .form-btn {
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

        .form-btn-primary {
            background: linear-gradient(135deg, var(--edit-accent), #0097a7);
            color: white;
        }

        .form-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--edit-shadow-hover);
        }

        .form-btn-secondary {
            background: var(--edit-hover);
            color: var(--edit-text);
            border: 1px solid var(--edit-border);
        }

        .form-btn-secondary:hover {
            background: var(--edit-border);
            transform: translateY(-2px);
            box-shadow: var(--edit-shadow-hover);
        }

        .form-btn-success {
            background: linear-gradient(135deg, var(--edit-success), #059669);
            color: white;
        }

        .form-btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
        }

        .form-btn-warning {
            background: linear-gradient(135deg, var(--edit-warning), #d97706);
            color: white;
        }

        .form-btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-2px);
        }

        .form-btn-icon {
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

        .form-btn-back {
            background: rgba(148, 163, 184, 0.1);
            color: var(--edit-text-muted);
            border: 1px solid var(--edit-border);
        }

        .form-btn-back:hover {
            background: var(--edit-hover);
            color: var(--edit-text);
            transform: translateY(-2px);
        }

        /* ========== ИСТОРИЯ ПРОВЕРОК ========== */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .history-list::-webkit-scrollbar {
            width: 6px;
        }

        .history-list::-webkit-scrollbar-track {
            background: var(--edit-hover);
            border-radius: 3px;
        }

        .history-list::-webkit-scrollbar-thumb {
            background: var(--edit-border);
            border-radius: 3px;
        }

        .history-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--edit-hover);
            border: 1px solid var(--edit-border);
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .history-item:hover {
            background: var(--edit-accent-light);
            border-color: var(--edit-accent);
            transform: translateX(5px);
        }

        .history-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .history-icon.online {
            background: rgba(16, 185, 129, 0.1);
            color: var(--edit-success);
        }

        .history-icon.offline {
            background: rgba(239, 68, 68, 0.1);
            color: var(--edit-danger);
        }

        .history-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--edit-warning);
        }

        .history-content {
            flex: 1;
            min-width: 0;
        }

        .history-title {
            color: var(--edit-text);
            font-size: 13px;
            font-weight: 500;
            margin: 0 0 2px 0;
        }

        .history-subtitle {
            color: var(--edit-text-secondary);
            font-size: 11px;
            margin: 0;
        }

        .history-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }

        .history-time {
            color: var(--edit-text-muted);
            font-size: 11px;
            white-space: nowrap;
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
            color: var(--edit-danger);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--edit-success);
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

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 768px) {
            .edit-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .form-actions,
            .action-buttons {
                flex-direction: column;
            }

            .form-btn {
                justify-content: center;
                width: 100%;
            }

            .status-info {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .edit-wrapper {
                padding: 10px;
            }

            .edit-header {
                padding: 16px;
            }

            .edit-header-left h1 {
                font-size: 20px;
            }

            .edit-card-body {
                padding: 16px;
            }

            .status-info {
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
            border: 3px solid var(--edit-border);
            border-top-color: var(--edit-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* ========== ПОДСКАЗКИ ========== */
        .form-hint {
            display: block;
            color: var(--edit-text-muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .field-error {
            color: var(--edit-danger);
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="edit-wrapper">
        <!-- Шапка страницы -->
        <div class="edit-header">
            <div class="edit-header-left">
                <h1><i class="fas fa-edit"></i> Редактирование ноды</h1>
                <p>Изменение параметров ноды <?= htmlspecialchars($node['node_name']) ?></p>
            </div>
            <div class="edit-header-right">
                <a href="nodes.php" class="form-btn-icon form-btn-back" title="Вернуться к списку">
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

        <div class="edit-grid">
            <!-- Левая колонка: Форма и настройки -->
            <div class="left-column">
                <!-- Форма редактирования -->
                <div class="edit-card">
                    <div class="edit-card-header">
                        <h2><i class="fas fa-cog"></i> Основные параметры</h2>
                    </div>
                    <div class="edit-card-body">
                        <form method="POST" class="node-form" id="nodeForm">
                            <!-- Добавляем скрытое поле для идентификации формы -->
                            <input type="hidden" name="node_form" value="1">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">
                                        <i class="fas fa-network-wired"></i> Кластер
                                    </label>
                                    <select name="cluster_id" class="form-input" required id="clusterSelect">
                                        <?php foreach ($clusters as $cluster): ?>
                                            <option value="<?= $cluster['id'] ?>"
                                                <?= $cluster['id'] == $node['cluster_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cluster['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">
                                        <i class="fas fa-tag"></i> Имя ноды
                                    </label>
                                    <input type="text"
                                           name="node_name"
                                           class="form-input"
                                           value="<?= htmlspecialchars($node['node_name']) ?>"
                                           required
                                           maxlength="50"
                                           id="nodeName">
                                    <span class="form-hint">Уникальное имя ноды в рамках кластера</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">
                                        <i class="fas fa-globe"></i> Адрес сервера
                                    </label>
                                    <input type="text"
                                           name="hostname"
                                           class="form-input"
                                           value="<?= htmlspecialchars($node['hostname']) ?>"
                                           required
                                           id="hostname">
                                    <span class="form-hint">IP-адрес или доменное имя сервера</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-terminal"></i> SSH порт
                                    </label>
                                    <input type="number"
                                           name="ssh_port"
                                           class="form-input"
                                           value="<?= htmlspecialchars($node['ssh_port'] ?? 22) ?>"
                                           min="1"
                                           max="65535"
                                           id="sshPort">
                                    <span class="form-hint">Порт для SSH подключения</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-code"></i> API порт
                                    </label>
                                    <input type="number"
                                           name="api_port"
                                           class="form-input"
                                           value="<?= htmlspecialchars($node['api_port']) ?>"
                                           min="1"
                                           max="65535"
                                           id="apiPort">
                                    <span class="form-hint">Порт API Proxmox (обычно 8006)</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-user"></i> Пользователь
                                    </label>
                                    <input type="text"
                                           name="username"
                                           class="form-input"
                                           value="<?= htmlspecialchars($node['username']) ?>"
                                           id="username">
                                    <span class="form-hint">Имя пользователя для подключения</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-key"></i> Пароль
                                    </label>
                                    <input type="password"
                                           name="password"
                                           class="form-input"
                                           placeholder="Оставьте пустым, чтобы не менять"
                                           id="password">
                                    <span class="form-hint">Введите новый пароль или оставьте пустым</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-align-left"></i> Описание
                                </label>
                                <textarea name="description"
                                          class="form-input"
                                          rows="3"
                                          id="description"><?= htmlspecialchars($node['description']) ?></textarea>
                            </div>

                            <div class="form-grid">
                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="is_active"
                                           <?= $node['is_active'] ? 'checked' : '' ?>
                                           id="isActive">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-power-off"></i> Активная нода
                                        <span class="checkbox-hint">Нода будет использоваться для создания новых ВМ</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="is_cluster_master"
                                           <?= $node['is_cluster_master'] ? 'checked' : '' ?>
                                           id="isClusterMaster">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-crown"></i> Главная нода кластера
                                        <span class="checkbox-hint">Используется для VNC консоли всех нод кластера</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="skip_verification"
                                           id="skipVerification">
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-forward"></i> Не проверять доступность
                                        <span class="checkbox-hint">Сохранить без проверки доступности</span>
                                    </span>
                                </label>

                                <label class="checkbox-container">
                                    <input type="checkbox"
                                           name="force_status_update"
                                           id="forceStatusUpdate"
                                           checked>
                                    <span class="checkmark"></span>
                                    <span class="checkbox-label">
                                        <i class="fas fa-sync"></i> Обновить статус
                                        <span class="checkbox-hint">Обновить статус доступности при сохранении</span>
                                    </span>
                                </label>
                            </div>

                            <div class="form-actions">
                                <button type="submit"
                                        name="update_node"
                                        class="form-btn form-btn-primary"
                                        id="submitBtn">
                                    <i class="fas fa-save"></i> Сохранить изменения
                                </button>
                                <a href="nodes.php" class="form-btn form-btn-secondary">
                                    <i class="fas fa-times"></i> Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Статус и история -->
            <div class="right-column">
                <!-- Карточка статуса -->
                <div class="edit-card node-status-card">
                    <div class="edit-card-header node-status-header">
                        <h2><i class="fas fa-chart-line"></i> Статус ноды</h2>
                    </div>
                    <div class="edit-card-body">
                        <div class="status-badge-large <?= $node['status'] === 'online' ? 'status-online' : ($node['status'] === 'offline' ? 'status-offline' : 'status-unknown') ?>">
                            <?= $node['status'] === 'online' ? 'Доступна' : ($node['status'] === 'offline' ? 'Недоступна' : 'Статус неизвестен') ?>
                        </div>

                        <div class="status-info">
                            <div class="status-item">
                                <div class="status-label">Последняя проверка</div>
                                <div class="status-value">
                                    <?= $node['last_check'] ? date('H:i', strtotime($node['last_check'])) : '—' ?>
                                </div>
                                <div class="status-subtext">
                                    <?= $node['last_check'] ? date('d.m.Y', strtotime($node['last_check'])) : 'Никогда' ?>
                                </div>
                            </div>

                            <div class="status-item">
                                <div class="status-label">Среднее время</div>
                                <div class="status-value">
                                    <?= $node_stats['avg_response_time'] ? round($node_stats['avg_response_time'], 1) . 'мс' : '—' ?>
                                </div>
                                <div class="status-subtext">отклика</div>
                            </div>
                        </div>

                        <div class="status-info">
                            <div class="status-item">
                                <div class="status-label">Всего проверок</div>
                                <div class="status-value"><?= $node_stats['total_checks'] ?? 0 ?></div>
                                <div class="status-subtext">за всё время</div>
                            </div>

                            <div class="status-item">
                                <div class="status-label">Доступна</div>
                                <div class="status-value">
                                    <?php
                                    $total = $node_stats['total_checks'] ?? 1;
                                    $online = $node_stats['online_checks'] ?? 0;
                                    $uptime = $total > 0 ? round(($online / $total) * 100, 1) : 0;
                                    echo $uptime . '%';
                                    ?>
                                </div>
                                <div class="status-subtext">uptime</div>
                            </div>
                        </div>

                        <form method="POST" class="action-buttons" style="margin-top: 16px;">
                            <input type="hidden" name="check_availability" value="1">
                            <button type="submit" class="form-btn form-btn-success">
                                <i class="fas fa-play"></i> Проверить сейчас
                            </button>
                        </form>
                    </div>
                </div>

                <!-- История проверок -->
                <div class="edit-card" style="margin-top: 24px;">
                    <div class="edit-card-header">
                        <h2><i class="fas fa-history"></i> История проверок</h2>
                    </div>
                    <div class="edit-card-body">
                        <?php if (!empty($check_history)): ?>
                            <div class="history-list">
                                <?php foreach ($check_history as $check): ?>
                                    <div class="history-item">
                                        <div class="history-icon <?= $check['status'] ?>">
                                            <i class="fas fa-<?= $check['status'] === 'online' ? 'check' : ($check['status'] === 'offline' ? 'times' : 'exclamation') ?>"></i>
                                        </div>
                                        <div class="history-content">
                                            <div class="history-title">
                                                <?= $check['check_type'] === 'scheduled' ? 'Плановая проверка' :
                                                   ($check['check_type'] === 'manual' ? 'Ручная проверка' : 'Полная проверка') ?>
                                            </div>
                                            <div class="history-subtitle">
                                                Время отклика: <?= $check['response_time'] ?>мс
                                            </div>
                                        </div>
                                        <div class="history-meta">
                                            <div class="history-time">
                                                <?= date('H:i', strtotime($check['created_at'])) ?>
                                            </div>
                                            <div class="history-time" style="font-size: 10px;">
                                                <?= date('d.m', strtotime($check['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--edit-text-muted);">
                                <i class="fas fa-history" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p>Нет данных о проверках</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($node_stats['total_checks'] ?? 0 > 10): ?>
                            <div style="text-align: center; margin-top: 16px;">
                                <a href="node_check_history.php?id=<?= $node['id'] ?>" class="form-btn form-btn-secondary" style="padding: 8px 16px; font-size: 12px;">
                                    <i class="fas fa-list"></i> Вся история
                                </a>
                            </div>
                        <?php endif; ?>
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
        const nodeForm = document.getElementById('nodeForm');
        const clusterSelect = document.getElementById('clusterSelect');
        const nodeName = document.getElementById('nodeName');
        const hostname = document.getElementById('hostname');
        const sshPort = document.getElementById('sshPort');
        const apiPort = document.getElementById('apiPort');
        const submitBtn = document.getElementById('submitBtn');
        const skipVerification = document.getElementById('skipVerification');
        const forceStatusUpdate = document.getElementById('forceStatusUpdate');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Валидация формы
        nodeForm.addEventListener('submit', function(e) {
            // Не отменяем отправку формы по умолчанию
            if (!validateForm()) {
                e.preventDefault();
                return;
            }

            // Если проверка не пропущена и требуется обновление статуса, показываем подтверждение
            if (!skipVerification.checked && forceStatusUpdate.checked) {
                if (!confirm('При сохранении будет выполнена проверка доступности ноды. Продолжить?')) {
                    e.preventDefault();
                    return;
                }
            }

            // Показываем загрузку
            loadingOverlay.classList.add('active');

            // Форма отправится стандартным способом
        });

        // Общая валидация формы
        function validateForm() {
            let isValid = true;

            // Очищаем ошибки
            clearFieldErrors();

            // Проверка обязательных полей
            if (!clusterSelect.value) {
                showFieldError(clusterSelect, 'Выберите кластер');
                isValid = false;
            }

            if (!nodeName.value.trim()) {
                showFieldError(nodeName, 'Введите имя ноды');
                isValid = false;
            } else if (nodeName.value.trim().length > 50) {
                showFieldError(nodeName, 'Имя ноды не должно превышать 50 символов');
                isValid = false;
            }

            if (!hostname.value.trim()) {
                showFieldError(hostname, 'Введите адрес сервера');
                isValid = false;
            } else if (!isValidHostname(hostname.value.trim())) {
                showFieldError(hostname, 'Введите корректный IP-адрес или доменное имя');
                isValid = false;
            }

            // Проверка портов
            if (!isValidPort(sshPort.value)) {
                showFieldError(sshPort, 'Введите корректный SSH порт (1-65535)');
                isValid = false;
            }

            if (!isValidPort(apiPort.value)) {
                showFieldError(apiPort, 'Введите корректный API порт (1-65535)');
                isValid = false;
            }

            return isValid;
        }

        // Валидация хоста
        function isValidHostname(hostname) {
            // Проверка IP-адреса IPv4
            const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;

            // Проверка IP-адреса IPv6 (упрощенная)
            const ipv6Pattern = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;

            // Проверка доменного имени
            const domainPattern = /^(?!:\/\/)([a-zA-Z0-9-_]+\.)*[a-zA-Z0-9][a-zA-Z0-9-_]+\.[a-zA-Z]{2,11}?$/;

            return ipPattern.test(hostname) || ipv6Pattern.test(hostname) || domainPattern.test(hostname) || hostname === 'localhost';
        }

        // Валидация порта
        function isValidPort(port) {
            const portNum = parseInt(port);
            return !isNaN(portNum) && portNum >= 1 && portNum <= 65535;
        }

        // Показать ошибку поля
        function showFieldError(field, message) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'field-error';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;

            field.parentNode.appendChild(errorDiv);
            field.style.borderColor = 'var(--edit-danger)';

            // Фокусировка на поле с ошибкой
            field.focus();
        }

        // Очистка ошибок полей
        function clearFieldErrors() {
            document.querySelectorAll('.field-error').forEach(el => el.remove());
            document.querySelectorAll('.form-input').forEach(input => {
                input.style.borderColor = '';
            });
        }

        // Взаимодействие чекбоксов
        skipVerification.addEventListener('change', function() {
            if (this.checked) {
                forceStatusUpdate.disabled = true;
                forceStatusUpdate.checked = false;
            } else {
                forceStatusUpdate.disabled = false;
            }
        });

        forceStatusUpdate.addEventListener('change', function() {
            if (this.checked) {
                skipVerification.disabled = true;
                skipVerification.checked = false;
            } else {
                skipVerification.disabled = false;
            }
        });

        // Автоматическая проверка при изменении хоста или портов
        let verificationTimeout;
        const fieldsToWatch = [hostname, sshPort, apiPort];

        fieldsToWatch.forEach(field => {
            field.addEventListener('input', function() {
                clearTimeout(verificationTimeout);
                verificationTimeout = setTimeout(() => {
                    if (hostname.value.trim()) {
                        // Сбрасываем чекбокс обновления статуса
                        forceStatusUpdate.checked = true;
                        skipVerification.checked = false;
                    }
                }, 1000);
            });
        });

        // Анимация при загрузке страницы
        const editCards = document.querySelectorAll('.edit-card');
        editCards.forEach((card, index) => {
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
            const wrapper = document.querySelector('.edit-wrapper');
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
            sessionStorage.setItem('nodeEditFormData', JSON.stringify({
                cluster_id: clusterSelect.value,
                node_name: nodeName.value,
                hostname: hostname.value,
                ssh_port: sshPort.value,
                api_port: apiPort.value,
                username: document.getElementById('username').value,
                description: document.getElementById('description').value,
                is_active: document.getElementById('isActive').checked,
                is_cluster_master: document.getElementById('isClusterMaster').checked,
                skip_verification: skipVerification.checked,
                force_status_update: forceStatusUpdate.checked
            }));
        }

        // Восстанавливаем данные из sessionStorage
        function restoreFormData() {
            const savedData = sessionStorage.getItem('nodeEditFormData');
            if (savedData) {
                const data = JSON.parse(savedData);
                clusterSelect.value = data.cluster_id || '';
                nodeName.value = data.node_name || '';
                hostname.value = data.hostname || '';
                sshPort.value = data.ssh_port || '22';
                apiPort.value = data.api_port || '8006';
                document.getElementById('username').value = data.username || '';
                document.getElementById('description').value = data.description || '';
                document.getElementById('isActive').checked = data.is_active !== false;
                document.getElementById('isClusterMaster').checked = data.is_cluster_master || false;
                skipVerification.checked = data.skip_verification || false;
                forceStatusUpdate.checked = data.force_status_update !== false;

                // Обновляем состояние чекбоксов
                if (skipVerification.checked) {
                    forceStatusUpdate.disabled = true;
                }
                if (forceStatusUpdate.checked) {
                    skipVerification.disabled = true;
                }
            }
        }

        // Очищаем сохраненные данные при успешной отправке
        nodeForm.addEventListener('submit', function() {
            sessionStorage.removeItem('nodeEditFormData');
        });

        // Сохраняем данные при изменении
        clusterSelect.addEventListener('change', saveFormData);
        nodeName.addEventListener('input', saveFormData);
        hostname.addEventListener('input', saveFormData);
        sshPort.addEventListener('input', saveFormData);
        apiPort.addEventListener('input', saveFormData);
        document.getElementById('username').addEventListener('input', saveFormData);
        document.getElementById('description').addEventListener('input', saveFormData);
        document.getElementById('isActive').addEventListener('change', saveFormData);
        document.getElementById('isClusterMaster').addEventListener('change', saveFormData);
        skipVerification.addEventListener('change', saveFormData);
        forceStatusUpdate.addEventListener('change', saveFormData);

        // Восстанавливаем данные при загрузке
        restoreFormData();
    });
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>
