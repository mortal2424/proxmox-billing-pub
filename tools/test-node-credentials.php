<?php
if (PHP_SAPI !== 'cli') exit(2);
$root=realpath($argv[1]??'');if(!$root)exit(2);
require $root.'/includes/ProxmoxApiClient.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function refuse(Closure $callback,$message){try{$callback();}catch(Throwable $e){return;}throw new RuntimeException($message);}
putenv('PROXMOX_NODE_KEY='.base64_encode(random_bytes(32)));
$value=" secret with spaces 🌤️ ";$encrypted=encryptNodePassword($value);
check($encrypted!==encryptNodePassword($value),'Repeated nonce');
check(decryptNodePassword($encrypted)===$value,'Roundtrip changed password');
check(decryptNodePassword('legacy')==='legacy','Legacy compatibility');
$tampered=$encrypted;$tampered[strlen($tampered)-3]=$tampered[strlen($tampered)-3]==='A'?'B':'A';
refuse(fn()=>decryptNodePassword($tampered),'Accepted tampering');
putenv('PROXMOX_NODE_KEY='.base64_encode(random_bytes(32)));
refuse(fn()=>decryptNodePassword($encrypted),'Accepted wrong key');
refuse(fn()=>encryptNodePassword(str_repeat('a',300)),'Accepted oversized secret');
echo "PASS AES-GCM roundtrip, distinct nonces, tamper/wrong-key denial and legacy compatibility\n";
if(!empty($argv[2])){
 $url=parse_url($argv[2]);$base=['hostname'=>$url['host'],'api_port'=>$url['port']];
 $api=new ProxmoxApiClient($base+['username'=>'fixture@pve!billing','password'=>encryptNodePassword('fixture-token-secret')]);
 check($api->usesToken(),'Token mode');refuse(fn()=>$api->getPasswordTicket(),'Token requested password login');
 check($api->request('GET','/version')['mode']==='token','Token header');
 check($api->request('POST','/nodes/fixture/qemu/100/vncproxy',['websocket'=>1])['mode']==='token','Token POST header');
 $api=new ProxmoxApiClient($base+['username'=>'fixture','password'=>encryptNodePassword('fixture-password')]);
 $ticket=$api->getPasswordTicket();check($ticket['ticket']==='fixture-ticket','Ticket mode');
 check($api->request('GET','/version')['mode']==='password','Password cookie');
 check($api->request('POST','/nodes/fixture/qemu/100/vncproxy',['websocket'=>1])['mode']==='password','Password CSRF header');
 refuse(fn()=>$api->request('GET','/../access/users'),'Path traversal accepted');
 echo "PASS pinned HTTPS, token-only GET/POST and password ticket/cookie/CSRF authentication\n";
}
