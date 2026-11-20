<?php
/** Restricted guest mutations. The callback must wait for asynchronous UPID tasks. */
final class ProxmoxGuestApi
{
    private Closure $request;
    public function __construct(callable $request, private string $node)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$node)) throw new InvalidArgumentException('Некорректная нода');
        $this->request=Closure::fromCallable($request);
    }
    public function base($vmid,string $type): string
    {
        if (!in_array($type,['qemu','lxc'],true)||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$vmid)) {
            throw new InvalidArgumentException('Некорректный тип гостя или VMID');
        }
        return '/nodes/'.$this->node.'/'.$type.'/'.(int)$vmid;
    }
    public function config($vmid,string $type): array
    {
        $config=($this->request)('GET',$this->base($vmid,$type).'/config',[]);
        if(!is_array($config)||!preg_match('/^[a-f0-9]{40}$/iD',(string)($config['digest']??''))) {
            throw new RuntimeException('Не получена проверяемая конфигурация гостя');
        }
        if(!empty($config['lock'])||!empty($config['template']))throw new RuntimeException('Гость заблокирован или является шаблоном');
        return $config;
    }
    public function stopped($vmid,string $type): void
    {
        $status=($this->request)('GET',$this->base($vmid,$type).'/status/current',[]);
        if(($status['status']??'')!=='stopped')throw new RuntimeException('Сначала остановите ВМ или контейнер');
    }
    public static function diskKey(string $type,$disk): string
    {
        if($type==='lxc'&&in_array((string)$disk,['0','rootfs'],true))return 'rootfs';
        $key=ctype_digit((string)$disk)?'scsi'.$disk:(string)$disk;
        if($type!=='qemu'||!preg_match('/^(?:scsi(?:[0-9]|[12][0-9]|30)|virtio(?:[0-9]|1[0-5])|sata[0-5]|ide[0-3])$/D',$key)) {
            throw new InvalidArgumentException('Некорректный диск');
        }
        return $key;
    }
    public static function disk(array $config,string $key): array
    {
        $value=$config[$key]??null;
        if(!is_string($value)||str_contains($value,'media=cdrom')||
            !preg_match('/^([A-Za-z0-9_.-]+):([^,]+)(?:,|$)/D',$value,$volume)||
            !preg_match('/(?:^|,)size=([0-9]+(?:\.[0-9]+)?)([KMGT]?)(?:,|$)/D',$value,$size)) {
            throw new RuntimeException('Не удалось безопасно определить том и размер диска');
        }
        $units=[''=>1,'K'=>1024,'M'=>1048576,'G'=>1073741824,'T'=>1099511627776];
        $bytes=(float)$size[1]*$units[$size[2]];
        if(!is_finite($bytes)||$bytes<=0)throw new RuntimeException('Некорректный размер тома');
        return ['volume'=>$volume[1].':'.$volume[2], 'storage'=>$volume[1], 'bytes'=>$bytes];
    }
    public static function allocatedBytes(array $config,string $type): float
    {
        $volumes=[];
        foreach($config as$key=>$value){
            if(!preg_match($type==='lxc'?'/^(?:rootfs|mp[0-9]+)$/D':'/^(?:scsi|virtio|sata|ide)[0-9]+$/D',(string)$key))continue;
            if(!is_string($value)||str_contains($value,'media=cdrom')||$value==='none')continue;
            // LXC bind mounts are host paths, not allocated storage volumes.
            if($type==='lxc'&&str_starts_with($value,'/'))continue;
            $disk=self::disk($config,$key);$volumes[$disk['volume']]=$disk['bytes'];
        }
        if(!$volumes)throw new RuntimeException('Не найдены диски для учёта ресурсов');
        return (float)array_sum($volumes);
    }

    public static function allocatedGiB(array $config,string $type): int
    {
        return (int)ceil(self::allocatedBytes($config,$type)/1073741824);
    }

    public function addNetwork($vmid,string $type,$slot,string $bridge): void
    {
        $slot=preg_replace('/^net/','',(string)$slot);
        if(!preg_match('/^[0-9]$/D',$slot)||!preg_match('/^[A-Za-z0-9_.-]{1,15}$/D',$bridge))throw new InvalidArgumentException('Некорректный сетевой интерфейс');
        $config=$this->config($vmid,$type);$key='net'.$slot;
        if(isset($config[$key]))throw new RuntimeException('Сетевой интерфейс уже существует');
        $value=$type==='qemu'?'virtio,bridge='.$bridge:'name=eth'.$slot.',bridge='.$bridge.',ip=dhcp,firewall=1';
        ($this->request)('PUT',$this->base($vmid,$type).'/config',[$key=>$value,'digest'=>$config['digest']]);
    }

    public function resize($vmid,string $type,$disk,$newSize,string $storage=''): array
    {
        $key=self::diskKey($type,$disk);
        $size=filter_var($newSize,FILTER_VALIDATE_INT);
        if($size===false||$size<1||$size>1048576)throw new InvalidArgumentException('Некорректный размер диска');
        $config=$this->config($vmid,$type);$before=self::disk($config,$key);
        if($storage!==''&&$storage!==$before['storage'])throw new RuntimeException('Перенос хранилища не совмещается с увеличением диска');
        if($size*1073741824<$before['bytes'])throw new RuntimeException('Уменьшение диска запрещено');
        if($size*1073741824>$before['bytes']) {
            ($this->request)('PUT',$this->base($vmid,$type).'/resize',['disk'=>$key,'size'=>$size.'G','digest'=>$config['digest']]);
        }
        $after=self::disk($this->config($vmid,$type),$key);
        if($after['volume']!==$before['volume']||$after['bytes']<$size*1073741824) {
            throw new RuntimeException('Результат увеличения диска требует проверки администратора; не повторяйте запрос');
        }
        return ['before'=>$before,'after'=>$after,'disk'=>$key];
    }
    public function resources($vmid,string $type,int $cpu,int $ram): void
    {
        if($cpu<1||$cpu>256||$ram<16||$ram>1048576)throw new InvalidArgumentException('Некорректные ресурсы');
        $config=$this->config($vmid,$type);$this->stopped($vmid,$type);
        $parameters=['cores'=>$cpu,'memory'=>$ram,'digest'=>$config['digest']];
        if($type==='qemu')$parameters['sockets']=1;
        ($this->request)('PUT',$this->base($vmid,$type).'/config',$parameters);
        $after=$this->config($vmid,$type);
        if((int)($after['cores']??0)!==$cpu||(int)($after['memory']??0)!==$ram||
            ($type==='qemu'&&(int)($after['sockets']??1)!==1)) {
            throw new RuntimeException('Результат изменения ресурсов требует проверки администратора');
        }
    }
    public function destroy($vmid,string $type): void
    {
        $config=$this->config($vmid,$type);
        if(!empty($config['protection']))throw new RuntimeException('На госте включена защита от удаления');
        $this->stopped($vmid,$type);
        $latest=$this->config($vmid,$type);
        if($latest['digest']!==$config['digest'])throw new RuntimeException('Конфигурация изменилась; обновите страницу');
        // No purge/skiplock/unreferenced-disk flags: never broaden deletion scope.
        ($this->request)('DELETE',$this->base($vmid,$type),[]);
        $guests=($this->request)('GET','/nodes/'.$this->node.'/'.$type,[]);
        if(!is_array($guests))throw new RuntimeException('Не удалось подтвердить удаление гостя');
        foreach($guests as$guest)if((int)($guest['vmid']??0)===(int)$vmid)throw new RuntimeException('Гость всё ещё присутствует на Proxmox; проверьте результат задачи');
    }
}
