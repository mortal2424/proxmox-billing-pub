<?php
require_once __DIR__ . '/ProxmoxApiClient.php';

class ProxmoxAuth
{
    private ProxmoxApiClient $client;
    private string $hostname;

    public function __construct($db, $nodeId, $hostname, $username, $password, $apiPort = 8006)
    {
        $this->hostname = $hostname;
        $this->client = new ProxmoxApiClient(['hostname' => $hostname, 'api_port' => $apiPort,
            'username' => $username, 'password' => $password]);
    }

    public function getTicket(): array
    {
        $ticket = $this->client->getPasswordTicket();
        return ['ticket' => $ticket['ticket'], 'csrf_token' => $ticket['CSRFPreventionToken'], 'domain' => $this->hostname];
    }

    public function getVncProxy($vmid, $nodeName): array
    {
        $vmid = filter_var($vmid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$vmid || !is_string($nodeName) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $nodeName)) throw new InvalidArgumentException('Некорректный узел или идентификатор ВМ');
        $data = $this->client->request('POST', '/nodes/' . $nodeName . '/qemu/' . $vmid . '/vncproxy', ['websocket' => 1]);
        if (!is_array($data) || empty($data['port']) || empty($data['ticket'])) throw new RuntimeException('Неполный ответ VNC proxy');
        return $data;
    }
}
