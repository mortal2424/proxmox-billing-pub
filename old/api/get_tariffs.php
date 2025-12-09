<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

try {
    session_start();
    checkAuth();

    $db = new Database();
    $pdo = $db->getConnection();
    $user_id = $_SESSION['user']['id'];

    $vmType = $_GET['vm_type'] ?? 'qemu';
    if (!in_array($vmType, ['qemu', 'lxc'])) {
        $vmType = 'qemu';
    }

    // Обычные тарифы
    $regular_tariffs = $pdo->query("
        SELECT * FROM tariffs 
        WHERE is_active = 1 
        AND is_custom = 0 
        AND vm_type = '$vmType' 
        ORDER BY price
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Кастомные тарифы - новый оптимизированный запрос
    $custom_tariffs = $pdo->query("
        SELECT DISTINCT t.* 
        FROM tariffs t
        INNER JOIN vms v ON t.id = v.tariff_id
        WHERE t.is_active = 1
        AND t.is_custom = 1
        AND t.vm_type = '$vmType'
        AND v.user_id = $user_id
        ORDER BY t.created_at DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Логирование для отладки
    error_log("User $user_id requested $vmType tariffs. Found: " . 
             count($regular_tariffs) . " regular, " . 
             count($custom_tariffs) . " custom");

    echo json_encode([
        'success' => true,
        'regular_tariffs' => $regular_tariffs,
        'custom_tariffs' => $custom_tariffs,
        'debug_info' => [
            'user_id' => $user_id,
            'vm_type' => $vmType,
            'has_custom_tariffs' => !empty($custom_tariffs)
        ]
    ]);

} catch (Exception $e) {
    error_log("Tariffs loading error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'user_id' => $user_id ?? null,
            'vm_type' => $vmType ?? null
        ]
    ]);
}