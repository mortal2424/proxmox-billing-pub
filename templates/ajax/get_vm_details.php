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
        WHERE v.id = ? AND v.user_id = ? AND v.status<>'deleted'
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

    require_once __DIR__ . '/../../includes/guest_metrics.php';
    $metrics = array_slice(guestMetricRows($db->getConnection(), (int)$vm['id'], $vm['vm_type'], 3600), -60);

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
