<?php
/** Bounded-memory multipart upload over the same verified TLS connection. */
final class ProxmoxUpload
{
    public static function send(string $url, array $tls, array $headers, string $file, string $filename, string $content): mixed
    {
        if (!in_array($content,['iso','vztmpl'],true) ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,254}$/D',$filename) ||
            ($content==='iso'&&!preg_match('/\.iso$/iD',$filename)) ||
            ($content==='vztmpl'&&!preg_match('/\.tar\.(?:gz|xz|zst)$/iD',$filename))) {
            throw new InvalidArgumentException('Некорректное имя или тип образа');
        }
        $parts=parse_url($url);
        if (($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['query'])||isset($parts['fragment'])) {
            throw new InvalidArgumentException('Некорректный адрес загрузки');
        }
        $input=@fopen($file,'rb');
        if (!$input) throw new RuntimeException('Не удалось прочитать образ');
        $socket=null;
        try {
            $stat=fstat($input);$size=$stat['size'];
            if (($stat['mode']&0170000)!==0100000||$size<1||$size>10737418240) throw new RuntimeException('Недопустимый размер образа');
            $boundary='HomeVlad'.bin2hex(random_bytes(24));
            $prefix="--$boundary\r\nContent-Disposition: form-data; name=\"content\"\r\n\r\n$content\r\n".
                "--$boundary\r\nContent-Disposition: form-data; name=\"filename\"; filename=\"$filename\"\r\nContent-Type: application/octet-stream\r\n\r\n";
            $suffix="\r\n--$boundary--\r\n";
            $host=trim($parts['host'],'[]');$port=$parts['port']??443;
            $authority=(str_contains($host,':')?'['.$host.']':$host).':'.$port;
            $context=stream_context_create(['ssl'=>$tls+['peer_name'=>$host,'SNI_enabled'=>true]]);
            $socket=@stream_socket_client('tls://'.$authority,$errno,$error,20,STREAM_CLIENT_CONNECT,$context);
            if (!$socket) throw new RuntimeException('Не удалось установить проверенное HTTPS-соединение для загрузки');
            stream_set_timeout($socket,120);
            $deadline=microtime(true)+3600;
            $request='POST '.$parts['path']." HTTP/1.0\r\nHost: $authority\r\nAccept: application/json\r\nConnection: close\r\n";
            foreach($headers as$header){
                if(strpbrk($header,"\r\n")!==false)throw new InvalidArgumentException('Некорректный заголовок');
                $request.=$header."\r\n";
            }
            $request.="Content-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: ".(strlen($prefix)+$size+strlen($suffix))."\r\n\r\n";
            self::write($socket,$request.$prefix,$deadline);
            $remaining=$size;
            while($remaining>0){
                $chunk=fread($input,min(1048576,$remaining));
                if($chunk===false||$chunk==='')throw new RuntimeException('Чтение образа прервано');
                self::write($socket,$chunk,$deadline);$remaining-=strlen($chunk);
            }
            self::write($socket,$suffix,$deadline);
            $response='';
            while(!feof($socket)){
                $chunk=fread($socket,65536);
                if($chunk===false||($chunk===''&&!feof($socket)))throw new RuntimeException('Ответ загрузки не получен; проверьте хранилище перед повтором');
                $response.=$chunk;
                if(strlen($response)>8454144)throw new RuntimeException('Ответ загрузки слишком велик');
                if(microtime(true)>$deadline)throw new RuntimeException('Истекло время загрузки; проверьте хранилище перед повтором');
            }
            return self::decode($response);
        } finally {
            fclose($input);if(is_resource($socket))fclose($socket);
        }
    }

    private static function write($socket,string $data,float $deadline): void
    {
        for($offset=0,$length=strlen($data);$offset<$length;){
            if(microtime(true)>$deadline)throw new RuntimeException('Истекло время загрузки; проверьте хранилище перед повтором');
            $written=fwrite($socket,substr($data,$offset));
            if($written===false||$written===0)throw new RuntimeException('Передача образа прервана; проверьте хранилище перед повтором');
            $offset+=$written;
        }
    }

    public static function decode(string $response): mixed
    {
        do {
            $end=strpos($response,"\r\n\r\n");
            if($end===false||$end>65536)throw new RuntimeException('Некорректный ответ загрузки');
            $head=substr($response,0,$end);$response=substr($response,$end+4);
            if(!preg_match('#^HTTP/1\.[01] ([0-9]{3})(?: |\r\n|$)#',$head,$match))throw new RuntimeException('Некорректный статус загрузки');
            $status=(int)$match[1];
        }while($status===100);
        if($status<200||$status>=300)throw new RuntimeException('API загрузки Proxmox вернул HTTP '.$status,$status);
        if(preg_match('/^Content-Length:\s*(\d+)\s*$/im',$head,$length) && (int)$length[1]!==strlen($response)) {
            throw new RuntimeException('Неполный ответ загрузки');
        }
        if(preg_match('/^Transfer-Encoding:\s*chunked\s*$/im',$head)){
            $decoded='';
            while(true){
                $end=strpos($response,"\r\n");
                if($end===false||!preg_match('/^[0-9a-f]{1,8}$/iD',substr($response,0,$end)))throw new RuntimeException('Некорректный chunked-ответ');
                $size=hexdec(substr($response,0,$end));$response=substr($response,$end+2);
                if($size===0)break;
                if($size>8388608-strlen($decoded)||strlen($response)<$size+2||substr($response,$size,2)!=="\r\n")throw new RuntimeException('Некорректный размер ответа');
                $decoded.=substr($response,0,$size);$response=substr($response,$size+2);
            }
            $response=$decoded;
        }
        if(strlen($response)>8388608)throw new RuntimeException('Ответ загрузки слишком велик');
        $data=json_decode($response,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!array_key_exists('data',$data))throw new RuntimeException('Некорректный JSON загрузки');
        return $data['data'];
    }
}
