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

$clusters = $pdo->query("SELECT id, name FROM proxmox_clusters WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_node'])) {
    try {
        $cluster_id = intval($_POST['cluster_id']);
        $node_name = trim($_POST['node_name']);
        $hostname = trim($_POST['hostname']);
        $api_port = intval($_POST['api_port'] ?? 8006);
        $ssh_port = intval($_POST['ssh_port'] ?? 22);
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $is_cluster_master = isset($_POST['is_cluster_master']) ? 1 : 0;
        $skip_verification = isset($_POST['skip_verification']) ? 1 : 0;

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

        // Проверяем, что для кластера нет уже главной ноды
        if ($is_cluster_master) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                                 WHERE cluster_id = ? AND is_cluster_master = 1");
            $stmt->execute([$cluster_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("В этом кластере уже есть главная нода");
            }
        }

        // Проверка уникальности имени ноды в кластере
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                              WHERE cluster_id = ? AND node_name = ?");
        $stmt->execute([$cluster_id, $node_name]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Нода с таким именем уже существует в этом кластере");
        }

        // Проверка уникальности хоста и порта
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes
                              WHERE hostname = ? AND api_port = ?");
        $stmt->execute([$hostname, $api_port]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Нода с таким адресом и портом уже существует");
        }

        // Проверяем доступность ноды, если не пропущена
        if (!$skip_verification) {
            $verification_result = verifyNodeAvailability($hostname, $ssh_port, $api_port);
            if (!$verification_result['success']) {
                throw new Exception("Проверка доступности не пройдена: " . $verification_result['message']);
            }

            // Если проверка прошла, устанавливаем ноду как активную
            $is_active = 1;
        }

        // Вставляем запись о ноде
        $stmt = $pdo->prepare("INSERT INTO proxmox_nodes
                             (cluster_id, node_name, hostname, api_port, ssh_port, username,
                             password, is_active, description, is_cluster_master, last_check, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");

        $status = $skip_verification ? 'unknown' : 'online';
        $stmt->execute([
            $cluster_id, $node_name, $hostname, $api_port, $ssh_port,
            $username, $password, $is_active, $description, $is_cluster_master, $status
        ]);

        $node_id = $pdo->lastInsertId();

        // Если проверка прошла успешно, записываем результат
        if (!$skip_verification) {
            $stmt = $pdo->prepare("INSERT INTO node_checks (node_id, check_type, status, response_time, details, created_at)
                                  VALUES (?, 'full', 'success', ?, ?, NOW())");
            $stmt->execute([$node_id, $verification_result['response_time'], json_encode($verification_result)]);
        }

        $_SESSION['success'] = "Нода '{$node_name}' успешно добавлена" . ($skip_verification ? " (проверка пропущена)" : " (проверка пройдена)");
        header("Location: nodes.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

/**
 * Функция проверки доступности ноды (без exec)
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
        // 1. Проверка DNS разрешения
        $dns_result = checkDNSResolution($hostname);
        $result['checks']['dns'] = $dns_result;

        if (!$dns_result['success']) {
            $result['message'] = "Не удалось разрешить DNS имя хоста";
            return $result;
        }

        // 2. Проверка доступности через стандартные порты
        $ping_result = checkHostAvailability($hostname);
        $result['checks']['ping'] = $ping_result;

        if (!$ping_result['success']) {
            $result['message'] = "Хост не отвечает на стандартные порты";
            return $result;
        }

        // 3. Проверка SSH порта
        $ssh_result = checkPort($hostname, $ssh_port);
        $result['checks']['ssh'] = $ssh_result;

        if (!$ssh_result['success']) {
            $result['message'] = "SSH порт ($ssh_port) недоступен";
            return $result;
        }

        // 4. Проверка API порта Proxmox (основной критерий)
        $api_result = checkPort($hostname, $api_port);
        $result['checks']['api'] = $api_result;

        if (!$api_result['success']) {
            $result['message'] = "API порт ($api_port) недоступен";
            return $result;
        }

        // 5. Проверка HTTPS соединения
        $https_result = checkHTTPS($hostname, $api_port);
        $result['checks']['https'] = $https_result;

        if (!$https_result['success']) {
            $result['message'] = "HTTPS соединение не установлено";
            return $result;
        }

        $result['success'] = true;
        $result['message'] = "Все проверки пройдены успешно";

    } catch (Exception $e) {
        $result['message'] = "Ошибка при проверке: " . $e->getMessage();
    }

    $result['response_time'] = round((microtime(true) - $start_time) * 1000, 2); // в мс

    return $result;
}

/**
 * Проверка DNS разрешения имени хоста
 */
function checkDNSResolution($hostname) {
    $result = ['success' => false, 'ip' => null];
    
    // Проверяем, является ли строка IP-адресом
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        $result['success'] = true;
        $result['ip'] = $hostname;
        return $result;
    }
    
    // Пробуем разрешить DNS имя
    $ip = gethostbyname($hostname);
    
    if ($ip !== $hostname) {
        $result['success'] = true;
        $result['ip'] = $ip;
    } else {
        // Пробуем через dns_get_record
        $dns_records = @dns_get_record($hostname, DNS_A);
        if (!empty($dns_records)) {
            $result['success'] = true;
            $result['ip'] = $dns_records[0]['ip'] ?? null;
        }
    }
    
    return $result;
}

/**
 * Проверка доступности хоста через стандартные порты
 */
function checkHostAvailability($hostname, $timeout = 2) {
    $result = ['success' => false, 'latency' => 0];
    
    // Пробуем подключиться к нескольким стандартным портам
    $ports = [
        80 => 'http',
        443 => 'https',
        22 => 'ssh',
        8006 => 'proxmox'
    ];
    
    foreach ($ports as $port => $service) {
        $start_time = microtime(true);
        
        // Для HTTPS портов используем ssl://
        $protocol = in_array($port, [443, 8443, 8006]) ? "ssl://" : "";
        
        $socket = @fsockopen($protocol . $hostname, $port, $errno, $errstr, $timeout);
        
        if ($socket) {
            $result['success'] = true;
            $result['latency'] = round((microtime(true) - $start_time) * 1000, 2);
            $result['service'] = $service;
            $result['port'] = $port;
            fclose($socket);
            break;
        }
    }
    
    return $result;
}

/**
 * Проверка доступности порта
 */
function checkPort($hostname, $port, $timeout = 3) {
    $result = ['success' => false];
    
    // Для HTTPS портов используем ssl://
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
 * Проверка HTTPS соединения
 */
function checkHTTPS($hostname, $port, $timeout = 5) {
    $result = ['success' => false];
    
    $url = "https://{$hostname}:{$port}";
    
    // Используем curl если доступен
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
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
                'timeout' => $timeout,
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

$title = "Добавление ноды | HomeVlad Cloud";
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

        /* ========== СТИЛИ ДЛЯ ФОРМЫ ДОБАВЛЕНИЯ НОДЫ ========== */
        :root {
            --node-bg: #f8fafc;
            --node-card-bg: #ffffff;
            --node-border: #e2e8f0;
            --node-text: #1e293b;
            --node-text-secondary: #64748b;
            --node-text-muted: #94a3b8;
            --node-hover: #f1f5f9;
            --node-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --node-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --node-accent: #00bcd4;
            --node-accent-light: rgba(0, 188, 212, 0.1);
            --node-success: #10b981;
            --node-warning: #f59e0b;
            --node-danger: #ef4444;
            --node-info: #3b82f6;
            --node-purple: #8b5cf6;
        }

        [data-theme="dark"] {
            --node-bg: #0f172a;
            --node-card-bg: #1e293b;
            --node-border: #334155;
            --node-text: #ffffff;
            --node-text-secondary: #cbd5e1;
            --node-text-muted: #94a3b8;
            --node-hover: #2d3748;
            --node-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --node-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .node-wrapper {
            padding: 20px;
            background: var(--node-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .node-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .node-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .node-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .node-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--node-card-bg);
            border-radius: 12px;
            border: 1px solid var(--node-border);
            box-shadow: var(--node-shadow);
        }

        .node-header-left h1 {
            color: var(--node-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .node-header-left h1 i {
            color: var(--node-accent);
        }

        .node-header-left p {
            color: var(--node-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        .node-header-right {
            display: flex;
            gap: 10px;
        }

        /* ========== СЕТКА ФОРМЫ ========== */
        .node-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .form-section {
            background: var(--node-card-bg);
            border-radius: 12px;
            border: 1px solid var(--node-border);
            box-shadow: var(--node-shadow);
            overflow: hidden;
            animation: slideIn 0.5s ease;
        }

        .form-section-header {
            padding: 20px;
            border-bottom: 1px solid var(--node-border);
            background: var(--node-hover);
        }

        .form-section-header h2 {
            color: var(--node-text);
            font-size: 16px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section-header h2 i {
            color: var(--node-accent);
        }

        .form-section-body {
            padding: 24px;
        }

        /* ========== ФОРМА ========== */
        .node-form {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            color: var(--node-text);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: var(--node-danger);
            margin-left: 4px;
        }

        .form-input {
            padding: 12px 16px;
            border: 2px solid var(--node-border);
            border-radius: 8px;
            background: var(--node-card-bg);
            color: var(--node-text);
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--node-accent);
            box-shadow: 0 0 0 3px var(--node-accent-light);
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

        /* ========== КНОПКА ПРОВЕРКИ ========== */
        .verification-section {
            background: linear-gradient(135deg, var(--node-info), #2563eb);
            color: white;
            border: none;
        }

        .verification-header {
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .verification-status {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .verification-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .verification-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }

        .verification-content {
            flex: 1;
        }

        .verification-label {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .verification-details {
            font-size: 12px;
            opacity: 0.9;
        }

        .verification-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .verification-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            outline: none;
            font-family: inherit;
            flex: 1;
            justify-content: center;
        }

        .verification-btn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .verification-btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .verification-btn-success {
            background: rgba(16, 185, 129, 0.9);
            color: white;
            border: none;
        }

        .verification-btn-success:hover {
            background: rgba(16, 185, 129, 1);
            transform: translateY(-2px);
        }

        .verification-btn-warning {
            background: rgba(245, 158, 11, 0.9);
            color: white;
            border: none;
        }

        .verification-btn-warning:hover {
            background: rgba(245, 158, 11, 1);
            transform: translateY(-2px);
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
            background: var(--node-hover);
        }

        .checkbox-container input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid var(--node-border);
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
            background: var(--node-accent);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark {
            border-color: var(--node-accent);
            background: var(--node-accent-light);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark::after {
            display: block;
        }

        .checkbox-label {
            color: var(--node-text);
            font-size: 14px;
            font-weight: 500;
        }

        .checkbox-hint {
            display: block;
            color: var(--node-text-muted);
            font-size: 12px;
            font-weight: normal;
            margin-top: 4px;
        }

        /* ========== КНОПКИ ФОРМЫ ========== */
        .form-actions {
            display: flex;
            gap: 16px;
            padding: 24px;
            background: var(--node-card-bg);
            border-radius: 12px;
            border: 1px solid var(--node-border);
            box-shadow: var(--node-shadow);
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
            background: linear-gradient(135deg, var(--node-accent), #0097a7);
            color: white;
        }

        .form-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--node-shadow-hover);
        }

        .form-btn-secondary {
            background: var(--node-hover);
            color: var(--node-text);
            border: 1px solid var(--node-border);
        }

        .form-btn-secondary:hover {
            background: var(--node-border);
            transform: translateY(-2px);
            box-shadow: var(--node-shadow-hover);
        }

        .form-btn-disabled {
            background: var(--node-border);
            color: var(--node-text-muted);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .form-btn-disabled:hover {
            transform: none;
            box-shadow: none;
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
            color: var(--node-text-muted);
            border: 1px solid var(--node-border);
        }

        .form-btn-back:hover {
            background: var(--node-hover);
            color: var(--node-text);
            transform: translateY(-2px);
        }

        /* ========== СТАТУС ПРОВЕРКИ ========== */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--node-success);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--node-warning);
        }

        .status-error {
            background: rgba(239, 68, 68, 0.2);
            color: var(--node-danger);
        }

        .status-pending {
            background: rgba(148, 163, 184, 0.2);
            color: var(--node-text-muted);
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

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 188, 212, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(0, 188, 212, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 188, 212, 0);
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
            border: 3px solid var(--node-border);
            border-top-color: var(--node-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
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
            color: var(--node-danger);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--node-success);
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
        @media (max-width: 1024px) {
            .node-form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .node-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-btn {
                justify-content: center;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .node-wrapper {
                padding: 10px;
            }

            .node-header {
                padding: 16px;
            }

            .node-header-left h1 {
                font-size: 20px;
            }

            .form-section-body {
                padding: 16px;
            }
        }

        /* ========== ПОДСКАЗКИ ========== */
        .form-hint {
            display: block;
            color: var(--node-text-muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .field-error {
            color: var(--node-danger);
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ========== РЕЗУЛЬТАТЫ ПРОВЕРКИ ========== */
        .verification-results {
            margin-top: 16px;
            padding: 16px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .verification-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .verification-result-item:last-child {
            border-bottom: none;
        }

        .result-label {
            font-size: 13px;
            opacity: 0.9;
        }

        .result-value {
            font-size: 13px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="node-wrapper">
        <!-- Шапка страницы -->
        <div class="node-header">
            <div class="node-header-left">
                <h1><i class="fas fa-server"></i> Добавление новой ноды</h1>
                <p>Добавьте сервер Proxmox для управления виртуальными машинами</p>
            </div>
            <div class="node-header-right">
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

        <!-- Форма добавления ноды -->
        <form method="POST" class="node-form" id="nodeForm">
            <div class="node-form-grid">
                <!-- Основные параметры -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h2><i class="fas fa-cog"></i> Основные параметры</h2>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label class="form-label required">
                                <i class="fas fa-network-wired"></i> Кластер
                            </label>
                            <select name="cluster_id" class="form-input" required id="clusterSelect">
                                <option value="">-- Выберите кластер --</option>
                                <?php foreach ($clusters as $cluster): ?>
                                    <option value="<?= $cluster['id'] ?>"><?= htmlspecialchars($cluster['name']) ?></option>
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
                                   placeholder="node1, proxmox-01 и т.д."
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
                                   placeholder="192.168.1.100 или proxmox.example.com"
                                   required
                                   id="hostname">
                            <span class="form-hint">IP-адрес или доменное имя сервера</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i> Описание
                            </label>
                            <textarea name="description"
                                      class="form-input"
                                      placeholder="Описание сервера, его назначение и т.д."
                                      rows="3"
                                      id="description"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Параметры подключения -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h2><i class="fas fa-plug"></i> Параметры подключения</h2>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-network-wired"></i> SSH порт
                            </label>
                            <input type="number"
                                   name="ssh_port"
                                   class="form-input"
                                   value="22"
                                   min="1"
                                   max="65535"
                                   id="sshPort">
                            <span class="form-hint">Порт для SSH подключения (по умолчанию: 22)</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-code"></i> API порт
                            </label>
                            <input type="number"
                                   name="api_port"
                                   class="form-input"
                                   value="8006"
                                   min="1"
                                   max="65535"
                                   id="apiPort">
                            <span class="form-hint">Порт API Proxmox (по умолчанию: 8006)</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user"></i> Пользователь
                            </label>
                            <input type="text"
                                   name="username"
                                   class="form-input"
                                   placeholder="root"
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
                                   placeholder="Пароль для подключения"
                                   id="password">
                            <span class="form-hint">Пароль пользователя (шифруется при сохранении)</span>
                        </div>
                    </div>
                </div>

                <!-- Проверка доступности -->
                <div class="form-section verification-section">
                    <div class="form-section-header verification-header">
                        <h2><i class="fas fa-check-circle"></i> Проверка доступности</h2>
                    </div>
                    <div class="form-section-body">
                        <div class="verification-status" id="verificationStatus">
                            <div class="verification-item">
                                <div class="verification-icon">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность хоста</div>
                                    <div class="verification-details">Проверка DNS и стандартных портов</div>
                                </div>
                                <span class="status-indicator status-pending" id="pingStatus"></span>
                            </div>

                            <div class="verification-item">
                                <div class="verification-icon">
                                    <i class="fas fa-terminal"></i>
                                </div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность SSH порта</div>
                                    <div class="verification-details">Порт: <span id="sshPortDisplay">22</span></div>
                                </div>
                                <span class="status-indicator status-pending" id="sshStatus"></span>
                            </div>

                            <div class="verification-item">
                                <div class="verification-icon">
                                    <i class="fas fa-code"></i>
                                </div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность API порта</div>
                                    <div class="verification-details">Порт: <span id="apiPortDisplay">8006</span></div>
                                </div>
                                <span class="status-indicator status-pending" id="apiStatus"></span>
                            </div>

                            <div class="verification-actions">
                                <button type="button" class="verification-btn verification-btn-primary" id="verifyBtn">
                                    <i class="fas fa-play"></i> Запустить проверку
                                </button>
                            </div>

                            <div class="verification-results" id="verificationResults" style="display: none;">
                                <div class="verification-result-item">
                                    <span class="result-label">Общее время проверки:</span>
                                    <span class="result-value" id="totalTime">0 мс</span>
                                </div>
                                <div class="verification-result-item">
                                    <span class="result-label">Статус:</span>
                                    <span class="result-value" id="overallStatus">не проверено</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Настройки ноды -->
                <div class="form-section">
                    <div class="form-section-header">
                        <h2><i class="fas fa-sliders-h"></i> Настройки ноды</h2>
                    </div>
                    <div class="form-section-body">
                        <label class="checkbox-container">
                            <input type="checkbox" name="is_active" checked id="isActive">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">
                                <i class="fas fa-power-off"></i> Активная нода
                                <span class="checkbox-hint">Нода будет использоваться для создания новых ВМ</span>
                            </span>
                        </label>

                        <label class="checkbox-container">
                            <input type="checkbox" name="is_cluster_master" id="isClusterMaster">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">
                                <i class="fas fa-crown"></i> Главная нода кластера
                                <span class="checkbox-hint">Используется для VNC консоли всех нод кластера</span>
                            </span>
                        </label>

                        <label class="checkbox-container">
                            <input type="checkbox" name="skip_verification" id="skipVerification">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">
                                <i class="fas fa-forward"></i> Пропустить проверку доступности
                                <span class="checkbox-hint">Добавить ноду без проверки (не рекомендуется)</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Кнопки действий -->
            <div class="form-actions">
                <button type="submit"
                        name="add_node"
                        class="form-btn form-btn-primary"
                        id="submitBtn">
                    <i class="fas fa-save"></i> Создать ноду
                </button>
                <a href="nodes.php" class="form-btn form-btn-secondary">
                    <i class="fas fa-times"></i> Отмена
                </a>
            </div>
        </form>
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
        const sshPortDisplay = document.getElementById('sshPortDisplay');
        const apiPortDisplay = document.getElementById('apiPortDisplay');
        const verifyBtn = document.getElementById('verifyBtn');
        const submitBtn = document.getElementById('submitBtn');
        const skipVerification = document.getElementById('skipVerification');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Элементы статусов проверки
        const pingStatus = document.getElementById('pingStatus');
        const sshStatus = document.getElementById('sshStatus');
        const apiStatus = document.getElementById('apiStatus');
        const verificationResults = document.getElementById('verificationResults');
        const totalTime = document.getElementById('totalTime');
        const overallStatus = document.getElementById('overallStatus');

        // Флаги проверки
        let verificationCompleted = false;
        let verificationInProgress = false;

        // Обновление отображения портов
        function updatePortDisplays() {
            sshPortDisplay.textContent = sshPort.value;
            apiPortDisplay.textContent = apiPort.value;
        }

        sshPort.addEventListener('input', updatePortDisplays);
        apiPort.addEventListener('input', updatePortDisplays);
        updatePortDisplays();

        // Кнопка проверки доступности
        verifyBtn.addEventListener('click', function() {
            if (verificationInProgress) return;

            // Валидация полей
            if (!validateVerificationFields()) {
                return;
            }

            startVerification();
        });

        // Функция валидации полей для проверки
        function validateVerificationFields() {
            let isValid = true;

            // Очистка предыдущих ошибок
            clearFieldErrors();

            // Проверка кластера
            if (!clusterSelect.value) {
                showFieldError(clusterSelect, 'Выберите кластер');
                isValid = false;
            }

            // Проверка имени ноды
            if (!nodeName.value.trim()) {
                showFieldError(nodeName, 'Введите имя ноды');
                isValid = false;
            } else if (nodeName.value.trim().length > 50) {
                showFieldError(nodeName, 'Имя ноды не должно превышать 50 символов');
                isValid = false;
            }

            // Проверка хоста
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
            // Проверка IP-адреса
            const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;

            // Проверка доменного имени
            const domainPattern = /^(?!:\/\/)([a-zA-Z0-9-_]+\.)*[a-zA-Z0-9][a-zA-Z0-9-_]+\.[a-zA-Z]{2,11}?$/;

            return ipPattern.test(hostname) || domainPattern.test(hostname) || hostname === 'localhost';
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
            field.style.borderColor = 'var(--node-danger)';

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

        // Запуск проверки доступности
        async function startVerification() {
            verificationInProgress = true;
            verificationCompleted = false;

            // Сброс статусов
            resetVerificationStatus();

            // Показать оверлей загрузки
            loadingOverlay.classList.add('active');

            // Обновление кнопки
            verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка...';
            verifyBtn.disabled = true;

            try {
                const startTime = Date.now();

                // Собираем данные для проверки
                const verificationData = {
                    hostname: hostname.value.trim(),
                    ssh_port: parseInt(sshPort.value),
                    api_port: parseInt(apiPort.value),
                    cluster_id: clusterSelect.value,
                    node_name: nodeName.value.trim()
                };

                // Отправляем запрос на проверку
                const response = await fetch('check_node_availability.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(verificationData)
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                // Обновляем статусы на основе результата
                updateVerificationStatus(result);

                // Вычисляем общее время
                const elapsedTime = Date.now() - startTime;
                totalTime.textContent = `${elapsedTime} мс`;

                // Показываем результаты
                verificationResults.style.display = 'block';

                // Обновляем общий статус
                if (result.success) {
                    overallStatus.textContent = 'Пройдена';
                    overallStatus.className = 'result-value status-success';

                    // Активируем кнопку отправки
                    submitBtn.disabled = false;
                    verificationCompleted = true;
                } else {
                    overallStatus.textContent = 'Не пройдена';
                    overallStatus.className = 'result-value status-error';

                    // Показываем предупреждение
                    if (!skipVerification.checked) {
                        submitBtn.disabled = true;
                    }
                }

            } catch (error) {
                console.error('Ошибка проверки:', error);

                // Показываем ошибку
                overallStatus.textContent = 'Ошибка проверки';
                overallStatus.className = 'result-value status-error';
                verificationResults.style.display = 'block';

                // Обновляем статусы
                updateStatusElement(pingStatus, 'error', 'Ошибка');
                updateStatusElement(sshStatus, 'error', 'Ошибка');
                updateStatusElement(apiStatus, 'error', 'Ошибка');

            } finally {
                verificationInProgress = false;

                // Скрыть оверлей загрузки
                loadingOverlay.classList.remove('active');

                // Обновить кнопку
                verifyBtn.innerHTML = '<i class="fas fa-redo"></i> Проверить снова';
                verifyBtn.disabled = false;
            }
        }

        // Сброс статусов проверки
        function resetVerificationStatus() {
            updateStatusElement(pingStatus, 'pending', 'ожидание');
            updateStatusElement(sshStatus, 'pending', 'ожидание');
            updateStatusElement(apiStatus, 'pending', 'ожидание');

            verificationResults.style.display = 'none';
            overallStatus.textContent = 'не проверено';
            overallStatus.className = 'result-value';
            totalTime.textContent = '0 мс';

            submitBtn.disabled = !skipVerification.checked;
        }

        // Обновление статуса элемента
        function updateStatusElement(element, status, text) {
            element.className = `status-indicator status-${status}`;
            element.textContent = text;

            // Обновляем иконку в родительском элементе
            const icon = element.closest('.verification-item').querySelector('.verification-icon i');
            if (status === 'success') {
                icon.className = 'fas fa-check-circle';
            } else if (status === 'error') {
                icon.className = 'fas fa-times-circle';
            } else if (status === 'warning') {
                icon.className = 'fas fa-exclamation-circle';
            } else {
                icon.className = 'fas fa-server';
            }
        }

        // Обновление статусов на основе результата
        function updateVerificationStatus(result) {
            // Обновляем статусы проверок
            if (result.checks && result.checks.ping) {
                const pingResult = result.checks.ping;
                updateStatusElement(pingStatus,
                    pingResult.success ? 'success' : 'error',
                    pingResult.success ? `доступен` : 'не доступен'
                );
            }

            if (result.checks && result.checks.ssh) {
                const sshResult = result.checks.ssh;
                updateStatusElement(sshStatus,
                    sshResult.success ? 'success' : 'error',
                    sshResult.success ? 'доступен' : 'не доступен'
                );
            }

            if (result.checks && result.checks.api) {
                const apiResult = result.checks.api;
                updateStatusElement(apiStatus,
                    apiResult.success ? 'success' : 'error',
                    apiResult.success ? 'доступен' : 'не доступен'
                );
            }
        }

        // Обработчик изменения чекбокса пропуска проверки
        skipVerification.addEventListener('change', function() {
            if (this.checked) {
                submitBtn.disabled = false;
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<i class="fas fa-forward"></i> Проверка пропущена';
            } else {
                submitBtn.disabled = !verificationCompleted;
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = verificationCompleted ?
                    '<i class="fas fa-redo"></i> Проверить снова' :
                    '<i class="fas fa-play"></i> Запустить проверку';
            }
        });

        // Валидация формы при отправке
        nodeForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!validateForm()) {
                return;
            }

            // Если проверка не пройдена и не пропущена - показываем предупреждение
            if (!verificationCompleted && !skipVerification.checked) {
                if (!confirm('Проверка доступности ноды не пройдена. Вы уверены, что хотите добавить ноду без проверки?')) {
                    return;
                }
            }

            // Показываем загрузку
            loadingOverlay.classList.add('active');

            // Отправляем форму
            setTimeout(() => {
                this.submit();
            }, 500);
        });

        // Общая валидация формы
        function validateForm() {
            clearFieldErrors();
            let isValid = true;

            // Проверка обязательных полей
            if (!clusterSelect.value) {
                showFieldError(clusterSelect, 'Выберите кластер');
                isValid = false;
            }

            if (!nodeName.value.trim()) {
                showFieldError(nodeName, 'Введите имя ноды');
                isValid = false;
            }

            if (!hostname.value.trim()) {
                showFieldError(hostname, 'Введите адрес сервера');
                isValid = false;
            }

            // Проверка портов
            if (!isValidPort(sshPort.value)) {
                showFieldError(sshPort, 'Введите корректный SSH порт');
                isValid = false;
            }

            if (!isValidPort(apiPort.value)) {
                showFieldError(apiPort, 'Введите корректный API порт');
                isValid = false;
            }

            return isValid;
        }

        // Автоматическая проверка при изменении полей
        let verificationTimeout;
        const fieldsToWatch = [hostname, sshPort, apiPort];

        fieldsToWatch.forEach(field => {
            field.addEventListener('input', function() {
                clearTimeout(verificationTimeout);
                verificationTimeout = setTimeout(() => {
                    if (hostname.value.trim() && !verificationInProgress) {
                        resetVerificationStatus();
                    }
                }, 1000);
            });
        });

        // Анимация при загрузке страницы
        const formSections = document.querySelectorAll('.form-section');
        formSections.forEach((section, index) => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';

            setTimeout(() => {
                section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, index * 100);
        });

        // Обновление отступа при изменении размера окна
        function updateWrapperMargin() {
            const wrapper = document.querySelector('.node-wrapper');
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