<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

header('Content-Type: application/json');

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

$action = $_GET['action'] ?? '';
$vm_id = $_GET['id'] ?? 0;

// Получаем базовые данные о VM
$stmt = $pdo->prepare("
    SELECT v.*, n.hostname, n.username, n.password, n.node_name, n.id as node_id 
    FROM vms v 
    JOIN proxmox_nodes n ON v.node_id = n.id 
    WHERE v.id = ? AND v.user_id = ?
");
$stmt->execute([$vm_id, $user_id]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    http_response_code(404);
    echo json_encode(['error' => 'VM not found']);
    exit;
}

// Инициализация Proxmox API
try {
    $proxmox = new ProxmoxAPI(
        $vm['hostname'],
        $vm['username'],
        $vm['password'],
        $vm['ssh_port'] ?? 22,
        $vm['node_name'],
        $vm['node_id'],
        $pdo
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Proxmox connection error']);
    exit;
}

switch ($action) {
    case 'get_disks':
        if ($vm['vm_type'] === 'qemu') {
            // Для KVM получаем информацию о дисках
            try {
                $vm_info = $proxmox->getVmDetailedInfo($vm['vm_id'], $vm['vm_type']);
                $disks = $vm_info['config']['disks'] ?? [];
                $storages = $proxmox->getNodeStorages();
                
                $result = [
                    'disks' => array_map(function($disk) use ($storages) {
                        return [
                            'id' => $disk['id'],
                            'size' => $disk['size'],
                            'storage' => $disk['storage'],
                            'storages' => array_map(function($storage) use ($disk) {
                                return [
                                    'name' => $storage['name'],
                                    'available' => $storage['available'],
                                    'selected' => $storage['name'] === $disk['storage']
                                ];
                            }, $storages)
                        ];
                    }, $disks)
                ];
            } catch (Exception $e) {
                $result = ['disks' => [], 'error' => $e->getMessage()];
            }
        } else {
            // Для LXC просто возвращаем текущий размер диска
            $result = [
                'disk_size' => $vm['disk_gb'] ?? 10
            ];
        }
        
        echo json_encode($result);
        break;
        
    case 'get_networks':
        try {
            if ($vm['vm_type'] === 'qemu') {
                // Для KVM
                $vm_info = $proxmox->getVmDetailedInfo($vm['vm_id'], $vm['vm_type']);
                $networks = $vm_info['config']['networks'] ?? [];
            } else {
                // Для LXC
                $config = $proxmox->getLxcConfig($vm['vm_id']);
                $networks = [];
                
                foreach ($config as $key => $value) {
                    if (strpos($key, 'net') === 0) {
                        $parts = explode(',', $value);
                        $net = ['id' => str_replace('net', '', $key)];
                        
                        foreach ($parts as $part) {
                            if (strpos($part, '=') !== false) {
                                list($k, $v) = explode('=', $part);
                                if ($k === 'name') $net['name'] = $v;
                                if ($k === 'bridge') $net['bridge'] = $v;
                                if ($k === 'hwaddr') $net['mac'] = $v;
                            }
                        }
                        
                        $networks[] = $net;
                    }
                }
            }
            
            $node_networks = $proxmox->getNodeNetworks();
            $sdn_networks = $proxmox->getSDNNetworks();
            
            // Формируем список доступных сетей с учетом SDN
            $available_networks = $node_networks;
            foreach ($sdn_networks as $sdn_net) {
                $available_networks[] = [
                    'name' => $sdn_net['name'],
                    'alias' => $sdn_net['alias'] ?? ''
                ];
            }
            
            $result = [
                'networks' => array_map(function($net) {
                    return [
                        'id' => $net['id'],
                        'name' => $net['name'] ?? null,
                        'mac' => $net['mac'] ?? null,
                        'bridge' => $net['bridge'],
                        'alias' => $net['alias'] ?? null
                    ];
                }, $networks),
                'availableNetworks' => $available_networks
            ];
        } catch (Exception $e) {
            $result = ['networks' => [], 'availableNetworks' => [], 'error' => $e->getMessage()];
        }
        
        echo json_encode($result);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}