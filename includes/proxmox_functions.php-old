<?php
require_once __DIR__ . '/ssh_functions.php';
require_once __DIR__ . '/ProxmoxAuth.php';

class ProxmoxAPI {
    private $host;
    private $username;
    private $password;
    private $port;
    private $nodeName;
    private $ssh;
    private $db;
    private $nodeId;
    private $apiPort;
    private $pdo;
    
    public function __construct($host, $username, $password, $port = 22, $nodeName = null, $nodeId = null, $db = null) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = (int)$port;
        $this->nodeName = $nodeName ?: gethostname();
        $this->nodeId = $nodeId;
        $this->db = $db;
        $this->apiPort = 8006;
        $this->connect();

        // Инициализация PDO
        if ($db instanceof PDO) {
            $this->pdo = $db;
        } elseif ($db instanceof Database) {
            $this->pdo = $db->getConnection();
        } else {
            throw new Exception("Database connection not provided");
        }
    }
    
    private function connect() {
        if (!function_exists('ssh2_connect')) {
            throw new Exception("SSH2 расширение не установлено в PHP");
        }
        
        $this->ssh = @ssh2_connect($this->host, $this->port);
        if (!$this->ssh) {
            throw new Exception("Не удалось подключиться к {$this->host}:{$this->port}");
        }
        
        if (!@ssh2_auth_password($this->ssh, $this->username, $this->password)) {
            throw new Exception("Ошибка аутентификации для пользователя {$this->username}");
        }
    }

    public function createVM($params) {
    $this->pdo->beginTransaction();
    
    try {
        // 1. Получаем уникальный ID для VM
        $vmid = $this->getClusterNextID();
        error_log("[VM Creation] Starting creation of VM ID: {$vmid}");

        // 2. Проверка существования VM
        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM vms WHERE vm_id = ?");
        $checkStmt->execute([$vmid]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception("VM with ID {$vmid} already exists in database");
        }

        // 3. Подготовка параметров
        $nodeId = $this->nodeId;
        $isWindows = ($params['os_type'] ?? 'linux') === 'windows';
        $hasIso = !empty($params['iso']);
        $storage = $params['storage'] ?? 'local';
        $network = $params['network'] ?? 'vmbr0';
        $sdn = $params['sdn'] ?? null;
        $isCustom = $params['is_custom'] ?? 0;
        $osVersion = $params['os_version'] ?? '';
        $storageForIso = 'local'; // Для ISO всегда используем хранилище 'local'
        
        // Обработка SDN сети
        $networkValue = !empty($sdn) ? (strpos($sdn, '/') !== false ? explode('/', $sdn)[1] : $sdn) : $network;
        $sdn = !empty($sdn) ? $sdn : null;

        // 4. Создаем базовую VM
        $createCmd = sprintf(
            "qm create %d --name %s --cores %d --memory %d --machine q35 --bios ovmf --agent 1 --onboot 1 --scsihw %s",
            $vmid,
            escapeshellarg($params['hostname'] ?? 'vm-' . $vmid),
            $params['cpu'] ?? 1,
            $params['ram'] ?? 1024,
            $params['scsihw'] ?? 'virtio-scsi-pci'
        );
        error_log("[VM Creation] Creating base VM: {$createCmd}");
        $this->execSSHCommand($createCmd);

        // 5. Настройка дисков
        $diskSize = $params['disk'] ?? '10';
        $diskCmd = "qm set {$vmid} --scsi0 {$storage}:{$diskSize},format=raw,ssd=1,discard=on,cache=writeback";
        error_log("[VM Creation] Setting main disk: {$diskCmd}");
        $this->execSSHCommand($diskCmd);

        // EFI диск
        $efiCmd = "qm set {$vmid} --efidisk0 {$storage}:1,format=raw,pre-enrolled-keys=0";
        error_log("[VM Creation] Setting EFI disk: {$efiCmd}");
        $this->execSSHCommand($efiCmd);

        // 6. Настройка CD-ROM (исправленная версия)
        $cdroms = $params['cdroms'] ?? [];
        
        // Для Windows VM добавляем обязательный virtio-win.iso
        if ($isWindows) {
            $virtioIso = "{$storageForIso}:iso/virtio-win.iso";
            if (!in_array($virtioIso, $cdroms)) {
                array_unshift($cdroms, $virtioIso);
            }
            
            // Проверяем существование virtio-win.iso
            $checkCmd = "test -f /var/lib/vz/template/iso/virtio-win.iso";
            try {
                $this->execSSHCommand($checkCmd);
            } catch (Exception $e) {
                throw new Exception("Обязательный файл virtio-win.iso не найден в /var/lib/vz/template/iso/");
            }
        }

        // Монтируем все CD-ROM из списка
        $cdromIndex = 2; // Начинаем с ide2
        $mountedCdroms = [];
        foreach ($cdroms as $cdrom) {
            // Форматируем путь к ISO правильно
            if (!preg_match('/^[a-z0-9_-]+:iso\//i', $cdrom)) {
                $cdrom = "{$storageForIso}:iso/" . ltrim($cdrom, '/');
            }
            
            // Извлекаем имя файла для проверки
            $isoFile = preg_replace('/^[^:]+:iso\//', '', $cdrom);
            
            // Проверяем существование ISO
            $checkCmd = "test -f /var/lib/vz/template/iso/{$isoFile}";
            try {
                $this->execSSHCommand($checkCmd);
            } catch (Exception $e) {
                if (strpos($cdrom, 'virtio-win.iso') !== false) {
                    throw new Exception("Файл ISO не найден: {$isoFile}");
                }
                error_log("[VM Creation] Warning: ISO file not found - {$isoFile}");
                continue;
            }
            
            // Монтируем ISO
            $isoCmd = "qm set {$vmid} --ide{$cdromIndex} {$cdrom},media=cdrom";
            error_log("[VM Creation] Setting CD-ROM {$cdromIndex}: {$isoCmd}");
            
            try {
                $this->execSSHCommand($isoCmd);
                $mountedCdroms[] = $cdromIndex;
                $cdromIndex++;
                
                if ($cdromIndex > 5) { // Максимум 4 CD-ROM (ide2-ide5)
                    error_log("[VM Creation] Warning: Maximum CD-ROM devices reached");
                    break;
                }
            } catch (Exception $e) {
                if (strpos($cdrom, 'virtio-win.iso') !== false) {
                    throw new Exception("Не удалось монтировать virtio-win.iso: " . $e->getMessage());
                }
                error_log("[VM Creation] Warning: Failed to mount CD-ROM: " . $e->getMessage());
            }
        }

        // 7. Настройка порядка загрузки
        $bootOrder = $hasIso ? "order='ide2;scsi0'" : "order='scsi0'";
        $bootCmd = "qm set {$vmid} --boot {$bootOrder}";
        error_log("[VM Creation] Setting boot order: {$bootCmd}");
        $this->execSSHCommand($bootCmd);

        // 8. Настройка сети
        $netCmd = "qm set {$vmid} --net0 virtio,bridge=" . escapeshellarg($networkValue);
        error_log("[VM Creation] Setting network: {$netCmd}");
        $this->execSSHCommand($netCmd);

        // 9. Дополнительные параметры для Windows
        /*if ($isWindows) {
            $winCmd1 = "qm set {$vmid} --cpu kvm64,flags=+aes";
            $winCmd2 = "qm set {$vmid} --tablet usb-tablet";
            error_log("[VM Creation] Setting Windows params: {$winCmd1} && {$winCmd2}");
            $this->execSSHCommand($winCmd1);
            $this->execSSHCommand($winCmd2);
        }*/

        // 10. Cloud-init для Linux
        if (!$isWindows && !empty($params['password'])) {
            $ciCmd = "qm set {$vmid} --cipassword " . escapeshellarg($params['password']) . " --ciuser root";
            $this->execSSHCommand($ciCmd);
        }

        // 11. Сохранение в базу данных
        $status = $hasIso ? 'stopped' : 'running';
        $stmt = $this->pdo->prepare("
            INSERT INTO vms 
            (user_id, vm_id, node_id, tariff_id, hostname, cpu, ram, disk, 
             network, sdn, storage, os_type, os_version, status, created_at, is_custom) 
            VALUES (:user_id, :vm_id, :node_id, :tariff_id, :hostname, :cpu, :ram, :disk, 
                    :network, :sdn, :storage, :os_type, :os_version, :status, NOW(), :is_custom)
        ");
        
        $stmt->execute([
            ':user_id' => $params['user_id'] ?? $_SESSION['user']['id'],
            ':vm_id' => $vmid,
            ':node_id' => $nodeId,
            ':tariff_id' => $params['tariff_id'],
            ':hostname' => $params['hostname'] ?? 'vm-' . $vmid,
            ':cpu' => $params['cpu'] ?? 1,
            ':ram' => $params['ram'] ?? 1024,
            ':disk' => $params['disk'] ?? 10,
            ':network' => $networkValue,
            ':sdn' => $sdn,
            ':storage' => $storage,
            ':os_type' => $params['os_type'] ?? 'linux',
            ':os_version' => $osVersion,
            ':status' => $status,
            ':is_custom' => $isCustom ? 1 : 0
        ]);

        // 12. Создаем запись о биллинге для кастомных тарифов
        if ($isCustom) {
            $this->createBillingRecord($vmid, $params);
        }

        // 13. Запуск VM если не указан ISO
        if (!$hasIso) {
            $this->execSSHCommand("qm start {$vmid}");
        }

        $this->pdo->commit();
        error_log("[VM Creation] VM {$vmid} created successfully");
        
        return [
            'vmid' => $vmid,
            'status' => $status,
            'node_name' => $this->nodeName,
            'hostname' => $params['hostname'] ?? 'vm-' . $vmid
        ];

    } catch (Exception $e) {
        $this->pdo->rollBack();
        
        if (isset($vmid)) {
            try {
                $this->execSSHCommand("qm destroy {$vmid} --purge");
            } catch (Exception $cleanupError) {
                error_log("[VM Creation] Cleanup error: " . $cleanupError->getMessage());
            }
        }
        
        error_log("[VM Creation] Error: " . $e->getMessage());
        throw new Exception("Failed to create VM: " . $e->getMessage());
    }
}

public function getLXCTemplates() {
    try {
        $command = "pvesh get /nodes/{$this->nodeName}/storage --output-format json";
        $response = $this->execSSHCommand($command);
        $storages = json_decode($response, true);
        
        if (empty($storages)) {
            throw new Exception("Не удалось получить список хранилищ");
        }
        
        $templates = [];
        foreach ($storages as $storage) {
            if (isset($storage['active']) && $storage['active'] == 1 && 
                isset($storage['content']) && strpos($storage['content'], 'vztmpl') !== false) {
                
                try {
                    $templateResponse = $this->execSSHCommand(
                        "pvesh get /nodes/{$this->nodeName}/storage/{$storage['storage']}/content --output-format json"
                    );
                    $contents = json_decode($templateResponse, true);
                    
                    if (isset($contents) && is_array($contents)) {
                        foreach ($contents as $file) {
                            if (isset($file['content']) && $file['content'] == 'vztmpl') {
                                $templates[] = [
                                    'storage' => $storage['storage'],
                                    'volid' => $file['volid'],
                                    'name' => isset($file['volid']) ? basename($file['volid']) : 'unknown',
                                    'size' => isset($file['size']) ? $this->formatFileSize($file['size']) : 'unknown'
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error getting templates from storage {$storage['storage']}: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        // Сортируем шаблоны по имени
        usort($templates, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $templates;
    } catch (Exception $e) {
        error_log("Error getting LXC templates: " . $e->getMessage());
        return [];
    }
}

public function createLXC($params) {
    $this->pdo->beginTransaction();
    
    try {
        // Получение уникального ID
        $vmid = $this->getClusterNextID();
        error_log("[LXC Creation] Starting creation of container ID: {$vmid}");

        // Проверка существования контейнера
        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM vms WHERE vm_id = ?");
        $checkStmt->execute([$vmid]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception("Container with ID {$vmid} already exists in database");
        }

        // Подготовка параметров
        $hostname = $params['hostname'] ?? 'lxc-' . $vmid;
        $password = $params['password'] ?? '';
        $storage = $params['storage'] ?? 'local';
        $template = $params['template'] ?? '';
        $nodeId = $this->nodeId;

        if (empty($template)) {
            throw new Exception("LXC template not specified");
        }

        // Создание контейнера (без сети)
        $createCmd = "pct create {$vmid} " . escapeshellarg($template) . " " .
                    "--storage {$storage} " .
                    "--hostname " . escapeshellarg($hostname) . " " .
                    "--cores " . ($params['cpu'] ?? 1) . " " .
                    "--memory " . ($params['ram'] ?? 1024) . " " .
                    "--rootfs " . ($params['disk'] ?? 10) . " " .
                    "--password " . escapeshellarg($password) . " " .
                    "--unprivileged 0 " .
                    "--onboot 1 " .
                    "--features nesting=1";

        error_log("[LXC Creation] Creating container: {$createCmd}");
        $this->execSSHCommand($createCmd);

        // Настройка сети
        $network = $params['network'] ?? 'vmbr0';
        $sdn = $params['sdn'] ?? null;
        
        if (!empty($sdn)) {
            $sdnParts = explode('/', $sdn);
            $vnet = end($sdnParts);
            
            if (empty($vnet)) {
                throw new Exception("Invalid SDN format. Expected 'ZoneName/VNetName'");
            }
            
            $netString = "name=eth0,bridge={$vnet},ip=dhcp,firewall=1";
            
            /*if (stripos($vnet, 'vlan') !== false) {
                $netString .= ",tag=1";
            }*/
        } else {
            $netString = "name=eth0,bridge={$network},ip=dhcp,firewall=1";
        }
        
        $netCmd = "pct set {$vmid} --net0 '{$netString}'";
        error_log("[LXC Creation] Setting network: {$netCmd}");
        $this->execSSHCommand($netCmd);

        // Настройка DNS серверов (Яндекс DNS)
        $dnsServers = "77.88.8.88 77.88.8.2";
        $dnsCmd = "pct set {$vmid} --nameserver '{$dnsServers}'";
        error_log("[LXC Creation] Setting DNS: {$dnsCmd}");
        $this->execSSHCommand($dnsCmd);

        // Альтернативный вариант - через конфигурационный файл
        // $this->execSSHCommand("pct exec {$vmid} -- bash -c 'echo \"nameserver 77.88.8.88\" > /etc/resolv.conf'");
        // $this->execSSHCommand("pct exec {$vmid} -- bash -c 'echo \"nameserver 77.88.8.2\" >> /etc/resolv.conf'");

        // Запуск контейнера
        error_log("[LXC Creation] Starting container {$vmid}");
        $this->execSSHCommand("pct start {$vmid}");

        // Сохранение в базу данных
        $stmt = $this->pdo->prepare("
            INSERT INTO vms 
            (user_id, vm_id, node_id, tariff_id, hostname, cpu, ram, disk, 
             network, sdn, storage, os_type, vm_type, status, created_at, is_custom) 
            VALUES (:user_id, :vm_id, :node_id, :tariff_id, :hostname, :cpu, :ram, :disk, 
                    :network, :sdn, :storage, :os_type, 'lxc', 'running', NOW(), :is_custom)
        ");
        
        $stmt->execute([
            ':user_id' => $params['user_id'] ?? $_SESSION['user']['id'],
            ':vm_id' => $vmid,
            ':node_id' => $nodeId,
            ':tariff_id' => $params['tariff_id'],
            ':hostname' => $hostname,
            ':cpu' => $params['cpu'] ?? 1,
            ':ram' => $params['ram'] ?? 1024,
            ':disk' => $params['disk'] ?? 10,
            ':network' => !empty($sdn) ? $vnet : $network,
            ':sdn' => $sdn,
            ':storage' => $storage,
            ':os_type' => 'linux',
            ':is_custom' => $params['is_custom'] ?? 0
        ]);

        if ($params['is_custom'] ?? 0) {
            $this->createBillingRecord($vmid, $params);
        }

        $this->pdo->commit();
        error_log("[LXC Creation] Container {$vmid} created successfully");
        
        return [
            'vmid' => $vmid,
            'status' => 'running',
            'node_name' => $this->nodeName,
            'hostname' => $hostname
        ];

    } catch (Exception $e) {
        $this->pdo->rollBack();
        
        if (isset($vmid)) {
            try {
                error_log("[LXC Creation] Cleanup failed container {$vmid}");
                $this->execSSHCommand("pct destroy {$vmid}");
            } catch (Exception $cleanupError) {
                error_log("[LXC Creation] Cleanup error: " . $cleanupError->getMessage());
            }
        }
        
        error_log("[LXC Creation] Error: " . $e->getMessage());
        throw new Exception("Failed to create LXC container: " . $e->getMessage());
    }
}

    /**
 * Приостанавливает виртуальную машину при недостатке средств
 * @param int $vmid ID виртуальной машины
 * @param string $reason Причина приостановки
 * @return bool Возвращает true при успешной приостановке
 */
public function suspendVM($vmid, $reason = 'Недостаточно средств на балансе') {
    try {
        // 1. Проверяем, является ли пользователь администратором
        $userStmt = $this->pdo->prepare("
            SELECT u.is_admin 
            FROM vms v
            JOIN users u ON u.id = v.user_id
            WHERE v.vm_id = ?
        ");
        $userStmt->execute([$vmid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Не приостанавливаем ВМ администраторов
        if ($user && $user['is_admin'] == 1) {
            error_log("VM #{$vmid} belongs to admin - skipping suspend");
            return true;
        }

        // 2. Останавливаем виртуальную машину
        $this->stopVM($vmid, true); // force stop
        
        // 3. Обновляем статус в базе данных
        $stmt = $this->pdo->prepare("
            UPDATE vms 
            SET status = 'suspended', 
                suspended_at = NOW(), 
                suspend_reason = ?
            WHERE vm_id = ?
        ");
        $stmt->execute([$reason, $vmid]);
        
        // 4. Создаем уведомление для пользователя
        $userStmt = $this->pdo->prepare("
            SELECT user_id FROM vms WHERE vm_id = ?
        ");
        $userStmt->execute([$vmid]);
        $userId = $userStmt->fetchColumn();
        
        if ($userId) {
            $notificationStmt = $this->pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, is_read) 
                VALUES (?, ?, ?, 0)
            ");
            $notificationStmt->execute([
                $userId,
                "Виртуальная машина #{$vmid} приостановлена",
                "Ваша виртуальная машина #{$vmid} была приостановлена по причине: {$reason}. Пожалуйста, пополните баланс."
            ]);
        }
        
        error_log("VM #{$vmid} suspended successfully. Reason: {$reason}");
        return true;
        
    } catch (Exception $e) {
        error_log("Error suspending VM #{$vmid}: " . $e->getMessage());
        return false;
    }
}

    /**
     * Возобновляет работу виртуальной машины
     */
    public function unsuspendVM($vmid) {
        try {
            // 1. Проверяем баланс перед возобновлением
            $vmStmt = $this->pdo->prepare("
                SELECT v.*, u.balance, u.bonus_balance, t.is_custom, t.price as tariff_price
                FROM vms v
                JOIN users u ON u.id = v.user_id
                LEFT JOIN tariffs t ON t.id = v.tariff_id
                WHERE v.vm_id = ?
            ");
            $vmStmt->execute([$vmid]);
            $vm = $vmStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vm) {
                throw new Exception("VM not found");
            }
            
            // Получаем текущие цены на ресурсы
            $pricesStmt = $this->pdo->query("
                SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1
            ");
            $prices = $pricesStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'price_per_hour_cpu' => 0.001000,
                'price_per_hour_ram' => 0.000010,
                'price_per_hour_disk' => 0.000050
            ];
            
            // Рассчитываем стоимость
            if ($vm['is_custom']) {
                $cost = ($vm['cpu'] * $prices['price_per_hour_cpu']) +
                       ($vm['ram'] * $prices['price_per_hour_ram']) +
                       ($vm['disk'] * $prices['price_per_hour_disk']);
            } else {
                $cost = $vm['tariff_price'] / 30;
            }
            
            $totalBalance = $vm['balance'] + $vm['bonus_balance'];
            
            if ($totalBalance < $cost) {
                throw new Exception("Недостаточно средств для возобновления работы VM");
            }
            
            // 2. Запускаем виртуальную машину
            $this->startVM($vmid);
            
            // 3. Обновляем статус в базе данных
            $stmt = $this->pdo->prepare("
                UPDATE vms 
                SET status = 'running', 
                    suspended_at = NULL, 
                    suspend_reason = NULL
                WHERE vm_id = ?
            ");
            $stmt->execute([$vmid]);
            
            // 4. Создаем уведомление для пользователя
            $notificationStmt = $this->pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, is_read) 
                VALUES (?, ?, ?, 0)
            ");
            $notificationStmt->execute([
                $vm['user_id'],
                "Виртуальная машина #{$vmid} возобновлена",
                "Ваша виртуальная машина #{$vmid} была успешно возобновлена."
            ]);
            
            error_log("VM #{$vmid} unsuspended successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("Error unsuspending VM #{$vmid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверяет баланс и при необходимости приостанавливает ВМ
     */
    public function checkBalanceAndSuspendIfNeeded($vmid) {
        try {
            // 1. Получаем информацию о ВМ и пользователе
            $vmStmt = $this->pdo->prepare("
                SELECT v.*, u.balance, u.bonus_balance, u.is_admin, 
                       t.is_custom, t.price as tariff_price
                FROM vms v
                JOIN users u ON u.id = v.user_id
                LEFT JOIN tariffs t ON t.id = v.tariff_id
                WHERE v.vm_id = ?
            ");
            $vmStmt->execute([$vmid]);
            $vm = $vmStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vm) {
                throw new Exception("VM not found");
            }

            // Если пользователь администратор - пропускаем проверку баланса
            if ($vm['is_admin'] == 1) {
                return true;
            }
            
            // 2. Получаем текущие цены на ресурсы
            $pricesStmt = $this->pdo->query("
                SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1
            ");
            $prices = $pricesStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'price_per_hour_cpu' => 0.001000,
                'price_per_hour_ram' => 0.000010,
                'price_per_hour_disk' => 0.000050
            ];
            
            // 3. Рассчитываем стоимость
            if ($vm['is_custom']) {
                // Почасовая стоимость для кастомных тарифов
                $cost = ($vm['cpu'] * $prices['price_per_hour_cpu']) +
                       ($vm['ram'] * $prices['price_per_hour_ram']) +
                       ($vm['disk'] * $prices['price_per_hour_disk']);
            } else {
                // Дневная стоимость для готовых тарифов (цена/30 дней)
                $cost = $vm['tariff_price'] / 30;
            }
            
            $cost = round($cost, 6);
            
            // 4. Проверяем баланс
            $totalBalance = $vm['balance'] + $vm['bonus_balance'];
            
            if ($totalBalance < $cost) {
                // Недостаточно средств - приостанавливаем ВМ
                $this->suspendVM($vmid, "Недостаточно средств на балансе. Требуется: " . number_format($cost, 6) . " ₽");
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in checkBalanceAndSuspendIfNeeded for VM #{$vmid}: " . $e->getMessage());
            return false;
        }
    }

    private function createBillingRecord($vmid, $params) {
        try {
            // Получаем цены за ресурсы из базы данных
            $stmt = $this->pdo->prepare("
                SELECT price_per_hour_cpu, price_per_hour_ram, price_per_hour_disk 
                FROM resource_prices 
                ORDER BY updated_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $prices = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prices) {
                $prices = [
                    'price_per_hour_cpu' => 0.001000,
                    'price_per_hour_ram' => 0.000010,
                    'price_per_hour_disk' => 0.000050
                ];
            }

            // Рассчитываем стоимость в час
            $cpuCost = $params['cpu'] * $prices['price_per_hour_cpu'];
            $ramCost = $params['ram'] * $prices['price_per_hour_ram'];
            $diskCost = $params['disk'] * $prices['price_per_hour_disk'];
            $totalPerHour = $cpuCost + $ramCost + $diskCost;

            // Создаем запись о биллинге
            $stmt = $this->pdo->prepare("
                INSERT INTO vm_billing 
                (vm_id, user_id, cpu, ram, disk, price_per_hour_cpu, price_per_hour_ram, 
                 price_per_hour_disk, total_per_hour, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $vmid,
                $params['user_id'] ?? $_SESSION['user']['id'],
                $params['cpu'],
                $params['ram'],
                $params['disk'],
                $prices['price_per_hour_cpu'],
                $prices['price_per_hour_ram'],
                $prices['price_per_hour_disk'],
                $totalPerHour
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Error creating billing record: " . $e->getMessage());
            throw $e;
        }
    }

    public function getVirtualMachines() {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/qemu --output-format json";
            $response = $this->execSSHCommand($command);
            
            if (empty($response)) {
                throw new Exception("Пустой ответ от сервера через SSH");
            }
            
            if (strpos($response, '<!DOCTYPE html>') !== false) {
                throw new Exception("Получен HTML-ответ вместо JSON");
            }
            
            return $this->parseVMResponse($response);
        } catch (Exception $e) {
            error_log("SSH method failed: " . $e->getMessage());
            return $this->getVirtualMachinesAPI();
        }
    }
    
    private function getVirtualMachinesAPI() {
        if (!$this->db || !$this->nodeId) {
            throw new Exception("Для API метода требуется подключение к БД и ID ноды");
        }

        $auth = new ProxmoxAuth($this->db, $this->nodeId, $this->host, $this->username, $this->password);
        $ticket = $auth->getTicket();
        
        $url = "https://{$this->host}:{$this->apiPort}/api2/json/nodes/{$this->nodeName}/qemu";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Cookie: PVEAuthCookie=" . urlencode($ticket['ticket']),
                "CSRFPreventionToken: " . $ticket['csrf_token']
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Ошибка API запроса: " . $error);
        }
        
        $data = json_decode($response, true);
        if (empty($data['data'])) {
            throw new Exception("Пустой ответ API");
        }
        
        return $this->parseVMResponse(json_encode($data['data']));
    }
    
    public function getVNCConsole($vmid) {
        try {
            $vmid = (int)$vmid;
            if ($vmid <= 0) {
                throw new Exception("Неверный ID виртуальной машины");
            }

            if (empty($this->nodeName)) {
                throw new Exception("Имя ноды не указано");
            }

            // Всегда используем API метод, если есть доступ к БД
            if ($this->db && $this->nodeId) {
                $auth = new ProxmoxAuth(
                    $this->db,
                    $this->nodeId,
                    $this->host,
                    $this->username,
                    $this->password
                );

                $vncData = $auth->getVncProxy($vmid, $this->nodeName);
                
                return [
                    'hostname' => $this->host,
                    'port' => $vncData['port'],
                    'ticket' => $vncData['ticket'],
                    'vmid' => $vmid,
                    'node' => $this->nodeName,
                    'apiPort' => $this->apiPort
                ];
            }

            // Fallback на SSH метод (только если нет доступа к API)
            $command = "pvesh create /nodes/{$this->nodeName}/qemu/{$vmid}/vncproxy --websocket 1 --output-format json";
            $response = $this->execSSHCommand($command);

            $data = json_decode($response, true);
            if (empty($data['port']) || empty($data['ticket'])) {
                throw new Exception("Неполные данные VNC");
            }

            return [
                'hostname' => $this->host,
                'port' => (int)$data['port'],
                'ticket' => $data['ticket'],
                'vmid' => $vmid,
                'node' => $this->nodeName,
                'apiPort' => $this->apiPort
            ];

        } catch (Exception $e) {
            error_log("Ошибка в getVNCConsole() для ноды {$this->host}: " . $e->getMessage());
            throw $e;
        }
    }

    public function getLxcStatus($vmid) {
    try {
        // Формируем команду для получения статуса контейнера
        $command = "pvesh get /nodes/{$this->nodeName}/lxc/{$vmid}/status/current --output-format json";
        
        // Выполняем команду через SSH
        $response = $this->execSSHCommand($command);
        
        // Парсим JSON ответ
        $statusData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Ошибка парсинга JSON: " . json_last_error_msg());
        }
        
        if (empty($statusData)) {
            throw new Exception("Пустой ответ от сервера");
        }
        
        // Формируем результат
        $result = [
            'status' => $statusData['status'] ?? 'unknown',
            'vmid' => $vmid,
            'uptime' => $statusData['uptime'] ?? 0,
            'mem' => $statusData['mem'] ?? 0,
            'maxmem' => $statusData['maxmem'] ?? 0,
            'disk' => $statusData['disk'] ?? 0,
            'maxdisk' => $statusData['maxdisk'] ?? 0,
            'cpu' => $statusData['cpu'] ?? 0,
            'is_running' => ($statusData['status'] ?? '') === 'running'
        ];
        
        // Конвертируем байты в мегабайты для памяти
        if (isset($result['mem'])) {
            $result['mem'] = round($result['mem'] / (1024 * 1024), 2);
        }
        if (isset($result['maxmem'])) {
            $result['maxmem'] = round($result['maxmem'] / (1024 * 1024), 2);
        }
        
        // Конвертируем байты в гигабайты для диска
        if (isset($result['disk'])) {
            $result['disk'] = round($result['disk'] / (1024 * 1024 * 1024), 2);
        }
        if (isset($result['maxdisk'])) {
            $result['maxdisk'] = round($result['maxdisk'] / (1024 * 1024 * 1024), 2);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[ProxmoxAPI] Error getting LXC status for {$vmid}: " . $e->getMessage());
        
        // Если контейнер не существует, возвращаем статус 'not exist'
        if (strpos($e->getMessage(), 'does not exist') !== false) {
            return [
                'status' => 'not exist',
                'vmid' => $vmid,
                'is_running' => false
            ];
        }
        
        throw new Exception("Failed to get LXC container status: " . $e->getMessage());
    }
}

    public function startVM($vmid) {
    try {
        // Сначала проверяем статус VM
        $status = $this->getVMStatus($vmid);
        if ($status['status'] === 'running') {
            return ['success' => true, 'status' => 'running'];
        }

        // Получаем информацию о пользователе
        $userStmt = $this->pdo->prepare("
            SELECT u.is_admin FROM vms v
            JOIN users u ON u.id = v.user_id
            WHERE v.vm_id = ?
        ");
        $userStmt->execute([$vmid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Проверяем баланс только для обычных пользователей (is_admin = 0)
        if ($user && $user['is_admin'] == 0) {
            if (!$this->checkBalanceAndSuspendIfNeeded($vmid)) {
                throw new Exception("Недостаточно средств на балансе для запуска VM");
            }
        }
        
        $command = "pvesh create /nodes/{$this->nodeName}/qemu/{$vmid}/status/start";
        $output = $this->execSSHCommand($command);
        
        // Обновляем статус в базе данных
        $stmt = $this->pdo->prepare("
            UPDATE vms SET status = 'running' WHERE vm_id = ?
        ");
        $stmt->execute([$vmid]);
        
        return ['success' => true, 'status' => 'running'];
    } catch (Exception $e) {
        error_log("Error starting VM #{$vmid}: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
    
    public function stopVM($vmid, $force = false) {
        try {
            $forceFlag = $force ? ' --force-stop 1' : '';
            $command = "pvesh create /nodes/{$this->nodeName}/qemu/{$vmid}/status/stop{$forceFlag}";
            $output = $this->execSSHCommand($command);
            
            // Обновляем статус в базе данных
            $status = $force ? 'suspended' : 'stopped';
            $stmt = $this->pdo->prepare("
                UPDATE vms SET status = ? WHERE vm_id = ?
            ");
            $stmt->execute([$status, $vmid]);
            
            return $output;
        } catch (Exception $e) {
            error_log("Error stopping VM #{$vmid}: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function rebootVM($vmid, $force = false) {
        $forceFlag = $force ? ' --force-stop 1' : '';
        $command = "pvesh create /nodes/{$this->nodeName}/qemu/{$vmid}/status/reboot{$forceFlag}";
        return $this->execSSHCommand($command);
    }
    
    public function getVMConfig($vmid) {
        $command = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmid}/config --output-format json";
        $response = $this->execSSHCommand($command);
        return json_decode($response, true);
    }
    
    public function getVMStatus($vmid) {
        $command = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmid}/status/current --output-format json";
        $response = $this->execSSHCommand($command);
        return json_decode($response, true);
    }
    
    private function parseVMResponse($response) {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Ошибка парсинга JSON: " . json_last_error_msg());
        }
        
        $result = [];
        foreach ((array)$data as $vm) {
            $result[] = [
                'vmid' => isset($vm['vmid']) ? $vm['vmid'] : 0,
                'name' => isset($vm['name']) ? $vm['name'] : 'N/A',
                'status' => isset($vm['status']) ? $vm['status'] : 'unknown',
                'cpus' => isset($vm['cpus']) ? $vm['cpus'] : 0,
                'mem' => $this->convertToGB(isset($vm['mem']) ? $vm['mem'] : 0),
                'disk' => $this->convertToGB(isset($vm['maxdisk']) ? $vm['maxdisk'] : 0),
                'uptime' => isset($vm['uptime']) ? $vm['uptime'] : 0,
                'netin' => isset($vm['netin']) ? $vm['netin'] : 0,
                'netout' => isset($vm['netout']) ? $vm['netout'] : 0
            ];
        }
        
        return $result;
    }
    
    private function convertToGB($bytes) {
        return round($bytes / (1024 * 1024 * 1024), 2);
    }
    
    public function execSSHCommand($command) {
        $stream = @ssh2_exec($this->ssh, $command);
        if (!$stream) {
            throw new Exception("Не удалось выполнить команду: $command");
        }
        
        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        
        $output = stream_get_contents($stream);
        $error = stream_get_contents($errorStream);
        
        fclose($stream);
        fclose($errorStream);
        
        if (!empty($error)) {
            throw new Exception("Ошибка выполнения команды: " . trim($error));
        }
        
        return trim($output);
    }
    
    public function __destruct() {
        if ($this->ssh && is_resource($this->ssh)) {
            @ssh2_disconnect($this->ssh);
        }
    }

    public function getNodeResources() {
    try {
        // Получаем данные о памяти и диске через pvesh
        $pveshCommand = "pvesh get /nodes/{$this->nodeName}/status --output-format json";
        $pveshResponse = $this->execSSHCommand($pveshCommand);
        
        if (empty($pveshResponse)) {
            throw new Exception("Empty response from pvesh command");
        }
        
        $pveshData = json_decode($pveshResponse, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        
        // Получаем загрузку CPU через top
        $topCommand = "LC_ALL=C top -b -n 1 | grep '^%Cpu'";
        $topResponse = $this->execSSHCommand($topCommand);
        
        if (empty($topResponse)) {
            throw new Exception("Empty response from top command");
        }
        
        // Парсим строку с информацией о CPU
        // Пример строки: %Cpu(s):  5.3 us,  0.5 sy,  0.0 ni, 94.0 id,  0.2 wa,  0.0 hi,  0.0 si,  0.0 st
        $cpuUsage = 0;
        if (preg_match('/%Cpu\(s\):\s+([\d.]+)\s+us/', $topResponse, $matches)) {
            $cpuUsage = (float)$matches[1];
        }
        
        return [
            'memory' => $pveshData['memory']['total'] ?? 0,
            'free_memory' => $pveshData['memory']['free'] ?? 0,
            'disk' => $pveshData['rootfs']['total'] ?? 0,
            'free_disk' => $pveshData['rootfs']['free'] ?? 0,
            'cpu_usage' => $cpuUsage
        ];
    } catch (Exception $e) {
        error_log("Error getting node resources: " . $e->getMessage());
        throw new Exception("Could not get node resources: " . $e->getMessage());
    }
}

    public function getNodeStorages() {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/storage --output-format json";
            $response = $this->execSSHCommand($command);
            
            if (empty($response)) {
                throw new Exception("Empty response from node");
            }
            
            $storages = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON decode error: " . json_last_error_msg());
            }
            
            $result = [];
            foreach ($storages as $storage) {
                try {
                    // Пропускаем неактивные хранилища
                    if (isset($storage['active']) && $storage['active'] != 1) {
                        continue;
                    }
                    
                    // Включаем только хранилища, содержащие 'images' в content
                    if (!isset($storage['content']) || strpos($storage['content'], 'images') === false) {
                        continue;
                    }
                    
                    // Для всех типов хранилищ используем стандартные поля
                    $available = isset($storage['avail']) ? round($storage['avail'] / (1024 * 1024 * 1024), 2) : 0;
                    $total = isset($storage['total']) ? round($storage['total'] / (1024 * 1024 * 1024), 2) : 0;
                    
                    // Пропускаем хранилища с ошибками доступности
                    if ($available <= 0) {
                        error_log("Storage {$storage['storage']} skipped - not available (type: {$storage['type']}, available: {$available}GB)");
                        continue;
                    }
                    
                    $result[] = [
                        'name' => $storage['storage'],
                        'type' => isset($storage['type']) ? $storage['type'] : 'unknown',
                        'available' => $available,
                        'total' => $total,
                        'content' => isset($storage['content']) ? $storage['content'] : ''
                    ];
                } catch (Exception $e) {
                    error_log("Error processing storage {$storage['storage']} (type: {$storage['type']}): " . $e->getMessage());
                    continue;
                }
            }
            
            // Сортируем хранилища по имени
            usort($result, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            return $result;
        } catch (Exception $e) {
            error_log("Error getting node storages: " . $e->getMessage());
            return []; // Возвращаем пустой массив вместо исключения
        }
    }

    public function getISOImages() {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/storage --output-format json";
            $response = $this->execSSHCommand($command);
            $storages = json_decode($response, true);
            
            if (empty($storages)) {
                throw new Exception("Не удалось получить список хранилищ");
            }
            
            $isos = [];
            foreach ($storages as $storage) {
                // Проверяем активность хранилища и наличие контента 'iso'
                if (isset($storage['active']) && $storage['active'] == 1 && 
                    isset($storage['content']) && strpos($storage['content'], 'iso') !== false) {
                    
                    try {
                        $isoResponse = $this->execSSHCommand(
                            "pvesh get /nodes/{$this->nodeName}/storage/{$storage['storage']}/content --output-format json"
                        );
                        $contents = json_decode($isoResponse, true);
                        
                        if (isset($contents) && is_array($contents)) {
                            foreach ($contents as $file) {
                                // Проверяем как по content=iso, так и по расширению .iso
                                if ((isset($file['content']) && $file['content'] == 'iso') || 
                                    (isset($file['volid']) && preg_match('/\.iso$/i', $file['volid']))) {
                                    
                                    $isos[] = [
                                        'storage' => $storage['storage'],
                                        'volid' => $file['volid'],
                                        'name' => isset($file['volid']) ? basename($file['volid']) : 'unknown',
                                        'size' => isset($file['size']) ? $this->formatFileSize($file['size']) : 'unknown'
                                    ];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error getting ISO from storage {$storage['storage']}: " . $e->getMessage());
                        continue;
                    }
                }
            }
            
            // Сортируем ISO по имени
            usort($isos, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            return $isos;
        } catch (Exception $e) {
            error_log("Error getting ISO images: " . $e->getMessage());
            return [];
        }
    }

    private function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    public function getNodeNetworks() {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/network --output-format json";
            $response = $this->execSSHCommand($command);
            $networks = json_decode($response, true);
            
            if (empty($networks)) {
                return ['vmbr0'];
            }
            
            $result = [];
            foreach ($networks as $net) {
                if (isset($net['type']) && $net['type'] === 'bridge') {
                    $result[] = $net['iface'];
                }
            }
            
            return empty($result) ? ['vmbr0'] : $result;
        } catch (Exception $e) {
            error_log("Error getting node networks: " . $e->getMessage());
            return ['vmbr0'];
        }
    }

    public function getSDNNetworks() {
        try {
            // Получаем зоны SDN
            $command = "pvesh get /cluster/sdn/zones --output-format json";
            $zonesResponse = $this->execSSHCommand($command);
            $zones = json_decode($zonesResponse, true);
            
            if (empty($zones)) {
                return [];
            }
            
            // Получаем все VNets
            $vnetsResponse = $this->execSSHCommand("pvesh get /cluster/sdn/vnets --output-format json");
            $vnets = json_decode($vnetsResponse, true);
            
            $result = [];
            foreach ($zones as $zone) {
                if (!isset($zone['zone'])) continue;
                
                foreach ($vnets as $vnet) {
                    if (isset($vnet['zone']) && $vnet['zone'] == $zone['zone']) {
                        $result[] = [
                            'name' => $zone['zone'] . '/' . $vnet['vnet'],
                            'zone' => $zone['zone'],
                            'vnet' => $vnet['vnet'],
                            'alias' => isset($vnet['alias']) ? $vnet['alias'] : '',
                            'type' => isset($vnet['type']) ? $vnet['type'] : 'unknown'
                        ];
                    }
                }
            }
            
            return $result;
        } catch (Exception $e) {
            // SDN может быть не настроен
            error_log("SDN not configured or error: " . $e->getMessage());
            return [];
        }
    }

    private function getClusterNextID() {
        // Пытаемся получить ID из кластера
        $command = "pvesh get /cluster/nextid";
        $response = trim($this->execSSHCommand($command));
        
        if (empty($response) || !is_numeric($response)) {
            // Fallback для автономной ноды
            $command = "pvesh get /nodes/{$this->nodeName}/nextid";
            $response = trim($this->execSSHCommand($command));
            
            if (empty($response) || !is_numeric($response)) {
                // Последний fallback - ручной поиск
                $command = "pvesh get /cluster/resources --type vm --output-format json";
                $vms = json_decode($this->execSSHCommand($command), true);
                $maxId = 100; // Начальный минимальный ID
                
                if (is_array($vms)) {
                    foreach ($vms as $vm) {
                        if (isset($vm['vmid']) && $vm['vmid'] > $maxId) {
                            $maxId = $vm['vmid'];
                        }
                    }
                }
                $response = $maxId + 1;
            }
        }
        
        return (int)$response;
    } 

    private function getStorageInfo($storageName) {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/storage/{$storageName}/status --output-format json";
            $response = $this->execSSHCommand($command);
            return json_decode($response, true);
        } catch (Exception $e) {
            error_log("Error getting storage info for {$storageName}: " . $e->getMessage());
            return ['type' => 'unknown'];
        }
    }

    private function getStorageType($storageName) {
        try {
            $command = "pvesh get /nodes/{$this->nodeName}/storage/{$storageName}/status --output-format json";
            $response = $this->execSSHCommand($command);
            $data = json_decode($response, true);
            return $data['type'] ?? 'unknown';
        } catch (Exception $e) {
            error_log("Error getting storage type for {$storageName}: " . $e->getMessage());
            return 'unknown';
        }
    }

    private function getNextVMID() {
        // Получаем максимальный VMID в кластере
        $command = "pvesh get /cluster/nextid";
        $response = $this->execSSHCommand($command);
        
        if (empty($response)) {
            // Fallback для автономных нод
            $command = "pvesh get /nodes/{$this->nodeName}/nextid";
            $response = $this->execSSHCommand($command);
        }
        
        $vmid = intval(trim($response));
        
        // Проверяем, что VMID не занят на текущей ноде
        $checkCmd = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmid}/status";
        $checkResult = $this->execSSHCommand($checkCmd);
        
        if (strpos($checkResult, 'not exist') === false) {
            // Если VMID занят, ищем следующий свободный
            for ($i = $vmid + 1; $i < $vmid + 100; $i++) {
                $checkCmd = "pvesh get /nodes/{$this->nodeName}/qemu/{$i}/status";
                if (strpos($this->execSSHCommand($checkCmd), 'not exist') !== false) {
                    return $i;
                }
            }
            throw new Exception("Could not find available VMID after 100 attempts");
        }
        
        return $vmid;
    }

    public function getVMNetworkInfo($vmid) {
    $command = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmid}/agent/network-get-interfaces --output-format json";
    $response = $this->execSSHCommand($command);
    return json_decode($response, true);
    }

    public function getRRDData($vmId, $timeframe) {
    $command = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmId}/rrddata --timeframe {$timeframe} --output-format json";
    $rrdResponse = $this->execSSHCommand($command);
    return json_decode($rrdResponse, true);
    }

    public function execGuestCommand($vmid, $command) {
    // Проверяем, что VM запущена
    $status = $this->getVMStatus($vmid);
    if ($status['status'] !== 'running') {
        throw new Exception("VM is not running");
    }
    
    // Проверяем, что QEMU агент доступен
    $agentStatus = $this->execSSHCommand("qm agent {$vmid} ping");
    if (strpos($agentStatus, 'QEMU guest agent is not running') !== false) {
        throw new Exception("QEMU agent is not running");
    }
    
    // Выполняем команду через QEMU агент
    $fullCommand = "qm guest exec {$vmid} -- " . escapeshellcmd($command);
    return $this->execSSHCommand($fullCommand);
    }

    public function executeGuestCommand($vmid, $command) {
    // Проверяем, что VM запущена
    $status = $this->getVMStatus($vmid);
    if ($status['status'] !== 'running') {
        throw new Exception("VM {$vmid} is not running");
    }
    
    // Проверяем, что QEMU агент доступен
    $agentStatus = $this->execSSHCommand("qm agent {$vmid} ping");
    if (strpos($agentStatus, 'QEMU guest agent is not running') !== false) {
        throw new Exception("QEMU agent is not running for VM {$vmid}");
    }
    
    // Выполняем команду через QEMU агент
    return $this->execSSHCommand($command);
}

    public function getVMInfo($vmid) {
    // Получаем полную конфигурацию ВМ
    $config = $this->getVMConfig($vmid);
    
    // Получаем текущий статус ВМ
    $command = "pvesh get /nodes/{$this->nodeName}/qemu/{$vmid}/status/current --output-format json";
    $status = json_decode($this->execSSHCommand($command), true);
    
    // Формируем информацию о ВМ
    $vmInfo = [
        'name' => $config['name'] ?? 'VM ' . $vmid,
        'cpu' => $config['cores'] ?? $config['sockets'] ?? 1,
        'memory' => $config['memory'] ?? 1024, // в MB
        'disk' => $this->calculateTotalDisk($config), // в GB
        'network' => $this->getPrimaryNetwork($config),
        'storage' => $this->getPrimaryStorage($config),
        'status' => $status['status'] ?? 'stopped'
    ];
    
    return $vmInfo;
}

private function calculateTotalDisk($config) {
    $totalDisk = 0;
    foreach ($config as $key => $value) {
        if (strpos($key, 'disk') === 0 || strpos($key, 'ide') === 0 || 
            strpos($key, 'sata') === 0 || strpos($key, 'scsi') === 0) {
            if (preg_match('/size=(\d+)(\w+)/i', $value, $matches)) {
                $size = (int)$matches[1];
                $unit = strtolower($matches[2]);
                
                switch ($unit) {
                    case 't': $size *= 1024; break;
                    case 'g': break;
                    case 'm': $size /= 1024; break;
                    case 'k': $size /= 1024 / 1024; break;
                    default: $size = 0;
                }
                
                $totalDisk += $size;
            }
        }
    }
    return round($totalDisk, 1);
}

private function getPrimaryNetwork($config) {
    foreach ($config as $key => $value) {
        if (strpos($key, 'net') === 0 && preg_match('/bridge=([^,]+)/', $value, $matches)) {
            return $matches[1];
        }
    }
    return 'vmbr0';
}

private function getPrimaryStorage($config) {
    foreach ($config as $key => $value) {
        if ((strpos($key, 'disk') === 0 || strpos($key, 'scsi') === 0) && 
            preg_match('/storage=([^,]+)/', $value, $matches)) {
            return $matches[1];
        }
    }
    return 'local';
}

public function executeLxcCommand($vmid, $command) {
    try {
        // Проверяем, что контейнер запущен
        $status = $this->getLxcStatus($vmid);
        if (!$status['is_running']) {
            throw new Exception("LXC container {$vmid} is not running");
        }

        // Формируем полную команду для выполнения внутри контейнера
        $fullCommand = "pct exec {$vmid} -- " . escapeshellcmd($command);
        
        // Выполняем команду через SSH
        return $this->execSSHCommand($fullCommand);
        
    } catch (Exception $e) {
        error_log("[ProxmoxAPI] Error executing LXC command: " . $e->getMessage());
        throw new Exception("Failed to execute LXC command: " . $e->getMessage());
    }
}

public function getLXCStatusMetric($vmId) {
    $command = "pvesh get /nodes/{$this->nodeName}/lxc/{$vmId}/status/current --output-format json";
    $response = $this->execSSHCommand($command);
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка декодирования JSON: " . json_last_error_msg());
    }
    
    return $data;
}

public function getLxcRRDData($vmId, $timeframe) {
    $command = "pvesh get /nodes/{$this->nodeName}/lxc/{$vmId}/rrddata --timeframe {$timeframe} --output-format json";
    $response = $this->execSSHCommand($command);
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка декодирования JSON RRD: " . json_last_error_msg());
    }
    
    return $data;
}

public function startContainer($vmid) {
    try {
        // Сначала проверяем статус контейнера
        $status = $this->getContainerStatus($vmid);
        if ($status['status'] === 'running') {
            return ['success' => true, 'status' => 'running'];
        }

        // Получаем информацию о пользователе
        $userStmt = $this->pdo->prepare("
            SELECT u.is_admin FROM vms v
            JOIN users u ON u.id = v.user_id
            WHERE v.vm_id = ? AND v.vm_type = 'lxc'
        ");
        $userStmt->execute([$vmid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Проверяем баланс только для обычных пользователей (is_admin = 0)
        if ($user && $user['is_admin'] == 0) {
            if (!$this->checkBalanceAndSuspendIfNeeded($vmid)) {
                throw new Exception("Недостаточно средств на балансе для запуска контейнера");
            }
        }
        
        $command = "pvesh create /nodes/{$this->nodeName}/lxc/{$vmid}/status/start";
        $output = $this->execSSHCommand($command);
        
        // Обновляем статус в базе данных
        $stmt = $this->pdo->prepare("
            UPDATE vms SET status = 'running' WHERE vm_id = ? AND vm_type = 'lxc'
        ");
        $stmt->execute([$vmid]);
        
        return ['success' => true, 'status' => 'running'];
    } catch (Exception $e) {
        error_log("Error starting container #{$vmid}: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

public function stopContainer($vmid, $force = false) {
    try {
        $forceFlag = $force ? ' --force-stop 1' : '';
        $command = "pvesh create /nodes/{$this->nodeName}/lxc/{$vmid}/status/stop{$forceFlag}";
        $output = $this->execSSHCommand($command);
        
        // Обновляем статус в базе данных
        $status = $force ? 'suspended' : 'stopped';
        $stmt = $this->pdo->prepare("
            UPDATE vms SET status = ? WHERE vm_id = ? AND vm_type = 'lxc'
        ");
        $stmt->execute([$status, $vmid]);
        
        return $output;
    } catch (Exception $e) {
        error_log("Error stopping container #{$vmid}: " . $e->getMessage());
        throw $e;
    }
}

public function rebootContainer($vmid, $force = false) {
    try {
        $forceFlag = $force ? ' --force-stop 1' : '';
        $command = "pvesh create /nodes/{$this->nodeName}/lxc/{$vmid}/status/reboot{$forceFlag}";
        $output = $this->execSSHCommand($command);
        
        // После успешной перезагрузки статус остается running
        $stmt = $this->pdo->prepare("
            UPDATE vms SET status = 'running' WHERE vm_id = ? AND vm_type = 'lxc'
        ");
        $stmt->execute([$vmid]);
        
        return $output;
    } catch (Exception $e) {
        error_log("Error rebooting container #{$vmid}: " . $e->getMessage());
        throw $e;
    }
}

public function getContainerStatus($vmid) {
    try {
        $command = "pvesh get /nodes/{$this->nodeName}/lxc/{$vmid}/status/current";
        $output = $this->execSSHCommand($command);
        
        $statusData = json_decode($output, true);
        if (!$statusData) {
            throw new Exception("Failed to parse container status");
        }
        
        return [
            'status' => $statusData['status'] ?? 'unknown',
            'lock' => $statusData['lock'] ?? null,
            'ha' => $statusData['ha'] ?? null
        ];
    } catch (Exception $e) {
        error_log("Error getting container #{$vmid} status: " . $e->getMessage());
        return ['status' => 'unknown'];
    }
}


public function changeVmResources($vmid, $type, $plan_id, $custom_cpu = null, $custom_ram = null, $custom_disk = null) {
    try {
        // Получаем текущий статус
        $status = $this->getVMStatus($vmid);
        $was_running = ($status['status'] === 'running');
        
        // Если VM запущена - останавливаем
        if ($was_running) {
            $this->stopVM($vmid);
            
            // Ждем остановки
            $wait = 0;
            while ($status['status'] !== 'stopped' && $wait < 30) {
                sleep(1);
                $wait++;
                $status = $this->getVMStatus($vmid);
            }
            
            if ($status['status'] !== 'stopped') {
                throw new Exception("Не удалось остановить VM для изменения конфигурации");
            }
        }
        
        // Если выбран тариф - берем параметры из тарифа
        if ($plan_id > 0) {
            $stmt = $this->pdo->prepare("SELECT cpu_cores, ram_mb, disk_gb FROM tariffs WHERE id = ?");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plan) {
                throw new Exception("Тариф не найден");
            }
            
            $cpu = $plan['cpu_cores'];
            $ram = $plan['ram_mb'];
            $disk = $plan['disk_gb'];
        } else {
            // Иначе используем кастомные параметры
            if (!$custom_cpu || !$custom_ram || ($type === 'qemu' && !$custom_disk)) {
                throw new Exception("Не указаны все необходимые параметры");
            }
            
            $cpu = $custom_cpu;
            $ram = $custom_ram;
            $disk = $custom_disk;
        }
        
        // Обновляем конфигурацию
        $params = [
            'cores' => $cpu,
            'memory' => $ram
        ];
        
        // Для KVM можно изменить диск, для LXC - нет
        if ($type === 'qemu') {
            $params['scsi0'] = "{$disk}G";
        }
        
        $result = $this->post("/nodes/{$this->nodeName}/{$type}/{$vmid}/config", $params);
        
        if (isset($result['errors'])) {
            throw new Exception($result['errors']);
        }
        
        // Обновляем данные в базе
        $stmt = $this->pdo->prepare("
            UPDATE vms SET 
                tariff_id = ?, 
                cpu_cores = ?, 
                ram_mb = ?, 
                disk_gb = ?,
                is_custom = ?
            WHERE vm_id = ? AND vm_type = ?
        ");
        $stmt->execute([
            $plan_id > 0 ? $plan_id : null,
            $cpu,
            $ram,
            $disk,
            $plan_id > 0 ? 0 : 1,
            $vmid,
            $type
        ]);
        
        // Запускаем VM обратно, если она была запущена
        if ($was_running) {
            $this->startVM($vmid);
        }
        
        return ['status' => 'success', 'message' => 'Ресурсы успешно изменены'];
        
    } catch (Exception $e) {
        error_log("Error changing VM resources: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Изменяет диск VM
 */
public function changeVmDisk($vmid, $type, $disk_id, $new_size, $new_storage) {
    try {
        if ($type !== 'qemu') {
            throw new Exception("Изменение диска доступно только для виртуальных машин");
        }
        
        // Получаем текущую конфигурацию
        $config = $this->getVMConfig($vmid);
        $disk_key = "scsi{$disk_id}";
        
        if (!isset($config[$disk_key])) {
            throw new Exception("Диск не найден");
        }
        
        // Формируем новый параметр диска
        $new_disk = "{$new_storage}:{$new_size}G";
        
        // Обновляем конфигурацию
        $params = [
            $disk_key => $new_disk
        ];
        
        $result = $this->post("/nodes/{$this->nodeName}/{$type}/{$vmid}/config", $params);
        
        if (isset($result['errors'])) {
            throw new Exception($result['errors']);
        }
        
        return ['status' => 'success', 'message' => 'Диск успешно изменен'];
        
    } catch (Exception $e) {
        error_log("Error changing VM disk: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Добавляет сетевой интерфейс
 */
public function addVmNetwork($vmid, $type, $network_id, $bridge) {
    try {
        $params = [
            "net{$network_id}" => "bridge={$bridge}"
        ];
        
        $result = $this->post("/nodes/{$this->nodeName}/{$type}/{$vmid}/config", $params);
        
        if (isset($result['errors'])) {
            throw new Exception($result['errors']);
        }
        
        return ['status' => 'success', 'message' => 'Сетевой интерфейс успешно добавлен'];
        
    } catch (Exception $e) {
        error_log("Error adding VM network: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Удаляет VM/контейнер
 */
public function deleteVm($vmid, $type) {
    try {
        // Проверяем статус
        $status = $this->getVMStatus($vmid);
        
        // Если VM запущена - останавливаем
        if ($status['status'] === 'running') {
            $this->stopVM($vmid, true);
            
            // Ждем остановки
            $wait = 0;
            while ($status['status'] !== 'stopped' && $wait < 30) {
                sleep(1);
                $wait++;
                $status = $this->getVMStatus($vmid);
            }
            
            if ($status['status'] !== 'stopped') {
                throw new Exception("Не удалось остановить VM перед удалением");
            }
        }
        
        // Удаляем VM
        $command = ($type === 'qemu') ? "qm destroy {$vmid}" : "pct destroy {$vmid}";
        $this->execSSHCommand($command);
        
        // Удаляем из базы данных
        $stmt = $this->pdo->prepare("DELETE FROM vms WHERE vm_id = ? AND vm_type = ?");
        $stmt->execute([$vmid, $type]);
        
        return ['status' => 'success', 'message' => 'VM успешно удалена'];
        
    } catch (Exception $e) {
        error_log("Error deleting VM: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Выполняет POST запрос к API Proxmox
 */
private function post($path, $params) {
    $command = "pvesh create {$path}";
    foreach ($params as $key => $value) {
        $command .= " --{$key} " . escapeshellarg($value);
    }
    $command .= " --output-format json";
    
    $response = $this->execSSHCommand($command);
    return json_decode($response, true);
}

public function getVmDetailedInfo($vmid, $type = 'qemu') {
    try {
        $config = $this->getVMConfig($vmid);
        $status = $this->getVMStatus($vmid);
        
        // Парсим диски и сети
        $disks = [];
        $networks = [];
        
        foreach ($config as $key => $value) {
            // Диски (для KVM и LXC разные префиксы)
            if (($type === 'qemu' && (strpos($key, 'virtio') === 0 || strpos($key, 'scsi') === 0 || strpos($key, 'sata') === 0)) || 
                ($type === 'lxc' && strpos($key, 'mp') === 0)) {
                
                $disk_id = preg_replace('/[^0-9]/', '', $key);
                $size = 0;
                $storage = '';
                
                if ($type === 'qemu') {
                    // Формат для KVM: storage:size,format=...
                    if (preg_match('/([^:]+):(\d+)/', $value, $matches)) {
                        $storage = $matches[1];
                        $size = $matches[2];
                    }
                } else {
                    // Формат для LXC: volume=...,size=...
                    if (preg_match('/volume=([^,]+)/', $value, $vol_matches) && 
                        preg_match('/size=(\d+)/', $value, $size_matches)) {
                        $storage = explode(':', $vol_matches[1])[0];
                        $size = $size_matches[1];
                    }
                }
                
                if ($storage && $size) {
                    $disks[] = [
                        'id' => $disk_id,
                        'size' => $size,
                        'storage' => $storage
                    ];
                }
            }
            
            // Сети
            if (strpos($key, 'net') === 0) {
                $net_id = preg_replace('/[^0-9]/', '', $key);
                $mac = '';
                $bridge = '';
                
                $parts = explode(',', $value);
                foreach ($parts as $part) {
                    if (strpos($part, '=') !== false) {
                        list($k, $v) = explode('=', $part);
                        if ($k == 'macaddr') $mac = $v;
                        if ($k == 'bridge' || $k == 'name') $bridge = $v;
                    } else if (strpos($part, 'vmbr') !== false) {
                        $bridge = $part;
                    }
                }
                
                $networks[] = [
                    'id' => $net_id,
                    'mac' => $mac,
                    'bridge' => $bridge
                ];
            }
        }
        
        return [
            'config' => [
                'disks' => $disks,
                'networks' => $networks
            ],
            'status' => $status
        ];
        
    } catch (Exception $e) {
        error_log("Error getting VM info: " . $e->getMessage());
        return [
            'config' => [
                'disks' => [],
                'networks' => []
            ],
            'status' => ['status' => 'unknown']
        ];
    }
}

public function getLxcConfig($vmid) {
    try {
        $command = "pvesh get /nodes/{$this->nodeName}/lxc/{$vmid}/config --output-format json";
        $response = $this->execSSHCommand($command);
        return json_decode($response, true) ?: [];
    } catch (Exception $e) {
        error_log("Error getting LXC config: " . $e->getMessage());
        return [];
    }
}

public function getVncTicket($node, $vmid, $vmType = 'qemu') {
        if (!function_exists('ssh2_connect')) {
            throw new Exception("Требуется SSH2 расширение PHP");
        }
        
        // Подключаемся к серверу
        $ssh = ssh2_connect($this->host, 22);
        if (!$ssh) {
            throw new Exception("Ошибка подключения к {$this->host}");
        }
        
        if (!ssh2_auth_password($ssh, $this->username, $this->password)) {
            throw new Exception("Ошибка аутентификации SSH");
        }
        
        // Формируем команду pvesh
        $cmd = sprintf(
            'pvesh create /nodes/%s/%s/%s/vncproxy --output-format json',
            escapeshellarg($node),
            escapeshellarg($vmType),
            escapeshellarg($vmid)
        );
        
        // Выполняем команду
        $stream = ssh2_exec($ssh, $cmd);
        if (!$stream) {
            throw new Exception("Ошибка выполнения команды pvesh");
        }
        
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
        ssh2_disconnect($ssh);
        
        if (empty($output)) {
            throw new Exception("Пустой ответ от pvesh");
        }
        
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Ошибка парсинга JSON: " . json_last_error_msg());
        }
        
        if (!isset($data['data']['ticket'])) {
            throw new Exception("Неверный формат ответа от pvesh");
        }
        
        return $data['data']['ticket'];
    }

    public function login($username, $password) {
        $url = "https://{$this->host}:{$this->port}/api2/json/access/ticket";
        
        $postData = [
            'username' => $username,
            'password' => $password
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("Ошибка аутентификации. Код: {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['data'])) {
            throw new Exception("Неверный формат ответа от сервера Proxmox");
        }
        
        return $data['data'];
    }

    public function apiLogin() {
    $url = "https://{$this->host}:{$this->apiPort}/api2/json/access/ticket";
    
    $postData = [
        'username' => $this->username,
        'password' => $this->password
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200) {
        throw new Exception("Ошибка аутентификации API. Код: {$httpCode}");
    }
    
    $data = json_decode($response, true);
    return $data['data'] ?? null;
}

public function getAPITicket() {
        $url = "https://{$this->host}:{$this->apiPort}/api2/json/access/ticket";
        
        $postData = [
            'username' => $this->username,
            'password' => $this->password
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Proxmox API Auth Failed. Code: {$httpCode}, Error: {$error}");
            throw new Exception("Ошибка аутентификации. Проверьте учетные данные");
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            throw new Exception("Ошибка обработки ответа сервера");
        }

        return $data['data'] ?? null;
    }

    public function getTicketViaSSH() {
    $this->connect(); // Устанавливаем SSH соединение
    
    $command = "pvesh get /access/ticket --username {$this->username} --password {$this->password} --output-format json";
    $stream = ssh2_exec($this->ssh, $command);
    
    stream_set_blocking($stream, true);
    $response = stream_get_contents($stream);
    fclose($stream);
    
    $data = json_decode($response, true);
    return $data['data'] ?? null;
}
}
