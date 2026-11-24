<?php
/** Uses session-local TEMPORARY tables, never production VM/user rows or real guest APIs. */
if(PHP_SAPI!=='cli')exit(2);
$options=getopt('',['application-root:']);
if(empty($options['application-root']))exit(2);
require rtrim($options['application-root'],'/').'/includes/db.php';
require __DIR__.'/../includes/proxmox_functions.php';
function ensure($value,string $message):void{if(!$value)throw new RuntimeException($message);}
final class FixtureGuestTransport
{
    public array $config=['digest'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','scsi0'=>'fast:vm-120-disk-0,size=1G','cores'=>1,'memory'=>128,'sockets'=>1];
    public array $calls=[];
    public bool $failDelete=false;
    public bool $destroyed=false;
    public function request(string $method,string $path,array $parameters=[]):mixed{return $this->requestAndWait($method,$path,$parameters);}
    public function requestAndWait(string $method,string $path,array $parameters=[],int $timeout=180):mixed
    {
        $this->calls[]=[$method,$path,$parameters];
        if($path==='/nodes/node2/network' && $method==='GET')return [['iface'=>'vmbr0','type'=>'bridge','active'=>1],['iface'=>'management','type'=>'bridge','active'=>1]];
        if($path==='/nodes/node2/qemu'&&$method==='GET')return $this->destroyed?[]:[['vmid'=>120]];
        if($path==='/nodes/node2/qemu/120/config'&&$method==='GET')return $this->config;
        if($path==='/nodes/node2/qemu/120/status/current'&&$method==='GET')return ['status'=>'stopped'];
        if($method==='PUT'){
            ensure(($parameters['digest']??'')===$this->config['digest'],'Missing or stale digest');
            if(str_ends_with($path,'/resize'))$this->config[$parameters['disk']]='fast:vm-120-disk-0,size='.$parameters['size'];
            elseif(str_ends_with($path,'/config'))$this->config=array_replace($this->config,$parameters);
            else throw new RuntimeException('Unexpected fixture update');
            $this->config['digest']=sha1(json_encode($this->config));return null;
        }
        if($method==='DELETE'&&$path==='/nodes/node2/qemu/120'){
            if($this->failDelete)throw new RuntimeException('Fixture denial');$this->destroyed=true;return 'UPID:fixture';
        }
        throw new RuntimeException('Unexpected fixture route');
    }
    public function writes():int{return count(array_filter($this->calls,fn($call)=>$call[0]!=='GET'));}
}
$pdo=(new Database)->getConnection();$directory=sys_get_temp_dir().'/homevlad-db-isolation-'.bin2hex(random_bytes(8));putenv('PROXMOX_GUEST_LOCK_DIR='.$directory);
try{
 $pdo->exec('CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,is_admin INT,is_active INT)');
 $pdo->exec('CREATE TEMPORARY TABLE user_quotas(user_id INT PRIMARY KEY,max_cpu INT,max_ram INT,max_disk INT)');
 $pdo->exec('CREATE TEMPORARY TABLE tariffs(id INT PRIMARY KEY,cpu INT,ram INT,disk INT,is_active INT,is_custom INT,vm_type VARCHAR(10),os_type VARCHAR(10))');
 $pdo->exec("CREATE TEMPORARY TABLE vms(id INT PRIMARY KEY,node_id INT,vm_id INT,vm_type VARCHAR(10),user_id INT,cpu INT,ram INT,disk INT,tariff_id INT,is_custom INT,os_type VARCHAR(10),status VARCHAR(20),network VARCHAR(20),sdn VARCHAR(20),INDEX(vm_id)) ENGINE=InnoDB");
 $pdo->exec("CREATE TEMPORARY TABLE node_placement_pools(node_id INT,kind VARCHAR(12),pool_name VARCHAR(30),resource VARCHAR(30))");
 $pdo->exec("INSERT INTO node_placement_pools VALUES (1,'network','public','vmbr0'),(4,'network','private','management')");
 $pdo->exec('INSERT INTO users VALUES(100,0,1)');$pdo->exec('INSERT INTO user_quotas VALUES(100,8,2048,20)');
 $pdo->exec("INSERT INTO vms VALUES(1001,1,120,'qemu',100,1,128,1,0,1,'linux','stopped','vmbr0',NULL),(1004,4,120,'qemu',100,1,128,1,0,1,'linux','stopped','vmbr0',NULL)");
 $reflection=new ReflectionClass(ProxmoxAPI::class);$api=$reflection->newInstanceWithoutConstructor();$transport=new FixtureGuestTransport();
 foreach(['pdo'=>$pdo,'nodeId'=>1,'nodeName'=>'node2','apiClient'=>$transport]as$name=>$value){$property=$reflection->getProperty($name);$property->setAccessible(true);$property->setValue($api,$value);}
 $other=$pdo->query('SELECT * FROM vms WHERE id=1004')->fetch(PDO::FETCH_ASSOC);
 $result=$api->changeVmResources(120,'qemu',0,2,256,1);ensure($result['status']==='success','Resource change failed');
 $target=$pdo->query('SELECT * FROM vms WHERE id=1001')->fetch(PDO::FETCH_ASSOC);ensure((int)$target['cpu']===2&&(int)$target['ram']===256,'Real schema columns not updated');
 ensure($other===$pdo->query('SELECT * FROM vms WHERE id=1004')->fetch(PDO::FETCH_ASSOC),'Same VMID on another node changed');
 $writes=$transport->writes();$result=$api->changeVmResources(120,'qemu',0,8,256,1);ensure($result['status']==='error'&&$transport->writes()===$writes,'Quota bypass');
 $result=$api->changeVmDisk(120,'qemu','scsi0',2,'fast');ensure($result['status']==='success','Resize failed');
 ensure((int)$pdo->query('SELECT disk FROM vms WHERE id=1001')->fetchColumn()===2,'Disk size not saved');
 $result=$api->changeVmDisk(120,'qemu','scsi0',2,'fast');ensure($result['status']==='success','Idempotent resize failed');
 ensure((int)$pdo->query('SELECT disk FROM vms WHERE id=1001')->fetchColumn()===2,'Resize retry double counted disk');
 ensure($other===$pdo->query('SELECT * FROM vms WHERE id=1004')->fetch(PDO::FETCH_ASSOC),'Other node disk changed');
 $writes=$transport->writes();$result=$api->addVmNetwork(120,'qemu','net1','private');ensure($result['status']==='error'&&$transport->writes()===$writes,'Unassigned network accepted');
 $result=$api->addVmNetwork(120,'qemu','net1','public');ensure($result['status']==='success','Assigned network rejected');
 $writes=$transport->writes();$result=$api->deleteVm(120,'qemu');ensure($result['status']==='error'&&$transport->writes()===$writes,'Duplicate VMID deletion reached API');
 $pdo->exec('DELETE FROM vms WHERE id=1004');$transport->failDelete=true;$result=$api->deleteVm(120,'qemu');
 ensure($result['status']==='error'&&(int)$pdo->query('SELECT COUNT(*) FROM vms WHERE id=1001')->fetchColumn()===1,'API failure deleted billing row');
 $transport->failDelete=false;$result=$api->deleteVm(120,'qemu');ensure($result['status']==='success','Unique guest delete failed');
 ensure((int)$pdo->query('SELECT COUNT(*) FROM vms')->fetchColumn()===0,'Confirmed deletion not saved');
 ensure($reflection->getProperty('pdo')->getValue($api)===$pdo,'Fixture connection changed');
 ensure(ProxmoxApiClient::taskWarningCount('OK')===0&&ProxmoxApiClient::taskWarningCount('WARNINGS: 2')===2,'Warning completion rejected');
 foreach(['ERROR: failed','WARNINGS: 1 ERROR','WARNINGS: 0','']as$exit){$failed=false;try{ProxmoxApiClient::taskWarningCount($exit);}catch(RuntimeException$error){$failed=true;}ensure($failed,'Failure accepted as success');}
 echo "PASS temporary MySQL tables: node isolation, quotas, retry accounting, network restrictions, deletion ordering and task warnings\n";
}finally{
 if($pdo->inTransaction())$pdo->rollBack();
 $pdo->exec('DROP TEMPORARY TABLE IF EXISTS node_placement_pools,vms,tariffs,user_quotas,users');
 foreach(glob($directory.'/*')?:[]as$file)unlink($file);if(is_dir($directory))rmdir($directory);
}
