<?php
declare(strict_types=1);

function consoleHostingOption(string $name): string
{
    static $options;
    if ($options === null) {
        $path = dirname(__DIR__, 2) . '/homevlad-console.php';
        $options = is_file($path) ? require $path : [];
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

function validateConsoleBrokerUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    if (!preg_match('#^https://[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$#D', $url) ||
        filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new DomainException('Укажите HTTPS-адрес внешнего прокси без пути.');
    }
    return $url;
}

function consoleConnection(): array
{
    $settings = consoleSettings();
    if (isset($settings['connection'])) return $settings['connection'];
    $url = consoleHostingOption('HOMEVLAD_CONSOLE_BROKER');
    return $url === '' ? ['url'=>null] : [
        'url'=>validateConsoleBrokerUrl($url),
        'secret'=>consoleHostingOption('HOMEVLAD_CONSOLE_BROKER_SECRET'),
    ];
}

function consoleRemoteBroker(): ?string
{
    return consoleConnection()['url'];
}

function consoleConnectionForEdit(string $mode, string $url, string $secret, array $current): array
{
    if ($mode === 'local') return ['url'=>null];
    if ($mode !== 'remote') throw new DomainException('Выберите тип подключения.');
    $url = validateConsoleBrokerUrl($url);
    if ($secret === '' && $url === $current['url']) $secret = $current['secret'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) {
        throw new DomainException('Укажите общий секрет из 64 шестнадцатеричных символов для этого прокси.');
    }
    return ['url'=>$url, 'secret'=>$secret];
}

function consoleSocketUrl(): string
{
    $broker = consoleRemoteBroker();
    return $broker === null ? '/console/socket' : 'wss://' . substr($broker, 8) . '/console/socket';
}

function consoleBrokerRequest(string $payload, ?array $connection = null): string
{
    $connection ??= consoleConnection();
    $broker = $connection['url'];
    if ($broker !== null) {
        $secret = $connection['secret'] ?? '';
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

function consoleBrokerStatus(?array $connection = null): array
{
    $response = consoleBrokerRequest("{\"operation\":\"status\"}\n", $connection);
    if ($response === '') return ['status'=>'offline'];
    $status = json_decode($response, true);
    if (!is_array($status) || ($status['status'] ?? '') !== 'ok' ||
        !is_array($status['origins'] ?? null) || !is_int($status['ticket_ttl'] ?? null)) {
        return ['status'=>'legacy'];
    }
    $status['origins'] = array_values(array_filter($status['origins'], static fn ($origin): bool => is_string($origin)));
    return $status['origins'] ? $status : ['status'=>'legacy'];
}

function saveConsoleSettings(string $origin, int $ttl, array $broker, array $pins, array $connection): void
{
    $path = consoleSettingsPath();
    if (!$path || ($broker['status'] ?? '') !== 'ok' || !in_array($origin, $broker['origins'] ?? [], true) ||
        $ttl < 15 || $ttl > 90) {
        throw new DomainException('Выберите разрешённый HTTPS-адрес и срок от 15 до 90 секунд. Нужен совместимый прокси.');
    }
    $temporary = tempnam(dirname($path), '.console-');
    if ($temporary === false) throw new RuntimeException('Не удалось сохранить настройки консоли.');
    try {
        $data = json_encode(['origin'=>$origin, 'ticket_ttl'=>$ttl, 'pins'=>$pins, 'connection'=>$connection], JSON_THROW_ON_ERROR);
        if (!chmod($temporary, 0600) || file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $path)) {
            throw new RuntimeException('Не удалось сохранить настройки консоли.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}
