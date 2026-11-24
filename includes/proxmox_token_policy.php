<?php
require_once __DIR__ . '/node_credentials.php';

const HOMEVLAD_SERVICE_USER = 'homevlad-billing@pve';
const HOMEVLAD_SERVICE_MARKER = 'HomeVlad billing integration v1';

function homevladTokenRoles(): array
{
    return [
        'HomeVladAuditV1' => ['path'=>'/', 'privileges'=>['Sys.Audit']],
        'HomeVladGuestsV1' => ['path'=>'/vms', 'privileges'=>[
            'VM.Allocate','VM.Audit','VM.Clone','VM.Console','VM.PowerMgmt','VM.Config.CPU',
            'VM.Config.Memory','VM.Config.Disk','VM.Config.Network','VM.Config.Options',
            'VM.Config.CDROM','VM.Config.HWType','VM.Config.Cloudinit','VM.GuestAgent.Audit']],
        'HomeVladStorageV1' => ['path'=>'/storage', 'privileges'=>[
            'Datastore.Audit','Datastore.AllocateSpace','Datastore.AllocateTemplate']],
        'HomeVladNetworksV1' => ['path'=>'/sdn/zones', 'privileges'=>['SDN.Audit','SDN.Use']],
    ];
}

function homevladPrivilegeSet($privileges): array
{
    $result = is_array($privileges) ? (array_is_list($privileges) ? $privileges : array_keys(array_filter($privileges))) : preg_split('/[\s,]+/', (string)$privileges, -1, PREG_SPLIT_NO_EMPTY);
    $result = array_values(array_unique($result)); sort($result); return $result;
}

function verifyHomevladTokenPermissions(callable $request): array
{
    $allowed = [];
    foreach (homevladTokenRoles() as $role) $allowed = array_merge($allowed, $role['privileges']);
    $permissions = $request('GET', '/access/permissions', []);
    if (!is_array($permissions) || !$permissions) throw new RuntimeException('Токен не вернул список прав');
    foreach ($permissions as $path => $grants) {
        if (!is_array($grants)) throw new RuntimeException('Некорректный ответ о правах');
        foreach ($grants as $privilege => $enabled) {
            if ($enabled && !in_array($privilege, $allowed, true) &&
                !($path === '/storage/hv-ci-snippets' && $privilege === 'Datastore.Allocate')) throw new RuntimeException('У токена обнаружены избыточные права');
        }
    }
    foreach (homevladTokenRoles() as $role) {
        $atPath = $request('GET', '/access/permissions', ['path'=>$role['path']]);
        foreach ($role['privileges'] as $privilege) {
            if (empty($atPath[$role['path']][$privilege])) throw new RuntimeException('Не подтверждены необходимые права токена: ' . $privilege);
        }
    }
    return ['scopes'=>count(homevladTokenRoles()), 'privileges'=>count(array_unique($allowed))];
}

