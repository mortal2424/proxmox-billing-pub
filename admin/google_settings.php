<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/../includes/account_totp.php';

$db = (new Database())->getConnection();
requireNodeAdministrator($db);
$nodeCsrf = $_SESSION['node_csrf'];
$googleCallback = 'https://homevlad.ru/login/google.php';
$backUrl = '/admin/settings.php';
require __DIR__ . '/../includes/google_admin_view.php';
