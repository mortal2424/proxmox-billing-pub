<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('HOMEVLAD_DOCKER_SMOKE') !== '1' || getenv('DB_NAME') !== 'homevlad_smoke') {
    fwrite(STDERR, "Disposable Docker smoke database required\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'nginx';
ini_set('session.use_cookies', '0');
require '/var/www/html/includes/db.php';
require '/var/www/html/includes/node_credentials.php';

$pdo = (new Database())->getConnection();
$pdo->exec("INSERT INTO proxmox_clusters (name,is_active) VALUES ('Docker smoke',1)");
$zone = (int)$pdo->lastInsertId();
$query = $pdo->prepare('INSERT INTO proxmox_nodes (cluster_id,node_name,hostname,api_port,username,password,is_active) VALUES (?,?,?,?,?,?,1)');
$query->execute([$zone,'qa','apache',8006,'qa@pve!panel',encryptNodePassword('docker-smoke-token')]);
$node = (int)$pdo->lastInsertId();
$query = $pdo->prepare('INSERT INTO users (email,phone,first_name,last_name,full_name,password_hash,balance,is_active,is_admin)
    VALUES (?,?,?,?,?,?,100,1,?)');
$accounts = [];
foreach (['customer'=>0,'other'=>0,'admin'=>1] as $label=>$admin) {
    $query->execute(["$label@example.invalid",'','','',$label,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$admin]);
    $accounts[$label] = (int)$pdo->lastInsertId();
}
$query = $pdo->prepare("INSERT INTO vms (user_id,vm_id,node_id,hostname,cpu,ram,disk,storage,status,vm_type,is_custom)
    VALUES (?,?,?,?,1,256,1,'qa-storage','stopped',?,1)");
foreach ([501=>'qemu',502=>'lxc'] as $vm=>$type) {
    $query->execute([$accounts['customer'],$vm,$node,"qa-$type",$type]);
}
$managed = $pdo->prepare("INSERT INTO vms_admin (user_id,vm_id,node_id,hostname,cpu,ram,disk,storage,status)
    VALUES (?,503,?,'qa-managed',1,256,1,'qa-storage','stopped')");
$managed->execute([$accounts['admin'],$node]);

$sessionFiles = [];
foreach ($accounts as $label=>$id) {
    session_id(bin2hex(random_bytes(24)));
    if (!session_start()) throw new RuntimeException("Could not start fixture session");
    $_SESSION = [
        'user'=>['auth_generation'=>1,'id'=>$id,'is_admin'=>(int)($label==='admin'),'email'=>"$label@example.invalid"],
        'update_csrf'=>bin2hex(random_bytes(32)),
        'node_csrf'=>bin2hex(random_bytes(32)),
        'mobile_csrf'=>bin2hex(random_bytes(32)),
    ];
    $sessionFiles[$label] = ['id'=>session_id(),'csrf'=>$_SESSION['update_csrf'],
        'node_csrf'=>$_SESSION['node_csrf'],'mobile_csrf'=>$_SESSION['mobile_csrf']];
    session_write_close();
}
file_put_contents('/tmp/homevlad-docker-sessions.json',json_encode(['node'=>$node,'sessions'=>$sessionFiles],JSON_THROW_ON_ERROR));
chmod('/tmp/homevlad-docker-sessions.json',0600);
