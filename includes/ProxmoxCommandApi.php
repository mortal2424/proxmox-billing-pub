<?php

/** Compatibility for the panel's old command-shaped calls. This never invokes a shell. */
final class ProxmoxCommandApi
{
    private Closure $request;
    public function __construct(callable $request, private string $node)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$node)) throw new InvalidArgumentException('Некорректное имя узла');
        $this->request=Closure::fromCallable($request);
    }
    public static function words(string $command): array
    {
        $words=[];$word='';$quote=null;$present=false;$length=strlen($command);
        for($i=0;$i<$length;$i++){
            $char=$command[$i];
            if($char==="\0"||$char==="\n"||$char==="\r")throw new InvalidArgumentException('Недопустимый формат запроса');
            if($quote==="'"){if($char==="'")$quote=null;else$word.=$char;continue;}
            if($char==='\\'){
                if(++$i>=$length)throw new InvalidArgumentException('Незавершённое экранирование');
                if($quote==='"'&&!str_contains('$`"\\',$command[$i]))$word.='\\';
                $word.=$command[$i];$present=true;continue;
            }
            if($quote==='"'){if($char==='"')$quote=null;else$word.=$char;continue;}
            if($char==="'"||$char==='"'){$quote=$char;$present=true;continue;}
            if(ctype_space($char)){if($present){$words[]=$word;$word='';$present=false;}continue;}
            if(str_contains(';|&<>`$()',$char))throw new InvalidArgumentException('Операторы оболочки запрещены');
            $word.=$char;$present=true;
        }
        if($quote!==null)throw new InvalidArgumentException('Незавершённая строка');
        if($present)$words[]=$word;
        return $words;
    }
    private static function options(array $words): array
    {
        $options=[];
        while($words){
            $name=array_shift($words);
            if(!preg_match('/^--([a-zA-Z][a-zA-Z0-9_-]*)$/D',$name,$match))throw new InvalidArgumentException('Некорректный параметр API');
            $key=$match[1];if(isset($options[$key]))throw new InvalidArgumentException('Повторяющийся параметр API');
            $value=(!$words||str_starts_with($words[0],'--'))?1:array_shift($words);
            if($key==='output-format'){if($value!=='json')throw new InvalidArgumentException('Требуется JSON');continue;}
            $options[$key]=$value;
        }
        return $options;
    }
    private function invoke(string $method,string $path,array $options=[]): string
    {
        $value=($this->request)($method,$path,$options);
        return $value===null?'':(is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR));
    }
    public function execute(string $command): string
    {
        $words=self::words($command);$binary=array_shift($words);$action=array_shift($words);
        if($binary==='pvesh'){
            $method=['get'=>'GET','create'=>'POST','set'=>'PUT','delete'=>'DELETE'][$action]??null;
            $path=array_shift($words);
            if(!$method||!is_string($path)||in_array('..',explode('/',rawurldecode($path)),true)||!preg_match('#^/(?:nodes/'.preg_quote($this->node,'#').'(?:/|$)|cluster(?:/|$)|storage(?:/|$))#D',$path))throw new InvalidArgumentException('Недопустимый маршрут API');
            $options=self::options($words);
            if(isset($options['force-stop'])){unset($options['force-stop']);if(str_ends_with($path,'/status/stop'))$options['overrule-shutdown']=1;}
            return $this->invoke($method,$path,$options);
        }
        if(in_array($binary,['qm','pct'],true)){
            $vmid=array_shift($words);
            if(!is_string($vmid)||!preg_match('/^[1-9][0-9]{0,8}$/D',$vmid))throw new InvalidArgumentException('Некорректный VMID');
            $type=$binary==='qm'?'qemu':'lxc';$base='/nodes/'.$this->node.'/'.$type;
            $template=$binary==='pct'&&$action==='create'?array_shift($words):null;
            $options=self::options($words);
            if($action==='create'){
                $options['vmid']=(int)$vmid;
                if($binary==='pct'){
                    if(!is_string($template)||!preg_match('#^[A-Za-z0-9_.-]+:vztmpl/[A-Za-z0-9_.+-]+$#D',$template))throw new InvalidArgumentException('Некорректный шаблон');
                    $options['ostemplate']=$template;
                    if(isset($options['rootfs'])&&is_numeric($options['rootfs']))$options['rootfs']=($options['storage']??'local').':'.$options['rootfs'];
                    if((string)($options['unprivileged']??'1')!=='1')throw new RuntimeException('Панель создаёт только непривилегированные LXC');
                    $options['unprivileged']=1;
                }
                return $this->invoke('POST',$base,$options);
            }
            if($action==='set')return $this->invoke('PUT',$base.'/'.$vmid.'/config',$options);
            if($action==='destroy')return $this->invoke('DELETE',$base.'/'.$vmid,$options);
            if(in_array($action,['start','stop','shutdown','reboot'],true))return $this->invoke('POST',$base.'/'.$vmid.'/status/'.$action,$options);
            if($action==='config')return $this->invoke('GET',$base.'/'.$vmid.'/config',$options);
            if($action==='status')return $this->invoke('GET',$base.'/'.$vmid.'/status/current',$options);
        }
        throw new RuntimeException('Операция ещё не переведена на ограниченный API. Выполнение SSH-команд отключено');
    }
}
