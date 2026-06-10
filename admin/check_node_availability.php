<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Неверный метод запроса']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Неверные данные']);
    exit;
}

$hostname = $data['hostname'] ?? '';
$ssh_port = (int)($data['ssh_port'] ?? 22);
$api_port = (int)($data['api_port'] ?? 8006);
$ignore_ssl = isset($data['ignore_ssl']) ? (bool)$data['ignore_ssl'] : false;

if (empty($hostname)) {
    echo json_encode(['success' => false, 'message' => 'Не указан адрес сервера']);
    exit;
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

function checkDNS($hostname) {
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
            if ($code == 200 || $code == 401 || $code == 403) {
                $result['success'] = true;
            }
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

$start_time = microtime(true);
$result = [
    'success' => false,
    'message' => '',
    'checks' => []
];

try {
    $dns = checkDNS($hostname);
    $result['checks']['dns'] = $dns;
    if (!$dns['success']) throw new Exception("Не удалось разрешить DNS имя хоста");

    $ports = [22, 80, 443, 8006];
    $port_available = false;
    foreach ($ports as $port) {
        $check = checkPort($hostname, $port, 2);
        if ($check['success']) {
            $port_available = true;
            $result['checks']['ping'] = ['success' => true, 'port' => $port];
            break;
        }
    }
    if (!$port_available) throw new Exception("Хост не отвечает на стандартные порты");

    $ssh = checkPort($hostname, $ssh_port);
    $result['checks']['ssh'] = $ssh;
    if (!$ssh['success']) throw new Exception("SSH порт ($ssh_port) недоступен");

    $api = checkPort($hostname, $api_port);
    $result['checks']['api'] = $api;
    if (!$api['success']) throw new Exception("API порт ($api_port) недоступен");

    $https = checkHTTPS($hostname, $api_port, $ignore_ssl);
    $result['checks']['https'] = $https;
    if ($ignore_ssl && !$https['success'] && $api['success']) {
        // Если порт открыт, но SSL не прошёл, и игнор включён – считаем успехом
        $https['success'] = true;
        $https['warning'] = "SSL проверка пропущена (самоподписанный сертификат)";
        $result['checks']['https'] = $https;
    }
    if (!$https['success']) throw new Exception("HTTPS не отвечает (сертификат недействителен)");

    $result['success'] = true;
    $result['message'] = "Все проверки пройдены успешно";
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

$result['response_time'] = round((microtime(true) - $start_time) * 1000, 2);
echo json_encode($result);