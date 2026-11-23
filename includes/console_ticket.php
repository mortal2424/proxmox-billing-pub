<?php
require_once __DIR__ . '/node_api_credentials.php';
require_once __DIR__ . '/console_settings.php';

final class ConsoleBrokerUnavailable extends RuntimeException {}

function issueConsoleTicket(array $node, int $vmId, string $type): string
{
    $node = panelNodeApiCredentials($node);
    $target = [
        'hostname' => $node['hostname'],
        'api_port' => $node['api_port'],
        'node_name' => $node['node_name'],
        'username' => $node['username'],
        'password' => decryptNodePassword($node['password']),
        'vmid' => $vmId,
        'type' => $type,
    ];
    $settings = consoleSettings();
    $target += array_intersect_key($settings, ['origin'=>true, 'ticket_ttl'=>true]);
    $pin = $settings['pins'][(int)($node['id'] ?? 0)] ?? null;
    if ($pin !== null) $target['cert_sha256'] = $pin;
    $payload = json_encode($target, JSON_THROW_ON_ERROR) . "\n";
    $token = consoleBrokerRequest($payload);
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new ConsoleBrokerUnavailable('Сервис консоли недоступен');
    }
    return $token;
}
