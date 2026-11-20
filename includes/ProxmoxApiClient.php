<?php
require_once __DIR__ . '/node_credentials.php';
require_once __DIR__ . '/proxmox_tls.php';

final class ProxmoxApiClient
{
    private string $baseUrl;
    private string $username;
    private string $secret;
    private bool $token;
    private array $tls;
    private ?array $ticket = null;
    private int $ticketExpires = 0;
    private array $taskWarnings = [];

    public function __construct(array $node)
    {
        $host = (string) ($node['hostname'] ?? '');
        $port = (int) ($node['api_port'] ?? 8006);
        if ((!filter_var($host, FILTER_VALIDATE_IP) &&
             !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) ||
            $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Некорректный адрес API Proxmox');
        }
        $authority = (str_contains($host, ':') ? "[$host]" : strtolower($host)) . ":$port";
        $this->baseUrl = 'https://' . $authority . '/api2/json';
        $this->username = (string) ($node['username'] ?? '');
        $this->secret = decryptNodePassword($node['password'] ?? '');
        if ($this->username === '' || $this->secret === '') {
            throw new InvalidArgumentException('Не заданы учётные данные API');
        }
        $this->token = str_contains($this->username, '!');
        if ($this->token) {
            if (!preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+![A-Za-z0-9_.-]+$/D', $this->username) ||
                !preg_match('/^[A-Za-z0-9_.~=-]{1,255}$/D', $this->secret)) {
                throw new InvalidArgumentException('Некорректный идентификатор или секрет API-токена');
            }
        } elseif (!str_contains($this->username, '@')) {
            $this->username .= '@pam';
        }
        $this->tls = proxmoxTlsOptions($host, $port);
    }

    public function usesToken(): bool
    {
        return $this->token;
    }

    public function request(string $method, string $path, array $parameters = []): mixed
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) ||
            !preg_match('#^/[A-Za-z0-9_./%:!@~-]+$#D', $path) ||
            in_array('..', explode('/', rawurldecode($path)), true)) {
            throw new InvalidArgumentException('Некорректный запрос к API Proxmox');
        }
        if ($this->token) {
            $headers = ['Authorization: PVEAPIToken=' . $this->username . '=' . $this->secret];
        } else {
            $this->getPasswordTicket();
            $headers = ['Cookie: PVEAuthCookie=' . rawurlencode($this->ticket['ticket'])];
            if ($method !== 'GET') {
                $headers[] = 'CSRFPreventionToken: ' . $this->ticket['CSRFPreventionToken'];
            }
        }
        return $this->exchange($method, $path, $parameters, $headers);
    }

    public function uploadImage(string $node, string $storage, string $file, string $filename, string $content): mixed
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$node) || !preg_match('/^[A-Za-z0-9_.-]+$/D',$storage)) {
            throw new InvalidArgumentException('Некорректное хранилище');
        }
        require_once __DIR__.'/ProxmoxUpload.php';
        if ($this->token) {
            $headers=['Authorization: PVEAPIToken='.$this->username.'='.$this->secret];
        } else {
            $ticket=$this->getPasswordTicket();
            $headers=['Cookie: PVEAuthCookie='.rawurlencode($ticket['ticket']), 'CSRFPreventionToken: '.$ticket['CSRFPreventionToken']];
        }
        $result=ProxmoxUpload::send($this->baseUrl.'/nodes/'.$node.'/storage/'.$storage.'/upload',
            $this->tls,$headers,$file,$filename,$content);
        if(is_string($result)&&str_starts_with($result,'UPID:'))$this->waitForTask($result,600);
        return $result;
    }

    public function getPasswordTicket(): array
    {
        if ($this->token) throw new RuntimeException('API-токен не использует парольные билеты');
        if ($this->ticket === null || time() >= $this->ticketExpires) {
            $ticket = $this->exchange('POST', '/access/ticket',
                ['username' => $this->username, 'password' => $this->secret], []);
            if (!is_array($ticket) || !is_string($ticket['ticket'] ?? null) ||
                !is_string($ticket['CSRFPreventionToken'] ?? null) ||
                preg_match('/[\r\n]/', $ticket['ticket'] . $ticket['CSRFPreventionToken'])) {
                throw new RuntimeException('API Proxmox вернул некорректный билет');
            }
            $this->ticket = $ticket;
            $this->ticketExpires = time() + 6000;
        }
        return $this->ticket;
    }

    public function requestAndWait(string $method, string $path, array $parameters = [], int $timeout = 180): mixed
    {
        $result = $this->request($method, $path, $parameters);
        if ($method !== 'GET' && is_string($result) && str_starts_with($result, 'UPID:')) {
            $this->waitForTask($result, $timeout);
        }
        return $result;
    }

    public function waitForTask(string $upid, int $timeout = 180): array
    {
        if (!preg_match('/^UPID:([A-Za-z0-9_.-]+):[^\r\n]{1,1000}$/D', $upid, $match) || $timeout < 1 || $timeout > 3600) {
            throw new InvalidArgumentException('Некорректная задача Proxmox');
        }
        $deadline = microtime(true) + $timeout;
        do {
            $state = $this->request('GET', '/nodes/' . $match[1] . '/tasks/' . rawurlencode($upid) . '/status');
            if (!is_array($state) || !isset($state['status'])) throw new RuntimeException('Некорректный статус задачи Proxmox');
            if ($state['status'] === 'stopped') {
                $warningCount=self::taskWarningCount((string)($state['exitstatus']??''));
                if($warningCount>0){$this->taskWarnings[]=$warningCount;$this->taskWarnings=array_slice($this->taskWarnings,-20);}
                return $state;
            }
            if ($state['status'] !== 'running') throw new RuntimeException('Неизвестный статус задачи Proxmox');
            if (microtime(true) >= $deadline) break;
            usleep(250000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Задача Proxmox ещё выполняется. Не повторяйте операцию до проверки её статуса');
    }

    public static function taskWarningCount(string $exitStatus): int
    {
        if($exitStatus==='OK')return 0;
        if(preg_match('/^WARNINGS: ([1-9][0-9]{0,5})$/D',$exitStatus,$match))return (int)$match[1];
        throw new RuntimeException('Операция Proxmox завершилась с ошибкой');
    }

    public function taskWarnings(): array
    {
        return $this->taskWarnings;
    }

    private function exchange(string $method, string $path, array $parameters, array $headers): mixed
    {
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $url = $this->baseUrl . $path;
        if ($method === 'GET' && $encoded !== '') {
            $url .= '?' . $encoded;
        }
        $headers[] = 'Accept: application/json';
        $headers[] = 'Connection: close';
        $options = [
            'method' => $method, 'header' => $headers, 'timeout' => 20,
            'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true,
        ];
        if ($method !== 'GET') {
            $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = $encoded;
        }
        $http_response_header = [];
        $body = @file_get_contents($url, false, stream_context_create([
            'http' => $options, 'ssl' => $this->tls,
        ]), 0, 8388609);
        if ($body === false) {
            throw new RuntimeException('Не удалось установить проверенное HTTPS-соединение с Proxmox');
        }
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("API Proxmox вернул HTTP $status", $status);
        }
        if (strlen($body) > 8388608) {
            throw new RuntimeException('Ответ API Proxmox превышает допустимый размер');
        }
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
            throw new RuntimeException('Некорректный ответ API Proxmox');
        }
        return $decoded['data'];
    }
}
