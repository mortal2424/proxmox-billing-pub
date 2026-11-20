<?php
require __DIR__.'/../includes/ProxmoxUpload.php';
function check($value):void{if(!$value)throw new RuntimeException('Assertion failed');}
check(ProxmoxUpload::decode("HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n{\"data\":\"UPID:test\"}")==='UPID:test');
$body='{"data":null}';
check(ProxmoxUpload::decode("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n".dechex(strlen($body))."\r\n$body\r\n0\r\n\r\n")===null);
foreach(["HTTP/1.0 200 OK\r\nContent-Length: 100\r\n\r\n{\"data\":null}","HTTP/1.0 302 Moved\r\nLocation: https://evil.test\r\n\r\n{}","HTTP/1.0 401 Unauthorized\r\n\r\n{}","HTTP/1.0 200 OK\r\n\r\n{}","HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\nff\r\n{}\r\n0\r\n\r\n"]as$reply){
 $rejected=false;try{ProxmoxUpload::decode($reply);}catch(Throwable$e){$rejected=true;}check($rejected);
}
foreach([['a".iso','iso'],['../a.iso','iso'],["a\r\nx.iso",'iso'],['a.iso','images']]as[$filename,$content]){
 $rejected=false;try{ProxmoxUpload::send('https://pve.invalid/upload',[],[],'/nonexistent',$filename,$content);}catch(InvalidArgumentException$e){$rejected=true;}check($rejected);
}
echo "PASS upload parser, redirects, malformed responses and multipart injection\n";
