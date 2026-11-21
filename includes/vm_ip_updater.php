<?php
require_once __DIR__ . '/cli_only.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/proxmox_functions.php';
require_once __DIR__ . '/guest_ip.php';

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
                        $addresses = readGuestIpAddresses([$api, 'request'], $node['node_name'], (int)$vm['vm_id'], $vm['vm_type']);
                        if ($addresses === null) continue;
                        $save = $this->pdo->prepare("UPDATE vms SET ip_address=? WHERE node_id=? AND vm_id=? AND vm_type=? AND status<>'deleted'");
                        $save->execute([$addresses, $node['id'], $vm['vm_id'], $vm['vm_type']]);
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
