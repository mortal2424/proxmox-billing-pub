<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../admin/admin_functions.php';

checkAuth();
$_SESSION['ticket_csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    (!is_string($_POST['ticket_csrf'] ?? null) || !hash_equals($_SESSION['ticket_csrf'], $_POST['ticket_csrf']))) {
    http_response_code(403);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Получаем статистику по тикетам
$ticket_stats = $pdo->query("
    SELECT
        COUNT(*) as total_tickets,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_tickets,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tickets,
        SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) as answered_tickets
    FROM tickets WHERE user_id = $user_id
")->fetch();

// Получаем среднее время ответа
$avg_response = $pdo->query("
    SELECT
        AVG(TIMESTAMPDIFF(MINUTE, t.created_at, tr.created_at)) as avg_response_minutes
    FROM tickets t
    LEFT JOIN ticket_replies tr ON t.id = tr.ticket_id AND tr.user_id != t.user_id
    WHERE t.user_id = $user_id AND tr.created_at IS NOT NULL
    GROUP BY t.id
    LIMIT 1
")->fetch();

// Получаем список тикетов пользователя с пагинацией
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$tickets = $pdo->prepare("
    SELECT * FROM tickets
    WHERE user_id = ?
    ORDER BY
        CASE
            WHEN status = 'open' THEN 1
            WHEN status = 'pending' THEN 2
            WHEN status = 'answered' THEN 3
            ELSE 4
        END,
        created_at DESC
    LIMIT ? OFFSET ?
");
$tickets->execute([$user_id, $per_page, $offset]);
$tickets = $tickets->fetchAll(PDO::FETCH_ASSOC);

// Получаем общее количество тикетов для пагинации
$total_tickets = $ticket_stats['total_tickets'];
$total_pages = ceil($total_tickets / $per_page);

// Получаем подробности конкретного тикета
$selected_ticket = null;
$ticket_replies = [];
$ticket_attachments = [];

if (isset($_GET['ticket_id'])) {
    $ticket_id = (int)$_GET['ticket_id'];
    $selected_ticket = $pdo->query("SELECT * FROM tickets WHERE id = $ticket_id AND user_id = $user_id")->fetch();

    if ($selected_ticket) {
        $ticket_replies = $pdo->query("
            SELECT tr.*, u.email, u.is_admin, u.avatar
            FROM ticket_replies tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.ticket_id = $ticket_id
            ORDER BY tr.created_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ticket_attachments = $pdo->query("SELECT * FROM ticket_attachments WHERE ticket_id = $ticket_id")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Обработка создания нового тикета
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    try {
        $subject = trim($_POST['subject']);
        $message = trim($_POST['message']);
        $department = $_POST['department'];
        $priority = $_POST['priority'];

        // Валидация
        if (empty($subject) || empty($message)) {
            throw new Exception("Все поля обязательны для заполнения");
        }

        if (strlen($subject) < 5) {
            throw new Exception("Тема должна содержать минимум 5 символов");
        }

        if (strlen($message) < 20) {
            throw new Exception("Сообщение должно содержать минимум 20 символов");
        }

        // Создаем тикет
        $stmt = $pdo->prepare("INSERT INTO tickets
            (user_id, subject, message, department, priority, status)
            VALUES (?, ?, ?, ?, ?, 'open')");
        $stmt->execute([$user_id, $subject, $message, $department, $priority]);
        $ticket_id = $pdo->lastInsertId();

        // Обработка вложений
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/tickets/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = basename($_FILES['attachments']['name'][$key]);
                    $fileSize = $_FILES['attachments']['size'][$key];
                    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);

                    // Проверка размера файла (5MB)
                    if ($fileSize > 5 * 1024 * 1024) {
                        continue;
                    }

                    // Проверка типа файла
                    $allowedTypes = [
                        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                        'application/pdf', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain', 'text/csv'
                    ];
                    if (!in_array($mimeType, $allowedTypes)) {
                        continue;
                    }

                    $filePath = $uploadDir . bin2hex(random_bytes(16)) . '.bin';

                    if (move_uploaded_file($tmpName, $filePath)) {
                        $stmt = $pdo->prepare("INSERT INTO ticket_attachments
                            (ticket_id, file_name, file_path, file_size, mime_type)
                            VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$ticket_id, $fileName, $filePath, $fileSize, $mimeType]);
                    }
                }
            }
        }

        // Отправляем уведомление поддержке
        if (function_exists('sendNotificationToSupport')) {
            sendNotificationToSupport($ticket_id);
        }

        $_SESSION['success_message'] = "✅ Тикет #$ticket_id успешно создан! Мы ответим вам в ближайшее время.";
        header("Location: support.php?ticket_id=" . $ticket_id);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "❌ Ошибка: " . $e->getMessage();
    }
}

// Обработка ответа на тикет
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket'])) {
    try {
        $ticket_id = (int)$_POST['ticket_id'];
        $message = trim($_POST['message']);

        if (empty($message)) {
            throw new Exception("Сообщение не может быть пустым");
        }

        if (strlen($message) < 10) {
            throw new Exception("Сообщение должно содержать минимум 10 символов");
        }

        // Проверяем принадлежность тикета пользователю
        $check = $pdo->prepare("SELECT id, status FROM tickets WHERE id = ? AND user_id = ?");
        $check->execute([$ticket_id, $user_id]);
        $ticket = $check->fetch();
        if (!$ticket) {
            throw new Exception("Тикет не найден");
        }

        // Проверяем, что тикет не закрыт
        if ($ticket['status'] === 'closed') {
            throw new Exception("Тикет закрыт. Создайте новый тикет для продолжения обсуждения.");
        }

        // Добавляем ответ
        $stmt = $pdo->prepare("INSERT INTO ticket_replies
            (ticket_id, user_id, message)
            VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $user_id, $message]);

        // Обновляем статус тикета
        $pdo->prepare("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?")
            ->execute([$ticket_id]);

        // Обработка вложений для ответа
        if (!empty($_FILES['reply_attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/tickets/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach ($_FILES['reply_attachments']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['reply_attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = basename($_FILES['reply_attachments']['name'][$key]);
                    $fileSize = $_FILES['reply_attachments']['size'][$key];
                    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);

                    if ($fileSize > 5 * 1024 * 1024) {
                        continue;
                    }

                    $allowedTypes = [
                        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                        'application/pdf', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain', 'text/csv'
                    ];
                    if (!in_array($mimeType, $allowedTypes)) {
                        continue;
                    }

                    $filePath = $uploadDir . bin2hex(random_bytes(16)) . '.bin';

                    if (move_uploaded_file($tmpName, $filePath)) {
                        $stmt = $pdo->prepare("INSERT INTO ticket_attachments
                            (ticket_id, file_name, file_path, file_size, mime_type)
                            VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$ticket_id, $fileName, $filePath, $fileSize, $mimeType]);
                    }
                }
            }
        }

        // Отправляем уведомление администраторам
        if (function_exists('sendNotificationToSupport')) {
            sendNotificationToSupport($ticket_id);
        }

        $_SESSION['success_message'] = "✅ Ваш ответ отправлен!";
        header("Location: support.php?ticket_id=" . $ticket_id);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "❌ Ошибка: " . $e->getMessage();
    }
}

