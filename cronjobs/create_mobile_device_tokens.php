<?php

require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = (new Database())->getConnection();
$pdo->exec('CREATE TABLE IF NOT EXISTS mobile_device_tokens (
    token_hash BINARY(32) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    password_fingerprint BINARY(32) NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX mobile_device_expiry (expires_at)
) ENGINE=InnoDB');
