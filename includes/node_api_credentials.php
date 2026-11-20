<?php
require_once __DIR__.'/node_credentials.php';

function panelNodeApiCredentials(array $node): array
{
    if(str_contains((string)($node['username']??''),'!'))return $node;
    $directory=getenv('PROXMOX_TOKEN_CANDIDATE_DIR')?:dirname(__DIR__,2).'/.proxmox-api-candidates';
    if(empty($node['id']))return $node;
    $file=$directory.'/'.(int)$node['id'].'.json';
    if(!file_exists($file))return $node;
    if(is_link($directory)||is_link($file)||!is_file($file)||(fileperms($directory)&0077)!==0||(fileperms($file)&0077)!==0)throw new RuntimeException('Небезопасное хранилище API-токенов');
    $candidate=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if(($candidate['node_id']??0)!==(int)$node['id']||($candidate['hostname']??'')!==$node['hostname']||
        ($candidate['api_port']??0)!==(int)(($node['api_port']??0)?:8006)||($candidate['state']??'')!=='verified'||empty($candidate['privsep'])||
        ($candidate['expires_at']??0)<0||(($candidate['expires_at']??0)>0&&$candidate['expires_at']<=time())||
        ($candidate['username']??'')!=='homevlad-billing@pve!homevlad-node-'.(int)$node['id'].'-v1'||!str_starts_with((string)($candidate['password']??''),NODE_PASSWORD_PREFIX))throw new RuntimeException('Кандидат API-токена не соответствует подключению');
    return array_replace($node,['username'=>$candidate['username'],'password'=>$candidate['password']]);
}
