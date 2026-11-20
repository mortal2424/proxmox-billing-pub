<?php
require __DIR__.'/../includes/ProxmoxCommandApi.php';
$calls=[];
$api=new ProxmoxCommandApi(function($method,$path,$options)use(&$calls){$calls[]=[$method,$path,$options];return ['ok'=>true];},'node2');
function check($condition):void {if(!$condition)throw new RuntimeException('Assertion failed');}
$api->execute("qm set 120 --description 'customer\u{0027}\\\u{0027}\u{0027}s server' --memory 2048");
check($calls[0]===['PUT','/nodes/node2/qemu/120/config',['description'=>"customer's server",'memory'=>'2048']]);
$api->execute("pct create 121 local:vztmpl/debian.tar.zst --storage fast --rootfs 10 --password 'quoted ; password' --unprivileged 1");
check($calls[1][0]==='POST'&&$calls[1][1]==='/nodes/node2/lxc'&&$calls[1][2]['rootfs']==='fast:10'&&$calls[1][2]['password']==='quoted ; password');
$api->execute('pvesh create /nodes/node2/lxc/121/status/stop --force-stop 1');
check($calls[2][2]===['overrule-shutdown'=>1]);
$api->execute('pvesh get /cluster/nextid --output-format json');
check($calls[3]===['GET','/cluster/nextid',[]]);
foreach(['qm start 120; id','qm start $(id)','ssh root@host','pct create 121 local:vztmpl/a.tar.zst --unprivileged 0','pvesh get /nodes/other/status','qm set 120 --memory 1 --memory 2',"qm set 120 --name 'unfinished","qm start 120\nid"]as$command){
 $before=count($calls);$rejected=false;try{$api->execute($command);}catch(InvalidArgumentException|RuntimeException $e){$rejected=true;}check($rejected&&count($calls)===$before);
}
$failing=new ProxmoxCommandApi(function(){throw new RuntimeException('API denied');},'node2');
try{$failing->execute('qm start 120');throw new LogicException('API error swallowed');}catch(RuntimeException $e){check($e->getMessage()==='API denied');}
echo "PASS API translation, quoting, rejection and error propagation\n";
