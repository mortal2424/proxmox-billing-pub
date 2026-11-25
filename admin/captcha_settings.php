<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/../includes/account_totp.php';
require_once __DIR__ . '/../includes/web_login_challenge.php';
$db = (new Database())->getConnection();
requireNodeAdministrator($db);
$hostname = 'homevlad.ru';
$backUrl = '/admin/settings.php';
require __DIR__ . '/../includes/captcha_admin_view.php';
