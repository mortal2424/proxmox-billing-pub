<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/guest_order.php';

try {
    $pdo = (new Database())->getConnection();
    $user = mobileUser($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $type = $_GET['vm_type'] ?? '';
        if (!in_array($type, ['qemu', 'lxc'], true)) mobileRespond(['error' => 'Выберите тип гостя'], 400);
        $query = $pdo->prepare('SELECT id,name,cpu,ram,disk,price,os_type FROM tariffs
            WHERE is_active=1 AND is_custom=0 AND vm_type=? ORDER BY price,id');
        $query->execute([$type]);
        $tariffs = [];
        foreach ($query as $row) {
            $tariffs[] = [
                'id'=>(string)$row['id'],
                'label'=>sprintf('%s · %d vCPU · %d МБ · %d ГБ · %s ₽/мес', $row['name'], $row['cpu'], $row['ram'], $row['disk'], $row['price']),
                'os_type'=>$row['os_type'],
            ];
        }
        $zones = array_map(static fn(array $zone): array => ['id'=>(string)$zone['id'],'label'=>$zone['name']], guestOrderZones($pdo));
        mobileRespond(['zones'=>$zones,'tariffs'=>$tariffs]);
    }

    $input = mobileInput();
    if (!in_array($input['vm_type'] ?? null, ['qemu','lxc'], true)) mobileRespond(['error'=>'Выберите тип гостя'], 400);
    foreach (['zone_id','hostname','network_pool','storage_pool','tariff_id', $input['vm_type']==='qemu'?'cloud_image':'template'] as $field) {
        if (!is_string($input[$field] ?? null) || strlen($input[$field]) > 255) {
            mobileRespond(['error'=>'Проверьте заполнение заказа'], 400);
        }
    }
    $order = array_intersect_key($input, array_flip(['vm_type','zone_id','hostname','network_pool','storage_pool','tariff_id','cloud_image','template']));
    $order['install_method'] = 'cloud';
    $order['require_unique_hostname'] = true;
    session_write_close();
    [$created] = submitGuestOrder((int)$user['id'], $order);
    mobileRespond(['success'=>true, 'guestId'=>(int)$created['guest_id'], 'warning'=>$created['warning'] ?? null]);
} catch (PDOException $error) {
    error_log('Mobile order database operation failed');
    mobileRespond(['error'=>'Не удалось подтвердить заказ. Проверьте список машин перед повтором.'], 503);
} catch (Throwable $error) {
    error_log('Mobile order rejected: ' . get_class($error));
    mobileRespond(['error'=>strip_tags(str_replace(['<br>','</li>'], ' ', $error->getMessage()))], 422);
}
