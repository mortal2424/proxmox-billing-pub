<?php
/**
 * AJAX endpoint для проверки доступности ноды
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Проверка аутентификации
if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен', 'code' => 403]);
    exit;
}

// Разрешаем только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается', 'code' => 405]);
    exit;
}

// Получаем данные из запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST;
}

// Валидация входных данных
$required_fields = ['hostname'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Не указано поле: {$field}", 'code' => 400]);
        exit;
    }
}

// Параметры проверки
$hostname = trim($input['hostname']);
$ssh_port = intval($input['ssh_port'] ?? 22);
$api_port = intval($input['api_port'] ?? 8006);
$timeout = intval($input['timeout'] ?? 3);

// Функция проверки DNS разрешения (без exec)
function checkDNSResolution($hostname, $timeout = 2) {
    $result = ['success' => false, 'latency' => 0, 'error' => '', 'ip' => null];

    $hostname = parse_url($hostname, PHP_URL_HOST) ?: $hostname;
    $hostname = preg_replace('/:\d+$/', '', $hostname);

    if (empty($hostname)) {
        $result['error'] = 'Не указан hostname';
        return $result;
    }

    $start_time = microtime(true);

    // Проверяем, является ли строка IP-адресом
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        $result['success'] = true;
        $result['ip'] = $hostname;
        $result['latency'] = (microtime(true) - $start_time) * 1000;
        return $result;
    }

    // Пробуем разрешить DNS имя
    $ip = @gethostbyname($hostname);
    
    if ($ip !== $hostname) {
        $result['success'] = true;
        $result['ip'] = $ip;
        $result['latency'] = (microtime(true) - $start_time) * 1000;
    } else {
        // Пробуем через dns_get_record
        $dns_records = @dns_get_record($hostname, DNS_A);
        if (!empty($dns_records)) {
            $result['success'] = true;
            $result['ip'] = $dns_records[0]['ip'] ?? null;
            $result['latency'] = (microtime(true) - $start_time) * 1000;
        } else {
            $result['error'] = 'Не удалось разрешить DNS имя';
            $result['latency'] = (microtime(true) - $start_time) * 1000;
        }
    }

    return $result;
}

// Проверка доступности через стандартные порты (без exec)
function checkHostAvailability($hostname, $timeout = 2) {
    $result = ['success' => false, 'latency' => 0, 'error' => '', 'service' => null, 'port' => null];
    
    $hostname = parse_url($hostname, PHP_URL_HOST) ?: $hostname;
    $hostname = preg_replace('/:\d+$/', '', $hostname);

    if (empty($hostname)) {
        $result['error'] = 'Не указан hostname';
        return $result;
    }

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
            $result['latency'] = (microtime(true) - $start_time) * 1000;
            $result['service'] = $service;
            $result['port'] = $port;
            fclose($socket);
            break;
        }
    }
    
    if (!$result['success']) {
        $result['error'] = 'Не удалось подключиться к стандартным портам (80, 443, 22, 8006)';
    }
    
    return $result;
}

function checkPort($hostname, $port, $timeout = 3) {
    $result = ['success' => false, 'error' => '', 'latency' => 0];

    $hostname = parse_url($hostname, PHP_URL_HOST) ?: $hostname;

    $start_time = microtime(true);
    
    // Для HTTPS портов используем ssl://
    $protocol = in_array($port, [443, 8443, 8006]) ? "ssl://" : "";
    
    $socket = @fsockopen($protocol . $hostname, $port, $errno, $errstr, $timeout);

    if ($socket) {
        $result['success'] = true;
        $result['latency'] = (microtime(true) - $start_time) * 1000;
        fclose($socket);
    } else {
        $result['error'] = "{$errstr} (код: {$errno})";
        
        // Попытка без SSL для порта 8006
        if ($port == 8006 && $protocol === "ssl://") {
            $socket2 = @fsockopen($hostname, $port, $errno2, $errstr2, $timeout);
            if ($socket2) {
                $result['success'] = true;
                $result['warning'] = "Порт доступен, но не по SSL";
                $result['latency'] = (microtime(true) - $start_time) * 1000;
                fclose($socket2);
            }
        }
    }

    return $result;
}

function checkHTTPS($hostname, $port, $timeout = 5) {
    $result = ['success' => false, 'error' => '', 'latency' => 0];

    $start_time = microtime(true);
    
    $hostname_clean = parse_url($hostname, PHP_URL_HOST) ?: $hostname;
    $url = "https://{$hostname_clean}:{$port}";

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
        $result['latency'] = (microtime(true) - $start_time) * 1000;
        
        if (!curl_errno($ch)) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Proxmox возвращает 401 для неавторизованных запросов, что нормально
            if ($http_code == 200 || $http_code == 401 || $http_code == 403) {
                $result['success'] = true;
                $result['http_code'] = $http_code;
            } else {
                $result['error'] = "HTTP статус: {$http_code}";
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
        $result['latency'] = (microtime(true) - $start_time) * 1000;

        if ($headers !== false && is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, 'HTTP/') === 0) {
                    $response_code = substr($header, 9, 3);
                    if ($response_code == '200' || $response_code == '401' || $response_code == '403') {
                        $result['success'] = true;
                        $result['http_code'] = $response_code;
                        break;
                    } else {
                        $result['error'] = "HTTP статус: {$header}";
                    }
                }
            }
        } else {
            $result['error'] = 'Не удалось получить заголовки';
        }
    }

    return $result;
}

// Выполняем проверки
$start_time = microtime(true);
$response = [
    'success' => false,
    'message' => '',
    'checks' => [],
    'overall_status' => 'offline',
    'response_time' => 0
];

try {
    // 1. Проверка DNS разрешения
    $dns_result = checkDNSResolution($hostname, 2);
    $response['checks']['dns'] = $dns_result;

    // 2. Проверка доступности через стандартные порты
    $availability_result = checkHostAvailability($hostname, 2);
    $response['checks']['availability'] = $availability_result;

    // 3. Проверка SSH порта
    $ssh_result = checkPort($hostname, $ssh_port, $timeout);
    $response['checks']['ssh'] = $ssh_result;

    // 4. Проверка API порта
    $api_result = checkPort($hostname, $api_port, $timeout);
    $response['checks']['api'] = $api_result;

    // 5. Проверка HTTPS (если API порт доступен)
    if ($api_result['success']) {
        $https_result = checkHTTPS($hostname, $api_port, $timeout);
        $response['checks']['https'] = $https_result;
    }

    // Определяем общий статус
    $dns_ok = $dns_result['success'];
    $availability_ok = $availability_result['success'];
    $ssh_ok = $ssh_result['success'];
    $api_ok = $api_result['success'];
    $https_ok = $response['checks']['https']['success'] ?? false;

    // Логика определения статуса
    if (!$dns_ok) {
        $response['overall_status'] = 'offline';
        $response['success'] = false;
        $response['message'] = 'DNS имя не разрешается';
    } elseif (!$availability_ok) {
        $response['overall_status'] = 'warning';
        $response['success'] = false;
        $response['message'] = 'Хост не отвечает на стандартные порты';
    } elseif ($api_ok && $https_ok) {
        $response['overall_status'] = 'online';
        $response['success'] = true;
        $response['message'] = 'Нода полностью доступна';
    } elseif ($api_ok && !$https_ok) {
        $response['overall_status'] = 'warning';
        $response['success'] = true;
        $response['message'] = 'API порт доступен, но HTTPS соединение не установлено';
    } elseif ($ssh_ok) {
        $response['overall_status'] = 'warning';
        $response['success'] = true;
        $response['message'] = 'SSH доступен, но API порт недоступен';
    } else {
        $response['overall_status'] = 'offline';
        $response['success'] = false;
        $response['message'] = 'Нода недоступна';

        // Собираем ошибки
        $errors = [];
        if (!$dns_ok) $errors[] = 'DNS: ' . ($dns_result['error'] ?: 'не разрешается');
        if (!$availability_ok) $errors[] = 'Доступность: ' . ($availability_result['error'] ?: 'недоступен');
        if (!$ssh_ok) $errors[] = 'SSH порт: ' . ($ssh_result['error'] ?: 'недоступен');
        if (!$api_ok) $errors[] = 'API порт: ' . ($api_result['error'] ?: 'недоступен');

        $response['message'] .= '. Ошибки: ' . implode('; ', $errors);
    }

    $response['response_time'] = (microtime(true) - $start_time) * 1000;

} catch (Exception $e) {
    $response['message'] = 'Ошибка при проверке: ' . $e->getMessage();
    $response['response_time'] = (microtime(true) - $start_time) * 1000;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>