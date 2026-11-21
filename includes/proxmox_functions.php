<?php
require_once __DIR__ . '/node_credentials.php';
require_once __DIR__ . '/ProxmoxCommandApi.php';
require_once __DIR__ . '/node_api_credentials.php';
require_once __DIR__ . '/ProxmoxAuth.php';
require_once __DIR__ . '/guest_mutations.php';

class ProxmoxAPI {
    use ProxmoxGuestMutations;

    public const ORDER_COMMIT_UNCONFIRMED = 19001;
    private $host;
    private $username;
    private $password;
    private $port;
    private $nodeName;
    private $apiClient;
    private $commandApi;
    private $db;
    private $nodeId;
    private $apiPort;
    private $pdo;

    public function __construct($host, $username, $password, $port = 22, $nodeName = null, $nodeId = null, $db = null) {
        $this->host = $host;
        $this->username = $username;
        $this->password = decryptNodePassword($password);
        $this->port = (int)$port;
        $this->nodeName = $nodeName ?: gethostname();
        $this->nodeId = $nodeId;
        $this->db = $db;
        $this->apiPort = 8006;


        // Инициализация PDO
        if ($db instanceof PDO) {
            $this->pdo = $db;
        } elseif ($db instanceof Database) {
            $this->pdo = $db->getConnection();
        } else {
            throw new Exception("Database connection not provided");
        }
        $query = $this->pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=? AND is_active=1');
        $query->execute([(int)$nodeId]);
        $node = $query->fetch(PDO::FETCH_ASSOC);
        if (!$node || $node['hostname'] !== $host) throw new RuntimeException('Подключение к узлу недоступно');
        $this->nodeId = (int)$node['id'];
        $this->nodeName = $node['node_name'];
        $this->apiPort = (int)($node['api_port'] ?: 8006);
        $node = panelNodeApiCredentials($node);
        $this->username = $node['username'];
        $this->password = decryptNodePassword($node['password']);
        $this->apiClient = new ProxmoxApiClient($node);
        $this->commandApi = new ProxmoxCommandApi(fn($method,$path,$parameters) => $this->apiClient->requestAndWait($method,$path,$parameters), $this->nodeName);
    }

    public function controlGuest(int $vmid, string $type, string $action): string {
        if ($vmid < 1 || !in_array($type, ['qemu', 'lxc'], true) ||
            !in_array($action, ['start', 'shutdown', 'reboot', 'stop'], true) ||
            !preg_match('/^[A-Za-z0-9_.-]+$/D', $this->nodeName)) {
            throw new InvalidArgumentException('Некорректная команда управления');
        }
        return $this->execSSHCommand(
            "pvesh create /nodes/{$this->nodeName}/{$type}/{$vmid}/status/{$action}"
        );
    }

    private function requireIsoVolume(string $volume): void {
        if (!preg_match('#^([A-Za-z0-9_.-]+):iso/[A-Za-z0-9_.+-]+$#D', $volume, $match)) {
            throw new InvalidArgumentException('Некорректный ISO-образ');
        }
        $contents = $this->apiClient->request('GET', '/nodes/'.$this->nodeName.'/storage/'.$match[1].'/content', ['content'=>'iso']);
        foreach ($contents as $file) if (($file['volid'] ?? '') === $volume) return;
        throw new RuntimeException('ISO-образ отсутствует в выбранном хранилище');
    }

    private function beginGuestBilling(int $vmid, string $type, string $marker): void
    {
        $config = $this->guestApi()->config($vmid, $type);
        if (trim((string)($config['description'] ?? '')) !== $marker) {
            throw new RuntimeException('Не удалось подтвердить созданного гостя.');
        }
        $this->pdo = (new Database())->getConnection();
        $this->pdo->beginTransaction();
        // Lock the VMID range while the legacy billing foreign key still uses vm_id.
        $query = $this->pdo->prepare('SELECT id FROM vms WHERE vm_id=? FOR UPDATE');
        $query->execute([$vmid]);
        if ($query->fetchColumn() !== false) {
            throw new RuntimeException('Этот VMID уже используется в биллинге.');
        }
    }

