<?php
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/payment_actions.php';
$pdo = (new Database())->getConnection();
$user = mobileUser($pdo);
$isAdmin = (bool) $user['is_admin'];
$userId = (int) $user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = mobileInput();
        $action = $input['action'] ?? '';
        if ($action === 'payment_approve' || $action === 'payment_reject') {
            if (!$isAdmin) mobileRespond(['error' => 'Доступ запрещён'], 403);
            processPendingPayment($pdo, (int) ($input['id'] ?? 0), $action === 'payment_approve' ? 'approve' : 'reject');
            mobileRespond(['success' => true]);
        }
        if ($action === 'guest_power') {
            $id = (int) ($input['id'] ?? 0);
            $command = $input['command'] ?? '';
            if (!in_array($command, ['start', 'shutdown', 'reboot', 'stop'], true)) {
                mobileRespond(['error' => 'Неизвестная команда'], 400);
            }
            $query = $pdo->prepare('SELECT v.*, n.hostname AS api_host, n.username, n.password, n.ssh_port, n.node_name
                FROM vms v JOIN proxmox_nodes n ON n.id=v.node_id
                WHERE v.id=? AND (v.user_id=? OR ?=1) AND n.is_active=1');
            $query->execute([$id, $userId, (int) $isAdmin]);
            $guest = $query->fetch(PDO::FETCH_ASSOC);
            if (!$guest || !in_array($guest['vm_type'], ['qemu', 'lxc'], true)) {
                mobileRespond(['error' => 'Машина недоступна'], 404);
            }
            session_write_close();
            require_once __DIR__ . '/../includes/proxmox_functions.php';
            $api = new ProxmoxAPI($guest['api_host'], $guest['username'], $guest['password'],
                $guest['ssh_port'] ?: 22, $guest['node_name'], $guest['node_id'], $pdo);
            if ($command === 'start') {
                $method = $guest['vm_type'] === 'lxc' ? 'startContainer' : 'startVM';
                $result = $api->$method((int) $guest['vm_id']);
                if (is_array($result) && ($result['success'] ?? true) === false) {
                    throw new RuntimeException('Не удалось подтвердить запуск машины');
                }
            } else {
                $api->controlGuest((int) $guest['vm_id'], $guest['vm_type'], $command);
            }
            mobileRespond(['success' => true]);
        }
        if ($action === 'ticket_create') {
            $subject = trim(is_string($input['subject'] ?? null) ? $input['subject'] : '');
            $message = trim(is_string($input['message'] ?? null) ? $input['message'] : '');
            $department = $input['department'] ?? 'general';
            if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200 ||
                mb_strlen($message) < 20 || mb_strlen($message) > 5000 ||
                !in_array($department, ['general', 'technical', 'billing'], true)) {
                mobileRespond(['error' => 'Тема должна содержать 5–200 символов, сообщение 20–5000'], 400);
            }
            $query = $pdo->prepare("INSERT INTO tickets (user_id,subject,message,department,priority,status)
                VALUES (?,?,?,?,'medium','open')");
            $query->execute([$userId, $subject, $message, $department]);
            $ticketId = (int) $pdo->lastInsertId();
            $notify = 'support';
        } elseif (in_array($action, ['ticket_reply', 'ticket_close', 'ticket_reopen'], true)) {
            $ticketId = (int) ($input['id'] ?? 0);
            $pdo->beginTransaction();
            $query = $pdo->prepare('SELECT id,user_id,status FROM tickets WHERE id=? AND (user_id=? OR ?=1) FOR UPDATE');
            $query->execute([$ticketId, $userId, (int) $isAdmin]);
            $ticket = $query->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                $pdo->rollBack();
                mobileRespond(['error' => 'Обращение не найдено'], 404);
            }
            if ($action === 'ticket_reply') {
                if ($ticket['status'] === 'closed') {
                    $pdo->rollBack();
                    mobileRespond(['error' => 'Обращение закрыто'], 409);
                }
                $message = trim(is_string($input['message'] ?? null) ? $input['message'] : '');
                if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
                    $pdo->rollBack();
                    mobileRespond(['error' => 'Ответ должен содержать 10–2000 символов'], 400);
                }
                $query = $pdo->prepare('INSERT INTO ticket_replies (ticket_id,user_id,message,is_admin) VALUES (?,?,?,?)');
                $query->execute([$ticketId, $userId, $message, (int) $isAdmin]);
                $status = $isAdmin ? 'answered' : 'open';
            } elseif ($action === 'ticket_reopen') {
                if (!$isAdmin) {
                    $pdo->rollBack();
                    mobileRespond(['error' => 'Доступ запрещён'], 403);
                }
                $status = 'open';
            } else {
                $status = 'closed';
            }
            $pdo->prepare('UPDATE tickets SET status=?,updated_at=NOW() WHERE id=?')->execute([$status, $ticketId]);
            $pdo->commit();
            $notify = $isAdmin ? 'user' : 'support';
        } else {
            mobileRespond(['error' => 'Неизвестное действие'], 400);
        }
        session_write_close();
        echo json_encode(['success' => true, 'id' => $ticketId]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            require_once __DIR__ . '/../admin/admin_functions.php';
            if ($notify === 'user') sendNotificationToUser($ticketId, $message ?? null, $status ?? null);
            else sendNotificationToSupport($ticketId);
        } catch (Throwable $notificationError) {
            error_log('Mobile ticket notification failed: ' . get_class($notificationError));
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') mobileRespond(['error' => 'Метод не поддерживается'], 405);
    $resource = $_GET['resource'] ?? '';
    $adminScope = ($_GET['admin'] ?? '') === '1';
    if ($adminScope && !$isAdmin) mobileRespond(['error' => 'Доступ запрещён'], 403);
    if ($resource === 'guest') {
        $query = $pdo->prepare('SELECT v.vm_id,v.vm_type,n.* FROM vms v JOIN proxmox_nodes n ON n.id=v.node_id
            WHERE v.id=? AND (v.user_id=? OR ?=1) AND n.is_active=1');
        $query->execute([(int) ($_GET['id'] ?? 0), $userId, (int) $isAdmin]);
        $node = $query->fetch(PDO::FETCH_ASSOC);
        if (!$node) mobileRespond(['error' => 'Машина недоступна'], 404);
        session_write_close();
        require_once __DIR__ . '/../includes/ProxmoxApiClient.php';
        $api = new ProxmoxApiClient($node);
        $type = $node['vm_type'] === 'lxc' ? 'lxc' : 'qemu';
        $path = '/nodes/' . rawurlencode($node['node_name']) . '/' . $type . '/' . (int) $node['vm_id'] . '/status/current';
        $state = $api->request('GET', $path);
        mobileRespond(['status' => $state['status'] ?? 'unknown',
            'cpu' => isset($state['cpu']) ? (float) $state['cpu'] * 100 : null,
            'memory' => ($state['maxmem'] ?? 0) > 0 ? (float) $state['mem'] / $state['maxmem'] * 100 : null,
            'uptime' => (int) ($state['uptime'] ?? 0)]);
    }
    if ($resource === 'payments') {
        $query = $pdo->prepare('SELECT p.id,p.amount,p.status,p.created_at,p.payment_method,u.full_name,u.email
            FROM payments p JOIN users u ON u.id=p.user_id '
            . ($adminScope ? '' : ' WHERE p.user_id=? ')
            . 'ORDER BY p.id DESC LIMIT 100');
        $query->execute($adminScope ? [] : [$userId]);
        $items = [];
        foreach ($query as $row) {
            $items[] = ['id' => (int) $row['id'], 'amount' => (float) $row['amount'],
                'status' => $row['status'], 'createdAt' => $row['created_at'] ?? '',
                'method' => $row['payment_method'] ?? '', 'userName' => $row['full_name'] ?: $row['email']];
        }
        mobileRespond(['items' => $items]);
    }
    if ($resource === 'tickets' || $resource === 'ticket') {
        $id = (int) ($_GET['id'] ?? 0);
        $conditions = $adminScope ? '1=1' : 't.user_id=?';
        $params = $adminScope ? [] : [$userId];
        if ($resource === 'ticket') {
            $conditions .= ' AND t.id=?';
            $params[] = $id;
        }
        $query = $pdo->prepare('SELECT t.id,t.subject,t.message,t.status,t.created_at,t.updated_at,
            u.full_name,u.email FROM tickets t JOIN users u ON u.id=t.user_id WHERE '
            . $conditions . ' ORDER BY t.updated_at DESC,t.id DESC LIMIT 100');
        $query->execute($params);
        $items = [];
        foreach ($query as $row) {
            $items[] = ['id' => (int) $row['id'], 'subject' => $row['subject'],
                'message' => $row['message'], 'status' => $row['status'],
                'createdAt' => $row['created_at'] ?? '', 'updatedAt' => $row['updated_at'] ?? '',
                'userName' => $row['full_name'] ?: $row['email']];
        }
        if ($resource === 'tickets') mobileRespond(['items' => $items]);
        if (!$items) mobileRespond(['error' => 'Обращение не найдено'], 404);
        $query = $pdo->prepare('SELECT r.id,r.message,r.is_admin,r.created_at,u.full_name,u.email
            FROM ticket_replies r JOIN users u ON u.id=r.user_id WHERE r.ticket_id=?
            ORDER BY r.id DESC LIMIT 200');
        $query->execute([$id]);
        $replies = [];
        foreach ($query as $row) {
            $replies[] = ['id' => (int) $row['id'], 'message' => $row['message'], 'isAdmin' => (bool) $row['is_admin'],
                'createdAt' => $row['created_at'] ?? '', 'userName' => $row['full_name'] ?: $row['email']];
        }
        mobileRespond(['ticket' => $items[0], 'replies' => array_reverse($replies)]);
    }
    mobileRespond(['error' => 'Раздел не найден'], 404);
} catch (Throwable $error) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $rollbackError) {}
    if ($error instanceof PDOException) {
        error_log('Mobile resource database failed');
        mobileRespond(['error' => 'Сервис временно недоступен'], 503);
    }
    error_log('Mobile resource failed: ' . get_class($error));
    mobileRespond(['error' => $error instanceof RuntimeException ? $error->getMessage() : 'Не удалось выполнить запрос'], 400);
}
