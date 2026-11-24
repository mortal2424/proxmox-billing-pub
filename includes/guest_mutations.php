<?php
require_once __DIR__ . '/license_guard.php';
require_once __DIR__.'/ProxmoxGuestApi.php';
require_once __DIR__.'/guest_operation_lock.php';

trait ProxmoxGuestMutations
{
    private function guestApi(): ProxmoxGuestApi
    {
        return new ProxmoxGuestApi(fn($method,$path,$params)=>$this->apiClient->requestAndWait($method,$path,$params),$this->nodeName);
    }
    private function customerGuest($vmid,string $type): array
    {
        $this->guestApi()->base($vmid,$type);
        $query=$this->pdo->prepare('SELECT v.*,u.is_admin AS owner_is_admin FROM vms v JOIN users u ON u.id=v.user_id WHERE v.node_id=? AND v.vm_id=? AND v.vm_type=?');
        $query->execute([$this->nodeId,(int)$vmid,$type]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||$rows[0]['status']==='deleted')throw new RuntimeException('Гость не найден на выбранной ноде');
        $vm=$rows[0];
        if(PHP_SAPI!=='cli'){
            $user=$this->pdo->prepare('SELECT id,is_active,is_admin FROM users WHERE id=?');
            $user->execute([(int)($_SESSION['user']['id']??0)]);$account=$user->fetch(PDO::FETCH_ASSOC);
            if(!$account||!$account['is_active']||((int)$account['id']!==(int)$vm['user_id']&&!$account['is_admin']))throw new RuntimeException('Доступ запрещён');
        }
        return $vm;
    }
    private function guestMutation($vmid,string $type,callable $operation): array
    {
        try{
            $vm=$this->customerGuest($vmid,$type);
            return withGuestOperationLocks(['user:'.$vm['user_id'],'guest:'.$this->nodeId.':'.$type.':'.(int)$vmid],function()use($vmid,$type,$operation,$vm){
                $current=$this->customerGuest($vmid,$type);
                if($current['id']!==$vm['id']||$current['user_id']!==$vm['user_id'])throw new RuntimeException('Владелец или запись гостя изменились');
                return $operation($current,$this->guestApi());
            });
        }catch(Throwable$error){
            error_log('Guest operation failed node='.(int)$this->nodeId.' vm='.(int)$vmid.' class='.get_class($error));
            return ['status'=>'error','message'=>$error instanceof PDOException?'Не удалось сохранить результат в БД. Проверьте состояние гостя перед повтором':$error->getMessage()];
        }
    }
    private function guestQuota(array $vm,int $cpu,int $ram,int $disk): void
    {
        if($cpu<1||$cpu>256||$ram<16||$ram>1048576||$disk<1||$disk>1048576)throw new InvalidArgumentException('Некорректные ресурсы');
        if(!empty($vm['owner_is_admin']))return;
        $query=$this->pdo->prepare('SELECT max_cpu,max_ram,max_disk FROM user_quotas WHERE user_id=?');
        $query->execute([$vm['user_id']]);$quota=$query->fetch(PDO::FETCH_ASSOC)?:['max_cpu'=>10,'max_ram'=>10240,'max_disk'=>200];
        $query=$this->pdo->prepare("SELECT COALESCE(SUM(cpu),0) AS cpu,COALESCE(SUM(ram),0) AS ram,COALESCE(SUM(disk),0) AS disk FROM vms WHERE user_id=? AND id<>? AND status<>'deleted'");
        $query->execute([$vm['user_id'],$vm['id']]);$usage=$query->fetch(PDO::FETCH_ASSOC);
        foreach(['cpu'=>$cpu,'ram'=>$ram,'disk'=>$disk]as$key=>$value){
            if((int)$usage[$key]+$value>(int)$quota['max_'.$key])throw new RuntimeException('Превышена квота '.strtoupper($key));
        }
    }
    private function saveGuestMutation(array $vm,string $assignments,array $parameters): void
    {
        // A remote UPID can outlive MySQL's idle timeout. Reconnect only before the short write.
        try{$this->pdo->query('SELECT 1');}catch(PDOException$error){
            if(!in_array((int)($error->errorInfo[1]??0),[2006,2013],true))throw $error;
            $this->pdo=(new Database())->getConnection();
        }
        $query=$this->pdo->prepare('UPDATE vms SET '.$assignments.' WHERE id=? AND node_id=? AND vm_id=? AND vm_type=? AND user_id=?');
        $identity=[$vm['id'],$this->nodeId,$vm['vm_id'],$vm['vm_type'],$vm['user_id']];
        $query->execute(array_merge($parameters,$identity));
        if($query->rowCount()===0){
            $exists=$this->pdo->prepare('SELECT id FROM vms WHERE id=? AND node_id=? AND vm_id=? AND vm_type=? AND user_id=?');
            $exists->execute($identity);if(!$exists->fetchColumn())throw new RuntimeException('Запись гостя изменилась. Проверьте результат операции на Proxmox');
        }
    }
    public function changeVmResources($vmid,$type,$plan_id,$custom_cpu=null,$custom_ram=null,$custom_disk=null)
    {
        return $this->guestMutation($vmid,(string)$type,function($vm,$api)use($vmid,$type,$plan_id,$custom_cpu,$custom_ram,$custom_disk){
            if(in_array($vm['status'],['creating','suspended'],true))throw new RuntimeException('Изменение ресурсов сейчас недоступно');
            $planId=filter_var($plan_id??0,FILTER_VALIDATE_INT);
            if($planId===false||$planId<0)throw new InvalidArgumentException('Некорректный тариф');
            if($planId>0){
                $query=$this->pdo->prepare('SELECT cpu,ram,disk FROM tariffs WHERE id=? AND is_active=1 AND is_custom=0 AND vm_type=? AND os_type=?');
                $query->execute([$planId,$type,$vm['os_type']]);$plan=$query->fetch(PDO::FETCH_ASSOC);
                if(!$plan)throw new RuntimeException('Тариф недоступен для этого гостя');
                [$cpu,$ram,$disk]=[(int)$plan['cpu'],(int)$plan['ram'],(int)$plan['disk']];
            }else{
                $cpu=filter_var($custom_cpu,FILTER_VALIDATE_INT);$ram=filter_var($custom_ram,FILTER_VALIDATE_INT);$disk=filter_var($custom_disk,FILTER_VALIDATE_INT);
                if($cpu===false||$ram===false||$disk===false)throw new InvalidArgumentException('Укажите CPU, RAM и текущий размер дисков');
            }
            $config = $api->config($vmid, $type);
            $actual = ProxmoxGuestApi::allocatedGiB($config, $type);
            $currentCpu = (int)($config['cores'] ?? 1) * ($type === 'qemu' ? (int)($config['sockets'] ?? 1) : 1);
            $currentRam = (int)($config['memory'] ?? $vm['ram']);
            if ($planId > 0) {
                if ($cpu < max($currentCpu, (int)$vm['cpu']) || $ram < max($currentRam, (int)$vm['ram']) ||
                    $disk < max($actual, (int)$vm['disk']) ||
                    ($cpu === (int)$vm['cpu'] && $ram === (int)$vm['ram'] && $disk === (int)$vm['disk'])) {
                    throw new RuntimeException('Новый тариф должен увеличивать ресурсы гостя');
                }
            } elseif ($disk !== $actual) {
                throw new RuntimeException('Размер дисков меняется отдельно в разделе «Диски». Укажите текущий размер');
            }
            $this->guestQuota($vm, $cpu, $ram, $disk);
            $api->stopped($vmid, $type);
            return homevladLicensedMutation($this->pdo, ['vcpu' => $cpu - (int)$vm['cpu'], 'ram_mib' => $ram - (int)$vm['ram']],
                function () use ($api, $vmid, $type, $cpu, $ram, $disk, $actual, $config, $vm, $planId) {
            if ($disk > $actual) {
                $disks = ProxmoxGuestApi::allocatedDisks($config, $type);
                if (count($disks) !== 1) {
                    throw new RuntimeException('При смене тарифа можно увеличить диск гостя с одним диском');
                }
                $api->resize($vmid, $type, array_key_first($disks), $disk);
            }
            try {
                $api->resources($vmid, $type, $cpu, $ram);
            } catch (Throwable $error) {
                if ($disk > $actual) {
                    throw new RuntimeException('Диск увеличен. CPU и память требуют проверки. Повторите переход на тот же тариф после проверки.', 0, $error);
                }
                throw $error;
            }
            $this->saveGuestMutation($vm, 'cpu=?,ram=?,disk=?,tariff_id=?,is_custom=?', [$cpu, $ram, $disk, $planId, $planId===0?1:0]);
            return ['status'=>'success','message'=>'Ресурсы и тариф обновлены. Гость остаётся остановленным'];
            });
        });
    }
    public function changeVmDisk($vmid,$type,$disk_id,$new_size,$new_storage)
    {
        return $this->guestMutation($vmid,(string)$type,function($vm,$api)use($vmid,$type,$disk_id,$new_size,$new_storage){
            if(in_array($vm['status'],['creating','suspended'],true))throw new RuntimeException('Изменение диска сейчас недоступно');
            if(empty($vm['is_custom']))throw new RuntimeException('Для отдельного увеличения диска сначала выберите индивидуальные ресурсы');
            $size=filter_var($new_size,FILTER_VALIDATE_INT);
            if($size===false||$size<1||$size>1048576)throw new InvalidArgumentException('Некорректный размер диска');
            $key=ProxmoxGuestApi::diskKey($type,$disk_id);$config=$api->config($vmid,$type);$before=ProxmoxGuestApi::disk($config,$key);
            if($size*1073741824<$before['bytes'])throw new RuntimeException('Уменьшение диска запрещено');
            $predicted=(int)ceil((ProxmoxGuestApi::allocatedBytes($config,$type)-$before['bytes']+$size*1073741824)/1073741824);
            $this->guestQuota($vm,(int)$vm['cpu'],(int)$vm['ram'],$predicted);
            $api->resize($vmid,$type,$key,$size,(string)$new_storage);
            $actual=ProxmoxGuestApi::allocatedGiB($api->config($vmid,$type),$type);
            $this->saveGuestMutation($vm,'disk=?',[$actual]);
            return ['status'=>'success','message'=>'Существующий том увеличен. Размер раздела и файловой системы внутри ВМ проверьте в гостевой ОС'];
        });
    }
    public function guestNetworkPoolOptions(): array
    {
        $query = $this->pdo->prepare("SELECT DISTINCT pool_name, resource FROM node_placement_pools
            WHERE node_id=? AND kind='network' ORDER BY pool_name, resource");
        $query->execute([$this->nodeId]);
        $members = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$members) return [];
        $interfaces = $this->request('GET', '/nodes/' . rawurlencode($this->nodeName) . '/network', ['type'=>'any_bridge']);
        $live = [];
        foreach ($interfaces as $row) {
            $type = $row['type'] ?? '';
            if (in_array($type, ['bridge', 'OVSBridge', 'vnet'], true) &&
                (!empty($row['active']) || $type === 'vnet')) $live[$row['iface']] = $type;
        }
        $sdn = [];
        if (array_filter($members, static fn(array $member): bool => str_contains($member['resource'], '/'))) {
            foreach ($this->request('GET', '/cluster/sdn/vnets') as $vnet) {
                if (isset($vnet['zone'], $vnet['vnet'])) $sdn[$vnet['zone'] . '/' . $vnet['vnet']] = $vnet['vnet'];
            }
        }
        $available = [];
        foreach ($members as $member) {
            $resource = $member['resource'];
            $bridge = $sdn[$resource] ?? $resource;
            if (!isset($live[$bridge]) || (isset($sdn[$resource]) !== ($live[$bridge] === 'vnet'))) continue;
            $available[$member['pool_name']] ??= $bridge;
        }
        return $available;
    }

