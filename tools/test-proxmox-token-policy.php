<?php
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(2);
require $argv[1].'/includes/proxmox_token_policy.php';
putenv('PROXMOX_NODE_KEY='.base64_encode(random_bytes(32)));
function ensure($ok,$message){if(!$ok)throw new RuntimeException($message);}
function deny(Closure $call,$message){try{$call();}catch(Throwable$e){return;}throw new RuntimeException($message);}
$directory=sys_get_temp_dir().'/homevlad-token-fixture-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$node=['id'=>42,'hostname'=>'fixture.invalid','api_port'=>8006];$calls=[];$tokens=[];$roles=[];$users=[];
$request=function($method,$path,$parameters)use(&$calls,&$tokens,&$roles,&$users){
 $calls[]=[$method,$path,$parameters];
 if($path==='/access/roles'&&$method==='GET')return $roles;
 if($path==='/access/roles'&&$method==='POST'){$roles[]=['roleid'=>$parameters['roleid'],'privs'=>$parameters['privs']];return null;}
 if($path==='/access/users'&&$method==='GET')return $users;
 if($path==='/access/users'&&$method==='POST'){$users[]=$parameters;return null;}
 if($path==='/access/acl')return null;
 if(str_ends_with($path,'/token')&&$method==='GET')return $tokens;
 if($method==='POST'&&str_contains($path,'/token/')){$tokens[]=['tokenid'=>'homevlad-node-42-v1'];return ['full-tokenid'=>HOMEVLAD_SERVICE_USER.'!homevlad-node-42-v1','value'=>'fictional-token-secret','info'=>['privsep'=>1]];}
 if($method==='GET'&&str_contains($path,'/token/'))return ['privsep'=>1];
 throw new RuntimeException('Unexpected fixture request');
};
try{
 $candidate=prepareHomevladToken($request,$node,$directory);
 ensure(str_starts_with($candidate['password'],NODE_PASSWORD_PREFIX),'Plain secret saved');
 ensure(decryptNodePassword($candidate['password'])==='fictional-token-secret','Secret roundtrip failed');
 ensure(!str_contains(file_get_contents($directory.'/42.json'),'fictional-token-secret'),'Plain secret on disk');
 ensure((fileperms($directory.'/42.json')&0777)===0600,'Candidate permissions');
 ensure(count($tokens)===1&&count($roles)===4&&count($users)===1,'Provisioning counts');
 $again=prepareHomevladToken($request,$node,$directory);ensure($again===$candidate&&count($tokens)===1,'Recreated existing token');
 $original=$roles[0]['privs'];$roles[0]['privs'].=',Sys.Modify';
 deny(fn()=>prepareHomevladToken($request,$node,$directory),'Broadened role reused');$roles[0]['privs']=$original;
 $revoked=0;
 $invalid=function($m,$p,$a)use($request,&$revoked){
  if($m==='POST'&&str_ends_with($p,'/homevlad-node-43-v1'))return ['full-tokenid'=>HOMEVLAD_SERVICE_USER.'!homevlad-node-43-v1','value'=>'fictional','info'=>['privsep'=>0]];
  if($m==='DELETE'&&str_ends_with($p,'/homevlad-node-43-v1')){$revoked++;return null;}
  return $request($m,$p,$a);
 };
 deny(fn()=>prepareHomevladToken($invalid,array_replace($node,['id'=>43]),$directory),'Disabled privilege separation accepted');
 ensure($revoked===1&&!file_exists($directory.'/43.json'),'Unusable token not revoked');
 deny(fn()=>prepareHomevladToken($request,array_replace($node,['hostname'=>'other.invalid']),$directory),'Wrong host binding accepted');
 $permissions=function($method,$path,$parameters){$result=[];foreach(homevladTokenRoles()as$role)$result[$role['path']]=array_fill_keys($role['privileges'],1);return isset($parameters['path'])?[$parameters['path']=>$result[$parameters['path']]]:$result;};
 ensure(verifyHomevladTokenPermissions($permissions)['scopes']===4,'Permission verification failed');
 deny(fn()=>verifyHomevladTokenPermissions(fn($m,$p,$a)=>['/'=>['Permissions.Modify'=>1]]),'Privilege escalation accepted');
 deny(fn()=>verifyHomevladTokenPermissions(fn($m,$p,$a)=>['/'=>['Sys.Audit'=>1]]),'Missing permissions accepted');
 unlink($directory.'/42.json');
 deny(fn()=>prepareHomevladToken($request,$node,$directory),'Regenerated token with missing secret');
 echo "PASS limited policy, encrypted persistence, retry, host binding and excess/missing permission denial\n";
}finally{foreach(glob($directory.'/*')as$file)unlink($file);rmdir($directory);}
