<?php
/** Explicit opt-in QA: stopped, unnetworked, uniquely marked guests; no billing rows. */
if(PHP_SAPI!=='cli')exit(2);
$options=getopt('',['application-root:','journal-dir:','node:','type:','execute']);
if(!isset($options['execute'],$options['application-root'],$options['journal-dir'],$options['node'])){
 fwrite(STDERR,"Requires --execute --application-root=... --journal-dir=... --node=ID. Creates and removes temporary stopped QEMU/LXC guests using API tokens only.\n");exit(2);
}
require rtrim($options['application-root'],'/').'/includes/db.php';
require __DIR__.'/../includes/ProxmoxApiClient.php';
require __DIR__.'/../includes/node_api_credentials.php';
require __DIR__.'/../includes/ProxmoxGuestApi.php';
$directory=$options['journal-dir'];
if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0700,true))||(fileperms($directory)&0077)!==0)throw new RuntimeException('Private QA journal required');
$pdo=(new Database)->getConnection();$query=$pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=? AND is_active=1');$query->execute([(int)$options['node']]);$node=$query->fetch(PDO::FETCH_ASSOC);
if(!$node)throw new RuntimeException('Node unavailable');
$client=new ProxmoxApiClient(panelNodeApiCredentials($node));if(!$client->usesToken())throw new RuntimeException('Token required; root fallback forbidden');
$base='/nodes/'.rawurlencode($node['node_name']);$stores=$client->request('GET',$base.'/storage');$failed=false;
usort($stores,fn($a,$b)=>(['lvmthin'=>0,'zfspool'=>1,'dir'=>2][$a['type']??'']??3)<=>(['lvmthin'=>0,'zfspool'=>1,'dir'=>2][$b['type']??'']??3));
$types=isset($options['type'])?[$options['type']]:['qemu','lxc'];
foreach($types as$type)if(!in_array($type,['qemu','lxc'],true))throw new InvalidArgumentException('Invalid QA guest type');
foreach($types as$type){
 $vmid=null;$marker='HomeVlad temporary API QA '.bin2hex(random_bytes(16));$journal=null;$stage='prepare';$finished=false;
 try{
  $storage=null;
  foreach($stores as$store)if(!empty($store['active'])&&empty($store['shared'])&&($store['avail']??0)>12884901888&&in_array($type==='qemu'?'images':'rootdir',explode(',',$store['content']??''),true)){$storage=$store['storage'];break;}
  if(!$storage)throw new RuntimeException('No suitable private QA storage');
  $template=null;
  if($type==='lxc'){
   foreach($stores as$store){if(empty($store['active'])||!in_array('vztmpl',explode(',',$store['content']??''),true))continue;
    foreach($client->request('GET',$base.'/storage/'.$store['storage'].'/content',['content'=>'vztmpl'])as$file){if(preg_match('#^[A-Za-z0-9_.-]+:vztmpl/[A-Za-z0-9_.+-]+$#D',$file['volid'])){$template=$file['volid'];break 2;}}
   }
   if(!$template)throw new RuntimeException('Existing LXC template required');
  }
  $vmid=(int)$client->request('GET','/cluster/nextid');
  for($attempt=0;$attempt<100;$attempt++,$vmid++){
   // Never expose QA guests through an existing customer's stale billing/import row.
   $check=$pdo->prepare('SELECT (SELECT COUNT(*) FROM vms WHERE vm_id=?)+(SELECT COUNT(*) FROM vms_admin WHERE vm_id=?)');$check->execute([$vmid,$vmid]);
   if((int)$check->fetchColumn()!==0)continue;
   try{if((int)$client->request('GET','/cluster/nextid',['vmid'=>$vmid])===$vmid)break;}catch(RuntimeException$error){if(!in_array($error->getCode(),[400,500],true))throw $error;}
  }
  if($attempt>=100)throw new RuntimeException('No safe temporary VMID');
  $path=$base.'/'.$type.'/'.$vmid;
  $journal=$directory.'/'.$node['id'].'-'.$type.'-'.bin2hex(random_bytes(8)).'.json';
  $record=['node_id'=>(int)$node['id'],'type'=>$type,'vmid'=>$vmid,'marker'=>$marker,'state'=>'prepared'];
  $save=function($state)use(&$record,$journal){$record['state']=$state;$handle=fopen($journal,'c');chmod($journal,0600);ftruncate($handle,0);fwrite($handle,json_encode($record,JSON_THROW_ON_ERROR));fflush($handle);fclose($handle);};$save('prepared');
  $parameters=['vmid'=>$vmid,'cores'=>1,'memory'=>128,'description'=>$marker];
  if($type==='qemu')$parameters+=['name'=>'homevlad-api-qa-'.$vmid,'scsihw'=>'virtio-scsi-pci','scsi0'=>$storage.':1','ostype'=>'l26'];
  else $parameters+=['hostname'=>'homevlad-api-qa-'.$vmid,'ostemplate'=>$template,'rootfs'=>$storage.':8','unprivileged'=>1,'features'=>'nesting=1','start'=>0];
  $stage='create';$client->requestAndWait('POST',$base.'/'.$type,$parameters,600);$save('created');
  $owned=function()use($client,$path,$marker){$config=$client->request('GET',$path.'/config');if(trim($config['description']??'')!==$marker)throw new RuntimeException('QA ownership mismatch');return $config;};
  $ownedConfig=$owned();
  $record['volumes']=[ProxmoxGuestApi::disk($ownedConfig,$type==='qemu'?'scsi0':'rootfs')['volume']];$save('ownership-verified');
  $api=new ProxmoxGuestApi(function($method,$requestPath,$params)use($client,$owned,$path){
   if(!str_starts_with($requestPath,$path.'/')&&$requestPath!==$path)throw new RuntimeException('QA route mismatch');
   if($method!=='GET')$owned();
   return $client->requestAndWait($method,$requestPath,$params,600);
  },$node['node_name']);
  $stage='resources';$api->resources($vmid,$type,2,256);$save('resources-verified');
  $stage='resize';$api->resize($vmid,$type,$type==='qemu'?'scsi0':'rootfs',$type==='qemu'?2:9,$storage);$save('resize-verified');
  $stage='delete';$api->destroy($vmid,$type);$save('deleted');$finished=true;
  foreach($client->request('GET',$base.'/'.$type)as$guest)if((int)$guest['vmid']===$vmid)throw new RuntimeException('QA guest remains after delete');
  foreach($record['volumes']as$volume){$store=explode(':',$volume,2)[0];foreach($client->request('GET',$base.'/storage/'.$store.'/content',['vmid'=>$vmid])as$entry)if($entry['volid']===$volume)throw new RuntimeException('QA volume remains after delete');}
  echo 'NODE='.(int)$node['id'].' '.strtoupper($type).'_TOKEN_CREATE_RESIZE_CONFIG_DELETE_OK'.PHP_EOL;
 }catch(Throwable$error){
  $failed=true;echo 'NODE='.(int)$node['id'].' '.strtoupper($type).'_QA_FAIL stage='.$stage.' class='.get_class($error).' code='.(int)$error->getCode().PHP_EOL;
  if($vmid!==null&&!$finished){
   try{
    $path=$base.'/'.$type.'/'.$vmid;$config=$client->request('GET',$path.'/config');
    if(trim($config['description']??'')!==$marker)throw new RuntimeException('Not our guest');
    $cleanup=new ProxmoxGuestApi(fn($m,$p,$a)=>$client->requestAndWait($m,$p,$a,600),$node['node_name']);
    $cleanup->destroy($vmid,$type);if($journal)$save('deleted-after-failure');
    echo 'NODE='.(int)$node['id'].' '.strtoupper($type).'_OWN_QA_CLEANED'.PHP_EOL;
   }catch(Throwable$cleanup){echo 'NODE='.(int)$node['id'].' '.strtoupper($type).'_CHECK_PRIVATE_QA_JOURNAL'.PHP_EOL;}
  }
 }
}
exit($failed?1:0);
