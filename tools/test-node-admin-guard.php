<?php
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(2);
require $argv[1].'/includes/node_admin_guard.php';
final class GuardStatement extends PDOStatement{
 public function __construct(private array $row){}
 public function execute(?array $params=null):bool{return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->row;}
}
final class GuardDatabase extends PDO{
 public function __construct(private array $row){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new GuardStatement($this->row);}
}
$mode=$argv[2]??'get';$expected=in_array($mode,['get','valid-post'],true)?200:403;
$_SERVER['REQUEST_METHOD']=str_contains($mode,'post')?'POST':'GET';
session_start();$_SESSION=['user'=>['id'=>1,'is_admin'=>1],'node_csrf'=>str_repeat('a',64)];
if($mode==='valid-post')$_POST['node_csrf']=str_repeat('a',64);
if($mode==='bad-post')$_POST['node_csrf']='wrong';
http_response_code(200);
ob_start(function($output)use($mode,$expected){if(http_response_code()!==$expected){fwrite(STDERR,"FAIL $mode\n");return '';}return "PASS node guard $mode HTTP $expected\n";});
requireNodeAdministrator(new GuardDatabase(['is_admin'=>$mode==='forged'?0:1,'is_active'=>$mode==='inactive'?0:1]));
echo 'allowed';
