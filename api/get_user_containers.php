<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/guest_install_image.php';

checkAuth();

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

try {
    $imageColumn = guestInstallImageColumn($pdo) ? 'v.installation_image' : 'NULL AS installation_image';
    $stmt = $pdo->prepare("
        SELECT 
            v.id, v.vm_id, v.vm_type, v.hostname, v.cpu, v.ram, v.disk, v.status, 
            v.created_at, v.node_id, v.ip_address, v.os_version, $imageColumn,
            t.name as tariff_name, t.price AS tariff_price, v.is_custom, 
            n.node_name
        FROM vms v
        LEFT JOIN tariffs t ON t.id = v.tariff_id
        JOIN proxmox_nodes n ON n.id = v.node_id
        WHERE v.user_id = ? AND v.vm_type = 'lxc'
        ORDER BY v.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    require_once __DIR__ . '/../includes/guest_card_pricing.php';
    annotateGuestCardPrices($pdo, $containers);
    foreach ($containers as &$guest) $guest['image_label'] = guestInstallImageLabel($guest);
    unset($guest);

    echo json_encode([
        'success' => true,
        'containers' => $containers
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}