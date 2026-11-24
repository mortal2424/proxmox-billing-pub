<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/account_security_schema.php';

migrateAccountSecurity((new Database())->getConnection());
echo "Account security schema ready\n";
