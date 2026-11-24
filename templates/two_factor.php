<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account_totp.php';
checkAuth();
$db = (new Database())->getConnection();
$userId = (int)$_SESSION['user']['id'];
$active = $db->prepare('SELECT is_active FROM users WHERE id=?');
$active->execute([$userId]);
if (!$active->fetchColumn()) { http_response_code(403); exit; }
$passwordColumn = 'password_hash';
$issuer = 'HomeVlad Cloud';
$backUrl = '/templates/dashboard.php';
require __DIR__ . '/../includes/account_totp_settings.php';
