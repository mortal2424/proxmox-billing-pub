<?php
require __DIR__.'/../includes/ProxmoxGuestApi.php';
require __DIR__.'/../includes/guest_operation_lock.php';
function ensure($value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $operation):void{$failed=false;try{$operation();}catch(InvalidArgumentException|RuntimeException $error){$failed=true;}ensure($failed,'Unsafe operation accepted');}
foreach(['qemu','lxc']as$type){
 $disk=$type==='qemu'?'scsi0':'rootfs';$calls=[];$state='stopped';$fail=false;$protection=false;$lock=false;$destroyed=false;
 $config=['digest'=>str_repeat('a',40),$disk=>'fast:vm-120-disk-0,size=1G','cores'=>1,'memory'=>128];
 $api=new ProxmoxGuestApi(function($method,$path,$params)use(&$config,&$calls,&$state,&$fail,&$protection,&$lock,&$destroyed,$disk){
  $calls[]=[$method,$path,$params];
  if($method==='GET'&&preg_match('#^/nodes/node2/(?:qemu|lxc)$#D',$path))return $destroyed?[]:[['vmid'=>120]];
  if($method==='GET'&&str_ends_with($path,'/config'))return $config+['protection'=>$protection,'lock'=>$lock?'backup':''];
  if($method==='GET'&&str_ends_with($path,'/status/current'))return ['status'=>$state];
  if($fail)throw new RuntimeException('API denied');
  if($method==='PUT'&&str_ends_with($path,'/resize')){$config[$disk]='fast:vm-120-disk-0,size='.$params['size'];return 'UPID:fixture';}
  if($method==='PUT'&&str_ends_with($path,'/config')){$config=array_replace($config,$params);return null;}
  if($method==='DELETE'){$destroyed=true;return 'UPID:fixture';}
  throw new LogicException('Unexpected call');
 },'node2');
 $result=$api->resize(120,$type,$disk,2,'fast');
 ensure($result['after']['volume']==='fast:vm-120-disk-0'&&$result['after']['bytes']===2147483648.0,'Existing volume not preserved');
 $resize=array_values(array_filter($calls,fn($call)=>$call[0]==='PUT'))[0];
 ensure($resize===['PUT','/nodes/node2/'.$type.'/120/resize',['disk'=>$disk,'size'=>'2G','digest'=>str_repeat('a',40)]],'Wrong resize endpoint');
 $writes=count(array_filter($calls,fn($call)=>$call[0]!=='GET'));
 denied(fn()=>$api->resize(120,$type,$disk,1,'fast'));
 denied(fn()=>$api->resize(120,$type,$disk,3,'other'));
 ensure($writes===count(array_filter($calls,fn($call)=>$call[0]!=='GET')),'Invalid resize mutated guest');
 $api->resources(120,$type,2,256);ensure($config['cores']===2&&$config['memory']===256,'Resources not updated');
 $state='running';denied(fn()=>$api->resources(120,$type,3,512));denied(fn()=>$api->destroy(120,$type));$state='stopped';
 $lock=true;denied(fn()=>$api->resize(120,$type,$disk,3));$lock=false;
 $protection=true;denied(fn()=>$api->destroy(120,$type));$protection=false;
 $api->addNetwork(120,$type,'net1','vmbr0');denied(fn()=>$api->addNetwork(120,$type,'net1','vmbr0'));
 $net=$config['net1'];ensure(str_starts_with($net,$type==='qemu'?'virtio,':'name=eth1,'),'Invalid network format');
 $fail=true;denied(fn()=>$api->resize(120,$type,$disk,3));$fail=false;
 $api->destroy(120,$type);$deletions=array_values(array_filter($calls,fn($call)=>$call[0]==='DELETE'));$last=end($deletions);ensure($last===['DELETE','/nodes/node2/'.$type.'/120',[]],'Deletion scope broadened');
 denied(fn()=>$api->destroy('120;id',$type));denied(fn()=>$api->resize(120,$type,'scsi0;id',3));
 echo 'PASS '.$type.' resize, digest, resources, locks, protection, network and deletion'.PHP_EOL;
}
ensure(ProxmoxGuestApi::diskKey('qemu','sata0')==='sata0','Disk bus lost');
ensure(ProxmoxGuestApi::allocatedGiB(['scsi0'=>'fast:vm-120-disk-0,size=512M','ide2'=>'local:iso/test.iso,media=cdrom'],'qemu')===1,'Size units or CDROM wrong');
$directory=sys_get_temp_dir().'/homevlad-guest-lock-test-'.bin2hex(random_bytes(8));putenv('PROXMOX_GUEST_LOCK_DIR='.$directory);
try{
 withGuestOperationLocks(['user:1','guest:1:120'],function(){denied(fn()=>withGuestOperationLocks(['user:1'],fn()=>null));});
 ensure(withGuestOperationLocks(['user:1'],fn()=>42)===42,'Lock not released');
 denied(fn()=>withGuestOperationLocks(['user:1'],function(){throw new RuntimeException('Fixture');}));
 ensure(withGuestOperationLocks(['user:1'],fn()=>42)===42,'Failure retained lock');
 echo 'PASS mutation concurrency and failure release'.PHP_EOL;
}finally{foreach(glob($directory.'/*')as$file)unlink($file);rmdir($directory);}
