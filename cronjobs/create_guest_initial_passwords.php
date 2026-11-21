<?php

require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = (new Database())->getConnection();
$pdo->exec('CREATE TABLE IF NOT EXISTS guest_initial_passwords (
    guest_id INT NOT NULL PRIMARY KEY,
    ciphertext VARBINARY(128) NOT NULL,
    username VARCHAR(64) NOT NULL,
    applied BOOLEAN NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX guest_initial_password_expiry (expires_at)
) ENGINE=InnoDB');
