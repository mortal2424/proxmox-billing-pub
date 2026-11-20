<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

$lock = fopen(sys_get_temp_dir() . '/homevlad-node-health.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$pdo = (new Database())->getConnection();
$update = $pdo->prepare('UPDATE proxmox_nodes SET status = ?, last_check = NOW() WHERE id = ?');
foreach ($pdo->query('SELECT id, hostname, api_port FROM proxmox_nodes WHERE is_active = 1') as $node) {
    $host = $node['hostname'];
    $port = (int) ($node['api_port'] ?: 8006);
    if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        $update->execute(['unknown', $node['id']]);
        continue;
    }
    if (str_contains($host, ':')) {
        $host = '[' . $host . ']';
    }
    $curl = curl_init("https://$host:$port/api2/json/version");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    // Certificate failures mean unknown health, not proof that the node is offline.
    $state = in_array($code, [200, 401, 403], true) ? 'online'
        : (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_OPERATION_TIMEDOUT], true)
            ? 'offline' : 'unknown');
    $update->execute([$state, $node['id']]);
}
flock($lock, LOCK_UN);
fclose($lock);
