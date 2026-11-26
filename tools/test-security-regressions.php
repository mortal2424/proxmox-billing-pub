<?php
// CLI tests and the router for the loopback HTTP checks in test-security-http.sh.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_reset.php';
require_once __DIR__ . '/../includes/mobile_device_login.php';
require_once __DIR__ . '/../includes/guest_metrics.php';
require_once __DIR__ . '/../includes/billing_guest_state.php';
require_once __DIR__ . '/../includes/update_signature.php';
require_once __DIR__ . '/../includes/telegram_auth.php';
require_once __DIR__ . '/../includes/backup_restore.php';

if (PHP_SAPI === 'cli-server') {
    $route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($route === '/__session') {
        echo isLoggedIn() ? 'authenticated' : 'anonymous';
        return;
    }
    if ($route === '/__csrf') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
            echo formCsrfToken();
            return;
        }
        requirePostCsrf();
        echo 'accepted';
        return;
    }
    return false;
}
if (PHP_SAPI !== 'cli') exit(1);

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// The SQLite3 adapter keeps the suite runnable when PDO SQLite is not built in.
final class SecurityFixtureDatabase extends PDO
{
    public SQLite3 $sqlite;
    private bool $transaction = false;

    public function __construct()
    {
        $this->sqlite = new SQLite3(':memory:');
        $this->sqlite->enableExceptions(true);
        $this->sqlite->createFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new SecurityFixtureStatement($this->sqlite, $query);
    }

    public function beginTransaction(): bool
    {
        $this->transaction = $this->sqlite->exec('BEGIN');
        return $this->transaction;
    }

    public function commit(): bool
    {
        $result = $this->sqlite->exec('COMMIT');
        $this->transaction = false;
        return $result;
    }

    public function rollBack(): bool
    {
        $result = $this->sqlite->exec('ROLLBACK');
        $this->transaction = false;
        return $result;
    }

    public function inTransaction(): bool { return $this->transaction; }
}

final class SecurityFixtureStatement extends PDOStatement
{
    private SQLite3Result $result;
    private int $affected = 0;

    public function __construct(private SQLite3 $db, private string $query) {}

