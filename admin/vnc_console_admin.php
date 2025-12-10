<?php
// Включаем максимальное логирование ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/vnc_errors.log');

// Настройки заголовков
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');

// Класс для работы с VNC
class VncHelper {
    public static function getVncTicket($host, $port, $nodeName, $vmId, $vmType, $pveTicket, $csrfToken) {
        $logMessage = "Starting getVncTicket:\n";
        $logMessage .= "Host: $host\nPort: $port\nNode: $nodeName\nVMID: $vmId\nType: $vmType\n";

        $apiUrl = "https://{$host}:{$port}/api2/json/nodes/{$nodeName}/{$vmType}/{$vmId}/vncproxy";
        $logMessage .= "API URL: $apiUrl\n";

        $postData = http_build_query(['websocket' => 1]);
        $logMessage .= "POST Data: $postData\n";

        $headers = [
            'Cookie: PVEAuthCookie=' . $pveTicket,
            'CSRFPreventionToken: ' . $csrfToken,
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($postData)
        ];
        $logMessage .= "Headers: " . print_r($headers, true) . "\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_VERBOSE => true
        ]);

        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        fclose($verbose);

        curl_close($ch);

        $logMessage .= "HTTP Code: $httpCode\n";
        $logMessage .= "CURL Error: $error\n";
        $logMessage .= "Verbose Log:\n$verboseLog\n";
        $logMessage .= "Response: " . substr($response, 0, 1000) . "\n";

        error_log($logMessage);

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

    // Логируем начало выполнения
    error_log("========== Starting ADMIN VNC Request ==========");
    error_log("Request params: " . print_r($_GET, true));
    error_log("Session: " . print_r($_SESSION, true));

    // Проверяем права администратора
    if (!isAdmin()) {
        error_log("Access denied: User is not admin");
        throw new Exception('Доступ запрещен: требуются права администратора');
    }

    // Валидация параметров
    $nodeId = (int)($_GET['node_id'] ?? 0);
    $vmId = (int)($_GET['vm_id'] ?? 0);
    $vmType = $_GET['type'] ?? 'qemu'; // Админские ВМ - по умолчанию qemu

    if (!$nodeId || !$vmId) {
        throw new Exception('Не указан node_id или vm_id');
    }

    // Валидация типа ВМ
    if (!in_array($vmType, ['qemu', 'lxc'])) {
        throw new Exception("Неверный тип ВМ: $vmType. Допустимые значения: qemu, lxc");
    }

    // Получаем данные из БД
    $db = new Database();
    $pdo = $db->getConnection();

    error_log("Looking for admin VM in vms_admin table for vm_id=$vmId, node_id=$nodeId");

    // Ищем ВМ в таблице vms_admin
    $stmt = $pdo->prepare("SELECT vms_admin.*, pn.* FROM vms_admin
                         JOIN proxmox_nodes pn ON vms_admin.node_id = pn.id
                         WHERE vms_admin.vm_id = ? AND vms_admin.node_id = ?");
    $stmt->execute([$vmId, $nodeId]);
    $nodeData = $stmt->fetch();

    if (!$nodeData) {
        error_log("Admin VM not found with exact match, trying without node_id");

        // Пробуем найти ВМ только по vm_id (без привязки к node_id)
        $stmt = $pdo->prepare("SELECT vms_admin.*, pn.* FROM vms_admin
                             JOIN proxmox_nodes pn ON vms_admin.node_id = pn.id
                             WHERE vms_admin.vm_id = ?
                             ORDER BY vms_admin.id DESC
                             LIMIT 1");
        $stmt->execute([$vmId]);
        $nodeData = $stmt->fetch();

        if (!$nodeData) {
            error_log("Admin VM with VMID $vmId not found in vms_admin table");
            throw new Exception("Административная ВМ с VMID $vmId не найдена");
        }

        // Логируем, что нашли ВМ без привязки к ноде
        error_log("Found admin VM by VMID only: VMID={$nodeData['vm_id']}, NodeID={$nodeData['node_id']}");
    }

    error_log("Node data from DB: " . print_r($nodeData, true));

    // Проверяем наличие обязательных полей
    $missingFields = [];
    if (empty($nodeData['hostname'])) $missingFields[] = 'hostname';
    if (empty($nodeData['node_name'])) $missingFields[] = 'node_name';
    if (empty($nodeData['api_port'])) {
        $nodeData['api_port'] = 8006; // Значение по умолчанию для Proxmox
        error_log("Using default API port: 8006");
    }

    if (!empty($missingFields)) {
        error_log("Missing fields in node data: " . implode(', ', $missingFields));
        throw new Exception('Недостаточно данных о ноде. Отсутствуют: ' . implode(', ', $missingFields));
    }

    // Получаем тикет из базы данных
    $ticketStmt = $pdo->prepare("SELECT ticket, csrf_token FROM proxmox_tickets
                               WHERE node_id = ? AND expires_at > NOW()
                               ORDER BY expires_at DESC
                               LIMIT 1");
    $ticketStmt->execute([$nodeData['id']]);
    $ticket = $ticketStmt->fetch();

    error_log("Ticket data from DB: " . print_r($ticket, true));

    if (!$ticket || empty($ticket['ticket']) || empty($ticket['csrf_token'])) {
        // Пытаемся получить любой активный тикет для этого хоста
        $ticketStmt = $pdo->prepare("SELECT ticket, csrf_token FROM proxmox_tickets
                                   WHERE hostname = ? AND expires_at > NOW()
                                   ORDER BY expires_at DESC
                                   LIMIT 1");
        $ticketStmt->execute([$nodeData['hostname']]);
        $ticket = $ticketStmt->fetch();

        if (!$ticket || empty($ticket['ticket']) || empty($ticket['csrf_token'])) {
            error_log("No active session found for node {$nodeData['id']} or host {$nodeData['hostname']}");
            throw new Exception('Нет активной сессии для этой ноды. Попробуйте позже или обновите авторизацию.');
        }
        error_log("Using alternative ticket for host: " . $nodeData['hostname']);
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
            error_log("Using cluster master node: " . print_r($masterNode, true));
        }
    }

    // Определяем тип ВМ для API
    $vmTypeForApi = $vmType; // Для админских ВМ используем переданный тип

    error_log("Attempting to get VNC ticket for admin VM:");
    error_log("Host: $urlHost, Port: $urlPort, Node: {$nodeData['node_name']}, VMID: $vmId, Type: $vmTypeForApi");

    $vncData = VncHelper::getVncTicket(
        $urlHost,
        $urlPort,
        $nodeData['node_name'],
        $vmId,
        $vmTypeForApi,
        $ticket['ticket'],
        $ticket['csrf_token']
    );

    error_log("Received VNC data: " . print_r($vncData, true));

    // Определяем, является ли ВМ контейнером (lxc)
    $isLxc = ($vmTypeForApi === 'lxc');

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
    error_log("Generated VNC URL: " . $vncUrl);

    // Формируем ответ
    $response = [
        'success' => true,
        'data' => [
            'url' => $vncUrl,
            'vmid' => $vmId,
            'node' => $nodeData['node_name'],
            'vm_type' => $vmTypeForApi,
            'is_cluster' => !empty($nodeData['cluster_id']),
            'cookie' => [
                'name' => 'PVEAuthCookie',
                'value' => $ticket['ticket'],
                'domain' => parse_url($urlHost, PHP_URL_HOST) ?: $urlHost,
                'path' => '/',
                'secure' => true,
                'httponly' => false,
                'samesite' => 'None'
            ],
            'ticket_info' => [
                'host' => $urlHost,
                'port' => $urlPort,
                'node_name' => $nodeData['node_name'],
                'timestamp' => time()
            ]
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log("Exception in ADMIN VNC: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'details' => [
            'is_admin' => true,
            'vm_id' => $vmId ?? null,
            'node_id' => $nodeId ?? null,
            'vm_type' => $vmType ?? null
        ]
    ]);
}
