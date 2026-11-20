<?php
// This destructive fixture suite only accepts a disposable homevlad_qa_* database and directory.
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit(2);
$root = realpath($argv[1]);
if (!$root || !str_starts_with(basename($root), 'backup-qa.')) throw new RuntimeException('Disposable directory required');
require $root . '/includes/config.php';
if (!preg_match('/^homevlad_qa_[a-f0-9]+$/D', DB_NAME) || !str_starts_with(DB_USER, 'hvqa_')) throw new RuntimeException('Disposable database required');
require $root . '/includes/backup_restore.php';
function verify($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(Closure $operation, string $label): void {
    try { $operation(); } catch (Throwable $error) { echo "PASS rejection: $label\n"; return; }
    throw new RuntimeException('Accepted invalid operation: ' . $label);
}
function database(): PDO { return new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
function archiveFixture(string $file, array $files, ?string $sql = null): string {
    $zip = new ZipArchive(); verify($zip->open($file, ZipArchive::CREATE|ZipArchive::OVERWRITE) === true, 'ZIP creation');
    $zip->addFromString('backup_meta.json', json_encode(['type'=>$sql===null?'files':($files?'full':'db')]));
    foreach ($files as $path=>$content) $zip->addFromString($path,$content);
    if ($sql !== null) $zip->addFromString('database/database_dump.sql',$sql);
    verify($zip->close(),'ZIP close'); return $file;
}
require $root.'/includes/backup_schedule.php';
foreach ([
    ['weekly','03:00',1,'2026-11-16 02:00:00','2026-11-16 03:00:00'],
    ['weekly','03:00',1,'2026-11-16 04:00:00','2026-11-23 03:00:00'],
    ['monthly','03:00',20,'2026-11-20 02:00:00','2026-11-20 03:00:00'],
    ['monthly','03:00',31,'2026-01-31 05:00:00','2026-02-28 03:00:00'],
    ['monthly','03:00',31,'2028-02-27 05:00:00','2028-02-29 03:00:00'],
    ['daily','03:00',null,'2026-11-20 03:00:00','2026-11-21 03:00:00']
] as [$type,$time,$day,$now,$expectedRun]) verify(calculateNextRun($type,$time,$day,new DateTimeImmutable($now))===$expectedRun,'Schedule boundary');
rejected(fn()=>calculateNextRun('weekly','03:00',8),'invalid weekly day');
rejected(fn()=>calculateNextRun('daily','24:00'),'invalid schedule time');
echo "PASS daily, weekly, monthly and leap-year scheduling boundaries\n";
$p=database();
$p->exec('SET FOREIGN_KEY_CHECKS=0');
foreach($p->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM)as[$name,$type])$p->exec(($type==='VIEW'?'DROP VIEW ':'DROP TABLE ').'`'.str_replace('`','``',$name).'`');
$p->exec('SET FOREIGN_KEY_CHECKS=1');
$p->exec('CREATE TABLE users(id INT PRIMARY KEY) ENGINE=InnoDB');$p->exec('INSERT INTO users VALUES(1)');
$p->exec('CREATE TABLE fixture(id INT PRIMARY KEY,message TEXT,payload BLOB) ENGINE=InnoDB');
$q=$p->prepare('INSERT INTO fixture VALUES(1,?,?)');$q->execute(["Мятное облако 🌤️ \" ' \\ \n", "\0\xff\x01"]);
$p->exec('CREATE VIEW fixture_view AS SELECT id FROM fixture');
$expected=$p->query('SELECT message,HEX(payload) AS payload FROM fixture')->fetch(PDO::FETCH_ASSOC);
backupDatabaseCommand('export',$root.'/snapshot.sql');
$p->exec("UPDATE fixture SET message='changed'");$p->exec('CREATE TABLE extra(id INT)');
backupDatabaseCommand('import',$root.'/snapshot.sql');$p=database();
verify($p->query('SELECT message,HEX(payload) AS payload FROM fixture')->fetch(PDO::FETCH_ASSOC)===$expected,'Unicode/BLOB roundtrip');
verify(!$p->query("SHOW TABLES LIKE 'extra'")->fetchColumn(),'Extra table removal');
echo "PASS database export/import, Unicode, BLOB and replacement semantics\n";
file_put_contents($root.'/index.php','before');
$sql=file_get_contents($root.'/snapshot.sql');
$archive=archiveFixture($root.'/full.zip',['index.php'=>'after','new.php'=>'new','includes/config.php'=>'must not replace'],$sql);
$p->exec("UPDATE fixture SET message='before-restore'");
$configHash=hash_file('sha256',$root.'/includes/config.php');
$result=performBackupRestore($root,$archive,'full',hash_file('sha256',$archive));$p=database();
verify(file_get_contents($root.'/index.php')==='after' && is_file($root.'/new.php'),'Full file restore');
verify($p->query('SELECT message,HEX(payload) AS payload FROM fixture')->fetch(PDO::FETCH_ASSOC)===$expected,'Full DB restore');
verify(hash_file('sha256',$root.'/includes/config.php')===$configHash,'Configuration preservation');
verify(!file_exists($root.'/backups/restore/recovery.json'),'Successful journal removal');
$journal=json_decode(file_get_contents($result['safety_directory'].'/plan.json'),true);
rollbackBackupRestore($root,$journal);$p=database();
verify(file_get_contents($root.'/index.php')==='before' && !file_exists($root.'/new.php'),'File rollback');
verify($p->query('SELECT message FROM fixture')->fetchColumn()==='before-restore','DB rollback');
echo "PASS full restore, protected config, safety snapshot and rollback\n";
$broken=archiveFixture($root.'/broken.zip',['index.php'=>'broken'],"-- invalid fixture dump\n".str_repeat('-- filler\n',20)."\nCREATE TABLE incomplete(id INT);\nINVALID SQL;\n");
rejected(fn()=>performBackupRestore($root,$broken,'full',hash_file('sha256',$broken)),'failed database import');$p=database();
verify(file_get_contents($root.'/index.php')==='before','Files unchanged after failed import');
verify($p->query('SELECT message FROM fixture')->fetchColumn()==='before-restore','DB recovered after failed import');
verify(!file_exists($root.'/backups/restore/recovery.json'),'Failed import journal cleared after rollback');
echo "PASS automatic rollback after SQL import failure\n";
file_put_contents($root.'/collision','not a directory');
$collision=archiveFixture($root.'/collision.zip',['index.php'=>'intermediate','collision/file.php'=>'cannot write'],$sql);
rejected(fn()=>performBackupRestore($root,$collision,'full',hash_file('sha256',$collision)),'file replacement fails after DB import');$p=database();
verify(file_get_contents($root.'/index.php')==='before','Partial file replacement rolled back');
verify($p->query('SELECT message FROM fixture')->fetchColumn()==='before-restore','DB rolled back after file failure');
echo "PASS automatic rollback after partial file replacement\n";
$invalidPhp=archiveFixture($root.'/invalid-php.zip',['index.php'=>'<?php this is invalid syntax;']);
rejected(fn()=>performBackupRestore($root,$invalidPhp,'files',hash_file('sha256',$invalidPhp)),'invalid PHP before replacement');
verify(file_get_contents($root.'/index.php')==='before','Invalid PHP changed live files');
$traversal=archiveFixture($root.'/traversal.zip',['../escape.php'=>'bad']);
rejected(fn()=>inspectBackupArchive($traversal),'ZIP traversal');
$executable=archiveFixture($root.'/executable.zip',['uploads/shell.php'=>'bad']);
rejected(fn()=>inspectBackupArchive($executable),'executable upload');
$symlink=archiveFixture($root.'/symlink.zip',['index.php'=>'target']);
$z=new ZipArchive();$z->open($symlink);$z->setExternalAttributesName('index.php',3,0120777<<16);$z->close();
rejected(fn()=>inspectBackupArchive($symlink),'ZIP symlink');
rejected(fn()=>performBackupRestore($root,$archive,'files',str_repeat('0',64)),'changed archive hash');
$lock=backupOperationLock($root);
rejected(fn()=>performBackupRestore($root,$archive,'files',hash_file('sha256',$archive)),'concurrent update/restore');
flock($lock,LOCK_UN);fclose($lock);
$files=archiveFixture($root.'/files.zip',['index.php'=>'files only']);
$result=performBackupRestore($root,$files,'files',hash_file('sha256',$files));
verify(database()->query('SELECT message FROM fixture')->fetchColumn()==='before-restore','Files-only preserves DB');
$journal=json_decode(file_get_contents($result['safety_directory'].'/plan.json'),true);
restoreWriteJson($root.'/backups/restore/recovery.json',$journal);
$process=proc_open([PHP_BINARY,$root.'/cronjobs/restore_recovery.php','rollback'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
verify(proc_close($process)===0,'CLI recovery failed: '.$errors);
verify(file_get_contents($root.'/index.php')==='before' && !is_file($root.'/backups/restore/recovery.json'),'CLI recovery result');
echo "PASS files-only restore and CLI interrupted-operation recovery\n";
require $root.'/includes/backup_functions.php';
$pdo=database();$_SESSION=['user'=>['id'=>1]];
$result=createBackup('db','fixture');verify($result['success'],'createBackup DB failed: '.($result['error']??''));
verify(inspectBackupArchive($result['filepath'])['database'],'Created archive contains dump');
verify((fileperms($result['filepath'])&0777)===0600,'Backup permissions');
for($i=0;$i<5;$i++){ $name=BACKUP_DIR.'schedule_7_fixture_'.$i.'.zip';file_put_contents($name,'fixture');touch($name,time()-100+$i); }
file_put_contents(BACKUP_DIR.'schedule_8_fixture.zip','other schedule');
$count=cleanupOldBackups(BACKUP_DIR,2,7);verify($count===3,'Schedule retention count');
verify(is_file($result['filepath'])&&is_file(BACKUP_DIR.'schedule_8_fixture.zip'),'Retention scope isolation');
echo "PASS real backup creation, private permissions and per-schedule retention\nALL BACKUP FIXTURE CHECKS PASSED\n";
