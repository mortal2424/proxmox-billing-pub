<?php
// ajax/get_vm_details.php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

checkAuth();

$db = new Database();
$user_id = $_SESSION['user']['id'];

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID ВМ не указан']);
    exit;
}

$vm_id = intval($_GET['id']);

try {
    // Получаем информацию о ВМ
    $stmt = $db->getConnection()->prepare("
        SELECT v.*, t.name as tariff_name, t.is_custom, t.price, 
               t.price_per_hour_cpu, t.price_per_hour_ram, t.price_per_hour_disk,
               t.vm_type as tariff_vm_type
        FROM vms v
        LEFT JOIN tariffs t ON v.tariff_id = t.id
        WHERE v.id = ? AND v.user_id = ?
    ");
    $stmt->execute([$vm_id, $user_id]);
    $vm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vm) {
        echo json_encode(['success' => false, 'message' => 'ВМ не найдена или у вас нет к ней доступа']);
        exit;
    }

    // Получаем информацию о тарифе
    $tariff = [
        'name' => $vm['tariff_name'] ?: 'Без тарифа',
        'is_custom' => $vm['is_custom'] ?? 0,
        'price' => $vm['price'] ?? 0,
        'price_per_hour_cpu' => $vm['price_per_hour_cpu'] ?? 0,
        'price_per_hour_ram' => $vm['price_per_hour_ram'] ?? 0,
        'price_per_hour_disk' => $vm['price_per_hour_disk'] ?? 0,
        'vm_type' => $vm['tariff_vm_type'] ?? 'qemu'
    ];

    // Получаем последнее списание (для кастомного тарифа)
    $lastCharge = null;
    if ($tariff['is_custom']) {
        $stmt = $db->getConnection()->prepare("
            SELECT * FROM vm_billing 
            WHERE vm_id = ? AND user_id = ?
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$vm['vm_id'], $user_id]);
        $lastCharge = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Получаем метрики за последний час
    $table_name = $vm['vm_type'] === 'lxc' ? 'lxc_metrics' : 'vm_metrics';
    $metrics = [];
    
    // Проверяем существование таблицы
    $stmt = $db->getConnection()->query("SHOW TABLES LIKE '$table_name'");
    $table_exists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($table_exists) {
        $stmt = $db->getConnection()->prepare("
            SELECT * FROM $table_name 
            WHERE vm_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY timestamp ASC
            LIMIT 60
        ");
        $stmt->execute([$vm['vm_id']]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($metrics)) {
            // Если метрик нет, создаем демо-данные
            $metrics = generateDemoMetrics($vm['vm_type'] === 'lxc', $vm['ram'], $vm['disk']);
        }
    } else {
        $metrics = generateDemoMetrics($vm['vm_type'] === 'lxc', $vm['ram'], $vm['disk']);
    }

    // Получаем информацию о ноде
    $node_info = [];
    if ($vm['node_id']) {
        $stmt = $db->getConnection()->prepare("SELECT node_name, hostname, status FROM proxmox_nodes WHERE id = ?");
        $stmt->execute([$vm['node_id']]);
        $node_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'vm' => $vm,
        'tariff' => $tariff,
        'lastCharge' => $lastCharge,
        'metrics' => $metrics,
        'node_info' => $node_info
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_vm_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
} catch (Exception $e) {
    error_log("Error in get_vm_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}

// Функция для генерации демо-метрик
function generateDemoMetrics($isLxc = false, $ram_mb = 1024, $disk_gb = 20) {
    $metrics = [];
    $now = new DateTime();
    
    // Преобразуем RAM в GB для демо-данных
    $ram_gb = $ram_mb / 1024;
    
    for ($i = 59; $i >= 0; $i--) {
        $timestamp = clone $now;
        $timestamp->modify("-$i minutes");
        
        // Генерация реалистичных данных на основе характеристик ВМ
        $cpu_usage = rand(5, 45) + rand(0, 100) / 100; // 5-45%
        $mem_usage = rand($ram_gb * 0.2, $ram_gb * 0.8) + rand(0, 100) / 100; // 20-80% от доступной RAM
        $mem_total = $ram_gb; // GB
        $net_in = rand(0, 100) / 10; // Mbit/s
        $net_out = rand(0, 80) / 10; // Mbit/s
        $disk_read = rand(0, 50) / 10; // MB/s
        $disk_write = rand(0, 30) / 10; // MB/s
        
        $metrics[] = [
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'cpu_usage' => (float)$cpu_usage,
            'mem_usage' => (float)$mem_usage,
            'mem_total' => (float)$mem_total,
            'net_in' => (float)$net_in,
            'net_out' => (float)$net_out,
            'disk_read' => (float)$disk_read,
            'disk_write' => (float)$disk_write
        ];
    }
    
    return $metrics;
}