    public function addVmNetwork($vmid,$type,$network_id,$pool)
    {
        return $this->guestMutation($vmid,(string)$type,function($vm,$api)use($vmid,$type,$network_id,$pool){
            if(in_array($vm['status'],['creating','suspended'],true))throw new RuntimeException('Изменение сети сейчас недоступно');
            if (!is_string($pool)) throw new InvalidArgumentException('Выберите сетевой пул');
            $available = $this->guestNetworkPoolOptions();
            $bridge = $available[$pool] ?? null;
            if ($bridge === null) throw new RuntimeException('Сетевой пул недоступен на ноде гостя');
            $api->addNetwork($vmid,$type,$network_id,$bridge);
            return ['status'=>'success','message'=>'Сетевой интерфейс добавлен'];
        });
    }
    public function removeVmNetwork($vmid, string $type, string $key): array
    {
        return $this->guestMutation($vmid, $type, static function (array $vm, ProxmoxGuestApi $api) use ($vmid, $type, $key): array {
            if (in_array($vm['status'], ['creating', 'suspended'], true)) {
                throw new RuntimeException('Изменение сети сейчас недоступно');
            }
            $api->removeNetwork($vmid, $type, $key);
            return ['status' => 'success', 'message' => 'Дополнительный интерфейс удалён'];
        });
    }

