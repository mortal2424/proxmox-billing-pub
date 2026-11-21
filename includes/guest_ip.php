<?php

function readGuestIpAddresses(callable $request, string $node, int $vmid, string $type): ?string
{
    if (!in_array($type, ['qemu', 'lxc'], true) || $vmid <= 0) {
        throw new InvalidArgumentException('Некорректный гость');
    }
    $base = '/nodes/' . rawurlencode($node) . '/' . $type . '/' . $vmid;
    $status = $request('GET', $base . '/status/current');
    if (($status['status'] ?? '') !== 'running') {
        return null;
    }

    $response = $request('GET', $base . ($type === 'lxc' ? '/interfaces' : '/agent/network-get-interfaces'));
    return guestIpAddresses($response['result'] ?? $response ?? []);
}

function guestIpAddresses(array $interfaces): ?string
{
    $addresses = [];
    foreach ($interfaces as $interface) {
        if (!is_array($interface) || ($interface['name'] ?? '') === 'lo') continue;
        $values = [$interface['inet'] ?? '', $interface['inet6'] ?? ''];
        foreach ($interface['ip-addresses'] ?? [] as $address) {
            $values[] = $address['ip-address'] ?? '';
        }
        foreach ($values as $value) {
            if (!is_string($value)) continue;
            $ip = explode('/', $value, 2)[0];
            $packed = @inet_pton($ip);
            if ($packed === false) continue;
            if (strlen($packed) === 4) {
                if (preg_match('/^(?:127\.|169\.254\.|0\.)/', $ip) || ord($packed[0]) >= 224) continue;
            } elseif ($ip === '::' || $ip === '::1' || ord($packed[0]) === 0xff ||
                (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80)) {
                continue;
            }
            $addresses[inet_ntop($packed)] = true;
        }
    }
    $selected = '';
    foreach (array_keys($addresses) as $ip) {
        $next = $selected === '' ? $ip : "$selected, $ip";
        if (strlen($next) <= 255) $selected = $next;
    }
    return $selected === '' ? null : $selected;
}
