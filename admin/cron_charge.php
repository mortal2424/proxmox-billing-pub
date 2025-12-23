<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

error_log("[".date('Y-m-d H:i:s')."] Starting cron_charge.php");

$db = new Database();
$pdo = $db->getConnection();

// 1. Получаем текущие цены на ресурсы
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

// 2. Получаем все ВМ (и running, и stopped) с названием тарифа
$vms = $pdo->query("
    SELECT v.*, u.id as user_id, u.balance, u.bonus_balance, 
           t.is_custom, t.price as tariff_price, t.name as tariff_name,
           t.vm_type as tariff_vm_type
    FROM vms v
    JOIN users u ON u.id = v.user_id
    LEFT JOIN tariffs t ON t.id = v.tariff_id
    WHERE v.status IN ('running', 'stopped', 'suspended')
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($vms as $vm) {
    error_log("Processing VM #{$vm['vm_id']} (Type: {$vm['vm_type']}, Status: {$vm['status']})");
    
    try {
        $pdo->beginTransaction();
        
        $user_id = $vm['user_id'];
        $amount = 0;
        $metadata = [];
        
        // Определяем цены в зависимости от типа VM
        $cpu_price = $vm['vm_type'] === 'lxc' ? $resourcePrices['price_per_hour_lxc_cpu'] : $resourcePrices['price_per_hour_cpu'];
        $ram_price = $vm['vm_type'] === 'lxc' ? $resourcePrices['price_per_hour_lxc_ram'] : $resourcePrices['price_per_hour_ram'];
        $disk_price = $vm['vm_type'] === 'lxc' ? $resourcePrices['price_per_hour_lxc_disk'] : $resourcePrices['price_per_hour_disk'];
        
        // 3. Рассчитываем стоимость в зависимости от статуса
        if ($vm['status'] == 'running') {
            if ($vm['is_custom']) {
                // Почасовая оплата для кастомных тарифов (CPU+RAM+Disk)
                $cpu_cost = $vm['cpu'] * $cpu_price;
                $ram_cost = $vm['ram'] * $ram_price;
                $disk_cost = $vm['disk'] * $disk_price;
                $amount = $cpu_cost + $ram_cost + $disk_cost;
                
                $metadata = [
                    'cpu' => $vm['cpu'],
                    'cpu_price' => $cpu_price,
                    'memory' => $vm['ram'],
                    'memory_price' => $ram_price,
                    'disk' => $vm['disk'],
                    'disk_price' => $disk_price,
                    'vm_type' => $vm['vm_type']
                ];
            } else {
                // Ежедневная оплата для готовых тарифов
                $amount = round($vm['tariff_price'] / 30 / 24, 6); // Переводим в почасовую
                $metadata = [
                    'tariff_name' => $vm['tariff_name'],
                    'tariff_price' => $vm['tariff_price'],
                    'vm_type' => $vm['vm_type']
                ];
            }
        } else {
            // Для остановленных ВМ - только плата за диск (10% от обычной цены)
            $disk_cost = $vm['disk'] * $disk_price * 0.1;
            $amount = round($disk_cost, 6);
            
            $metadata = [
                'disk' => $vm['disk'],
                'disk_price' => $disk_price * 0.1,
                'vm_type' => $vm['vm_type']
            ];
        }
        
        $amount = round($amount, 6);
        error_log("Calculated amount for VM #{$vm['vm_id']}: {$amount}");

        if ($amount <= 0) {
            $pdo->commit();
            continue;
        }
        
        // 4. Сначала списываем с бонусного баланса (но не уходим в минус)
        $bonus_balance_used = min($vm['bonus_balance'], $amount);
        $remaining_amount = $amount - $bonus_balance_used;
        
        if ($bonus_balance_used > 0) {
            $pdo->prepare("UPDATE users SET bonus_balance = GREATEST(0, bonus_balance - ?) WHERE id = ?")
                ->execute([$bonus_balance_used, $user_id]);
            
            $pdo->prepare("
                INSERT INTO transactions (user_id, amount, type, description, balance_type, metadata)
                VALUES (?, ?, 'debit', ?, 'bonus', ?)
            ")->execute([
                $user_id,
                $bonus_balance_used,
                "Списание за " . ($vm['vm_type'] === 'lxc' ? 'контейнер' : 'ВМ') . " #{$vm['vm_id']} (" . ($vm['status'] == 'running' ? 'работает' : 'остановлен') . ")",
                json_encode($metadata)
            ]);
        }
        
        // 5. Затем списываем с основного баланса (если есть остаток)
        if ($remaining_amount > 0 && $vm['balance'] > 0) {
            $main_balance_used = min($vm['balance'], $remaining_amount);
            
            $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
                ->execute([$main_balance_used, $user_id]);
            
            $pdo->prepare("
                INSERT INTO transactions (user_id, amount, type, description, balance_type, metadata)
                VALUES (?, ?, 'debit', ?, 'main', ?)
            ")->execute([
                $user_id,
                $main_balance_used,
                "Списание за " . ($vm['vm_type'] === 'lxc' ? 'контейнер' : 'ВМ') . " #{$vm['vm_id']} (" . ($vm['status'] == 'running' ? 'работает' : 'остановлен') . ")",
                json_encode($metadata)
            ]);
            
            $remaining_amount -= $main_balance_used;
        }
        
        // 6. Проверяем, достаточно ли средств было списано
        if ($remaining_amount > 0) {
            // Если не хватило средств - приостанавливаем ВМ
            $pdo->prepare("UPDATE vms SET status = 'suspended' WHERE vm_id = ?")
                ->execute([$vm['vm_id']]);
            
            $vm_type_name = $vm['vm_type'] === 'lxc' ? 'контейнер' : 'ВМ';
            
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read)
                VALUES (?, ?, ?, 0)
            ")->execute([
                $user_id,
                "{$vm_type_name} #{$vm['vm_id']} приостановлен",
                "Недостаточно средств для {$vm_type_name} #{$vm['vm_id']}. Требуется: ".number_format($amount, 6)." ₽"
            ]);
            
            // Приостанавливаем ВМ на гипервизоре
            $proxmox = new ProxmoxFunctions($pdo);
            $proxmox->suspendVM($vm['vm_id'], "Недостаточно средств на балансе");
        } elseif ($vm['status'] == 'suspended') {
            // Если средств хватает, но ВМ приостановлена - возобновляем
            $current_balance = $pdo->prepare("SELECT balance, bonus_balance FROM users WHERE id = ?")
                ->execute([$user_id])
                ->fetch(PDO::FETCH_ASSOC);
            
            $total_balance = $current_balance['balance'] + $current_balance['bonus_balance'];
            $required_balance = $amount * 24; // Проверяем на сутки вперед
            
            if ($total_balance >= $required_balance) {
                $pdo->prepare("UPDATE vms SET status = 'stopped' WHERE vm_id = ?")
                    ->execute([$vm['vm_id']]);
                
                $proxmox = new ProxmoxFunctions($pdo);
                $proxmox->unsuspendVM($vm['vm_id']);
            }
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error processing VM #{$vm['vm_id']}: " . $e->getMessage());
    }
}

error_log("[".date('Y-m-d H:i:s')."] cron_charge.php completed");
echo "Charging completed successfully\n";