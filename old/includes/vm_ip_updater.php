<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/proxmox_functions.php';

class VmIpUpdater {
    private $pdo;
    
    public function __construct($db) {
        if ($db instanceof PDO) {
            $this->pdo = $db;
        } elseif ($db instanceof Database) {
            $this->pdo = $db->getConnection();
        } else {
            throw new Exception("Database connection not provided");
        }
    }
    
    public function updateAllVmIps() {
        try {
            error_log("[VM IP Updater] Starting IP update process");
            
            $nodes = $this->getActiveNodes();
            
            if (empty($nodes)) {
                error_log("[VM IP Updater] No active nodes found");
                return ['success' => true, 'message' => 'No active nodes found'];
            }
            
            foreach ($nodes as $node) {
                $this->processNode($node);
            }
            
            error_log("[VM IP Updater] IP update completed successfully");
            return ['success' => true, 'message' => 'IP addresses updated successfully'];
        } catch (Exception $e) {
            error_log("[VM IP Updater] Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function getActiveNodes() {
        $stmt = $this->pdo->query("SELECT * FROM proxmox_nodes WHERE is_active = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function processNode($node) {
        try {
            error_log("[VM IP Updater] Processing node: {$node['node_name']}");
            
            $proxmox = new ProxmoxAPI(
                $node['hostname'],
                $node['username'],
                $node['password'],
                22,
                $node['node_name'],
                $node['id'],
                $this->pdo
            );
            
            $stmt = $this->pdo->prepare("SELECT vm_id, ip_address, vm_type FROM vms WHERE node_id = ? AND status != 'deleted'");
            $stmt->execute([$node['id']]);
            $vms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($vms as $vm) {
                if ($vm['vm_type'] === 'lxc') {
                    $this->processLxcContainer($proxmox, $vm['vm_id'], $vm['ip_address']);
                } else {
                    $this->processVm($proxmox, $vm['vm_id'], $vm['ip_address']);
                }
            }
        } catch (Exception $e) {
            error_log("[VM IP Updater] Node error: " . $e->getMessage());
        }
    }
    
    private function processVm($proxmox, $vmid, $currentIp) {
        try {
            error_log("[VM IP Updater] Processing VM {$vmid}");
            
            $status = $proxmox->getVMStatus($vmid);
            
            // Если VM не запущена и текущий IP уже NULL - ничего не делаем
            if ($status['status'] !== 'running') {
                if ($currentIp === null) {
                    error_log("[VM IP Updater] VM {$vmid} is stopped and IP is already NULL");
                    return;
                }
                error_log("[VM IP Updater] VM {$vmid} is not running, setting IP to NULL");
                $this->setVmIpToNull($vmid);
                return;
            }
            
            $config = $proxmox->getVMConfig($vmid);
            $isWindows = $this->isWindowsVM($config);
            
            // Получаем новый IP с использованием ваших оригинальных методов
            $newIp = $isWindows ? $this->getWindowsIp($proxmox, $vmid) : $this->getLinuxIp($proxmox, $vmid);
            
            // Если не удалось получить IP и текущий IP уже NULL - ничего не делаем
            if ($newIp === null && $currentIp === null) {
                error_log("[VM IP Updater] Failed to get IP for VM {$vmid}, IP is already NULL");
                return;
            }
            
            // Если IP не изменился - ничего не делаем
            if ($newIp === $currentIp) {
                error_log("[VM IP Updater] IP for VM {$vmid} unchanged");
                return;
            }
            
            // Если не удалось получить новый IP, но текущий IP есть - оставляем текущий
            if ($newIp === null && $currentIp !== null) {
                error_log("[VM IP Updater] Failed to get new IP for VM {$vmid}, keeping current IP");
                return;
            }
            
            // Обновляем IP в базе данных
            $this->updateVmIpInDatabase($vmid, $newIp);
            error_log("[VM IP Updater] Updated IP for VM {$vmid} from '{$currentIp}' to '{$newIp}'");
            
        } catch (Exception $e) {
            error_log("[VM IP Updater] VM error: " . $e->getMessage());
            // В случае ошибки только устанавливаем NULL если текущий IP не NULL
            if ($currentIp !== null) {
                $this->setVmIpToNull($vmid);
            }
        }
    }
    
    private function processLxcContainer($proxmox, $vmid, $currentIp) {
        try {
            error_log("[VM IP Updater] Processing LXC container {$vmid}");
            
            $status = $proxmox->getLxcStatus($vmid);
            
            // Если контейнер не запущен и текущий IP уже NULL - ничего не делаем
            if ($status['status'] !== 'running') {
                if ($currentIp === null) {
                    error_log("[VM IP Updater] LXC {$vmid} is stopped and IP is already NULL");
                    return;
                }
                error_log("[VM IP Updater] LXC {$vmid} is not running, setting IP to NULL");
                $this->setVmIpToNull($vmid);
                return;
            }
            
            // Получаем IP для LXC контейнера
            $newIp = $this->getLxcIp($proxmox, $vmid);
            
            // Если не удалось получить IP и текущий IP уже NULL - ничего не делаем
            if ($newIp === null && $currentIp === null) {
                error_log("[VM IP Updater] Failed to get IP for LXC {$vmid}, IP is already NULL");
                return;
            }
            
            // Если IP не изменился - ничего не делаем
            if ($newIp === $currentIp) {
                error_log("[VM IP Updater] IP for LXC {$vmid} unchanged");
                return;
            }
            
            // Если не удалось получить новый IP, но текущий IP есть - оставляем текущий
            if ($newIp === null && $currentIp !== null) {
                error_log("[VM IP Updater] Failed to get new IP for LXC {$vmid}, keeping current IP");
                return;
            }
            
            // Обновляем IP в базе данных
            $this->updateVmIpInDatabase($vmid, $newIp);
            error_log("[VM IP Updater] Updated IP for LXC {$vmid} from '{$currentIp}' to '{$newIp}'");
            
        } catch (Exception $e) {
            error_log("[VM IP Updater] LXC error: " . $e->getMessage());
            // В случае ошибки только устанавливаем NULL если текущий IP не NULL
            if ($currentIp !== null) {
                $this->setVmIpToNull($vmid);
            }
        }
    }
    
    private function getLxcIp($proxmox, $vmid) {
    try {
        // Основной метод получения IP для LXC
        $command = "ip -4 -o addr show";
        $output = $proxmox->executeLxcCommand($vmid, $command);
        
        $ips = [];
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (strpos($line, 'lo') !== false) continue;
            if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                $ips[] = $matches[1];
            }
        }
        
        if (!empty($ips)) {
            return implode(', ', array_unique($ips));
        }
        
        // Fallback методы для LXC
        $fallbackCommands = [
            "hostname -I",
            "cat /etc/network/interfaces",
            "ip addr | grep 'inet ' | grep -v '127.0.0.1'"
        ];
        
        foreach ($fallbackCommands as $cmd) {
            try {
                $output = $proxmox->executeLxcCommand($vmid, $cmd);
                $ip = $this->parseSingleIpFromOutput($output);
                if ($ip) return $ip;
            } catch (Exception $e) {
                error_log("[VM IP Updater] LXC fallback command failed: " . $e->getMessage());
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("[VM IP Updater] LXC IP detection failed: " . $e->getMessage());
        return null;
    }
}
    
    private function getLinuxIp($proxmox, $vmid) {
        try {
            // Ваш оригинальный метод для Linux
            $command = "qm guest exec {$vmid} -- ip -4 -o addr show";
            $output = $proxmox->executeGuestCommand($vmid, $command);
            
            $ips = [];
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if (strpos($line, 'lo') !== false) continue;
                if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                    $ips[] = $matches[1];
                }
            }
            
            if (!empty($ips)) {
                return implode(', ', array_unique($ips));
            }
            
            // Fallback методы, если основной не сработал
            $fallbackCommands = [
                "qm guest exec {$vmid} -- hostname -I",
                "qm guest exec {$vmid} -- cat /etc/network/interfaces"
            ];
            
            foreach ($fallbackCommands as $cmd) {
                try {
                    $output = $proxmox->executeGuestCommand($vmid, $cmd);
                    $ip = $this->parseSingleIpFromOutput($output);
                    if ($ip) return $ip;
                } catch (Exception $e) {
                    error_log("[VM IP Updater] Linux fallback command failed: " . $e->getMessage());
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("[VM IP Updater] Linux IP detection failed: " . $e->getMessage());
            return null;
        }
    }
    
    private function getWindowsIp($proxmox, $vmid) {
        try {
            // Ваш оригинальный метод для Windows
            $command = "qm guest exec {$vmid} -- powershell -Command \"Get-NetIPAddress | Where-Object { \$_.AddressFamily -eq 'IPv4' -and \$_.InterfaceAlias -notlike '*Loopback*' } | Select-Object -ExpandProperty IPAddress\"";
            $output = $proxmox->executeGuestCommand($vmid, $command);
            
            $ips = $this->parseMultipleIpsFromOutput($output);
            if (!empty($ips)) {
                return implode(', ', $ips);
            }
            
            // Fallback методы для Windows
            $fallbackCommands = [
                "qm guest exec {$vmid} -- ipconfig | findstr IPv4",
                "qm guest exec {$vmid} -- ipconfig | findstr Адрес"
            ];
            
            foreach ($fallbackCommands as $cmd) {
                try {
                    $output = $proxmox->executeGuestCommand($vmid, $cmd);
                    $ips = $this->parseMultipleIpsFromOutput($output);
                    if (!empty($ips)) {
                        return implode(', ', $ips);
                    }
                } catch (Exception $e) {
                    error_log("[VM IP Updater] Windows fallback command failed: " . $e->getMessage());
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("[VM IP Updater] Windows IP detection failed: " . $e->getMessage());
            return null;
        }
    }
    
    private function parseSingleIpFromOutput($output) {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    private function parseMultipleIpsFromOutput($output) {
        $ips = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (preg_match_all('/(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                $ips = array_merge($ips, $matches[1]);
            }
        }
        
        return array_unique($ips);
    }
    
    private function isWindowsVM($config) {
        if (isset($config['ostype'])) {
            return strtolower($config['ostype']) === 'windows';
        }
        return false;
    }
    
    private function setVmIpToNull($vmid) {
        $stmt = $this->pdo->prepare("UPDATE vms SET ip_address = NULL WHERE vm_id = ?");
        $stmt->execute([$vmid]);
    }
    
    private function updateVmIpInDatabase($vmid, $ip) {
        $stmt = $this->pdo->prepare("UPDATE vms SET ip_address = ? WHERE vm_id = ?");
        $stmt->execute([$ip, $vmid]);
    }
}

// Основной скрипт
try {
    $db = new Database();
    $updater = new VmIpUpdater($db);
    $result = $updater->updateAllVmIps();
    
    if (!$result['success']) {
        error_log("[MAIN] Error: " . ($result['error'] ?? 'Unknown error'));
        exit(1);
    }
    
    exit(0);
} catch (Exception $e) {
    error_log("[MAIN] Critical error: " . $e->getMessage());
    exit(1);
}