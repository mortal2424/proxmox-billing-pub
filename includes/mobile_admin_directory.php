<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/license_guard.php';
require_once __DIR__ . '/payment_actions.php';

function mobileAdminAccounts(PDO $pdo, array $input): array
{
    $before = mobileAdminNumber($input['before'] ?? PHP_INT_MAX, 1, PHP_INT_MAX);
    $search = mobileAdminText($input, 'q', 255);
    $status = mobileAdminText($input, 'status', 16);
    $statusFilter = match ($status) {
        '' => '',
        'active' => ' AND is_active=1',
        'inactive' => ' AND COALESCE(is_active,0)=0',
        default => throw new InvalidArgumentException('Неизвестный статус пользователя'),
    };
    $query = $pdo->prepare("SELECT id,email,full_name,is_admin,is_active,balance
        FROM users WHERE id<? AND (?='' OR LOCATE(?,email)>0 OR LOCATE(?,full_name)>0 OR id=?)
        $statusFilter ORDER BY id DESC LIMIT 51");
    $query->execute([$before,$search,$search,$search,ctype_digit($search) && strlen($search) <= 10 ? (int)$search : 0]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    return ['items'=>array_map('mobileAdminAccountSummary', array_slice($rows,0,50)),
        'next'=>count($rows)>50 ? (int)$rows[49]['id'] : null];
}

function mobileAdminAccountSummary(array $row): array
{
    return ['id'=>(int)$row['id'], 'name'=>$row['full_name'] ?: $row['email'], 'email'=>$row['email'],
        'isAdmin'=>(bool)$row['is_admin'], 'isActive'=>(bool)$row['is_active'], 'balance'=>(string)($row['balance'] ?? '0.00')];
}

function mobileAdminAccount(PDO $pdo, array $input, int $actor): ?array
{
    $id = mobileAdminNumber($input['id'] ?? null, 1, PHP_INT_MAX);
    $query = $pdo->prepare('SELECT id,email,full_name,is_admin,is_active,balance,user_type,company_name,inn,kpp,created_at
        FROM users WHERE id=?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return mobileAdminAccountSummary($row) + [
        'fullName'=>(string)$row['full_name'], 'type'=>$row['user_type'], 'company'=>(string)$row['company_name'],
        'inn'=>(string)$row['inn'], 'kpp'=>(string)$row['kpp'], 'createdAt'=>(string)$row['created_at'], 'isSelf'=>$id===$actor,
    ];
}

function mobileAdminSaveAccount(PDO $pdo, array $input, int $actor): int
{
    $id = mobileAdminNumber($input['id'] ?? 0, 0, PHP_INT_MAX);
    $email = mobileAdminText($input, 'email', 255);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный email');
    $name = mobileAdminText($input, 'fullName', 100);
    $type = mobileAdminText($input, 'type', 16);
    if (!in_array($type, ['individual','entrepreneur','legal'], true)) throw new InvalidArgumentException('Неизвестный тип пользователя');
    $company = $type === 'individual' ? null : mobileAdminText($input,'company',255);
    $inn = $type === 'individual' ? null : mobileAdminText($input,'inn',12);
    $kpp = $type === 'legal' ? mobileAdminText($input,'kpp',9) : null;
    if (($inn !== null && $inn !== '' && !preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',$inn)) ||
        ($kpp !== null && $kpp !== '' && !preg_match('/^[0-9]{9}$/D',$kpp))) {
        throw new InvalidArgumentException('Проверьте ИНН и КПП');
    }
    $active = mobileAdminFlag($input, 'isActive');
    $admin = mobileAdminFlag($input, 'isAdmin');
    $hash = null;
    if ($id === 0) {
        $password = $input['password'] ?? null;
        if (!is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72 ||
            str_contains($password, "\0")) throw new InvalidArgumentException('Пароль должен содержать минимум 8 символов и занимать до 72 байт');
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }

    if ($id === 0) homevladReserveLicense($pdo, ['users' => 1]);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id,is_admin,is_active FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE');
        $query->execute([$actor,$id]);
        $accounts = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        if (empty($accounts[$actor]['is_admin']) || empty($accounts[$actor]['is_active'])) {
            throw new DomainException('Для изменения пользователя нужны права администратора');
        }
        if ($id === $actor && (!$active || !$admin)) {
            throw new DomainException('Нельзя отключить собственную учётную запись или снять с неё права администратора');
        }
        if ($id !== 0 && !isset($accounts[$id])) throw new DomainException('Пользователь не найден');
        $values = [$email,$name,$type,$company,$inn,$kpp,$active,$admin];
        if ($id !== 0) {
            $query = $pdo->prepare('UPDATE users SET email=?,full_name=?,user_type=?,company_name=?,inn=?,kpp=?,is_active=?,is_admin=?
                WHERE id=?');
            $query->execute([...$values,$id]);
        } else {
            $query = $pdo->prepare("INSERT INTO users (email,full_name,user_type,company_name,inn,kpp,is_active,is_admin,
                password_hash,phone,first_name,last_name) VALUES (?,?,?,?,?,?,?,?,?,'','','')");
            $query->execute([...$values,$hash]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        if (isset($accounts[$id])) {
            $changes = [];
            foreach (['is_admin'=>$admin,'is_active'=>$active] as $field=>$value) {
                if ((int) $accounts[$id][$field] !== (int) $value) {
                    $changes[$field] = ['before'=>(int)$accounts[$id][$field],'after'=>(int)$value];
                }
            }
            auditAccountEdit($actor, $id, $changes);
        }
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Пользователь с таким email уже существует');
        }
        throw $error;
    }
}

function mobileAdminPaymentSummary(array $row): array
{
    return ['id'=>(int)$row['id'], 'amount'=>(string)$row['amount'], 'status'=>$row['status'] ?? '',
        'createdAt'=>(string)$row['created_at'], 'method'=>(string)$row['payment_method'],
        'userName'=>$row['full_name'] ?: $row['email'], 'email'=>$row['email'], 'userId'=>(int)$row['user_id']];
}

function mobileAdminPayments(PDO $pdo, array $input): array
{
    $before = mobileAdminNumber($input['before'] ?? PHP_INT_MAX, 1, PHP_INT_MAX);
    $user = mobileAdminNumber($input['user'] ?? 0, 0, PHP_INT_MAX);
    $search = mobileAdminText($input, 'q', 255);
    $status = mobileAdminText($input, 'status', 16);
    if (!in_array($status,['','pending','completed','failed'],true)) throw new InvalidArgumentException('Неизвестный статус платежа');
    $query = $pdo->prepare("SELECT p.id,p.user_id,p.amount,p.status,p.created_at,p.payment_method,u.full_name,u.email
        FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id<? AND (?=0 OR p.user_id=?) AND (?='' OR p.status=?)
        AND (?='' OR LOCATE(?,u.email)>0 OR LOCATE(?,u.full_name)>0 OR LOCATE(?,p.description)>0 OR p.id=?)
        ORDER BY p.id DESC LIMIT 51");
    $query->execute([$before,$user,$user,$status,$status,$search,$search,$search,$search,
        ctype_digit($search) && strlen($search) <= 10 ? (int)$search : 0]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    return ['items'=>array_map('mobileAdminPaymentSummary',array_slice($rows,0,50)),
        'next'=>count($rows)>50 ? (int)$rows[49]['id'] : null];
}

function mobileAdminPayment(PDO $pdo, array $input): ?array
{
    $id = mobileAdminNumber($input['id'] ?? null, 1, PHP_INT_MAX);
    $query = $pdo->prepare('SELECT p.id,p.user_id,p.amount,p.status,p.created_at,p.updated_at,p.paid_at,p.payment_method,
        p.description,p.invoice_number,p.control_word,u.full_name,u.email FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return mobileAdminPaymentSummary($row) + [
        'description'=>(string)$row['description'], 'invoice'=>(string)$row['invoice_number'],
        'updatedAt'=>(string)$row['updated_at'], 'paidAt'=>(string)$row['paid_at'], 'controlWord'=>(string)$row['control_word'],
    ];
}

function mobileAdminAddPayment(PDO $pdo, array $input): int
{
    $user = mobileAdminNumber($input['userId'] ?? null, 1, PHP_INT_MAX);
    $description = mobileAdminText($input,'description',255);
    $requestId = strtolower(mobileAdminText($input,'requestId',36));
    $amount = str_replace(',', '.', mobileAdminText($input,'amount',16));
    if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$requestId)) {
        throw new InvalidArgumentException('Некорректный идентификатор запроса');
    }
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',$amount,$match)) {
        throw new InvalidArgumentException('Укажите сумму с точностью до копеек');
    }
    $amount = $match[1] . '.' . str_pad($match[2] ?? '',2,'0');
    if ($amount === '0.00' || $description === '') throw new InvalidArgumentException('Укажите положительную сумму и описание');
    $reference = 'mobile-' . $requestId;

    $pdo->beginTransaction();
    try {
        // The payer row serializes retries for this payment, including when no matching payment exists yet.
        $query = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $query->execute([$user]);
        if (!$query->fetchColumn()) throw new DomainException('Пользователь не найден');
        $query = $pdo->prepare("SELECT id,amount,description FROM payments WHERE user_id=? AND payment_method='manual' AND control_word=?");
        $query->execute([$user,$reference]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((string)$existing['amount'] !== $amount || $existing['description'] !== $description) {
                throw new DomainException('Данные платежа изменились после отправки. Обновите список платежей');
            }
            $id = (int)$existing['id'];
        } else {
            $query = $pdo->prepare("INSERT INTO payments(user_id,amount,description,status,control_word,payment_method)
                VALUES (?,?,?,'pending',?,'manual')");
            $query->execute([$user,$amount,$description,$reference]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
