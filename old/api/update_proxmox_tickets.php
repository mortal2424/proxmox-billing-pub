<?php
require_once __DIR__ . '/../includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

// Получаем все ноды из базы
$nodes = $pdo->query("SELECT id, hostname, username, password, api_port FROM proxmox_nodes")->fetchAll();

foreach ($nodes as $node) {
    try {
        $port = isset($node['api_port']) ? $node['api_port'] : 8006;
        $url = "https://{$node['hostname']}:{$port}/api2/json/access/ticket";
        
        // Формируем данные для авторизации
        $username = $node['username'];
        if (strpos($username, '@') === false) {
            $username .= '@pam'; // Добавляем домен если отсутствует
        }
        
        $postData = "username=" . urlencode($username) . "&password=" . urlencode($node['password']);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($postData)
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            error_log("Failed to get ticket for node {$node['id']}: HTTP {$httpCode}");
            error_log("Request data: " . print_r($postData, true));
            error_log("Response: " . $response);
            curl_close($ch);
            continue;
        }
        
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['data']['ticket'])) {
            error_log("Invalid ticket response from node {$node['id']}");
            error_log("Response: " . $response);
            continue;
        }
        
        // Устанавливаем срок действия (2 часа от текущего момента)
        $expiresAt = new DateTime();
        $expiresAt->add(new DateInterval('PT2H')); // Добавляем 2 часа
        
        // Проверяем существование записи
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_tickets WHERE node_id = ?");
        $stmt->execute([$node['id']]);
        $exists = $stmt->fetchColumn();
        
        if ($exists) {
            // Обновляем существующую запись
            $stmt = $pdo->prepare("UPDATE proxmox_tickets 
                                 SET ticket = ?, csrf_token = ?, expires_at = ?
                                 WHERE node_id = ?");
        } else {
            // Создаем новую запись
            $stmt = $pdo->prepare("INSERT INTO proxmox_tickets 
                                 (ticket, csrf_token, expires_at, node_id) 
                                 VALUES (?, ?, ?, ?)");
        }
        
        $stmt->execute([
            $data['data']['ticket'],
            $data['data']['CSRFPreventionToken'],
            $expiresAt->format('Y-m-d H:i:s'),
            $node['id']
        ]);
        
        echo "Successfully " . ($exists ? "updated" : "created") . " ticket for node {$node['hostname']}\n";
    } catch (Exception $e) {
        error_log("Error processing node {$node['id']}: " . $e->getMessage());
    }
}