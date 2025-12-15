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

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Неверные данные']);
    exit;
}

$hostname = $data['hostname'] ?? '';
$ssh_port = $data['ssh_port'] ?? 22;
$api_port = $data['api_port'] ?? 8006;

if (empty($hostname)) {
    echo json_encode(['success' => false, 'message' => 'Не указан адрес сервера']);
    exit;
}

// Функции проверки (те же, что и в add_node.php)
function checkHostPing($hostname, $timeout = 2) {
    $result = ['success' => false, 'latency' => 0];
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "ping -n 1 -w " . ($timeout * 1000) . " " . escapeshellarg($hostname);
    } else {
        $command = "ping -c 1 -W " . $timeout . " " . escapeshellarg($hostname);
    }
    
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    
    if ($return_var === 0) {
        $result['success'] = true;
        foreach ($output as $line) {
            if (preg_match('/time[=<](\d+\.?\d*)\s*ms/', $line, $matches)) {
                $result['latency'] = floatval($matches[1]);
                break;
            }
        }
    }
    
    return $result;
}

function checkPort($hostname, $port, $timeout = 3) {
    $result = ['success' => false];
    
    $socket = @fsockopen($hostname, $port, $errno, $errstr, $timeout);
    
    if ($socket) {
        $result['success'] = true;
        fclose($socket);
    } else {
        $result['error'] = $errstr;
    }
    
    return $result;
}

function checkHTTPS($hostname, $port, $timeout = 5) {
    $result = ['success' => false];
    
    $url = "https://{$hostname}:{$port}";
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ],
        'http' => ['timeout' => $timeout]
    ]);
    
    $headers = @get_headers($url, 0, $context);
    
    if ($headers && strpos($headers[0], '200') !== false) {
        $result['success'] = true;
    }
    
    return $result;
}

// Выполняем проверки
$start_time = microtime(true);
$result = [
    'success' => false,
    'message' => '',
    'checks' => []
];

try {
    // 1. Проверка ping
    $ping_result = checkHostPing($hostname);
    $result['checks']['ping'] = $ping_result;
    
    if (!$ping_result['success']) {
        $result['message'] = "Хост недоступен (ping)";
        echo json_encode($result);
        exit;
    }
    
    // 2. Проверка SSH порта
    $ssh_result = checkPort($hostname, $ssh_port);
    $result['checks']['ssh'] = $ssh_result;
    
    if (!$ssh_result['success']) {
        $result['message'] = "SSH порт ($ssh_port) недоступен";
        echo json_encode($result);
        exit;
    }
    
    // 3. Проверка API порта Proxmox
    $api_result = checkPort($hostname, $api_port);
    $result['checks']['api'] = $api_result;
    
    if (!$api_result['success']) {
        $result['message'] = "API порт ($api_port) недоступен";
        echo json_encode($result);
        exit;
    }
    
    // 4. Проверка HTTPS (опционально)
    $https_result = checkHTTPS($hostname, $api_port);
    $result['checks']['https'] = $https_result;
    
    $result['success'] = true;
    $result['message'] = "Все проверки пройдены успешно";
    
} catch (Exception $e) {
    $result['message'] = "Ошибка при проверке: " . $e->getMessage();
}

$result['response_time'] = round((microtime(true) - $start_time) * 1000, 2);

echo json_encode($result);
?>