    private function finishGuestCreation(int $vmid, string $type, bool $start): array
    {
        $result = ['status' => 'stopped'];
        if (!$start) return $result;
        try {
            $this->controlGuest($vmid, $type, 'start');
            $result['status'] = 'running';
            $stmt = $this->pdo->prepare("UPDATE vms SET status='running' WHERE node_id=? AND vm_id=? AND vm_type=? AND status='stopped'");
            $stmt->execute([$this->nodeId, $vmid, $type]);
        } catch (Throwable $error) {
            $result['warning'] = 'Гость создан. Проверьте его текущий статус в списке ВМ перед повторным запуском.';
            error_log("Guest start or status update unconfirmed: node={$this->nodeId} vmid={$vmid}");
        }
        return $result;
    }

    private function rollbackGuestCreation(?int $vmid, string $type, string $marker, bool $commitAttempted): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($vmid === null || $commitAttempted) return;
        try {
            $api = $this->guestApi();
            $config = $api->config($vmid, $type);
            if (trim((string)($config['description'] ?? '')) === $marker) {
                $api->destroy($vmid, $type);
            }
        } catch (Throwable $error) {
            error_log("Guest creation requires reconciliation: node={$this->nodeId} type={$type} vmid={$vmid} marker={$marker}");
        }
    }

    public function createVM($params) {
    // Validate every value that becomes part of a remote shell command.
    $storage = (string)($params['storage'] ?? 'local');
    $network = (string)($params['network'] ?? 'vmbr0');
    $scsihw = (string)($params['scsihw'] ?? 'virtio-scsi-pci');
    $diskSize = (string)($params['disk'] ?? '10');
    $cpu = filter_var($params['cpu'] ?? 1, FILTER_VALIDATE_INT);
    $ram = filter_var($params['ram'] ?? 1024, FILTER_VALIDATE_INT);
    if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $storage) ||
        !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $network) ||
        !in_array($scsihw, ['virtio-scsi-pci', 'virtio-scsi-single', 'lsi', 'lsi53c810', 'megasas', 'pvscsi'], true) ||
        !preg_match('/^[1-9][0-9]{0,5}$/', $diskSize) ||
        $cpu === false || $cpu < 1 || $cpu > 256 ||
        $ram === false || $ram < 16 || $ram > 1048576) {
        throw new InvalidArgumentException('Invalid VM resource or device parameters');
    }
    $sdnInput = (string)($params['sdn'] ?? '');
    if ($sdnInput !== '' && !preg_match('#^[A-Za-z0-9_.-]{1,64}(?:/[A-Za-z0-9_.-]{1,64})?$#', $sdnInput)) {
        throw new InvalidArgumentException('Invalid SDN network identifier');
    }
    $cdromsInput = $params['cdroms'] ?? [];
    if (!is_array($cdromsInput) || count($cdromsInput) > 4) {
        throw new InvalidArgumentException('Invalid ISO list');
    }
    foreach ($cdromsInput as $isoInput) {
        if (!is_string($isoInput) || !preg_match('#^(?:[A-Za-z0-9_.-]{1,64}:iso/|iso/)?[A-Za-z0-9_.+-]+$#', $isoInput) || basename($isoInput) === '.' || basename($isoInput) === '..') {
            throw new InvalidArgumentException('Invalid ISO identifier');
        }
    }

    $marker = 'HomeVlad provisioning ' . bin2hex(random_bytes(16));
    $commitAttempted = false;

    try {
        // 1. Получаем уникальный ID для VM
        $vmid = $this->getClusterNextID();
        error_log("[VM Creation] Starting creation of VM ID: {$vmid}");

        // 3. Подготовка параметров
        $nodeId = $this->nodeId;
        $isWindows = ($params['os_type'] ?? 'linux') === 'windows';
        $hasIso = !empty($params['iso']) || !empty($params['cdroms']);
        $storage = (string)($params['storage'] ?? 'local');
        $network = (string)($params['network'] ?? 'vmbr0');
        $sdn = $params['sdn'] ?? null;
        $isCustom = $params['is_custom'] ?? 0;
        $osVersion = $params['os_version'] ?? '';
        $storageForIso = 'local'; // Для ISO всегда используем хранилище 'local'

        // Обработка SDN сети
        $networkValue = !empty($sdn) ? (strpos($sdn, '/') !== false ? explode('/', $sdn)[1] : $sdn) : $network;
        $sdn = !empty($sdn) ? $sdn : null;

        // 4. Создаем базовую VM
        $createCmd = sprintf(
            "qm create %d --name %s --cores %d --memory %d --machine q35 --bios ovmf --agent 1 --onboot 1 --scsihw %s --description %s",
            $vmid,
            escapeshellarg($params['hostname'] ?? 'vm-' . $vmid),
            $cpu,
            $ram,
            escapeshellarg($scsihw),
            escapeshellarg($marker)
        );
        error_log("[VM Creation] Creating base VM: {$createCmd}");
        $this->execSSHCommand($createCmd);

        // 5. Настройка дисков
        $diskSize = (string)($params['disk'] ?? '10');
        $diskCmd = "qm set {$vmid} --scsi0 " . escapeshellarg("{$storage}:{$diskSize},format=raw,ssd=1,discard=on,cache=writeback");
        error_log("[VM Creation] Setting main disk: {$diskCmd}");
        $this->execSSHCommand($diskCmd);

        // EFI диск
        $efiCmd = "qm set {$vmid} --efidisk0 " . escapeshellarg("{$storage}:0,efitype=4m,pre-enrolled-keys=0");
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
            $checkVolume = $virtioIso;
            try {
                $this->requireIsoVolume($checkVolume);
            } catch (Exception $e) {
                throw new Exception("Обязательный файл virtio-win.iso не найден в /var/lib/vz/template/iso/");
            }
        }

        // Монтируем все CD-ROM из списка
        $cdromIndex = 0;
        $cdromDevices = ['ide2','ide3','sata0','sata1'];
        $mountedCdroms = [];
        foreach ($cdroms as $cdrom) {
            // Форматируем путь к ISO правильно
            if (!preg_match('/^[a-z0-9_.-]+:iso\//i', $cdrom)) {
                $isoName = preg_replace('#^iso/#', '', ltrim($cdrom, '/'));
                $cdrom = "{$storageForIso}:iso/" . $isoName;
            }

            // Извлекаем имя файла для проверки
            $isoFile = preg_replace('/^[^:]+:iso\//', '', $cdrom);

            // Проверяем существование ISO
            $checkVolume = $cdrom;
            try {
                $this->requireIsoVolume($checkVolume);
            } catch (Exception $e) {
                if (strpos($cdrom, 'virtio-win.iso') !== false) {
                    throw new Exception("Файл ISO не найден: {$isoFile}");
                }
                error_log("[VM Creation] Warning: ISO file not found - {$isoFile}");
                continue;
            }

            // Монтируем ISO
            $device = $cdromDevices[$cdromIndex];
            $isoCmd = "qm set {$vmid} --{$device} " . escapeshellarg($cdrom . ",media=cdrom");
            error_log("[VM Creation] Setting CD-ROM {$cdromIndex}: {$isoCmd}");

            try {
                $this->execSSHCommand($isoCmd);
                $mountedCdroms[] = $device;
                $cdromIndex++;

                if ($cdromIndex >= count($cdromDevices)) { // Максимум 4 CD-ROM (ide2-ide5)
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
        $netCmd = "qm set {$vmid} --net0 " . escapeshellarg("virtio,bridge={$networkValue}");
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
        $this->beginGuestBilling($vmid, 'qemu', $marker);
        $status = 'stopped';
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

        $commitAttempted = true;
        $this->pdo->commit();
        $launch = $this->finishGuestCreation($vmid, 'qemu', !$hasIso);
        error_log("[VM Creation] VM {$vmid} created successfully");

        return $launch + [
            'vmid' => $vmid,
            'node_name' => $this->nodeName,
            'hostname' => $params['hostname'] ?? 'vm-' . $vmid
        ];

    } catch (Throwable $error) {
        try {
            $this->rollbackGuestCreation($vmid ?? null, 'qemu', $marker, $commitAttempted);
        } catch (Throwable $rollbackError) {
            error_log("Could not roll back guest creation on node {$this->nodeId}");
        }
        if ($commitAttempted) {
            error_log("Guest billing commit unconfirmed: node={$this->nodeId} vmid={$vmid} marker={$marker}");
            throw new RuntimeException('Результат записи заказа требует проверки администратора. Не повторяйте заказ.', self::ORDER_COMMIT_UNCONFIRMED, $error);
        }
        if ($error instanceof PDOException) {
            throw new RuntimeException('Не удалось сохранить заказ. Обратитесь в поддержку.', 0, $error);
        }
        throw $error;
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
    // Validate all dynamic arguments before constructing pct shell commands.
    $storage = (string)($params['storage'] ?? 'local');
    $template = (string)($params['template'] ?? '');
    $network = (string)($params['network'] ?? 'vmbr0');
    $cpu = filter_var($params['cpu'] ?? 1, FILTER_VALIDATE_INT);
    $ram = filter_var($params['ram'] ?? 1024, FILTER_VALIDATE_INT);
    $disk = filter_var($params['disk'] ?? 10, FILTER_VALIDATE_INT);
    $sdn = (string)($params['sdn'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $storage) ||
        !preg_match('#^[A-Za-z0-9_.-]{1,64}:vztmpl/[A-Za-z0-9_.+-]+$#', $template) ||
        !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $network) ||
        ($sdn !== '' && !preg_match('#^[A-Za-z0-9_.-]{1,64}(?:/[A-Za-z0-9_.-]{1,64})?$#', $sdn)) ||
        $cpu === false || $cpu < 1 || $cpu > 256 ||
        $ram === false || $ram < 16 || $ram > 1048576 ||
        $disk === false || $disk < 1 || $disk > 1048576) {
        throw new InvalidArgumentException('Invalid LXC template, storage, network or resource parameters');
    }
    $marker = 'HomeVlad provisioning ' . bin2hex(random_bytes(16));
    $commitAttempted = false;

    try {
        // Получение уникального ID
        $vmid = $this->getClusterNextID();
        error_log("[LXC Creation] Starting creation of container ID: {$vmid}");

        // Подготовка параметров
        $hostname = $params['hostname'] ?? 'lxc-' . $vmid;
        $password = $params['password'] ?? '';
        $storage = (string)($params['storage'] ?? 'local');
        $template = (string)($params['template'] ?? '');
        $nodeId = $this->nodeId;

        if (empty($template)) {
            throw new Exception("LXC template not specified");
        }

        // Создание контейнера (без сети)
        $createCmd = "pct create {$vmid} " . escapeshellarg($template) . " " .
                     "--storage " . escapeshellarg($storage) . " " .
                    "--hostname " . escapeshellarg($hostname) . " " .
                    "--cores {$cpu} " .
                    "--memory {$ram} " .
                    "--rootfs {$disk} " .
                    "--password " . escapeshellarg($password) . " " .
                    "--unprivileged 1 " .
                    "--onboot 1 " .
                    "--features nesting=1 --description " . escapeshellarg($marker);

        error_log("[LXC Creation] Creating container {$vmid} with validated parameters");
        $this->execSSHCommand($createCmd);

        // Настройка сети
        $network = (string)($params['network'] ?? 'vmbr0');
        $sdn = (string)($params['sdn'] ?? '');

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

        $netCmd = "pct set {$vmid} --net0 " . escapeshellarg($netString);
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

        $this->beginGuestBilling($vmid, 'lxc', $marker);
        $stmt = $this->pdo->prepare("
            INSERT INTO vms
            (user_id, vm_id, node_id, tariff_id, hostname, cpu, ram, disk,
             network, sdn, storage, os_type, vm_type, status, created_at, is_custom)
            VALUES (:user_id, :vm_id, :node_id, :tariff_id, :hostname, :cpu, :ram, :disk,
                    :network, :sdn, :storage, :os_type, 'lxc', 'stopped', NOW(), :is_custom)
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

        $commitAttempted = true;
        $this->pdo->commit();
        $launch = $this->finishGuestCreation($vmid, 'lxc', true);
        error_log("[LXC Creation] Container {$vmid} created successfully");

        return $launch + [
            'vmid' => $vmid,
            'node_name' => $this->nodeName,
            'hostname' => $hostname
        ];

    } catch (Throwable $error) {
        try {
            $this->rollbackGuestCreation($vmid ?? null, 'lxc', $marker, $commitAttempted);
        } catch (Throwable $rollbackError) {
            error_log("Could not roll back guest creation on node {$this->nodeId}");
        }
        if ($commitAttempted) {
            error_log("Guest billing commit unconfirmed: node={$this->nodeId} vmid={$vmid} marker={$marker}");
            throw new RuntimeException('Результат записи заказа требует проверки администратора. Не повторяйте заказ.', self::ORDER_COMMIT_UNCONFIRMED, $error);
        }
        if ($error instanceof PDOException) {
            throw new RuntimeException('Не удалось сохранить заказ. Обратитесь в поддержку.', 0, $error);
        }
        throw $error;
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
            SELECT u.is_admin, v.vm_type
            FROM vms v
            JOIN users u ON u.id = v.user_id
            WHERE v.node_id = {$this->nodeId} AND v.vm_id = ?
        ");
        $userStmt->execute([$vmid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Не приостанавливаем ВМ администраторов
        if ($user && $user['is_admin'] == 1) {
            error_log("VM #{$vmid} belongs to admin - skipping suspend");
            return true;
        }

        // 2. Останавливаем виртуальную машину
        if (!$user) throw new RuntimeException('Гость не найден на выбранной ноде');
        if ($user['vm_type'] === 'lxc') $this->stopContainer($vmid,true); else $this->stopVM($vmid,true);

        // 3. Обновляем статус в базе данных
        $stmt = $this->pdo->prepare("
            UPDATE vms
            SET status = 'suspended',
                suspended_at = NOW(),
                suspend_reason = ?
            WHERE node_id = {$this->nodeId} AND vm_id = ?
        ");
        $stmt->execute([$reason, $vmid]);

        // 4. Создаем уведомление для пользователя
        $userStmt = $this->pdo->prepare("
            SELECT user_id FROM vms WHERE node_id = {$this->nodeId} AND vm_id = ?
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
                WHERE v.node_id = {$this->nodeId} AND v.vm_id = ?
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
            $started = $vm['vm_type']==='lxc' ? $this->startContainer($vmid) : $this->startVM($vmid);
            if (empty($started['success'])) throw new RuntimeException('Не удалось возобновить гостя');

            // 3. Обновляем статус в базе данных
            $stmt = $this->pdo->prepare("
                UPDATE vms
                SET status = 'running',
                    suspended_at = NULL,
                    suspend_reason = NULL
                WHERE node_id = {$this->nodeId} AND vm_id = ?
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
                WHERE v.node_id = {$this->nodeId} AND v.vm_id = ?
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
        return $this->parseVMResponse(json_encode($this->apiClient->request('GET', '/nodes/'.$this->nodeName.'/qemu'), JSON_THROW_ON_ERROR));
    }

    public function getVNCConsole($vmid) {
        $vmid = filter_var($vmid, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$vmid) throw new InvalidArgumentException('Некорректный VMID');
        $data = $this->apiClient->request('POST', '/nodes/'.$this->nodeName.'/qemu/'.$vmid.'/vncproxy', ['websocket'=>1]);
        return ['hostname'=>$this->host,'port'=>$data['port'],'ticket'=>$data['ticket'],'vmid'=>$vmid,'node'=>$this->nodeName,'apiPort'=>$this->apiPort];
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
            WHERE v.node_id = {$this->nodeId} AND v.vm_id = ?
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
            UPDATE vms SET status = 'running' WHERE node_id = {$this->nodeId} AND vm_id = ?
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
                UPDATE vms SET status = ? WHERE node_id = {$this->nodeId} AND vm_id = ?
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
        // Kept for old callers; requests are translated to HTTP and never reach a shell.
        return $this->commandApi->execute($command);
    }

    public function request($method, $path, array $parameters = []) {
        return $this->apiClient->requestAndWait($method, $path, $parameters);
    }

    public function getNodeResources() {
        $state = $this->apiClient->request('GET','/nodes/'.$this->nodeName.'/status');
        $total = (float)($state['memory']['total']??0);
        $used = (float)($state['memory']['used']??0);
        return ['total_memory'=>round($total/1073741824,2), 'free_memory'=>round(max(0,$total-$used)/1073741824,2),
            'cpu_usage'=>round((float)($state['cpu']??0)*100,2), 'storages'=>$this->getNodeStorages()];
    }

    public function getNodeStorages(?string $requiredContent = 'images') {
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
                    if ($requiredContent !== null && (!isset($storage['content']) || !in_array($requiredContent, explode(',', $storage['content']), true))) {
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

    private function getClusterNextID(): int
    {
        $next = filter_var($this->apiClient->request('GET', '/cluster/nextid'), FILTER_VALIDATE_INT);
        if ($next === false || $next < 100 || $next > 999999999) {
            throw new RuntimeException('Proxmox не вернул допустимый VMID.');
        }
        $reserved = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM vms WHERE vm_id=?) + (SELECT COUNT(*) FROM vms_admin WHERE vm_id=?)'
        );
        $guests = $this->apiClient->request('GET', '/cluster/resources', ['type' => 'vm']);
        $occupied = array_column($guests, 'vmid', 'vmid');
        for ($vmid = $next; $vmid <= min($next + 999, 999999999); $vmid++) {
            if (isset($occupied[$vmid])) continue;
            $reserved->execute([$vmid, $vmid]);
            if ((int)$reserved->fetchColumn() === 0) return $vmid;
        }
        throw new RuntimeException('Не удалось подобрать свободный VMID.');
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
        throw new RuntimeException('Выполнение команд внутри гостевой ОС отключено; используйте API чтения состояния');
    }

    public function executeGuestCommand($vmid, $command) {
        throw new RuntimeException('Выполнение команд внутри гостевой ОС отключено; используйте API чтения состояния');
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
        throw new RuntimeException('Выполнение команд внутри гостевой ОС отключено; используйте API чтения состояния');
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
            WHERE v.node_id = {$this->nodeId} AND v.vm_id = ? AND v.vm_type = 'lxc'
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
            UPDATE vms SET status = 'running' WHERE node_id = {$this->nodeId} AND vm_id = ? AND vm_type = 'lxc'
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
            UPDATE vms SET status = ? WHERE node_id = {$this->nodeId} AND vm_id = ? AND vm_type = 'lxc'
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
            UPDATE vms SET status = 'running' WHERE node_id = {$this->nodeId} AND vm_id = ? AND vm_type = 'lxc'
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


public function getVmDetailedInfo($vmid, $type = 'qemu') {
    $api=$this->guestApi();$base=$api->base($vmid,$type);
    $config=$this->apiClient->request('GET',$base.'/config');
    $status=$this->apiClient->request('GET',$base.'/status/current');
    $disks=[];$networks=[];
    foreach($config as$key=>$value){
        if(preg_match($type==='lxc'?'/^rootfs$/D':'/^(?:scsi|virtio|sata|ide)[0-9]+$/D',(string)$key)){
            if(!is_string($value)||str_contains($value,'media=cdrom')||$value==='none')continue;
            $disk=ProxmoxGuestApi::disk($config,$key);
            $disks[]=['id'=>$key,'size'=>$disk['bytes']/1073741824,'storage'=>$disk['storage']];
        }
        if(preg_match('/^net([0-9]+)$/D',(string)$key,$match)){
            $network=['id'=>$match[1],'mac'=>'','bridge'=>''];
            foreach(explode(',',(string)$value)as$part){
                $pair=explode('=',$part,2);if(count($pair)!==2)continue;
                if($pair[0]==='bridge')$network['bridge']=$pair[1];
                if($pair[0]==='name')$network['name']=$pair[1];
                if(in_array($pair[0],['hwaddr','macaddr','virtio','e1000','rtl8139'],true))$network['mac']=$pair[1];
            }
            $networks[]=$network;
        }
    }
    return ['config'=>['disks'=>$disks,'networks'=>$networks],'status'=>$status];
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
    if ($node !== $this->nodeName || !in_array($vmType,['qemu','lxc'],true) || !ctype_digit((string)$vmid) || (int)$vmid<1) throw new InvalidArgumentException('Некорректная консоль');
    $ticket=$this->apiClient->request('POST','/nodes/'.$this->nodeName.'/'.$vmType.'/'.(int)$vmid.'/vncproxy',['websocket'=>1]);
    return $ticket['ticket'];
}
public function login($username, $password) {
    $client=new ProxmoxApiClient(['hostname'=>$this->host,'api_port'=>$this->apiPort,'username'=>$username,'password'=>$password]);
    return $client->getPasswordTicket();
}
public function apiLogin() { return $this->apiClient->getPasswordTicket(); }
public function getAPITicket() { return $this->apiClient->getPasswordTicket(); }
public function getTicketViaSSH() { return $this->apiClient->getPasswordTicket(); }

public function getAvailableTemplates() {
    $rows=$this->apiClient->request('GET','/nodes/'.$this->nodeName.'/aplinfo');
    foreach($rows as&$row){$row['name']=$row['template']??$row['name']??'';$row['id']=$row['name'];}unset($row);
    return $rows;
}
public function downloadTemplate($storage, $templateName) {
    if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$storage)||!preg_match('/^[A-Za-z0-9_.+-]+$/D',$templateName))throw new InvalidArgumentException('Некорректный шаблон');
    return $this->apiClient->requestAndWait('POST','/nodes/'.$this->nodeName.'/aplinfo',['storage'=>$storage,'template'=>$templateName],600);
}
}
