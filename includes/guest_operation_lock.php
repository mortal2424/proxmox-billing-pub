<?php
/** Locks are persistent files: unlinking a locked file would allow a second lock inode. */
function withGuestOperationLocks(array $keys, callable $operation): mixed
{
    $directory=getenv('PROXMOX_GUEST_LOCK_DIR')?:dirname(__DIR__,2).'/.proxmox-guest-locks';
    if(!str_starts_with($directory,'/')||is_link($directory)||
        (!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))||
        (fileperms($directory)&0077)!==0)throw new RuntimeException('Недоступен приватный каталог блокировок');
    $handles=[];
    try {
        foreach(array_unique($keys)as$key){
            $path=$directory.'/'.hash('sha256',(string)$key).'.lock';
            if(is_link($path))throw new RuntimeException('Недопустимая ссылка блокировки');
            $handle=@fopen($path,'c');
            if(!$handle)throw new RuntimeException('Не удалось создать блокировку');
            $handles[]=$handle;
            if(!chmod($path,0600)||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('Уже выполняется другая операция. Дождитесь её завершения');
        }
        return $operation();
    } finally {
        foreach(array_reverse($handles)as$handle){flock($handle,LOCK_UN);fclose($handle);}
    }
}
