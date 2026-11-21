<?php
require_once __DIR__ . '/ProxmoxApiClient.php';

function probeNodeHealth(array $node): string
{
    $host = $node['hostname'];
    $port = (int) ($node['api_port'] ?: 8006);
    if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return 'unknown';
    }
    if (str_contains($host, ':')) {
        $host = '[' . $host . ']';
    }
    if (str_contains((string)$node['username'], '!')) {
        try {
            (new ProxmoxApiClient($node))->request('GET', '/nodes/' . rawurlencode($node['node_name']) . '/status');
            $state = 'online';
        } catch (Throwable $error) {
            $state = 'unknown';
            error_log('Node member status unavailable for node ' . (int)$node['id']);
        }
        return $state;
    }
    $http_response_header = [];
    try {
        $context = stream_context_create([
            'ssl' => proxmoxTlsOptions($node['hostname'], $port),
            'http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true,
                'header' => "Connection: close\r\n"],
        ]);
        $body = @file_get_contents("https://$host:$port/api2/json/version", false, $context, 0, 4096);
        $code = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match)) $code = (int) $match[1];
        }
        $state = $body !== false && in_array($code, [200, 401, 403], true) ? 'online' : 'unknown';
        if ($body === false) {
            $socket = @stream_socket_client("tcp://$host:$port", $errno, $message, 3);
            if ($socket) fclose($socket);
            else $state = 'offline';
        }
    } catch (Throwable $error) {
        error_log('Node health TLS configuration failed for node ' . (int) $node['id']);
        $state = 'unknown';
    }
    return $state;
}
