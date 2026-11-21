<?php
require_once __DIR__ . '/node_api_credentials.php';

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
    $payload = json_encode($target, JSON_THROW_ON_ERROR) . "\n";
    $socket = @stream_socket_client('unix:///run/homevlad-console/issuer.sock', $errno, $error, 2);
    if (!$socket) {
        throw new ConsoleBrokerUnavailable('Сервис консоли недоступен');
    }
    try {
        stream_set_timeout($socket, 3);
        $token = fwrite($socket, $payload) === strlen($payload) ? trim((string) fgets($socket, 66)) : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new ConsoleBrokerUnavailable('Не удалось открыть консоль');
        }
        return $token;
    } finally {
        fclose($socket);
    }
}
