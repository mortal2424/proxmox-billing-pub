<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('HOMEVLAD_DOCKER_SMOKE') !== '1' || getenv('DB_NAME') !== 'homevlad_smoke') {
    fwrite(STDERR, "Disposable Docker smoke database required\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'nginx';
ini_set('session.use_cookies', '0');
require '/var/www/html/includes/db.php';
$pdo = (new Database())->getConnection();
$pdo->exec('SET NAMES utf8mb4');
$fixtureEmail = 'backup-smoke@example.invalid';
$root = '/var/www/html';

function requireSmoke(bool $condition, string $failure): void
{
    if (!$condition) throw new RuntimeException($failure);
}

switch ($argv[1] ?? '') {
    case 'seed':
        $password = trim(stream_get_contents(STDIN));
        requireSmoke(strlen($password) >= 24, 'Disposable password missing');
        $query = $pdo->prepare("INSERT INTO users (email,phone,first_name,last_name,full_name,password_hash,is_active,is_admin)
            VALUES (?,'','','','Backup Smoke',?,1,1)");
        $query->execute([$fixtureEmail,password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->exec('CREATE TABLE homevlad_backup_smoke (id INT PRIMARY KEY, message TEXT, binary_data BLOB)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->prepare('INSERT INTO homevlad_backup_smoke VALUES (1,?,?)')->execute(['до копии 🌤', "\x00\xff"]);
        file_put_contents($root . '/static/backup-smoke.txt','site-before');
        file_put_contents($root . '/uploads/backup-smoke.txt','upload-before');
        break;
    case 'session':
        $query = $pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 AND is_admin=1');
        $query->execute([$fixtureEmail]);
        $id = (int)$query->fetchColumn();
        requireSmoke($id > 0, 'Disposable administrator missing');
        session_id(bin2hex(random_bytes(24)));
        requireSmoke(session_start(), 'Could not create smoke session');
        $_SESSION = ['user'=>['auth_generation'=>1,'id'=>$id,'is_admin'=>1,'email'=>$fixtureEmail],'backup_csrf'=>bin2hex(random_bytes(32))];
        echo json_encode(['sid'=>session_id(),'csrf'=>$_SESSION['backup_csrf']],JSON_THROW_ON_ERROR);
        session_write_close();
        break;
    case 'schedule':
        $query = $pdo->prepare('SELECT id FROM backup_schedules WHERE name=?');
        $query->execute([$argv[2]]);
        $id = (int)$query->fetchColumn();
        requireSmoke($id > 0, 'Schedule missing');
        echo $id;
        break;
    case 'due':
        $query = $pdo->prepare('UPDATE backup_schedules SET next_run=NOW() - INTERVAL 1 MINUTE WHERE id=? AND name=?');
        $query->execute([(int)$argv[2],'Smoke A']);
        requireSmoke($query->rowCount() === 1, 'Schedule was not made due');
        break;
    case 'change':
        $pdo->exec("UPDATE homevlad_backup_smoke SET message='after', binary_data=X'0080'");
        $pdo->exec('CREATE TABLE IF NOT EXISTS homevlad_backup_smoke_extra (id INT PRIMARY KEY)');
        file_put_contents($root . '/static/backup-smoke.txt','site-after');
        file_put_contents($root . '/uploads/backup-smoke.txt','upload-after');
        break;
    case 'verify':
        $dbRestored = ($argv[2] ?? '') === 'restored';
        $filesRestored = ($argv[3] ?? '') === 'restored';
        $row = $pdo->query('SELECT message, HEX(binary_data) AS bytes FROM homevlad_backup_smoke WHERE id=1')->fetch();
        requireSmoke($row === ($dbRestored ? ['message'=>'до копии 🌤','bytes'=>'00FF'] : ['message'=>'after','bytes'=>'0080']),
            'Database content or binary bytes differ');
        $extra = $pdo->query("SHOW TABLES LIKE 'homevlad_backup_smoke_extra'")->fetchColumn();
        requireSmoke((bool)$extra !== $dbRestored, 'Unexpected extra table state');
        foreach (['static'=>'site','uploads'=>'upload'] as $dir=>$label) {
            requireSmoke(file_get_contents("$root/$dir/backup-smoke.txt") === $label . ($filesRestored ? '-before' : '-after'),
                "$label file differs");
        }
        requireSmoke(!is_file($root.'/backups/restore/recovery.json'), 'Restore journal remains');
        echo "Verified DB {$argv[2]}, files {$argv[3]}\n";
        break;
    default:
        throw new InvalidArgumentException('Unknown smoke command');
}
