<?php

function migrateAccountSecurity(PDO $pdo): void
{
    $columns = static function (string $table) use ($pdo): array {
        return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    };
    if (!in_array('auth_generation', $columns('users'), true)) {
        $pdo->exec('ALTER TABLE users ADD auth_generation BIGINT UNSIGNED NOT NULL DEFAULT 1');
    }
    $trigger = $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='users_auth_generation'")->fetchColumn();
    if (!(int) $trigger) {
        $pdo->exec('CREATE TRIGGER users_auth_generation BEFORE UPDATE ON users FOR EACH ROW
            SET NEW.auth_generation = OLD.auth_generation + IF(
                NOT (BINARY NEW.password_hash <=> BINARY OLD.password_hash)
                OR NOT (NEW.is_active <=> OLD.is_active)
                OR NOT (NEW.is_admin <=> OLD.is_admin), 1, 0)');
    }
    if (!in_array('token_digest', $columns('password_resets'), true)) {
        $pdo->exec('ALTER TABLE password_resets ADD token_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
    }
    $pdo->exec('DELETE FROM password_resets WHERE token_digest IS NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_auth_replays (
        digest BINARY(32) NOT NULL PRIMARY KEY, expires_at BIGINT NOT NULL, INDEX (expires_at)
    ) ENGINE=InnoDB');
    foreach (['vm_metrics','lxc_metrics'] as $table) {
        if (!in_array('guest_id', $columns($table), true)) {
            // Legacy rows contain no node identity. Keep them unassigned.
            $pdo->exec("ALTER TABLE $table ADD guest_id INT NULL, ADD INDEX metrics_guest_time (guest_id,timestamp)");
        }
    }
}
