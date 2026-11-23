<?php
declare(strict_types=1);

function consoleHostingOption(string $name): string
{
    static $options;
    if ($options === null) {
        $site = dirname(__DIR__, 2);
        $privateConfig = dirname(__DIR__, 4) . '/tmp/homevlad-production-console/config.php';
        $path = basename($site) === 'homevlad.ru' && @is_file($privateConfig)
            ? $privateConfig : $site . '/homevlad-console.php';
        $options = @is_file($path) ? require $path : [];
    }
    $value = getenv($name);
    return $value !== false ? $value : ($options[$name] ?? '');
}

function consoleSettingsPath(): ?string
{
    return consoleHostingOption('HOMEVLAD_CONSOLE_SETTINGS') ?: (
        basename(dirname(__DIR__, 2)) === 'dev.homevlad.ru'
            ? dirname(__DIR__, 4) . '/tmp/homevlad-dev-license/console-settings.json' : null);
}

function consoleSocketPath(): string
{
    $path = getenv('HOMEVLAD_CONSOLE_SOCKET') ?: (
        basename(dirname(__DIR__, 2)) === 'dev.homevlad.ru'
            ? '/run/homevlad-console-dev/issuer.sock' : '/run/homevlad-console/issuer.sock');
    if (!preg_match('#^/run/homevlad-console(?:-[a-z0-9]+)?/issuer\.sock$#D', $path)) {
        throw new RuntimeException('Некорректный путь сокета консоли.');
    }
    return $path;
}

function consoleSettings(): array
{
    $path = consoleSettingsPath();
    if (!$path || !is_file($path)) return [];
    return json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
}

function consoleRemoteBroker(): ?string
{
    $origin = consoleHostingOption('HOMEVLAD_CONSOLE_BROKER') ?: '';
    if ($origin === '') return null;
    if (!preg_match('#^https://[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$#D', $origin) ||
        filter_var($origin, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Укажите HTTPS-адрес внешнего прокси без пути.');
    }
    return $origin;
}

function consoleSocketUrl(): string
{
    $broker = consoleRemoteBroker();
    return $broker === null ? '/console/socket' : 'wss://' . substr($broker, 8) . '/console/socket';
}

function consoleBrokerRequest(string $payload): string
{
    $broker = consoleRemoteBroker();
    if ($broker !== null) {
        $secret = consoleHostingOption('HOMEVLAD_CONSOLE_BROKER_SECRET') ?: '';
        if (!extension_loaded('curl') || !preg_match('/^[a-f0-9]{64}$/D', $secret)) return '';
        $curl = curl_init($broker . '/console/issuer');
        $response = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 4096) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        return curl_exec($curl) && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200 ? trim($response) : '';
    }

    $socket = @stream_socket_client('unix://' . consoleSocketPath(), $errno, $error, 2);
    if (!$socket) return '';
    try {
        stream_set_timeout($socket, 3);
        return fwrite($socket, $payload) === strlen($payload) ? trim((string) fgets($socket, 4096)) : '';
    } finally {
        fclose($socket);
    }
}

function consoleBrokerStatus(): array
{
    $response = consoleBrokerRequest("{\"operation\":\"status\"}\n");
    if ($response === '') return ['status'=>'offline'];
    $status = json_decode($response, true);
    return is_array($status) && ($status['status'] ?? '') === 'ok' ? $status : ['status'=>'legacy'];
}

function saveConsoleSettings(string $origin, int $ttl, array $broker, array $pins = []): void
{
    $path = consoleSettingsPath();
    if (!$path || ($broker['status'] ?? '') !== 'ok' || !in_array($origin, $broker['origins'] ?? [], true) ||
        $ttl < 15 || $ttl > 90) {
        throw new DomainException('Выберите разрешённый HTTPS-адрес и срок от 15 до 90 секунд. Нужен совместимый прокси.');
    }
    $temporary = tempnam(dirname($path), '.console-');
    if ($temporary === false) throw new RuntimeException('Не удалось сохранить настройки консоли.');
    try {
        $data = json_encode(['origin'=>$origin, 'ticket_ttl'=>$ttl, 'pins'=>$pins], JSON_THROW_ON_ERROR);
        if (!chmod($temporary, 0600) || file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $path)) {
            throw new RuntimeException('Не удалось сохранить настройки консоли.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}
