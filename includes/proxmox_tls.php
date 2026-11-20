<?php

function proxmoxTlsOptions(string $host, int $port): array
{
    $options = ['verify_peer' => true, 'verify_peer_name' => true, 'disable_compression' => true];
    $directory = dirname(__DIR__, 2);
    $ca = getenv('PROXMOX_CA_FILE') ?: $directory . '/.proxmox-ca.pem';
    if (getenv('PROXMOX_CA_FILE') || file_exists($ca)) {
        if (!is_readable($ca)) throw new RuntimeException('Файл CA Proxmox недоступен');
        $options['cafile'] = $ca;
    }
    $pinFile = getenv('PROXMOX_CERT_PINS') ?: $directory . '/.proxmox-cert-pins.json';
    if (!getenv('PROXMOX_CERT_PINS') && !file_exists($pinFile)) {
        return $options;
    }
    $raw = @file_get_contents($pinFile, false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new RuntimeException('Файл отпечатков Proxmox недоступен');
    }
    $pins = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($pins)) throw new RuntimeException('Некорректный файл отпечатков Proxmox');
    $authority = strtolower(str_contains($host, ':') ? "[$host]" : $host) . ":$port";
    if (isset($pins[$authority])) {
        if (!is_string($pins[$authority]) || !preg_match('/^[a-f0-9]{64}$/iD', $pins[$authority])) {
            throw new RuntimeException('Некорректный отпечаток сертификата Proxmox');
        }
        $options['peer_fingerprint'] = ['sha256' => strtolower($pins[$authority])];
        $options['verify_peer'] = false;
        $options['verify_peer_name'] = false;
    }
    return $options;
}
