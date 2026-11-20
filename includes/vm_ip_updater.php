<?php
require_once __DIR__ . '/cli_only.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/proxmox_functions.php';

final class VmIpUpdater
{
    private PDO $pdo;
    public function __construct($db)
    {
        $this->pdo = $db instanceof PDO ? $db : $db->getConnection();
    }

    public function updateAllVmIps(): array
    {
        $failures = 0;
        foreach ($this->pdo->query('SELECT * FROM proxmox_nodes WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) as $node) {
            try {
                $api = new ProxmoxAPI($node['hostname'], $node['username'], $node['password'], 22,
                    $node['node_name'], $node['id'], $this->pdo);
                $query = $this->pdo->prepare("SELECT vm_id,vm_type FROM vms WHERE node_id=? AND status!='deleted'");
                $query->execute([$node['id']]);
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $vm) {
                    try {
                        $vmid = (int)$vm['vm_id'];
                        $type = $vm['vm_type'] === 'lxc' ? 'lxc' : 'qemu';
                        $base = '/nodes/' . rawurlencode($node['node_name']) . '/' . $type . '/' . $vmid;
                        $status = $api->request('GET', $base . '/status/current');
                        if (($status['status'] ?? '') !== 'running') {
                            $addresses = null;
                        } else {
                            $interfaces = $api->request('GET', $base . ($type === 'lxc' ? '/interfaces' : '/agent/network-get-interfaces'));
                            $addresses = self::addresses($interfaces['result'] ?? $interfaces);
                            // An absent agent or an empty response must not erase a known address.
                            if ($addresses === null) continue;
                        }
                        $save = $this->pdo->prepare('UPDATE vms SET ip_address=? WHERE node_id=? AND vm_id=?');
                        $save->execute([$addresses, $node['id'], $vmid]);
                    } catch (Throwable $error) {
                        ++$failures;
                        error_log('VM IP read failed node='.(int)$node['id'].' vm='.(int)$vm['vm_id'].' class='.get_class($error));
                    }
                }
            } catch (Throwable $error) {
                ++$failures;
                error_log('VM IP node read failed node='.(int)$node['id'].' class='.get_class($error));
            }
        }
        return ['success' => $failures === 0, 'failures' => $failures];
    }

    public static function addresses(array $interfaces): ?string
    {
        $addresses = [];
        foreach ($interfaces as $interface) {
            if (!is_array($interface) || ($interface['name'] ?? '') === 'lo') continue;
            $values = isset($interface['inet']) ? [explode('/', $interface['inet'])[0]] : [];
            foreach ($interface['ip-addresses'] ?? [] as $address) {
                if (($address['ip-address-type'] ?? '') === 'ipv4') $values[] = $address['ip-address'] ?? '';
            }
            foreach ($values as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
                    preg_match('/^(?:127\.|169\.254\.|0\.)/', $ip) || $ip === '255.255.255.255') continue;
                $addresses[$ip] = true;
            }
        }
        return $addresses ? implode(', ', array_keys($addresses)) : null;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = (new VmIpUpdater(new Database()))->updateAllVmIps();
        exit($result['success'] ? 0 : 1);
    } catch (Throwable $error) {
        error_log('VM IP updater failed: '.get_class($error));
        exit(1);
    }
}