function writeHomevladTokenCandidate(string $path, array $candidate): void
{
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.part';
    try {
        $handle = fopen($temporary, 'x');
        if (!$handle) throw new RuntimeException('Не удалось создать закрытое хранилище токена');
        try {
            chmod($temporary, 0600);
            $contents = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) throw new RuntimeException('Не удалось сохранить токен');
            if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Не удалось синхронизировать токен');
        } finally { fclose($handle); }
        if (!rename($temporary, $path)) throw new RuntimeException('Не удалось завершить сохранение токена');
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function prepareHomevladToken(callable $request, array $node, string $directory): array
{
    nodeCredentialKey();
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700,true)) || (fileperms($directory)&0077)!==0) throw new RuntimeException('Недоступен закрытый каталог токенов или нарушены права 0700');
    $path = $directory . '/' . (int)$node['id'] . '.json';
    if (is_link($path)) throw new RuntimeException('Файл токена не должен быть ссылкой');
    if (is_file($path)) {
        $saved = json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (($saved['node_id']??0)!==(int)$node['id'] || ($saved['hostname']??'')!==$node['hostname'] ||
            ($saved['api_port']??0)!==(int)$node['api_port']) throw new RuntimeException('Токен относится к другому подключению');
        decryptNodePassword($saved['password']??'');
        $tokenName = 'homevlad-node-' . (int)$node['id'] . '-v1';
        if(($saved['username']??'')!==HOMEVLAD_SERVICE_USER.'!'.$tokenName) throw new RuntimeException('Неожиданный идентификатор токена');
        $remote=$request('GET','/access/users/'.rawurlencode(HOMEVLAD_SERVICE_USER).'/token/'.$tokenName,[]);
        if(empty($remote['privsep'])) throw new RuntimeException('У токена отключено разделение прав');
        $currentRoles=[];foreach($request('GET','/access/roles',[])as$role)$currentRoles[$role['roleid']]=$role;
        foreach(homevladTokenRoles()as$name=>$policy) {
            if(!isset($currentRoles[$name])||homevladPrivilegeSet($currentRoles[$name]['privs']??[])!==homevladPrivilegeSet($policy['privileges']))throw new RuntimeException('Роль подготовленного токена изменилась: '.$name);
        }
        if(($saved['state']??'')==='prepared') {
            foreach(homevladTokenRoles()as$name=>$policy)$request('PUT','/access/acl',[
                'path'=>$policy['path'],'tokens'=>$saved['username'],'roles'=>$name,'propagate'=>1]);
        }
        return $saved;
    }
    $roles = $request('GET','/access/roles',[]);
    $byName = []; foreach($roles as $role) $byName[$role['roleid']] = $role;
    $users = $request('GET','/access/users',[]); $existing = null;
    foreach($users as$user)if($user['userid']===HOMEVLAD_SERVICE_USER)$existing=$user;
    if($existing && (($existing['comment']??'')!==HOMEVLAD_SERVICE_MARKER || empty($existing['enable']) ||
        (!empty($existing['expire']) && $existing['expire']<=time()))) throw new RuntimeException('Служебная учётная запись существует с другими настройками');
    foreach(homevladTokenRoles() as $name=>$policy) {
        if(isset($byName[$name])) {
            if(homevladPrivilegeSet($byName[$name]['privs']??[])!==homevladPrivilegeSet($policy['privileges'])) throw new RuntimeException('Существующая роль отличается от политики HomeVlad: '.$name);
        }
    }
    $audit=$directory.'/'.(int)$node['id'].'.before.json';
    if(is_link($audit))throw new RuntimeException('Недопустимая ссылка на журнал подготовки');
    if(!is_file($audit))writeHomevladTokenCandidate($audit,['created_at'=>date(DATE_ATOM),'roles'=>$roles,'users'=>$users,'acl'=>$request('GET','/access/acl',[])]);
    foreach(homevladTokenRoles()as$name=>$policy)if(!isset($byName[$name]))$request('POST','/access/roles',['roleid'=>$name,'privs'=>implode(',',$policy['privileges'])]);
    if(!$existing)$request('POST','/access/users',['userid'=>HOMEVLAD_SERVICE_USER,'enable'=>1,'comment'=>HOMEVLAD_SERVICE_MARKER]);
    foreach(homevladTokenRoles()as$name=>$policy)$request('PUT','/access/acl',[
        'path'=>$policy['path'],'users'=>HOMEVLAD_SERVICE_USER,'roles'=>$name,'propagate'=>1]);
    $tokenName = 'homevlad-node-' . (int)$node['id'] . '-v1';
    $base = '/access/users/' . rawurlencode(HOMEVLAD_SERVICE_USER) . '/token';
    foreach($request('GET',$base,[])as$token)if($token['tokenid']===$tokenName)throw new RuntimeException('Токен уже существует, но его секрет не сохранён. Автоматическая перегенерация запрещена');
    $created = false; $saved = false;
    try {
        $token = $request('POST',$base.'/'.$tokenName,['privsep'=>1,'comment'=>HOMEVLAD_SERVICE_MARKER]);
        $created = true;
        if(($token['full-tokenid']??'')!==HOMEVLAD_SERVICE_USER.'!'.$tokenName || !is_string($token['value']??null) || $token['value']==='' || empty($token['info']['privsep'])) throw new RuntimeException('Некорректный ответ при создании токена');
        $candidate = ['format'=>1,'node_id'=>(int)$node['id'],'hostname'=>$node['hostname'],'api_port'=>(int)$node['api_port'],
            'username'=>$token['full-tokenid'],'password'=>encryptNodePassword($token['value']),'created_at'=>date(DATE_ATOM),
            'expires_at'=>(int)($token['info']['expire']??0),'privsep'=>1,'state'=>'prepared'];
        writeHomevladTokenCandidate($path,$candidate); $saved = true;
        foreach(homevladTokenRoles()as$name=>$policy)$request('PUT','/access/acl',[
            'path'=>$policy['path'],'tokens'=>$candidate['username'],'roles'=>$name,'propagate'=>1]);
        return $candidate;
    } catch(Throwable $error) {
        if($created && !$saved) {
            try {$request('DELETE',$base.'/'.$tokenName,[]);}catch(Throwable $cleanup){error_log('HomeVlad token rollback needs administrator attention for node '.(int)$node['id']);}
        }
        throw $error;
    }
}
