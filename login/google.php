<?php
declare(strict_types=1);
ini_set('session.use_strict_mode', '1');
session_start(['cookie_secure'=>true, 'cookie_httponly'=>true, 'cookie_samesite'=>'Lax']);
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/license_guard.php';
$db = (new Database())->getConnection();
$callback = 'https://homevlad.ru/login/google.php';
$loginUrl = '/login/login.php';
$dashboardUrl = '/templates/dashboard.php';
$vpn = false;

function googleRegisterPanelAccount(PDO $db, array $identity, array $input): int
{
    $first = is_string($input['first_name'] ?? null) ? trim($input['first_name']) : '';
    $last = is_string($input['last_name'] ?? null) ? trim($input['last_name']) : '';
    $phone = $input['phone'] ?? null;
    if (!preg_match('/^[^\p{C}]{1,100}$/u', $first) || !preg_match('/^[^\p{C}]{1,100}$/u', $last) ||
        !is_string($phone) || !preg_match('/^\+7[0-9]{10}$/D', $phone)) {
        throw new DomainException('Укажите имя, фамилию и телефон в формате +7XXXXXXXXXX.');
    }
    homevladReserveLicense($db, ['users'=>1]);
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (email,phone,password_hash,first_name,last_name,user_type,email_verified,bonus_balance,is_active)
          VALUES (?,?,?,?,?,\'individual\',1,3000,1)');
        $stmt->execute([$identity['email'],$phone,password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),$first,$last]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO balance_history (user_id,amount,operation_type,description)
            VALUES (?,3000,'bonus','Приветственный бонус за регистрацию')")->execute([$id]);
        $db->prepare('INSERT INTO user_quotas (user_id,max_vms,max_cpu,max_ram,max_disk) VALUES (?,3,10,10240,200)')->execute([$id]);
        $db->prepare('INSERT INTO web_google_identities (google_subject,user_id) VALUES (?,?)')->execute([$identity['subject'],$id]);
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally {
        homevladLicenseRelease($db);
    }
}

require __DIR__.'/../includes/google_flow.php';
