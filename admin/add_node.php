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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_node']) || isset($_POST['node_form']))) {
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
        $ignore_ssl = isset($_POST['ignore_ssl']) ? 1 : 0;

        if (empty($cluster_id)) throw new Exception("Кластер должен быть выбран");
        if (empty($node_name)) throw new Exception("Имя ноды обязательно");
        if (empty($hostname)) throw new Exception("Адрес сервера обязателен");

        if ($is_cluster_master) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes WHERE cluster_id = ? AND is_cluster_master = 1");
            $stmt->execute([$cluster_id]);
            if ($stmt->fetchColumn() > 0) throw new Exception("В этом кластере уже есть главная нода");
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes WHERE cluster_id = ? AND node_name = ?");
        $stmt->execute([$cluster_id, $node_name]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Нода с таким именем уже существует в этом кластере");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes WHERE hostname = ? AND api_port = ?");
        $stmt->execute([$hostname, $api_port]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Нода с таким адресом и портом уже существует");

        $verification_result = null;
        $status = 'unknown';

        if (!$skip_verification) {
            $verification_result = verifyNodeAvailability($hostname, $ssh_port, $api_port, $ignore_ssl);
            if (!$verification_result['success']) {
                throw new Exception("Проверка доступности не пройдена: " . $verification_result['message']);
            }
            $is_active = 1;
            $status = 'online';
        }

        $sql = "INSERT INTO proxmox_nodes
               (cluster_id, node_name, hostname, api_port, ssh_port, username,
                password, is_active, description, is_cluster_master, last_check, status)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";

        $stmt = $pdo->prepare($sql);
        $encrypted_password = $password;
        $stmt->execute([
            $cluster_id, $node_name, $hostname, $api_port, $ssh_port,
            $username, $encrypted_password, $is_active, $description,
            $is_cluster_master, $status
        ]);

        $node_id = $pdo->lastInsertId();
        error_log("Нода создана: ID = $node_id, Name = $node_name, Cluster = $cluster_id");

        if (!$skip_verification && $verification_result) {
            try {
                $stmt = $pdo->prepare("INSERT INTO node_checks (node_id, check_type, status, response_time, details, created_at)
                                      VALUES (?, 'full', 'success', ?, ?, NOW())");
                $stmt->execute([$node_id, $verification_result['response_time'], json_encode($verification_result)]);
            } catch (Exception $e) {}
        }

        $_SESSION['success'] = "Нода '{$node_name}' успешно добавлена" . ($skip_verification ? " (проверка пропущена)" : " (проверка пройдена)");
        header("Location: nodes.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        error_log("Ошибка при добавлении ноды: " . $e->getMessage());
    }
}

function verifyNodeAvailability($hostname, $ssh_port = 22, $api_port = 8006, $ignore_ssl = false) {
    $result = ['success' => false, 'message' => '', 'response_time' => 0, 'checks' => []];
    $start_time = microtime(true);
    try {
        $dns = checkDNSResolution($hostname);
        $result['checks']['dns'] = $dns;
        if (!$dns['success']) throw new Exception("Не удалось разрешить DNS имя хоста");

        $ping = checkHostAvailability($hostname);
        $result['checks']['ping'] = $ping;
        if (!$ping['success']) throw new Exception("Хост не отвечает на стандартные порты");

        $ssh = checkPort($hostname, $ssh_port);
        $result['checks']['ssh'] = $ssh;
        if (!$ssh['success']) throw new Exception("SSH порт ($ssh_port) недоступен");

        $api = checkPort($hostname, $api_port);
        $result['checks']['api'] = $api;
        if (!$api['success']) throw new Exception("API порт ($api_port) недоступен");

        $https = checkHTTPS($hostname, $api_port, $ignore_ssl);
        $result['checks']['https'] = $https;
        if ($ignore_ssl && !$https['success'] && $api['success']) {
            $https['success'] = true;
            $https['warning'] = "SSL проверка пропущена (самоподписанный сертификат)";
            $result['checks']['https'] = $https;
        }
        if (!$https['success']) throw new Exception("HTTPS не отвечает");

        $result['success'] = true;
        $result['message'] = "Все проверки пройдены успешно";
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    $result['response_time'] = round((microtime(true) - $start_time) * 1000, 2);
    return $result;
}

function checkDNSResolution($hostname) {
    $result = ['success' => false, 'ip' => null];
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        $result['success'] = true;
        $result['ip'] = $hostname;
        return $result;
    }
    $ip = gethostbyname($hostname);
    if ($ip !== $hostname) {
        $result['success'] = true;
        $result['ip'] = $ip;
    } else {
        $dns = @dns_get_record($hostname, DNS_A);
        if (!empty($dns)) {
            $result['success'] = true;
            $result['ip'] = $dns[0]['ip'] ?? null;
        }
    }
    return $result;
}

function checkHostAvailability($hostname, $timeout = 2) {
    $result = ['success' => false];
    $ports = [80, 443, 22, 8006];
    foreach ($ports as $port) {
        $protocol = in_array($port, [443, 8006]) ? "ssl://" : "";
        $socket = @fsockopen($protocol . $hostname, $port, $errno, $errstr, $timeout);
        if ($socket) {
            $result['success'] = true;
            fclose($socket);
            break;
        }
    }
    return $result;
}

function checkPort($hostname, $port, $timeout = 3) {
    $result = ['success' => false];
    $protocol = (in_array($port, [443, 8443, 8006])) ? "ssl://" : "";
    $socket = @fsockopen($protocol . $hostname, $port, $errno, $errstr, $timeout);
    if ($socket) {
        $result['success'] = true;
        fclose($socket);
    } else {
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

function checkHTTPS($hostname, $port, $ignore_ssl = false, $timeout = 5) {
    $result = ['success' => false];
    $url = "https://{$hostname}:{$port}";
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$ignore_ssl,
            CURLOPT_SSL_VERIFYHOST => $ignore_ssl ? 0 : 2,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0']
        ]);
        @curl_exec($ch);
        if (!curl_errno($ch)) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code == 200 || $code == 401 || $code == 403) $result['success'] = true;
        }
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => !$ignore_ssl,
                'verify_peer_name' => !$ignore_ssl,
                'allow_self_signed' => $ignore_ssl
            ],
            'http' => ['timeout' => $timeout, 'ignore_errors' => true]
        ]);
        $headers = @get_headers($url, 0, $context);
        if ($headers && is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, 'HTTP/') === 0 && preg_match('/\d{3}/', $header, $m)) {
                    $code = $m[0];
                    if ($code == 200 || $code == 401 || $code == 403) {
                        $result['success'] = true;
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

        /* ========== СТИЛИ ДЛЯ ФОРМЫ ДОБАВЛЕНИЯ НОДЫ (оригинальные) ========== */
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

        /* ========== СЕТКА ФОРМЫ (оригинальная 4 колонки) ========== */
        .node-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .node-form-grid {
                grid-template-columns: 1fr;
            }
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

        /* ========== КНОПКА ПРОВЕРКИ (оригинальные стили) ========== */
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

        @media (max-width: 768px) {
            .verification-item {
                flex-direction: column;
                align-items: flex-start;
            }
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
            margin-bottom: 6px;
        }

        /* Оригинальный класс для статуса (теперь используем verification-status-text) */
        .verification-status-text {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        .verification-status-text.pending {
            background: rgba(148, 163, 184, 0.2);
            color: var(--node-text-muted);
        }
        .verification-status-text.success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--node-success);
        }
        .verification-status-text.error {
            background: rgba(239, 68, 68, 0.2);
            color: var(--node-danger);
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
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .verification-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* ========== ЧЕКБОКСЫ (оригинальные) ========== */
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

        /* ========== КНОПКИ ФОРМЫ (оригинальные) ========== */
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
            background: rgba(148, 163, 184, 0.1);
            color: var(--node-text-muted);
            border: 1px solid var(--node-border);
        }

        .form-btn-icon:hover {
            background: var(--node-hover);
            color: var(--node-text);
            transform: translateY(-2px);
        }

        /* ========== СТАТУС ПРОВЕРКИ (для результатов) ========== */
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
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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

        .result-value.status-success {
            color: #a7f3d0;
        }

        .result-value.status-error {
            color: #fecaca;
        }
        
        /* Кнопка "Вернуться к списку" – иконка с эффектами */
.form-btn-icon.form-btn-back {
    width: 40px;
    height: 40px;
    background: rgba(148, 163, 184, 0.15);
    border: 1px solid var(--node-border);
    border-radius: 10px;
    color: var(--node-text-secondary);
    transition: all 0.2s ease;
}

.form-btn-icon.form-btn-back:hover {
    background: var(--node-accent-light);
    border-color: var(--node-accent);
    color: var(--node-accent);
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Блок "Настройки ноды" – отступы, тени, скругления */
.form-section:last-child .checkbox-container {
    background: var(--node-hover);
    border-radius: 10px;
    margin-bottom: 12px;
    padding: 12px 16px;
    transition: all 0.2s;
}

.form-section:last-child .checkbox-container:hover {
    background: var(--node-accent-light);
    transform: translateX(4px);
}

.form-section:last-child .checkbox-container .checkmark {
    border-radius: 6px;
    border-width: 2px;
}

.form-section:last-child .checkbox-label {
    font-weight: 500;
}

.form-section:last-child .checkbox-hint {
    color: var(--node-text-muted);
    font-size: 12px;
    margin-top: 4px;
}

/* Дополнительно – для ровных отступов внутри блока настроек */
.form-section:last-child .form-section-body {
    padding: 20px;
}
    </style>
</head>
<body>
    <?php require 'admin_sidebar.php'; ?>
    <div class="node-wrapper">
        <div class="node-header">
            <div class="node-header-left">
                <h1><i class="fas fa-server"></i> Добавление новой ноды</h1>
                <p>Добавьте сервер Proxmox для управления виртуальными машинами</p>
            </div>
            <div class="node-header-right">
                <a href="nodes.php" class="form-btn-icon form-btn-back" title="Вернуться к списку"><i class="fas fa-arrow-left"></i></a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><div><?= htmlspecialchars($_SESSION['error']) ?></div></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <form method="POST" id="nodeForm">
            <input type="hidden" name="node_form" value="1">
            <div class="node-form-grid">
                <!-- Основные параметры -->
                <div class="form-section">
                    <div class="form-section-header"><h2><i class="fas fa-cog"></i> Основные параметры</h2></div>
                    <div class="form-section-body">
                        <div class="form-group"><label class="form-label required">Кластер</label><select name="cluster_id" class="form-input" id="clusterSelect" required><option value="">-- Выберите кластер --</option><?php foreach ($clusters as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label class="form-label required">Имя ноды</label><input type="text" name="node_name" class="form-input" id="nodeName" maxlength="50" required><span class="form-hint">Уникальное имя в кластере</span></div>
                        <div class="form-group"><label class="form-label required">Адрес сервера</label><input type="text" name="hostname" class="form-input" id="hostname" required><span class="form-hint">IP-адрес или домен</span></div>
                        <div class="form-group"><label class="form-label">Описание</label><textarea name="description" class="form-input" rows="3" id="description"></textarea></div>
                    </div>
                </div>

                <!-- Параметры подключения -->
                <div class="form-section">
                    <div class="form-section-header"><h2><i class="fas fa-plug"></i> Параметры подключения</h2></div>
                    <div class="form-section-body">
                        <div class="form-group"><label class="form-label">SSH порт</label><input type="number" name="ssh_port" class="form-input" value="22" id="sshPort" min="1" max="65535"><span class="form-hint">Порт для SSH</span></div>
                        <div class="form-group"><label class="form-label">API порт</label><input type="number" name="api_port" class="form-input" value="8006" id="apiPort" min="1" max="65535"><span class="form-hint">Порт API Proxmox</span></div>
                        <div class="form-group"><label class="form-label">Пользователь</label><input type="text" name="username" class="form-input" id="username" placeholder="root"></div>
                        <div class="form-group"><label class="form-label">Пароль</label><input type="password" name="password" class="form-input" id="password"><span class="form-hint">Пароль (шифруется)</span></div>
                    </div>
                </div>

                <!-- Проверка доступности (с добавленной HTTPS проверкой) -->
                <div class="form-section verification-section">
                    <div class="form-section-header verification-header"><h2><i class="fas fa-check-circle"></i> Проверка доступности</h2></div>
                    <div class="form-section-body">
                        <div class="verification-status" id="verificationStatus">
                            <div class="verification-item">
                                <div class="verification-icon"><i class="fas fa-server"></i></div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность хоста</div>
                                    <div class="verification-details">DNS и стандартные порты</div>
                                    <div class="verification-status-text pending" id="pingStatusText">ожидание</div>
                                </div>
                            </div>
                            <div class="verification-item">
                                <div class="verification-icon"><i class="fas fa-terminal"></i></div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность SSH порта</div>
                                    <div class="verification-details">Порт: <span id="sshPortDisplay">22</span></div>
                                    <div class="verification-status-text pending" id="sshStatusText">ожидание</div>
                                </div>
                            </div>
                            <div class="verification-item">
                                <div class="verification-icon"><i class="fas fa-code"></i></div>
                                <div class="verification-content">
                                    <div class="verification-label">Доступность API порта</div>
                                    <div class="verification-details">Порт: <span id="apiPortDisplay">8006</span></div>
                                    <div class="verification-status-text pending" id="apiStatusText">ожидание</div>
                                </div>
                            </div>
                            <div class="verification-item">
                                <div class="verification-icon"><i class="fas fa-lock"></i></div>
                                <div class="verification-content">
                                    <div class="verification-label">HTTPS соединение</div>
                                    <div class="verification-details">Порт: <span id="apiPortDisplay">8006</span></div>
                                    <div class="verification-status-text pending" id="httpsStatusText">ожидание</div>
                                </div>
                            </div>
                            <div class="verification-actions">
                                <button type="button" class="verification-btn" id="verifyBtn"><i class="fas fa-play"></i> Запустить проверку</button>
                            </div>
                            <div class="verification-results" id="verificationResults" style="display: none;">
                                <div class="verification-result-item"><span class="result-label">Общее время проверки:</span><span class="result-value" id="totalTime">0 мс</span></div>
                                <div class="verification-result-item"><span class="result-label">Статус:</span><span class="result-value" id="overallStatus">не проверено</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Настройки ноды (добавлен чекбокс ignore SSL) -->
                <div class="form-section">
                    <div class="form-section-header"><h2><i class="fas fa-sliders-h"></i> Настройки ноды</h2></div>
                    <div class="form-section-body">
                        <label class="checkbox-container">
                            <input type="checkbox" name="is_active" checked id="isActive">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">Активная нода <span class="checkbox-hint">Нода будет использоваться для создания новых ВМ</span></span>
                        </label>
                        <label class="checkbox-container">
                            <input type="checkbox" name="is_cluster_master" id="isClusterMaster">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">Главная нода кластера <span class="checkbox-hint">Используется для VNC консоли всех нод кластера</span></span>
                        </label>
                        <label class="checkbox-container">
                            <input type="checkbox" name="skip_verification" id="skipVerification">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">Пропустить проверку доступности <span class="checkbox-hint">Добавить ноду без проверки (не рекомендуется)</span></span>
                        </label>
                        <label class="checkbox-container">
                            <input type="checkbox" name="ignore_ssl" id="ignoreSSL">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">Игнорировать SSL ошибки <span class="checkbox-hint">Для самоподписанных сертификатов</span></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_node" class="form-btn form-btn-primary" id="submitBtn"><i class="fas fa-save"></i> Создать ноду</button>
                <a href="nodes.php" class="form-btn form-btn-secondary"><i class="fas fa-times"></i> Отмена</a>
            </div>
        </form>
    </div>

    <div class="loading-overlay" id="loadingOverlay"><div class="loading-spinner"></div></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
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
        const ignoreSSL = document.getElementById('ignoreSSL');
        const loadingOverlay = document.getElementById('loadingOverlay');
        const pingStatusText = document.getElementById('pingStatusText');
        const sshStatusText = document.getElementById('sshStatusText');
        const apiStatusText = document.getElementById('apiStatusText');
        const httpsStatusText = document.getElementById('httpsStatusText');
        const verificationResults = document.getElementById('verificationResults');
        const totalTimeSpan = document.getElementById('totalTime');
        const overallStatusSpan = document.getElementById('overallStatus');

        let verificationCompleted = false;
        let verificationInProgress = false;

        function updatePortDisplays() {
            sshPortDisplay.textContent = sshPort.value;
            apiPortDisplay.textContent = apiPort.value;
        }
        sshPort.addEventListener('input', updatePortDisplays);
        apiPort.addEventListener('input', updatePortDisplays);
        updatePortDisplays();

        function updateStatusText(element, status, text) {
            if (!element) return;
            element.className = `verification-status-text ${status}`;
            element.textContent = text;
        }

        function resetVerificationStatus() {
            updateStatusText(pingStatusText, 'pending', 'ожидание');
            updateStatusText(sshStatusText, 'pending', 'ожидание');
            updateStatusText(apiStatusText, 'pending', 'ожидание');
            updateStatusText(httpsStatusText, 'pending', 'ожидание');
            verificationResults.style.display = 'none';
            overallStatusSpan.textContent = 'не проверено';
            overallStatusSpan.className = 'result-value';
            totalTimeSpan.textContent = '0 мс';
            submitBtn.disabled = !skipVerification.checked;
            verificationCompleted = false;
        }

        function validateBasicFields() {
            let valid = true;
            document.querySelectorAll('.field-error').forEach(el => el.remove());
            if (!clusterSelect.value) { showError(clusterSelect, 'Выберите кластер'); valid=false; }
            if (!nodeName.value.trim()) { showError(nodeName, 'Введите имя ноды'); valid=false; }
            if (!hostname.value.trim()) { showError(hostname, 'Введите адрес сервера'); valid=false; }
            if (!isValidPort(sshPort.value)) { showError(sshPort, 'Некорректный SSH порт'); valid=false; }
            if (!isValidPort(apiPort.value)) { showError(apiPort, 'Некорректный API порт'); valid=false; }
            return valid;
        }

        function isValidHostname(host) {
            const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
            const domainPattern = /^(?!:\/\/)([a-zA-Z0-9-_]+\.)*[a-zA-Z0-9][a-zA-Z0-9-_]+\.[a-zA-Z]{2,11}?$/;
            return ipPattern.test(host) || domainPattern.test(host) || host === 'localhost';
        }

        function isValidPort(p) { let n = parseInt(p); return !isNaN(n) && n>=1 && n<=65535; }
        function showError(field, msg) {
            let err = document.createElement('div');
            err.className = 'field-error';
            err.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${msg}`;
            field.parentNode.appendChild(err);
            field.style.borderColor = 'var(--node-danger)';
            setTimeout(() => { if (err.parentNode) err.remove(); field.style.borderColor = ''; }, 3000);
        }
        function clearFieldErrors() { document.querySelectorAll('.field-error').forEach(el=>el.remove()); document.querySelectorAll('.form-input').forEach(inp=>inp.style.borderColor=''); }

        async function startVerification() {
            if (verificationInProgress) return;
            if (!validateBasicFields()) return;
            verificationInProgress = true;
            resetVerificationStatus();
            loadingOverlay.classList.add('active');
            verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка...';
            verifyBtn.disabled = true;

            try {
                const start = Date.now();
                const response = await fetch('check_node_availability.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        hostname: hostname.value.trim(),
                        ssh_port: parseInt(sshPort.value),
                        api_port: parseInt(apiPort.value),
                        ignore_ssl: ignoreSSL.checked
                    })
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const result = await response.json();
                console.log('Результат проверки:', result);

                if (result.checks && result.checks.ping) updateStatusText(pingStatusText, result.checks.ping.success ? 'success' : 'error', result.checks.ping.success ? 'доступен' : 'не доступен');
                if (result.checks && result.checks.ssh) updateStatusText(sshStatusText, result.checks.ssh.success ? 'success' : 'error', result.checks.ssh.success ? 'доступен' : 'не доступен');
                if (result.checks && result.checks.api) updateStatusText(apiStatusText, result.checks.api.success ? 'success' : 'error', result.checks.api.success ? 'доступен' : 'не доступен');
                if (result.checks && result.checks.https) updateStatusText(httpsStatusText, result.checks.https.success ? 'success' : 'error', result.checks.https.success ? 'доступен' : 'не доступен');

                const elapsed = Date.now() - start;
                totalTimeSpan.textContent = `${elapsed} мс`;
                verificationResults.style.display = 'block';

                const allChecksOk = (result.checks?.ping?.success && result.checks?.ssh?.success && result.checks?.api?.success && result.checks?.https?.success);
                const explicitOk = (result.success === true || result.success === 'true' || result.success === 1);
                const isSuccess = allChecksOk || explicitOk;

                if (isSuccess) {
                    overallStatusSpan.textContent = 'Пройдена';
                    overallStatusSpan.className = 'result-value status-success';
                    verificationCompleted = true;
                    submitBtn.disabled = false;
                } else {
                    overallStatusSpan.textContent = 'Не пройдена';
                    overallStatusSpan.className = 'result-value status-error';
                    verificationCompleted = false;
                    if (!skipVerification.checked) submitBtn.disabled = true;
                }
            } catch (err) {
                console.error('Ошибка проверки:', err);
                overallStatusSpan.textContent = 'Ошибка проверки';
                overallStatusSpan.className = 'result-value status-error';
                verificationResults.style.display = 'block';
                updateStatusText(pingStatusText, 'error', 'ошибка');
                updateStatusText(sshStatusText, 'error', 'ошибка');
                updateStatusText(apiStatusText, 'error', 'ошибка');
                updateStatusText(httpsStatusText, 'error', 'ошибка');
            } finally {
                verificationInProgress = false;
                loadingOverlay.classList.remove('active');
                verifyBtn.innerHTML = '<i class="fas fa-redo"></i> Проверить снова';
                verifyBtn.disabled = false;
            }
        }

        verifyBtn.addEventListener('click', startVerification);

        skipVerification.addEventListener('change', function() {
            if (this.checked) {
                submitBtn.disabled = false;
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<i class="fas fa-forward"></i> Проверка пропущена';
            } else {
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = verificationCompleted ? '<i class="fas fa-redo"></i> Проверить снова' : '<i class="fas fa-play"></i> Запустить проверку';
                submitBtn.disabled = !verificationCompleted;
            }
        });

        document.getElementById('nodeForm').addEventListener('submit', function(e) {
            let valid = true;
            clearFieldErrors();
            if (!clusterSelect.value) { showError(clusterSelect, 'Выберите кластер'); valid=false; }
            if (!nodeName.value.trim()) { showError(nodeName, 'Введите имя ноды'); valid=false; }
            if (!hostname.value.trim()) { showError(hostname, 'Введите адрес сервера'); valid=false; }
            if (!isValidPort(sshPort.value)) { showError(sshPort, 'Некорректный SSH порт'); valid=false; }
            if (!isValidPort(apiPort.value)) { showError(apiPort, 'Некорректный API порт'); valid=false; }
            if (!valid) { e.preventDefault(); return; }
            if (!verificationCompleted && !skipVerification.checked) {
                if (!confirm('Проверка доступности ноды не пройдена. Вы уверены, что хотите добавить ноду без проверки?')) {
                    e.preventDefault();
                    return;
                }
            }
            loadingOverlay.classList.add('active');
        });

        let timeout;
        [hostname, sshPort, apiPort].forEach(f => {
            f.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    if (hostname.value.trim() && !verificationInProgress) resetVerificationStatus();
                }, 1000);
            });
        });
    });
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>