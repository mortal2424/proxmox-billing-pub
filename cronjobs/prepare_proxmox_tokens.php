<?php
require_once __DIR__.'/../includes/cli_only.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/ProxmoxApiClient.php';
require_once __DIR__.'/../includes/proxmox_token_policy.php';
require_once __DIR__.'/../includes/backup_database.php';
$mode=$argv[1]??'--plan';
if(!in_array($mode,['--plan','--prepare','--check'],true)){fwrite(STDERR,"Usage: php cronjobs/prepare_proxmox_tokens.php [--plan|--prepare|--check]\n");exit(2);}
if($mode==='--plan'){echo json_encode(homevladTokenRoles(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit;}
$lock=null;
try{
 $lock=backupOperationLock(dirname(__DIR__));nodeCredentialKey();
 $directory=getenv('PROXMOX_TOKEN_CANDIDATE_DIR')?:dirname(__DIR__,2).'/.proxmox-api-candidates';
 if(!str_starts_with($directory,'/'))throw new RuntimeException('Каталог токенов должен быть абсолютным путём');
 $pdo=(new Database)->getConnection();$nodes=$pdo->query('SELECT * FROM proxmox_nodes WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC);
 foreach($nodes as$node){
  $node['api_port']=(int)($node['api_port']?:8006);
  $file=$directory.'/'.(int)$node['id'].'.json';
  if($mode==='--prepare'){
   $admin=new ProxmoxApiClient($node);
   $candidate=prepareHomevladToken(fn($method,$path,$params)=>$admin->request($method,$path,$params),$node,$directory);
  }else{
   if(!is_file($file)||is_link($file))throw new RuntimeException('Подготовленный токен не найден для узла '.(int)$node['id']);
   $candidate=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
   if(($candidate['node_id']??null)!==(int)$node['id']||($candidate['hostname']??'')!==$node['hostname']||($candidate['api_port']??0)!==$node['api_port'])throw new RuntimeException('Несовпадение подключения кандидата');
  }
  $api=new ProxmoxApiClient($candidate);
  if(!$api->usesToken()||($candidate['username']??'')!==HOMEVLAD_SERVICE_USER.'!homevlad-node-'.(int)$node['id'].'-v1')throw new RuntimeException('Неожиданная учётная запись кандидата');
  $policy=verifyHomevladTokenPermissions(fn($method,$path,$params)=>$api->request($method,$path,$params));
  $api->request('GET','/nodes/'.rawurlencode($node['node_name']).'/status');
  $api->request('GET','/nodes/'.rawurlencode($node['node_name']).'/qemu');
  $api->request('GET','/nodes/'.rawurlencode($node['node_name']).'/lxc');
  if($mode==='--prepare'){$candidate['state']='verified';$candidate['verified_at']=date(DATE_ATOM);writeHomevladTokenCandidate($file,$candidate);}
  echo 'NODE='.(int)$node['id'].' TOKEN_VERIFIED scopes='.$policy['scopes'].' privileges='.$policy['privileges'].PHP_EOL;
 }
}catch(Throwable$error){fwrite(STDERR,'Подготовка токенов остановлена: '.$error->getMessage().PHP_EOL);exit(1);}
finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