    public function deleteVm($vmid,$type)
    {
        return $this->guestMutation($vmid,(string)$type,function($vm,$api)use($vmid,$type){
            $links = $this->pdo->query("
                SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vm_billing'
                    AND REFERENCED_TABLE_NAME='vms'
            ")->fetchAll(PDO::FETCH_ASSOC);
            $hasGuestLink = $links === [['COLUMN_NAME'=>'guest_id', 'REFERENCED_COLUMN_NAME'=>'id']];
            if (!$hasGuestLink) {
                $query = $this->pdo->prepare('SELECT COUNT(*) FROM vms WHERE vm_id=? AND id<>?');
                $query->execute([$vmid, $vm['id']]);
                if ((int)$query->fetchColumn() > 0) {
                    throw new RuntimeException('Удаление повторяющегося VMID доступно после миграции биллинга');
                }
            }
            $api->destroy($vmid,$type);
            try{
                $this->pdo->query('SELECT 1');
                $this->pdo->beginTransaction();
                $guard = $this->pdo->prepare($hasGuestLink
                    ? 'SELECT id,user_id,node_id,vm_type FROM vms WHERE id=? AND vm_id=? FOR UPDATE'
                    : 'SELECT id,user_id,node_id,vm_type FROM vms WHERE vm_id=? FOR UPDATE');
                $guard->execute($hasGuestLink ? [$vm['id'], $vmid] : [$vmid]);
                $matching=$guard->fetchAll(PDO::FETCH_ASSOC);
                if(count($matching)!==1||(int)$matching[0]['id']!==(int)$vm['id']||(int)$matching[0]['user_id']!==(int)$vm['user_id']||(int)$matching[0]['node_id']!==(int)$this->nodeId||$matching[0]['vm_type']!==$type)throw new RuntimeException('Изменились связи VMID в БД');
                $query=$this->pdo->prepare('DELETE FROM vms WHERE id=? AND node_id=? AND vm_id=? AND vm_type=? AND user_id=?');
                $query->execute([$vm['id'],$this->nodeId,$vmid,$type,$vm['user_id']]);
                if($query->rowCount()!==1)throw new RuntimeException('Не подтверждено удаление записи гостя');
                $this->pdo->commit();
            }catch(Throwable$error){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                error_log('Guest removed on Proxmox but DB cleanup failed node='.(int)$this->nodeId.' vm='.(int)$vmid);
                throw new RuntimeException('Гость удалён на Proxmox, но запись БД требует проверки администратора. Не повторяйте удаление');
            }
            return ['status'=>'success','message'=>'Гость удалён'];
        });
    }
}
