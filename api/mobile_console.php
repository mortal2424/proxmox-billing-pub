<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/console_ticket.php';

try {
    $pdo = (new Database())->getConnection();
    $user = mobileUser($pdo);
    $input = mobileInput();
    $id = $input['id'] ?? null;
    if (!is_int($id) || $id < 1) {
        mobileRespond(['error' => 'Укажите машину'], 400);
    }
    $query = $pdo->prepare("SELECT n.id,n.hostname,n.api_port,n.node_name,n.username,n.password,v.vm_id,v.vm_type
        FROM vms v JOIN proxmox_nodes n ON n.id=v.node_id
        WHERE v.id=? AND (v.user_id=? OR ?=1) AND v.status<>'deleted' AND n.is_active=1");
    $query->execute([$id, (int) $user['id'], (int) $user['is_admin']]);
    $guest = $query->fetch(PDO::FETCH_ASSOC);
    if (!$guest || !in_array($guest['vm_type'], ['qemu', 'lxc'], true)) {
        mobileRespond(['error' => 'Машина недоступна'], 404);
    }
    session_write_close();
    mobileRespond([
        'ticket' => issueConsoleTicket($guest, (int) $guest['vm_id'], $guest['vm_type']),
        'socketPath' => '/console/socket',
    ]);
} catch (ConsoleBrokerUnavailable $error) {
    mobileRespond(['error' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('Mobile console launch failed: ' . get_class($error));
    mobileRespond(['error' => 'Не удалось подготовить консоль'], 502);
}
