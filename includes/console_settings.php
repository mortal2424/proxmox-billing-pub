<?php
declare(strict_types=1);

function consoleSettingsPath(): ?string
{
    return getenv('HOMEVLAD_CONSOLE_SETTINGS') ?: (
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

function consoleBrokerStatus(): array
{
    $socket = @stream_socket_client('unix://' . consoleSocketPath(), $errno, $error, 2);
    if (!$socket) return ['status'=>'offline'];
    try {
        stream_set_timeout($socket, 2);
        fwrite($socket, "{\"operation\":\"status\"}\n");
        $status = json_decode((string)fgets($socket, 4096), true);
        return is_array($status) && ($status['status'] ?? '') === 'ok' ? $status : ['status'=>'legacy'];
    } finally {
        fclose($socket);
    }
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
