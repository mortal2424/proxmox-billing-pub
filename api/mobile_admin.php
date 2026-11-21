<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_infrastructure.php';
require_once __DIR__ . '/../includes/mobile_admin_directory.php';

try {
    $pdo = (new Database())->getConnection();
    $user = mobileUser($pdo);
    if (!$user['is_admin']) mobileRespond(['error' => 'Доступ запрещён'], 403);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        session_write_close();
        switch ($_GET['resource'] ?? '') {
            case 'accounts':
                mobileRespond(mobileAdminAccounts($pdo, $_GET));
            case 'account':
                $account = mobileAdminAccount($pdo, $_GET, (int)$user['id']);
                mobileRespond($account ?? ['error'=>'Пользователь не найден'], $account ? 200 : 404);
            case 'payments':
                mobileRespond(mobileAdminPayments($pdo, $_GET));
            case 'payment':
                $payment = mobileAdminPayment($pdo, $_GET);
                mobileRespond($payment ?? ['error'=>'Платёж не найден'], $payment ? 200 : 404);
            case 'guests':
                $before = mobileAdminNumber($_GET['before'] ?? PHP_INT_MAX, 1, PHP_INT_MAX);
                $search = mobileAdminText($_GET, 'q', 100);
                $type = $_GET['type'] ?? '';
                if (!in_array($type, ['', 'qemu', 'lxc'], true)) {
                    throw new InvalidArgumentException('Неизвестный тип машины');
                }
                $query = $pdo->prepare("SELECT v.id,v.vm_id,v.node_id,v.hostname,v.vm_type,v.status,v.cpu,v.ram,v.disk,
                    v.ip_address, u.id AS owner_id, COALESCE(NULLIF(u.full_name,''),u.email) AS owner,
                    n.node_name,z.name AS zone
                    FROM vms v JOIN users u ON u.id=v.user_id JOIN proxmox_nodes n ON n.id=v.node_id
                    LEFT JOIN proxmox_clusters z ON z.id=n.cluster_id
                    WHERE v.id<? AND v.status<>'deleted' AND v.vm_type IN ('qemu','lxc')
                    AND (?='' OR v.vm_type=?)
                    AND (?='' OR LOCATE(?,v.hostname)>0 OR LOCATE(?,u.full_name)>0 OR LOCATE(?,u.email)>0 OR v.vm_id=?)
                    ORDER BY v.id DESC LIMIT 51");
                $query->execute([$before,$type,$type,$search,$search,$search,$search,ctype_digit($search) ? (int)$search : 0]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $items = array_map(static fn(array $r): array => [
                    'id'=>(int)$r['id'],'vmId'=>(int)$r['vm_id'],'nodeId'=>(int)$r['node_id'],
                    'name'=>$r['hostname'],'type'=>$r['vm_type'],'status'=>$r['status'],
                    'cpu'=>(int)$r['cpu'],'ramMiB'=>(int)$r['ram'],'diskGiB'=>(int)$r['disk'],
                    'ipAddress'=>$r['ip_address'],'owner'=>$r['owner'],'ownerId'=>(int)$r['owner_id'],
                    'node'=>$r['node_name'],'zone'=>$r['zone'],
                ], array_slice($rows, 0, 50));
                mobileRespond(['items'=>$items,'next'=>count($rows)>50 ? $items[49]['id'] : null]);
            case 'infrastructure':
                mobileRespond(mobileAdminInfrastructure($pdo));
            default:
                mobileRespond(['error' => 'Раздел не найден'], 404);
        }
    }

    $input = mobileInput();
    session_write_close();
    switch ($input['action'] ?? '') {
        case 'account_save':
            $id = mobileAdminSaveAccount($pdo, $input, (int)$user['id']);
            mobileRespond(['success'=>true,'id'=>$id,'notice'=>'Пользователь сохранён']);
        case 'payment_add':
            $id = mobileAdminAddPayment($pdo, $input);
            mobileRespond(['success'=>true,'id'=>$id,'notice'=>'Платёж добавлен и ожидает проверки']);
        case 'payment_approve':
        case 'payment_reject':
            $id = mobileAdminNumber($input['id'] ?? null, 1, PHP_INT_MAX);
            $approve = $input['action'] === 'payment_approve';
            try {
                processPendingPayment($pdo, $id, $approve ? 'approve' : 'reject');
            } catch (RuntimeException $error) {
                if ($error instanceof PDOException) throw $error;
                throw new DomainException($error->getMessage(), 0, $error);
            }
            mobileRespond(['success'=>true,'notice'=>$approve ? 'Платёж подтверждён' : 'Платёж отклонён']);
        case 'zone_save':
            mobileAdminSaveZone($pdo, $input);
            mobileRespond(['success'=>true]);
        case 'node_save':
            $result = mobileAdminSaveNode($pdo, $input);
            mobileRespond(['success'=>true,'notice'=>$result]);
        case 'node_check':
        case 'node_import':
            $result = mobileAdminNodeAction($pdo, $input);
            mobileRespond(['success'=>true,'notice'=>$result]);
        default:
            mobileRespond(['error'=>'Неизвестное действие'], 400);
    }
} catch (InvalidArgumentException | DomainException $error) {
    mobileRespond(['error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Mobile administration failed: ' . get_class($error));
    mobileRespond(['error'=>'Не удалось выполнить операцию. Проверьте доступ к Proxmox и обновите данные.'], 502);
}
