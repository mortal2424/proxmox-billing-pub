<?php
class ProxmoxAuth {
    private $db;
    private $nodeId;
    private $hostname;
    private $username;
    private $password;
    private $apiPort;
    
    public function __construct($db, $nodeId, $hostname, $username, $password, $apiPort = 8006) {
        $this->db = $db;
        $this->nodeId = $nodeId;
        $this->hostname = $hostname;
        $this->username = $username;
        $this->password = $password;
        $this->apiPort = $apiPort;
    }
    
    public function getTicket() {
    try {
        // Всегда получаем свежий тикет для конкретной ноды
        $url = "https://{$this->hostname}:{$this->apiPort}/api2/json/access/ticket";
        $postData = [
            'username' => $this->username,
            'password' => $this->password,
            'realm' => 'pam'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: " . $httpCode);
        }

        $data = json_decode($response, true);
        if (empty($data['data'])) {
            throw new Exception("Не удалось получить ticket для ноды {$this->hostname}");
        }

        return [
            'ticket' => $data['data']['ticket'],
            'csrf_token' => $data['data']['CSRFPreventionToken'],
            'domain' => parse_url($this->hostname, PHP_URL_HOST)
        ];

    } catch (Exception $e) {
        error_log("Ошибка в getTicket() для ноды {$this->hostname}: " . $e->getMessage());
        throw $e;
    }
}

    public function getVncProxy($vmid, $nodeName) {
        try {
            $ticket = $this->getTicket();
            
            $url = "https://{$this->hostname}:{$this->apiPort}/api2/json/nodes/{$nodeName}/qemu/{$vmid}/vncproxy";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    "Cookie: PVEAuthCookie=" . urlencode($ticket['ticket']),
                    "CSRFPreventionToken: " . $ticket['csrf_token']
                ],
                CURLOPT_POSTFIELDS => http_build_query([
                    'websocket' => 1,
                    'generate-password' => 0
                ])
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($error) {
                throw new Exception("VNC Proxy CURL Error: " . $error);
            }

            if ($httpCode !== 200) {
                throw new Exception("VNC Proxy HTTP Error: " . $httpCode);
            }

            $data = json_decode($response, true);
            if (empty($data['data'])) {
                throw new Exception("Неверный ответ VNC proxy от ноды {$this->hostname}");
            }

            return $data['data'];
            
        } catch (Exception $e) {
            error_log("Ошибка в getVncProxy() для ноды {$this->hostname}: " . $e->getMessage());
            throw $e;
        }
    }

    
}