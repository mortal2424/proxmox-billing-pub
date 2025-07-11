<?php
require_once 'includes/proxmox.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $vm_id = $_POST['vm_id'];
    
    $proxmox = new ProxmoxHelper();
    switch ($action) {
        case 'start':
            $proxmox->startVM($vm_id);
            break;
        case 'stop':
            $proxmox->stopVM($vm_id);
            break;
    }
}
?>

<form method="POST" action="vm_control.php">
    <input type="hidden" name="vm_id" value="<?= $vm['vm_id'] ?>">
    <button type="submit" name="action" value="start">Старт</button>
    <button type="submit" name="action" value="stop">Стоп</button>
</form>