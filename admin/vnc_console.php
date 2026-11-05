<?php
// Включаем максимальное логирование ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Настройки заголовков
header('Content-Type: application/json');
// Класс для работы с VNC
class VncHelper {
    public static function getVncTicket($host, $port, $nodeName, $vmId, $vmType, $pveTicket, $csrfToken) {        $apiUrl = "https://{$host}:{$port}/api2/json/nodes/{$nodeName}/{$vmType}/{$vmId}/vncproxy";
        $postData = http_build_query(['websocket' => 1]);
        $headers = [
            'Cookie: PVEAuthCookie=' . $pveTicket,
            'CSRFPreventionToken: ' . $csrfToken,
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($postData)
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);$response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);curl_close($ch);
        if ($response === false) {
            throw new Exception("CURL Error: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Ошибка получения VNC ticket. Код: {$httpCode}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        if (!isset($data['data']['ticket'])) {
            throw new Exception("Неверный формат VNC ticket");
        }

        return $data['data'];
    }
}

// Основной код
try {
    session_start();
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/db.php';

    checkAuth();
    
    // Валидация параметров
    $nodeId = (int)($_GET['node_id'] ?? 0);
    $vmId = (int)($_GET['vm_id'] ?? 0);
    $vmType = $_GET['type'] ?? null;

    if (!$nodeId || !$vmId) {
        throw new Exception('Не указан node_id или vm_id');
    }

    // Получаем данные из БД
    $db = new Database();
    $pdo = $db->getConnection();
    $userId = $_SESSION['user']['id'];

    $stmt = $pdo->prepare("SELECT vms.*, pn.* FROM vms 
                         JOIN proxmox_nodes pn ON vms.node_id = pn.id
                         WHERE vms.vm_id = ? AND vms.user_id = ?");
    $stmt->execute([$vmId, $userId]);
    $nodeData = $stmt->fetch();
    if (!$nodeData) {
        throw new Exception('Доступ запрещен: ВМ не найдена или нет прав');
    }

    // Проверяем наличие обязательных полей
    if (empty($nodeData['hostname']) || empty($nodeData['node_name']) || empty($nodeData['api_port'])) {
        throw new Exception('Недостаточно данных о ноде');
    }

    // Получаем тикет из базы данных
    $ticketStmt = $pdo->prepare("SELECT ticket, csrf_token FROM proxmox_tickets 
                               WHERE node_id = ? AND expires_at > NOW()");
    $ticketStmt->execute([$nodeData['id']]);
    $ticket = $ticketStmt->fetch();
    if (!$ticket || empty($ticket['ticket']) || empty($ticket['csrf_token'])) {
        throw new Exception('Нет активной сессии для этой ноды. Попробуйте позже.');
    }

    // Определяем хост для подключения
    $urlHost = $nodeData['hostname'];
    $urlPort = $nodeData['api_port'];
    
    if (!empty($nodeData['cluster_id'])) {
        $clusterStmt = $pdo->prepare("SELECT * FROM proxmox_nodes 
                                    WHERE cluster_id = ? AND is_cluster_master = 1 
                                    LIMIT 1");
        $clusterStmt->execute([$nodeData['cluster_id']]);
        $masterNode = $clusterStmt->fetch();
        
        if ($masterNode) {
            $urlHost = $masterNode['hostname'];
            $urlPort = $masterNode['api_port'] ?? 8006;
        }
    }

    // Получаем VNC ticket
    $isLxc = ($vmType === 'lxc' || ($nodeData['vm_type'] ?? '') === 'lxc');
    $vmTypeForApi = $isLxc ? 'lxc' : 'qemu';
    $vncData = VncHelper::getVncTicket(
        $urlHost,
        $urlPort,
        $nodeData['node_name'],
        $vmId,
        $vmTypeForApi,
        $ticket['ticket'],
        $ticket['csrf_token']
    );
    // Формируем URL консоли
    $queryParams = [
        'console' => $isLxc ? 'lxc' : 'kvm',
        'vmid' => $vmId,
        'node' => $nodeData['node_name'],
        'vncticket' => $vncData['ticket']
    ];

    if ($isLxc) {
        $queryParams['xtermjs'] = '1';
    } else {
        $queryParams['novnc'] = '1';
        $queryParams['resize'] = 'scale';
    }

    
    // Формируем правильный URL
    $vncUrl = 'https://' . $urlHost . ':' . $urlPort . '/?' . http_build_query($queryParams);
    // Формируем ответ
$response = [
    'success' => true,
    'data' => [
        'url' => $vncUrl,
        'vmid' => $vmId,
        'node' => $nodeData['node_name'],
        'vm_type' => $isLxc ? 'lxc' : 'qemu',
        'is_cluster' => !empty($nodeData['cluster_id']),
        'cookie' => [
            'name' => 'PVEAuthCookie',
            'value' => $ticket['ticket'],
            'domain' => parse_url($urlHost, PHP_URL_HOST),
            'path' => '/',
            'secure' => true,
            'httponly' => false, // Required by the current browser-based Proxmox console flow
            'samesite' => 'None' // Важно для кросс-доменных запросов
        ],
    ]
];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log('VNC console request failed: ' . get_class($e));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Не удалось открыть консоль. Проверьте доступ и настройки Proxmox.'
    ]);
}