// Функции для текстового представления
if (!function_exists('getStatusText')) {
    function getStatusText($status) {
        $statuses = [
            'open' => 'Открыт',
            'answered' => 'Ответ получен',
            'closed' => 'Закрыт',
            'pending' => 'В ожидании'
        ];
        return $statuses[$status] ?? $status;
    }
}

if (!function_exists('getDepartmentText')) {
    function getDepartmentText($department) {
        $departments = [
            'technical' => 'Технические вопросы',
            'billing' => 'Биллинг и оплата',
            'general' => 'Общие вопросы'
        ];
        return $departments[$department] ?? $department;
    }
}

if (!function_exists('getPriorityText')) {
    function getPriorityText($priority) {
        $priorities = [
            'low' => 'Низкий',
            'medium' => 'Средний',
            'high' => 'Высокий',
            'critical' => 'Критичный'
        ];
        return $priorities[$priority] ?? $priority;
    }
}

if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' байт';
        } elseif ($bytes == 1) {
            return $bytes . ' байт';
        } else {
            return '0 байт';
        }
    }
}

$title = "Техническая поддержка | HomeVlad Cloud";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        /* Основной контейнер */
        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Заголовок страницы */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Статистика тикетов */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 16px 16px 0 0;
        }

        .stat-card.open::before { background: var(--info-gradient); }
        .stat-card.closed::before { background: var(--success-gradient); }
        .stat-card.pending::before { background: var(--warning-gradient); }
        .stat-card.answered::before { background: var(--purple-gradient); }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .stat-icon.open { background: var(--info-gradient); }
        .stat-icon.closed { background: var(--success-gradient); }
        .stat-icon.pending { background: var(--warning-gradient); }
        .stat-icon.answered { background: var(--purple-gradient); }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 8px 0;
            color: #1e293b;
        }

        body.dark-theme .stat-value {
            color: #f1f5f9;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 4px;
            font-weight: 500;
        }

        body.dark-theme .stat-label {
            color: #94a3b8;
        }

        /* Табы */
        .support-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: white;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        body.dark-theme .support-tabs {
            background: rgba(30, 41, 59, 0.7);
        }

        .support-tab {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .support-tab:hover {
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .support-tab.active {
            background: var(--secondary-gradient);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: slideIn 0.3s ease forwards;
        }

        /* Форма создания тикета */
        .ticket-form-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .ticket-form-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .section-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: var(--secondary-gradient);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        .section-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        body.dark-theme .section-subtitle {
            color: #94a3b8;
        }

        /* Формы */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }

        body.dark-theme .form-label {
            color: #cbd5e1;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            background: white;
            color: #1e293b;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        body.dark-theme .form-input {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.2);
            color: #cbd5e1;
        }

        .form-input:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        .form-textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .form-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #64748b;
        }

        body.dark-theme .form-hint {
            color: #94a3b8;
        }

        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--secondary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.3);
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid rgba(148, 163, 184, 0.3);
            color: #64748b;
        }

        body.dark-theme .btn-outline {
            color: #94a3b8;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-outline:hover {
            background: rgba(148, 163, 184, 0.1);
        }

        /* Список тикетов */
        .tickets-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ticket-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
        }

        body.dark-theme .ticket-item {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .ticket-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            border-color: rgba(0, 188, 212, 0.3);
        }

        .ticket-item.expanded {
            background: rgba(0, 188, 212, 0.05);
            border-color: rgba(0, 188, 212, 0.3);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            cursor: pointer;
        }

        .ticket-header-content {
            flex: 1;
        }

        .ticket-actions {
            display: flex;
            gap: 8px;
        }

        .view-ticket-btn {
            background: rgba(0, 188, 212, 0.1);
            border: 1px solid rgba(0, 188, 212, 0.2);
            color: #00bcd4;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .view-ticket-btn:hover {
            background: rgba(0, 188, 212, 0.2);
            transform: translateY(-1px);
        }

        .ticket-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .ticket-title {
            color: #f1f5f9;
        }

        .ticket-meta {
            display: flex;
            gap: 16px;
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
        }

        .ticket-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-open { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-answered { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
        .status-closed { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }

        .ticket-content {
            display: none;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        .ticket-item.expanded .ticket-content {
            display: block;
        }

        /* Переписка в тикете */
        .ticket-conversation {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message {
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .user-message {
            background: rgba(0, 188, 212, 0.05);
            border-color: rgba(0, 188, 212, 0.2);
            margin-left: 40px;
        }

        .admin-message {
            background: rgba(139, 92, 246, 0.05);
            border-color: rgba(139, 92, 246, 0.2);
            margin-right: 40px;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .message-user {
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .message-user {
            color: #f1f5f9;
        }

        .message-date {
            color: #64748b;
        }

        .message-body {
            line-height: 1.6;
        }

        /* Вложения */
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(148, 163, 184, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
        }

        .attachment-item:hover {
            background: rgba(0, 188, 212, 0.1);
            border-color: rgba(0, 188, 212, 0.2);
            transform: translateY(-2px);
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--secondary-gradient);
            color: white;
            font-size: 18px;
        }

        .attachment-info {
            flex: 1;
            overflow: hidden;
        }

        .attachment-name {
            font-weight: 600;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .attachment-size {
            font-size: 11px;
            color: #64748b;
        }

        /* Пустое состояние */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .empty-text {
            color: #64748b;
            margin-bottom: 24px;
            font-size: 16px;
        }

        body.dark-theme .empty-text {
            color: #94a3b8;
        }

        /* Пагинация */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-link {
            padding: 8px 16px;
            border-radius: 8px;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: rgba(0, 188, 212, 0.2);
            transform: translateY(-2px);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            color: #64748b;
            font-size: 14px;
        }

        /* Уведомления */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 400px;
        }

        .notification.success {
            background: var(--success-gradient);
        }

        .notification.error {
            background: var(--danger-gradient);
        }

        .notification.warning {
            background: var(--warning-gradient);
        }

        .notification.info {
            background: var(--info-gradient);
        }

        /* Загрузка файлов */
        .file-upload {
            border: 2px dashed rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            padding: 32px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.05);
        }

        .file-upload-icon {
            font-size: 32px;
            color: #00bcd4;
            margin-bottom: 12px;
        }

        .file-list {
            margin-top: 12px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: rgba(148, 163, 184, 0.05);
            border-radius: 6px;
            margin-bottom: 6px;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .stat-card {
                padding: 16px;
            }

            .support-tabs {
                flex-direction: column;
            }

            .ticket-header {
                flex-direction: column;
                gap: 8px;
            }

            .ticket-actions {
                align-self: flex-end;
            }

            .ticket-meta {
                flex-wrap: wrap;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .message {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Анимации */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card {
            animation: slideIn 0.5s ease forwards;
        }

        /* Кнопка вверх */
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--secondary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 999;
        }

        .scroll-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.5);
        }

        /* Стили для FAQ */
        .faq-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .faq-item {
            background: rgba(148, 163, 184, 0.05);
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #00bcd4;
        }

        .faq-item h3 {
            color: #1e293b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .faq-item h3 {
            color: #f1f5f9;
        }

        .faq-item p {
            color: #64748b;
            line-height: 1.6;
        }

        body.dark-theme .faq-item p {
            color: #94a3b8;
        }

        /* Стили для кнопок ответа */
        .reply-form {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .reply-form {
            background: rgba(30, 41, 59, 0.7);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        /* === ОБЩИЙ ФУТЕР === */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 80px 0 30px;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
            margin-top: auto;
            width: 100%;
        }

        .modern-footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .modern-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 188, 212, 0.5), transparent);
        }
    </style>
</head>
<body>
    <?php
    // Подключаем обновленную шапку
    include '../templates/headers/user_header.php';
    ?>

    <div class="main-container">
        <?php
        // Подключаем обновленный сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-headset"></i> Техническая поддержка
                </h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="document.querySelector('[data-tab=\"new-ticket\"]').click()">
                        <i class="fas fa-plus"></i> Новый тикет
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="notification success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['success_message']) ?></span>
                    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="notification error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['error_message']) ?></span>
                    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Статистика тикетов -->
            <div class="stats-grid">
                <div class="stat-card open">
                    <div class="stat-header">
                        <div class="stat-icon open">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-label">Открытые тикеты</div>
                    </div>
                    <div class="stat-value"><?= $ticket_stats['open_tickets'] ?? 0 ?></div>
                </div>

                <div class="stat-card answered">
                    <div class="stat-header">
                        <div class="stat-icon answered">
                            <i class="fas fa-reply"></i>
                        </div>
                        <div class="stat-label">Ожидают ответа</div>
                    </div>
                    <div class="stat-value"><?= $ticket_stats['answered_tickets'] ?? 0 ?></div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-header">
                        <div class="stat-icon pending">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="stat-label">В обработке</div>
                    </div>
                    <div class="stat-value"><?= $ticket_stats['pending_tickets'] ?? 0 ?></div>
                </div>

                <div class="stat-card closed">
                    <div class="stat-header">
                        <div class="stat-icon closed">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-label">Закрытые</div>
                    </div>
                    <div class="stat-value"><?= $ticket_stats['closed_tickets'] ?? 0 ?></div>
                </div>
            </div>

            <!-- Табы -->
            <div class="support-tabs">
                <div class="support-tab active" data-tab="new-ticket">
                    <i class="fas fa-plus-circle"></i> Новый тикет
                </div>
                <div class="support-tab" data-tab="my-tickets">
                    <i class="fas fa-ticket-alt"></i> Мои тикеты (<?= $total_tickets ?>)
                </div>
                <div class="support-tab" data-tab="faq">
                    <i class="fas fa-question-circle"></i> FAQ
                </div>
            </div>

            <!-- Вкладка: Новый тикет -->
            <div class="tab-content active" id="new-ticket">
                <div class="ticket-form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Создание нового тикета</h2>
                            <p class="section-subtitle">Опишите вашу проблему максимально подробно</p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="ticketForm">
                        <input type="hidden" name="ticket_csrf" value="<?= htmlspecialchars($_SESSION['ticket_csrf'], ENT_QUOTES) ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Тема тикета *</label>
                                <input type="text" name="subject" class="form-input" required
                                       placeholder="Кратко опишите проблему"
                                       minlength="5" maxlength="200">
                                <span class="form-hint">Минимум 5 символов</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Отдел *</label>
                                <select name="department" class="form-input form-select" required>
                                    <option value="">Выберите отдел</option>
                                    <option value="technical">🔧 Технические вопросы</option>
                                    <option value="billing">💰 Биллинг и оплата</option>
                                    <option value="general">📋 Общие вопросы</option>
                                    <option value="vm">🖥️ Виртуальные машины</option>
                                    <option value="network">🌐 Сеть и подключение</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Приоритет *</label>
                                <select name="priority" class="form-input form-select" required>
                                    <option value="">Выберите приоритет</option>
                                    <option value="low">🟢 Низкий</option>
                                    <option value="medium">🟡 Средний</option>
                                    <option value="high">🟠 Высокий</option>
                                    <option value="critical">🔴 Критичный</option>
                                </select>
                                <span class="form-hint">Критичный — для полной недоступности услуг</span>
                            </div>

                            <div class="form-group full-width" style="grid-column: 1 / -1;">
                                <label class="form-label">Подробное описание *</label>
                                <textarea name="message" class="form-input form-textarea" required
                                          placeholder="Опишите вашу проблему максимально подробно. Укажите:
1. Что именно не работает
2. Когда началась проблема
3. Какие действия вы предпринимали
4. Какой результат ожидаете"
                                          minlength="20" maxlength="5000"></textarea>
                                <span class="form-hint">Минимум 20 символов. Максимально подробное описание поможет быстрее решить проблему.</span>
                            </div>

                            <div class="form-group full-width" style="grid-column: 1 / -1;">
                                <label class="form-label">Вложения (до 3 файлов, максимум 5MB каждый)</label>
                                <div class="file-upload" onclick="document.getElementById('attachments').click()">
                                    <div class="file-upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <p>Нажмите для загрузки файлов или перетащите их сюда</p>
                                    <p class="form-hint">Поддерживаемые форматы: JPG, PNG, GIF, PDF, DOC, TXT, CSV</p>
                                </div>
                                <input type="file" name="attachments[]" id="attachments" multiple
                                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,.csv"
                                       style="display: none;">
                                <div class="file-list" id="fileList"></div>
                            </div>
                        </div>

                        <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);">
                            <button type="submit" name="create_ticket" class="btn btn-primary" style="width: 100%; padding: 16px;">
                                <i class="fas fa-paper-plane"></i> Создать тикет
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Вкладка: Мои тикеты -->
            <div class="tab-content" id="my-tickets">
                <?php if (empty($tickets)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <h3>У вас нет созданных тикетов</h3>
                        <p class="empty-text">Создайте свой первый тикет, и наша команда поддержки поможет вам</p>
                        <button class="btn btn-primary" onclick="document.querySelector('[data-tab=\"new-ticket\"]').click()">
                            <i class="fas fa-plus"></i> Создать тикет
                        </button>
                    </div>
                <?php else: ?>
                    <div class="tickets-list">
                        <?php foreach ($tickets as $ticket): ?>
                            <div class="ticket-item <?= (isset($_GET['ticket_id']) && $_GET['ticket_id'] == $ticket['id']) ? 'expanded' : '' ?>"
                                 data-ticket-id="<?= $ticket['id'] ?>">
                                <div class="ticket-header">
                                    <div class="ticket-header-content" onclick="toggleTicket(this.closest('.ticket-item'))">
                                        <h3 class="ticket-title">
                                            #<?= $ticket['id'] ?>: <?= htmlspecialchars($ticket['subject']) ?>
                                        </h3>
                                        <div class="ticket-meta">
                                            <span><i class="fas fa-layer-group"></i> <?= getDepartmentText($ticket['department']) ?></span>
                                            <span><i class="fas fa-exclamation-circle"></i> <?= getPriorityText($ticket['priority']) ?></span>
                                            <span><i class="fas fa-calendar-alt"></i> <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></span>
                                        </div>
                                    </div>
                                    <div class="ticket-actions">
                                        <span class="ticket-status status-<?= $ticket['status'] ?>">
                                            <?= getStatusText($ticket['status']) ?>
                                        </span>
                                        <button class="view-ticket-btn" data-ticket-id="<?= $ticket['id'] ?>">
                                            <i class="fas fa-external-link-alt"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="ticket-content">
                                    <div class="ticket-conversation">
                                        <!-- Сообщение тикета -->
                                        <div class="message user-message">
                                            <div class="message-header">
                                                <span class="message-user">
                                                    <i class="fas fa-user"></i> Вы
                                                </span>
                                                <span class="message-date">
                                                    <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
                                                </span>
                                            </div>
                                            <div class="message-body">
                                                <?= nl2br(htmlspecialchars($ticket['message'])) ?>
                                            </div>
                                        </div>

                                        <!-- Ответы -->
                                        <?php
                                        $replies = $pdo->query("
                                            SELECT tr.*, u.email, u.is_admin, u.avatar
                                            FROM ticket_replies tr
                                            JOIN users u ON tr.user_id = u.id
                                            WHERE tr.ticket_id = {$ticket['id']}
                                            ORDER BY tr.created_at ASC
                                        ")->fetchAll(PDO::FETCH_ASSOC);
                                        ?>

                                        <?php foreach ($replies as $reply): ?>
                                            <?php
                                            // Определяем, является ли ответ от текущего пользователя
                                            $is_current_user = ($reply['user_id'] == $user_id);
                                            ?>
                                            <div class="message <?= $is_current_user ? 'user-message' : 'admin-message' ?>">
                                                <div class="message-header">
                                                    <span class="message-user">
                                                        <i class="fas <?= $is_current_user ? 'fa-user' : 'fa-user-shield' ?>"></i>
                                                        <?= $is_current_user ? 'Вы' : 'Поддержка' ?>
                                                    </span>
                                                    <span class="message-date">
                                                        <?= date('d.m.Y H:i', strtotime($reply['created_at'])) ?>
                                                    </span>
                                                </div>
                                                <div class="message-body">
                                                    <?= nl2br(htmlspecialchars($reply['message'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <!-- Вложения -->
                                        <?php
                                        $attachments = $pdo->query("
                                            SELECT * FROM ticket_attachments
                                            WHERE ticket_id = {$ticket['id']}
                                        ")->fetchAll(PDO::FETCH_ASSOC);
                                        ?>

                                        <?php if (!empty($attachments)): ?>
                                            <div class="attachments-grid">
                                                <h4><i class="fas fa-paperclip"></i> Вложения:</h4>
                                                <?php foreach ($attachments as $file): ?>
                                                    <a href="/download.php?id=<?= (int)$file['id'] ?>" class="attachment-item" target="_blank">
                                                        <div class="attachment-icon">
                                                            <?php
                                                            $extension = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                                            $icon = 'file';
                                                            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $icon = 'image';
                                                            elseif ($extension === 'pdf') $icon = 'file-pdf';
                                                            elseif (in_array($extension, ['doc', 'docx'])) $icon = 'file-word';
                                                            elseif (in_array($extension, ['txt', 'csv'])) $icon = 'file-alt';
                                                            ?>
                                                            <i class="fas fa-<?= $icon ?>"></i>
                                                        </div>
                                                        <div class="attachment-info">
                                                            <div class="attachment-name"><?= htmlspecialchars($file['file_name']) ?></div>
                                                            <div class="attachment-size"><?= formatFileSize($file['file_size']) ?></div>
                                                        </div>
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Форма ответа -->
                                        <?php if ($ticket['status'] !== 'closed'): ?>
                                            <form method="POST" class="reply-form" enctype="multipart/form-data">
                        <input type="hidden" name="ticket_csrf" value="<?= htmlspecialchars($_SESSION['ticket_csrf'], ENT_QUOTES) ?>">
                                                <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                <div class="form-group">
                                                    <label class="form-label">Ваш ответ *</label>
                                                    <textarea name="message" class="form-input form-textarea" required
                                                              placeholder="Введите ваш ответ..."
                                                              minlength="10" maxlength="2000"></textarea>
                                                    <span class="form-hint">Минимум 10 символов</span>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label">Вложения</label>
                                                    <input type="file" name="reply_attachments[]" class="form-input" multiple
                                                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,.csv">
                                                    <span class="form-hint">Максимальный размер файла: 5MB</span>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" name="reply_ticket" class="btn btn-primary">
                                                        <i class="fas fa-reply"></i> Отправить ответ
                                                    </button>
                                                    <?php if ($ticket['status'] !== 'closed' && $ticket['user_id'] == $user_id): ?>
                                                        <button type="button" class="btn btn-outline" onclick="closeTicket(<?= $ticket['id'] ?>)">
                                                            <i class="fas fa-check-circle"></i> Закрыть тикет
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="notification info" style="position: relative; margin-top: 16px;">
                                                <i class="fas fa-info-circle"></i>
                                                <span>Тикет закрыт. Если у вас есть новые вопросы, создайте новый тикет.</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Пагинация -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?><?= isset($_GET['ticket_id']) ? '&ticket_id=' . $_GET['ticket_id'] : '' ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i> Назад
                                </a>
                            <?php endif; ?>

                            <span class="page-info">Страница <?= $page ?> из <?= $total_pages ?></span>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?><?= isset($_GET['ticket_id']) ? '&ticket_id=' . $_GET['ticket_id'] : '' ?>" class="page-link">
                                    Вперед <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Вкладка: FAQ -->
            <div class="tab-content" id="faq">
                <div class="ticket-form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Часто задаваемые вопросы</h2>
                            <p class="section-subtitle">Возможно, ответ на ваш вопрос уже есть здесь</p>
                        </div>
                    </div>

                    <div class="faq-list">
                        <div class="faq-item">
                            <h3><i class="fas fa-server"></i> Как создать виртуальную машину?</h3>
                            <p>Перейдите в раздел "Заказать ВМ", выберите тариф или настройте параметры вручную, затем нажмите "Создать ВМ".</p>
                        </div>

                        <div class="faq-item">
                            <h3><i class="fas fa-credit-card"></i> Как пополнить баланс?</h3>
                            <p>В разделе "Биллинг" выберите способ оплаты (СБП, карта или счет для юр. лиц) и следуйте инструкциям.</p>
                        </div>

                        <div class="faq-item">
                            <h3><i class="fas fa-network-wired"></i> Не могу подключиться к ВМ</h3>
                            <p>Проверьте: 1) Состояние ВМ в панели управления, 2) Правильность IP-адреса, 3) Настройки firewall на ВМ.</p>
                        </div>

                        <div class="faq-item">
                            <h3><i class="fas fa-hdd"></i> Как увеличить диск ВМ?</h3>
                            <p>В разделе "Мои ВМ" выберите нужную машину, затем "Изменить конфигурацию". Увеличение диска происходит с сохранением данных.</p>
                        </div>

                        <div class="faq-item">
                            <h3><i class="fas fa-clock"></i> Как быстро отвечает поддержка?</h3>
                            <p>Среднее время ответа: 15-30 минут в рабочее время (Пн-Пт, 9:00-18:00). Критичные тикеты обрабатываются в приоритетном порядке.</p>
                        </div>

                        <div class="faq-item">
                            <h3><i class="fas fa-backup"></i> Есть ли бэкапы?</h3>
                            <p>Да, мы делаем ежедневные бэкапы всех ВМ. Вы можете восстановить данные за последние 7 дней, создав тикет в поддержку.</p>
                        </div>
                    </div>

                    <div style="margin-top: 32px; padding: 20px; background: rgba(0, 188, 212, 0.05); border-radius: 12px; border: 1px solid rgba(0, 188, 212, 0.2);">
                        <h3 style="margin-bottom: 12px;"><i class="fas fa-headset"></i> Контакты поддержки</h3>
                        <p><strong>Email:</strong> support@homevlad.cloud</p>
                        <p><strong>Telegram:</strong> @homevlad_support_bot</p>
                        <p><strong>Часы работы:</strong> Пн-Пт 9:00-18:00 (МСК)</p>
                        <p><strong>Экстренная поддержка:</strong> +7 (964) 438-46-46 (только для критичных проблем)</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Переключение вкладок
            const supportTabs = document.querySelectorAll('.support-tab');
            const tabContents = document.querySelectorAll('.tab-content');

            supportTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Убираем активный класс у всех вкладок
                    supportTabs.forEach(t => t.classList.remove('active'));
                    // Добавляем активный класс текущей вкладке
                    this.classList.add('active');

                    // Скрываем все контенты
                    tabContents.forEach(c => c.classList.remove('active'));
                    // Показываем нужный контент
                    const tabId = this.dataset.tab;
                    document.getElementById(tabId).classList.add('active');

                    // Обновляем URL
                    const url = new URL(window.location);
                    if (tabId !== 'my-tickets') {
                        url.searchParams.delete('ticket_id');
                    }
                    window.history.pushState(null, '', url);
                });
            });

            // Функция для переключения тикета (только разворачивает/сворачивает)
            function toggleTicket(element) {
                // Закрываем все другие открытые тикеты
                document.querySelectorAll('.ticket-item.expanded').forEach(item => {
                    if (item !== element) {
                        item.classList.remove('expanded');
                    }
                });

                // Переключаем текущий тикет
                const wasExpanded = element.classList.contains('expanded');
                element.classList.toggle('expanded');

                // Обновляем URL если тикет открыт
                const ticketId = element.dataset.ticketId;
                const url = new URL(window.location);

                if (element.classList.contains('expanded')) {
                    url.searchParams.set('ticket_id', ticketId);
                    // Переключаемся на вкладку "Мои тикеты" только если текущая вкладка не "my-tickets"
                    const currentTab = document.querySelector('.support-tab.active').dataset.tab;
                    if (currentTab !== 'my-tickets') {
                        document.querySelector('[data-tab="my-tickets"]').click();
                    }
                } else {
                    url.searchParams.delete('ticket_id');
                }

                window.history.pushState(null, '', url);
            }

            // Функция для открытия тикета (гарантированно открывает и переключает вкладку)
            function openTicket(ticketId) {
                // Закрываем все открытые тикеты
                document.querySelectorAll('.ticket-item.expanded').forEach(item => {
                    item.classList.remove('expanded');
                });
                
                // Открываем нужный тикет
                const ticketElement = document.querySelector(`.ticket-item[data-ticket-id="${ticketId}"]`);
                if (ticketElement) {
                    ticketElement.classList.add('expanded');
                    
                    // Переключаемся на вкладку "Мои тикеты"
                    document.querySelector('[data-tab="my-tickets"]').click();
                    
                    // Обновляем URL
                    const url = new URL(window.location);
                    url.searchParams.set('ticket_id', ticketId);
                    window.history.pushState(null, '', url);
                    
                    // Прокручиваем к тикету
                    setTimeout(() => {
                        ticketElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                }
            }

            // Обработчики для кнопок открытия тикетов
            document.querySelectorAll('.view-ticket-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const ticketId = this.dataset.ticketId;
                    openTicket(ticketId);
                });
            });

            // При загрузке страницы открываем тикет из URL
            const urlParams = new URLSearchParams(window.location.search);
            const ticketId = urlParams.get('ticket_id');

            if (ticketId) {
                const ticketElement = document.querySelector(`.ticket-item[data-ticket-id="${ticketId}"]`);
                if (ticketElement) {
                    ticketElement.classList.add('expanded');
                    // Переключаемся на вкладку "Мои тикеты"
                    document.querySelector('[data-tab="my-tickets"]').click();
                    
                    // Прокручиваем к тикету
                    setTimeout(() => {
                        ticketElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                }
            }

            // Обработка загрузки файлов
            const attachmentsInput = document.getElementById('attachments');
            const fileList = document.getElementById('fileList');

            if (attachmentsInput) {
                attachmentsInput.addEventListener('change', function() {
                    fileList.innerHTML = '';
                    const files = Array.from(this.files);

                    // Ограничение на количество файлов
                    if (files.length > 3) {
                        showNotification('Можно загрузить не более 3 файлов', 'error');
                        this.value = '';
                        return;
                    }

                    // Проверка размера файлов
                    files.forEach(file => {
                        if (file.size > 5 * 1024 * 1024) {
                            showNotification(`Файл ${file.name} слишком большой. Максимальный размер: 5MB`, 'error');
                            this.value = '';
                            fileList.innerHTML = '';
                            return;
                        }
                    });

                    // Отображение списка файлов
                    files.forEach(file => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'file-item';
                        fileItem.innerHTML = `
                            <div>
                                <strong>${file.name}</strong>
                                <div style="font-size: 12px; color: #64748b;">
                                    ${(file.size / 1024 / 1024).toFixed(2)} MB
                                </div>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; color: #ef4444; cursor: pointer;">
                                <i class="fas fa-times"></i>
                            </button>
                        `;
                        fileList.appendChild(fileItem);
                    });
                });

                // Drag and drop
                const fileUpload = document.querySelector('.file-upload');
                if (fileUpload) {
                    fileUpload.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        this.style.borderColor = '#00bcd4';
                        this.style.background = 'rgba(0, 188, 212, 0.1)';
                    });

                    fileUpload.addEventListener('dragleave', function(e) {
                        e.preventDefault();
                        this.style.borderColor = '';
                        this.style.background = '';
                    });

                    fileUpload.addEventListener('drop', function(e) {
                        e.preventDefault();
                        this.style.borderColor = '';
                        this.style.background = '';

                        attachmentsInput.files = e.dataTransfer.files;
                        attachmentsInput.dispatchEvent(new Event('change'));
                    });
                }
            }

            // Кнопка "Наверх"
            const scrollToTopBtn = document.getElementById('scrollToTop');

            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    scrollToTopBtn.classList.add('visible');
                } else {
                    scrollToTopBtn.classList.remove('visible');
                }
            });

            scrollToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Валидация формы
            const ticketForm = document.getElementById('ticketForm');
            if (ticketForm) {
                ticketForm.addEventListener('submit', function(e) {
                    const subject = this.querySelector('[name="subject"]');
                    const message = this.querySelector('[name="message"]');

                    if (subject.value.trim().length < 5) {
                        e.preventDefault();
                        showNotification('Тема должна содержать минимум 5 символов', 'error');
                        subject.focus();
                        return;
                    }

                    if (message.value.trim().length < 20) {
                        e.preventDefault();
                        showNotification('Сообщение должно содержать минимум 20 символов', 'error');
                        message.focus();
                        return;
                    }
                });
            }

            // Удаление уведомлений через 5 секунд
            setTimeout(() => {
                document.querySelectorAll('.notification').forEach(notification => {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                });
            }, 5000);
        });

        // Функция для закрытия тикета
        function closeTicket(ticketId) {
            if (confirm('Вы уверены, что хотите закрыть этот тикет?')) {
                fetch('../api/close_ticket.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ ticket_id: ticketId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Тикет успешно закрыт', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.error || 'Ошибка при закрытии тикета', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Ошибка сети: ' + error.message, 'error');
                });
            }
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            // Удаляем старые уведомления
            document.querySelectorAll('.notification').forEach(n => {
                if (!n.classList.contains('persistent')) {
                    n.remove();
                }
            });

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               type === 'warning' ? 'fa-exclamation-triangle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            // Добавляем стили для анимации удаления
            if (!document.querySelector('#notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideOut {
                        from {
                            transform: translateX(0);
                            opacity: 1;
                        }
                        to {
                            transform: translateX(100%);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Обработка кнопки "Назад" в браузере
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const ticketId = urlParams.get('ticket_id');

            // Закрываем все тикеты
            document.querySelectorAll('.ticket-item.expanded').forEach(item => {
                item.classList.remove('expanded');
            });

            // Открываем тикет из URL
            if (ticketId) {
                const ticketElement = document.querySelector(`.ticket-item[data-ticket-id="${ticketId}"]`);
                if (ticketElement) {
                    ticketElement.classList.add('expanded');
                    // Переключаемся на вкладку "Мои тикеты"
                    document.querySelector('[data-tab="my-tickets"]').click();
                }
            }
        });
    </script>
</body>
</html>