    public function execute(?array $params = null): bool
    {
        $statement = $this->db->prepare($this->query);
        foreach ($params ?? [] as $position => $value) {
            $statement->bindValue($position + 1, $value,
                is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        try {
            $this->result = $statement->execute();
        } catch (Exception $error) {
            if ($this->db->lastErrorCode() !== 19) throw $error;
            $duplicate = new PDOException('Fixture duplicate key');
            $duplicate->errorInfo = ['23000',1062,'Fixture duplicate key'];
            throw $duplicate;
        }
        $this->affected = $this->db->changes();
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result->fetchArray($mode === PDO::FETCH_NUM ? SQLITE3_NUM : SQLITE3_ASSOC);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = [];
        while ($row = $this->fetch($mode === PDO::FETCH_COLUMN ? PDO::FETCH_NUM : $mode)) {
            $rows[] = $mode === PDO::FETCH_COLUMN ? $row[0] : $row;
        }
        return $rows;
    }

    public function rowCount(): int { return $this->affected; }
}

$pdo = new SecurityFixtureDatabase();
$pdo->sqlite->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT,
    is_active INTEGER, is_admin INTEGER, auth_generation INTEGER DEFAULT 1);
    CREATE TRIGGER fixture_auth_generation AFTER UPDATE ON users FOR EACH ROW
    WHEN NEW.password_hash<>OLD.password_hash OR NEW.is_active<>OLD.is_active OR NEW.is_admin<>OLD.is_admin
    BEGIN UPDATE users SET auth_generation=OLD.auth_generation+1 WHERE id=OLD.id; END;
    CREATE TABLE password_resets(email TEXT PRIMARY KEY,token TEXT,token_digest TEXT,expires_at TEXT);
    CREATE TABLE mobile_device_tokens(token_hash TEXT PRIMARY KEY,user_id INTEGER,password_fingerprint TEXT,expires_at INTEGER);
    INSERT INTO users VALUES(1,"admin@example.invalid","old",1,1,1);
    INSERT INTO users VALUES(2,"other@example.invalid","old",1,0,1);
    CREATE TABLE vms(id INTEGER PRIMARY KEY,user_id INTEGER,node_id INTEGER,vm_id INTEGER,vm_type TEXT,status TEXT);
    INSERT INTO vms VALUES(21,1,11,100,"qemu","running"),(22,2,12,100,"qemu","running");
    ALTER TABLE vms ADD COLUMN suspended_at TEXT;
    ALTER TABLE vms ADD COLUMN suspend_reason TEXT;
    CREATE TABLE vm_metrics(guest_id INTEGER, vm_id INTEGER, timestamp TEXT,cpu_usage REAL,
        mem_usage REAL,mem_total REAL,net_in REAL,net_out REAL,disk_read REAL,disk_write REAL);
    INSERT INTO vm_metrics VALUES(21,100,NOW(),11,1,2,0,0,0,0),(22,100,NOW(),99,1,2,0,0,0,0),
        (NULL,100,NOW(),55,1,2,0,0,0,0)');
session_start();
$_SESSION = ['user'=>['id'=>1,'is_admin'=>1]];
expect(currentSessionAccount($pdo) === null, 'Legacy/admin-only session must be invalidated');
$account = fn () => (array)$pdo->sqlite->querySingle('SELECT * FROM users WHERE id=1', true);
$establish = function () use ($account, $pdo): string {
    openAccountSession($account());
    expect((bool)currentSessionAccount($pdo), 'Fresh account session should be accepted');
    return issueMobileDeviceToken($pdo, 1);
};
$token = $establish();
$pdo->sqlite->exec('UPDATE users SET is_active=0 WHERE id=1');
expect(currentSessionAccount($pdo) === null, 'Blocked account retains a session');
$pdo->sqlite->exec('UPDATE users SET is_active=1 WHERE id=1');
expect(!resumeMobileDeviceLogin($pdo,$token), 'Reactivation restored a revoked mobile token');
$token = $establish();
$pdo->sqlite->exec('UPDATE users SET is_admin=0 WHERE id=1');
expect(currentSessionAccount($pdo) === null, 'Changed role retains a session');
$pdo->sqlite->exec('UPDATE users SET is_admin=1 WHERE id=1');
expect(!resumeMobileDeviceLogin($pdo,$token), 'Restoring a role restored a revoked token');
$token = $establish();

$raw = str_repeat('a', 64);
$issue = $pdo->prepare('INSERT INTO password_resets VALUES(?,?,?,?)');
$issue->execute(['admin@example.invalid','',passwordResetDigest($raw),date('Y-m-d H:i:s',time()+600)]);
expect(consumePasswordReset($pdo,'admin@example.invalid',$raw,'First-Password1'), 'Valid reset was rejected');
expect(!consumePasswordReset($pdo,'admin@example.invalid',$raw,'Second-Password1'), 'Reset accepted twice');
expect(password_verify('First-Password1',$account()['password_hash']), 'Reuse replaced the password');
expect(currentSessionAccount($pdo) === null && !resumeMobileDeviceLogin($pdo,$token), 'Password reset did not revoke access');
$issue->execute(['admin@example.invalid',$raw,null,date('Y-m-d H:i:s',time()+600)]);
expect(!consumePasswordReset($pdo,'admin@example.invalid',$raw,'Second-Password1'), 'Legacy raw token was accepted');

expect(customerMetricsGuest($pdo,1,'qemu',['guest_id'=>22]) === null, 'Owner check leaked another guest');
expect(customerMetricsGuest($pdo,1,'qemu',['vm_id'=>100]) === 21, 'Legacy owner lookup resolved to the wrong guest');
expect(customerMetricsGuest($pdo,2,'qemu',['vm_id'=>100]) === 22, 'Second node guest was confused with first');
expect(array_column(guestMetricRows($pdo,21,'qemu',3600),'cpu_usage') === [11.0], 'Historical metrics mixed nodes or old data');
expect((float)guestMetricRows($pdo,22,'qemu',null)[0]['cpu_usage'] === 99.0, 'Latest metrics mixed nodes');
$pdo->sqlite->createFunction('NOW', fn () => '2026-03-31 12:00:00', 0);
$pdo->sqlite->exec("INSERT INTO vm_metrics VALUES
    (21,100,'2026-03-31 10:59:59',20,1,2,0,0,0,0),
    (21,100,'2026-03-31 11:00:00',21,1,2,0,0,0,0),
    (21,100,'2026-03-31 11:45:00',22,1,2,0,0,0,0),
    (21,100,'2026-03-31 12:00:01',23,1,2,0,0,0,0)");
$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Asia/Vladivostok');
$hour = historicalGuestMetrics($pdo, 21, 'qemu', 'hour');
$month = historicalGuestMetrics($pdo, 21, 'qemu', 'month');
date_default_timezone_set($originalTimezone);
$pdo->sqlite->createFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
expect($hour['cpuData'] === [21.0, 22.0], 'Hour included out-of-range metrics');
expect($hour['rangeEnd'] - $hour['rangeStart'] === 3600000, 'Hour bounds are incorrect');
expect($hour['timestamps'][1] - $hour['timestamps'][0] === 2700000, 'Sample spacing is incorrect');
expect(gmdate('Y-m-d H:i:s', intdiv($month['rangeStart'], 1000)) === '2026-02-28 12:00:00',
    'Calendar month should clamp to the last day');
$pdo->sqlite->exec('INSERT INTO vms(id,user_id,node_id,vm_id,vm_type,status) VALUES(23,1,13,100,"qemu","running")');
expect(customerMetricsGuest($pdo,1,'qemu',['vm_id'=>100]) === null, 'Ambiguous legacy ID was accepted');

updateBillingGuestState($pdo, 21, 'Fixture balance exhausted');
expect($pdo->sqlite->querySingle("SELECT status FROM vms WHERE id=21") === 'suspended', 'Billing did not suspend its guest');
expect($pdo->sqlite->querySingle("SELECT status FROM vms WHERE id=22") === 'running', 'Billing changed a guest on another node');
updateBillingGuestState($pdo, 21, null);
expect($pdo->sqlite->querySingle("SELECT status FROM vms WHERE id=21") === 'running', 'Billing did not resume its guest');

putenv('HOMEVLAD_PUBLIC_URL=https://panel.example.invalid');
$_SERVER['HTTP_HOST'] = 'untrusted.example.invalid';
expect(canonicalPanelOrigin() === 'https://panel.example.invalid', 'Canonical URL uses request Host');
$pair = sodium_crypto_sign_keypair();
$key = base64_encode(sodium_crypto_sign_publickey($pair));
$signature = base64_encode(sodium_crypto_sign_detached('manifest', sodium_crypto_sign_secretkey($pair)));
verifyUpdateManifestSignature('manifest',$signature,$key);
$rejected = false;
try { verifyUpdateManifestSignature('changed',$signature,$key); } catch (RuntimeException) { $rejected = true; }
expect($rejected, 'Changed manifest passed signature verification');

$pdo->sqlite->exec('CREATE TABLE telegram_auth_replays(digest TEXT PRIMARY KEY, expires_at INTEGER)');
$telegram = ['auth_date'=>time(),'first_name'=>'Fixture','id'=>'12345'];
$telegram['hash'] = hash_hmac('sha256', "auth_date={$telegram['auth_date']}\nfirst_name=Fixture\nid=12345",
    hash('sha256','test-bot',true));
expect(consumeTelegramAuthorization($pdo,$telegram,'test-bot'), 'Valid Telegram data rejected');
expect(!consumeTelegramAuthorization($pdo,$telegram,'test-bot'), 'Telegram data accepted twice');

$archive = tempnam(sys_get_temp_dir(), 'hv-backup-test-');
try {
    $zip = new ZipArchive();
    expect($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Fixture archive failed');
    $zip->addFromString('backup_meta.json','{"type":"files"}');
    $zip->addFromString('includes/auth.php','test');
    $zip->addFromString('uploads/document.txt','test');
    $zip->close();
    putenv('HOMEVLAD_IMMUTABLE_IMAGE=1');
    expect(inspectBackupArchive($archive)['files'] === ['uploads/document.txt'], 'Docker restore can replace code');
    putenv('HOMEVLAD_IMMUTABLE_IMAGE');
} finally {
    unlink($archive);
}

echo "PASS session/role/password revocation, mobile token revocation, reset replay, metrics and billing isolation, canonical URL, Telegram replay, Docker restore and manifest signature\n";
