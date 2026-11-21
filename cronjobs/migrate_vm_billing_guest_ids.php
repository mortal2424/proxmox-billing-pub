<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_database.php';

$mode = $argv[1] ?? '--check';
if ($argc > 2 || !in_array($mode, ['--check', '--apply-offline'], true)) {
    fwrite(STDERR, "Usage: php cronjobs/migrate_vm_billing_guest_ids.php [--check|--apply-offline]\n");
    exit(2);
}

try {
    if ($mode === '--apply-offline') {
        $lock = backupOperationLock(dirname(__DIR__));
    }
    $pdo = (new Database())->getConnection();
    $foreignKeys = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vm_billing' AND REFERENCED_TABLE_NAME='vms'
    ")->fetchAll();
    $legacy = [];
    $modern = false;
    foreach ($foreignKeys as $key) {
        if ($key['COLUMN_NAME'] === 'vm_id' && $key['REFERENCED_COLUMN_NAME'] === 'vm_id') {
            $legacy[] = $key['CONSTRAINT_NAME'];
        } elseif ($key['COLUMN_NAME'] === 'guest_id' && $key['REFERENCED_COLUMN_NAME'] === 'id') {
            $modern = true;
        } else {
            throw new RuntimeException('Unexpected billing foreign key. Review schema before migrating.');
        }
    }
    if ($modern && !$legacy) {
        echo "Billing guest ID migration already applied.\n";
        exit;
    }
    if ($modern) throw new RuntimeException('Mixed foreign keys. Review schema before migrating.');

    $mapping = 'SELECT b.id AS billing_id, MIN(v.id) AS guest_id, COUNT(v.id) AS matches
        FROM vm_billing b LEFT JOIN vms v ON v.vm_id=b.vm_id AND v.user_id=b.user_id GROUP BY b.id';
    $unresolved = (int)$pdo->query("SELECT COUNT(*) FROM ($mapping) AS candidates WHERE matches<>1")->fetchColumn();
    echo "Unresolved billing rows: $unresolved\n";
    if ($unresolved > 0) exit(1);
    if ($mode === '--check') exit;

    $columns = $pdo->query("SHOW COLUMNS FROM vm_billing LIKE 'guest_id'")->fetchAll();
    if (!$columns) {
        $pdo->exec('ALTER TABLE vm_billing ADD COLUMN guest_id INT NULL AFTER id');
    }
    $pdo->exec("UPDATE vm_billing b JOIN ($mapping) AS candidates ON candidates.billing_id=b.id
        SET b.guest_id=candidates.guest_id WHERE candidates.matches=1");
    $changes = ['MODIFY guest_id INT NOT NULL',
        'ADD CONSTRAINT vm_billing_guest_fk FOREIGN KEY (guest_id) REFERENCES vms(id) ON DELETE CASCADE'];
    foreach ($legacy as $name) {
        $changes[] = 'DROP FOREIGN KEY `' . str_replace('`', '``', $name) . '`';
    }
    $pdo->exec('ALTER TABLE vm_billing ' . implode(', ', $changes));
    echo "Billing now references vms.id.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
