<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/zone_placement.php';
require_once __DIR__ . '/guest_initial_password.php';

function guestOrderContext(PDO $pdo, int $user_id): array
{
    // Получаем текущие цены на ресурсы
    $resourcePrices = $pdo->query("SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1")->fetch();
    if (!$resourcePrices) {
        $resourcePrices = [
            'price_per_hour_cpu' => 0.001000,
            'price_per_hour_ram' => 0.000010,
            'price_per_hour_disk' => 0.000050,
            'price_per_hour_lxc_cpu' => 0.000800,
            'price_per_hour_lxc_ram' => 0.000008,
            'price_per_hour_lxc_disk' => 0.000030
        ];
    }

    // Получаем квоты пользователя
    $quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
    if (!$quota) {
        $pdo->exec("INSERT INTO user_quotas (user_id) VALUES ($user_id) ON DUPLICATE KEY UPDATE user_id=user_id");
        $quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
    }

    // Получаем текущее использование ресурсов
    $usage = $pdo->query("
        SELECT
            COUNT(*) as vm_count,
            SUM(cpu) as total_cpu,
            SUM(ram) as total_ram,
            SUM(disk) as total_disk
        FROM vms
        WHERE user_id = $user_id AND status != 'deleted'
    ")->fetch();

    return [$resourcePrices, $quota, $usage];
}

function guestOrderZones(PDO $pdo, int $userId): array
{
    $zones = $pdo->prepare("SELECT z.id,z.name FROM proxmox_clusters z WHERE z.is_active=1 AND COALESCE(z.maintenance_mode,0)=0
    AND EXISTS (SELECT 1 FROM proxmox_nodes n WHERE n.cluster_id=z.id AND n.is_active=1 AND n.available_for_users=1)
    AND " . ZONE_ACCESS_FILTER . " ORDER BY z.name");
    $zones->execute([$userId]);
    return $zones->fetchAll(PDO::FETCH_ASSOC);
}

function submitGuestOrder(int $user_id, array $input): array
{
    return withGuestOperationLocks(['user:' . $user_id], static function () use ($user_id, $input): array {
        $pdo = (new Database())->getConnection();
        [$resourcePrices, $quota, $usage] = guestOrderContext($pdo, $user_id);
        $is_custom = false;
        try {
            $requestedVmType = $input['vm_type'] ?? 'qemu';
            if (!in_array($requestedVmType, ['qemu', 'lxc'], true)) {
                throw new InvalidArgumentException('Неизвестный тип машины');
            }
            $vmType = $requestedVmType;
            $installMethod = $input['install_method'] ?? 'iso';
            $zoneId = (int)($input['zone_id'] ?? 0);
            $hostname = trim($input['hostname'] ?? '');
            $password = $input['password'] ?? '';
            $confirmPassword = $input['confirm_password'] ?? '';
            if (!is_string($password) || !is_string($confirmPassword)) {
                throw new InvalidArgumentException('Некорректный формат пароля');
            }
            $generatedPassword = $password === '' && $confirmPassword === '';
            if ($generatedPassword) $password = $confirmPassword = generateGuestPassword();
            $networkPool = (string)($input['network_pool'] ?? '');
            $storagePool = (string)($input['storage_pool'] ?? '');
            $template = $input['template'] ?? '';
            if ($vmType === 'qemu' && !in_array($installMethod, ['cloud', 'iso'], true)) {
                throw new InvalidArgumentException('Неизвестный способ установки');
            }
            $fromCloud = $vmType === 'qemu' && $installMethod === 'cloud';
            $cloudKey = $fromCloud ? ($input['cloud_image'] ?? '') : '';
            if ($fromCloud && (!is_string($cloudKey) || !isset(cloudImageCatalog()[$cloudKey]))) {
                throw new InvalidArgumentException('Выберите cloud-образ');
            }
            if ($fromCloud) {
                // Allow the 600-second Proxmox disk import and subsequent setup to finish.
                set_time_limit(660);
            }
            $osType = $fromCloud ? (cloudImageCatalog()[$cloudKey]['os_type'] ?? 'linux') : ($input['os_type'] ?? 'linux');
            $osVersion = $fromCloud ? cloudImageCatalog()[$cloudKey]['label'] : ($vmType === 'qemu' ? ($input['os_version'] ?? '') : 'linux');

            $custom_cpu = (int)($input['custom_cpu'] ?? 0);
            $custom_ram = (int)($input['custom_ram'] ?? 0);
            $custom_disk = (int)($input['custom_disk'] ?? 0);
            $is_custom = isset($input['is_custom']) && $input['is_custom'] === '1';

            if (!$zoneId) throw new Exception('Не выбрана зона доступности');
            if (empty($hostname)) throw new Exception('Укажите имя хоста');
            if (strlen($password) < 8 || strlen($password) > 1024 || preg_match('/[\x00\r\n]/', $password)) {
                throw new InvalidArgumentException('Пароль должен содержать 8–1024 символа и быть в одной строке');
            }
            if ($password !== $confirmPassword) throw new Exception('Пароли не совпадают');
            if ($fromCloud && $osType === 'windows') {
                if (!preg_match('/^[a-z0-9][a-z0-9-]{0,13}[a-z0-9]$|^[a-z0-9]$/D', $hostname) || ctype_digit($hostname)) {
                    throw new InvalidArgumentException('Имя компьютера Windows должно содержать до 15 символов и не состоять только из цифр');
                }
                $classes = 0;
                foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $rule) {
                    $classes += preg_match($rule, $password);
                }
                if (strlen($password) < 12 || strlen($password) > 127 || $classes < 3 ||
                    preg_match('/[^\x20-\x7e]/', $password) || stripos($password, 'Administrator') !== false) {
                    throw new InvalidArgumentException('Пароль Windows должен содержать 12–127 печатных ASCII-символов из трёх групп: прописные, строчные, цифры и специальные символы');
                }
            }
            if ($networkPool === '') throw new Exception('Не выбран пул сети');
            if ($storagePool === '') throw new Exception('Не выбран пул хранилищ');
            if ($vmType === 'qemu' && empty($osVersion)) throw new Exception('Необходимо указать версию ОС');
            if ($vmType === 'lxc' && empty($template)) throw new Exception('Необходимо выбрать шаблон LXC');
            if (!preg_match('/^[a-z0-9\-]+$/', $hostname)) {
                throw new Exception('Имя хоста может содержать только латинские буквы (a-z), цифры (0-9) и дефисы');
            }

            if (!empty($input['require_unique_hostname'])) {
                $existing = $pdo->prepare("SELECT 1 FROM vms WHERE user_id=? AND hostname=? AND status<>'deleted' LIMIT 1");
                $existing->execute([$user_id, $hostname]);
                if ($existing->fetchColumn()) {
                    throw new InvalidArgumentException('Гость с таким именем уже есть. Проверьте список машин или выберите другое имя.');
                }
            }

            $user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();
            if (!$user || !$user['is_active']) throw new RuntimeException('Учётная запись недоступна');

            $tariff = null;
            $tariffId = null;

            if (!$is_custom) {
                $tariffId = (int)($input['tariff_id'] ?? 0);
                if (!$tariffId) throw new Exception('Не выбран тарифный план');

                $stmt = $pdo->prepare("SELECT * FROM tariffs WHERE id = ? AND is_active = 1 AND is_custom = 0 AND vm_type = ?");
                $stmt->execute([$tariffId, $vmType]);
                $tariff = $stmt->fetch();
                if (!$tariff) throw new Exception('Выбранный тарифный план не найден');
                $tariffOs = $tariff['os_type'] ?? '';
                if ($fromCloud && in_array($tariffOs, ['linux', 'windows'], true) && $tariffOs !== $osType) {
                    throw new InvalidArgumentException('Выберите cloud-образ для ОС выбранного тарифа');
                }
                if ($vmType === 'qemu' && $tariffOs === 'windows') $osType = 'windows';

                $total_cpu = $tariff['cpu'];
                $total_ram = $tariff['ram'];
                $total_disk = $tariff['disk'];

                $daily_cost = $tariff['price'] / 30;
                if (($user['balance'] + $user['bonus_balance']) < $daily_cost) {
                    throw new Exception("Недостаточно средств на балансе для создания. Требуется минимум: " . number_format($daily_cost, 6) . " ₽");
                }
            } else {
                if ($custom_cpu < 1 || $custom_cpu > 8) throw new Exception('Количество CPU должно быть от 1 до 8');
                if ($custom_ram < 512 || $custom_ram > 16384) throw new Exception('RAM должна быть от 512MB до 16GB');
                if ($custom_disk < 1 || $custom_disk > 100) throw new Exception('Диск должен быть от 1GB до 100GB');

                $total_cpu = $custom_cpu;
                $total_ram = $custom_ram;
                $total_disk = $custom_disk;

                $price_per_hour_cpu = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_cpu'] : $resourcePrices['price_per_hour_cpu'];
                $price_per_hour_ram = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_ram'] : $resourcePrices['price_per_hour_ram'];
                $price_per_hour_disk = $vmType === 'lxc' ? $resourcePrices['price_per_hour_lxc_disk'] : $resourcePrices['price_per_hour_disk'];

                $hourly_cost = ($custom_cpu * $price_per_hour_cpu) +
                              ($custom_ram * $price_per_hour_ram) +
                              ($custom_disk * $price_per_hour_disk);

                if (($user['balance'] + $user['bonus_balance']) < $hourly_cost) {
                    throw new Exception("Недостаточно средств на балансе для создания. Требуется минимум: " . number_format($hourly_cost, 6) . " ₽");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO tariffs
                    (name, cpu, ram, disk, price, is_active, is_custom, created_at, vm_type)
                    VALUES
                    (:name, :cpu, :ram, :disk, :price, 1, 1, NOW(), :vm_type)
                ");

                $monthlyPrice = round($hourly_cost * 24 * 30, 2);

                $stmt->execute([
                    ':name' => "Свой",
                    ':cpu' => $custom_cpu,
                    ':ram' => $custom_ram,
                    ':disk' => $custom_disk,
                    ':price' => $monthlyPrice,
                    ':vm_type' => $vmType
                ]);

                $tariffId = $pdo->lastInsertId();
            }

            $quota_errors = [];
            if ($usage['vm_count'] >= $quota['max_vms']) {
                $quota_errors[] = "Превышено максимальное количество виртуальных машин ({$quota['max_vms']})";
            }

            if (($usage['total_cpu'] + $total_cpu) > $quota['max_cpu']) {
                $quota_errors[] = "Превышена квота CPU (используется {$usage['total_cpu']} из {$quota['max_cpu']})";
            }
            if (($usage['total_ram'] + $total_ram) > $quota['max_ram']) {
                $quota_errors[] = "Превышена квота RAM (используется {$usage['total_ram']}MB из {$quota['max_ram']}MB)";
            }
            if (($usage['total_disk'] + $total_disk) > $quota['max_disk']) {
                $quota_errors[] = "Превышена квота дискового пространства (используется {$usage['total_disk']}GB из {$quota['max_disk']}GB)";
            }

            if (!empty($quota_errors)) {
                $quota_message = "Превышены квоты вашего аккаунта:<br><br><ul>";
                foreach ($quota_errors as $error) {
                    $quota_message .= "<li>{$error}</li>";
                }
                $quota_message .= "</ul><br>Для увеличения квот обратитесь к администратору через <a href='https://homevlad.ru/templates/support.php' target='_blank'>тикет систему</a>.";
                throw new Exception($quota_message);
            }

            $media = $vmType === 'qemu' ? ($fromCloud ? '' : (string)($input['iso'] ?? '')) : (string)$template;
            [$creationResult, $node] = withGuestOperationLocks(['placement-zone:' . $zoneId], function () use (
                $pdo, $zoneId, $vmType, $networkPool, $storagePool, $media, $total_cpu, $total_ram, $total_disk,
                $osType, $hostname, $password, $generatedPassword, $template, $osVersion, $user_id, $tariffId, $is_custom, $cloudKey
            ) {
                if (!zoneAllowsUser($pdo, $zoneId, $user_id)) {
                    throw new Exception('Зона доступности недоступна');
                }
                $placement = choosePlacement($pdo, $zoneId, $vmType, $networkPool, $storagePool,
                    $media, (int)$total_cpu, (int)$total_ram, (int)$total_disk, $osType === 'windows', $cloudKey);
                $node = $placement['node'];
                $proxmoxApi = placementClient($node, $pdo);
                $network = $placement['network'];
                $storage = $placement['storage'];
                $vmParams = [
                    'vm_type' => $vmType,
                    'hostname' => $hostname,
                    'password' => $password,
                    'show_initial_password' => $generatedPassword,
                    'cpu' => $total_cpu,
                    'ram' => $total_ram,
                    'disk' => $total_disk,
                    'network' => $network,
                    'sdn' => $placement['sdn'],
                    'storage' => $storage,
                    'template' => $template,
                    'os_type' => $osType,
                    'os_version' => $osVersion,
                    'user_id' => $user_id,
                    'tariff_id' => $tariffId,
                    'is_custom' => $is_custom ? 1 : 0
                ];

                if ($vmType === 'qemu') {
                    $vmParams['cloud_image'] = $placement['cloud_image'];
                    $vmParams['iso'] = $media;
                    $vmParams['cdroms'] = $media !== '' ? [$media] : [];
                    if ($placement['cloud_image'] !== null) {
                        $volume = $placement['cloud_image']['volid'];
                        return [withGuestOperationLocks(["cloud-image-volume:$volume"],
                            static fn() => $proxmoxApi->createVM($vmParams)), $node];
                    }
                    return [$proxmoxApi->createVM($vmParams), $node];
                }
                return [$proxmoxApi->createLXC($vmParams), $node];
            });
            return [$creationResult, $node];
        } catch (Throwable $e) {
            if (isset($tariffId) && $is_custom && $e->getCode() !== ProxmoxAPI::ORDER_COMMIT_UNCONFIRMED) {
                try {
                    $cleanupPdo = (new Database())->getConnection();
                    $cleanup = $cleanupPdo->prepare('DELETE FROM tariffs WHERE id=? AND NOT EXISTS (SELECT 1 FROM vms WHERE tariff_id=?)');
                    $cleanup->execute([$tariffId, $tariffId]);
                } catch (Throwable $cleanupError) {
                    error_log('Could not clean up unused order tariff');
                }
            }

            throw $e;
        }
    });
}
