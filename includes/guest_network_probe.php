<?php

function guestNetworkProbeDirectory(): string
{
    return getenv('HOMEVLAD_NETWORK_PROBE_DIR') ?: dirname(__DIR__) . '/backups/network-probes';
}

function guestNetworkProbeStatus(int $nodeId, int $now): array
{
    $path = guestNetworkProbeDirectory() . "/$nodeId";
    if (!is_file($path . '/key')) return ['label' => 'Проверка не настроена'];

    $raw = is_file($path . '/latest.json') ? file_get_contents($path . '/latest.json') : false;
    $report = $raw === false ? null : json_decode($raw, true);
    $time = $report['checked_at'] ?? null;
    if (!is_int($time) || $time < $now - 900 || $time > $now + 30) {
        return ['label' => 'Нет свежего отчёта'];
    }

    $count = (int) $report['debian'] + (int) $report['iana'];
    return [
        'label' => match ($count) {
            2 => 'Доступна',
            1 => 'Частичная доступность',
            default => 'Проверки не прошли',
        },
        'checked_at' => $time,
    ];
}

function guestNetworkProbeRecord(string $body, string $signature, int $now): int
{
    if (strlen($body) > 512 || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return 400;
    $report = json_decode($body, true, 8);
    if (!is_array($report) || count($report) !== 4 ||
        !is_int($report['node_id'] ?? null) || $report['node_id'] < 1 ||
        !is_int($report['checked_at'] ?? null) || $report['checked_at'] < $now - 120 ||
        $report['checked_at'] > $now + 30 || !is_bool($report['debian'] ?? null) ||
        !is_bool($report['iana'] ?? null)) return 400;

    $path = guestNetworkProbeDirectory() . '/' . $report['node_id'];
    $key = is_file($path . '/key') ? trim((string) file_get_contents($path . '/key')) : '';
    if ($key === '' || !hash_equals(hash_hmac('sha256', $body, $key), $signature)) return 403;

    $lock = fopen($path . '/lock', 'c');
    if (!$lock) return 503;
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return 503;
        $previous = is_file($path . '/latest.json') ? json_decode((string) file_get_contents($path . '/latest.json'), true) : null;
        if (($previous['checked_at'] ?? 0) >= $report['checked_at']) return 409;

        $temporary = tempnam($path, 'report-');
        if (!$temporary || file_put_contents($temporary, $body) !== strlen($body) ||
            !rename($temporary, $path . '/latest.json')) return 503;
        return 204;
    } finally {
        if ($temporary && is_file($temporary)) unlink($temporary);
        fclose($lock);
    }
}
