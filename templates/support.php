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
$user_id = (int) $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

$tab = ($_GET['tab'] ?? '') === 'closed' ? 'closed' : 'active';
$showCreate = in_array($_GET['action'] ?? '', ['create', 'new'], true) || isset($_POST['create_ticket']);
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $close = $pdo->prepare("UPDATE tickets SET status = 'closed', updated_at = NOW()
        WHERE id = ? AND user_id = ? AND (status IS NULL OR status <> 'closed')");
    $close->execute([$ticketId, $user_id]);
    if (!$close->rowCount()) {
        $_SESSION['error_message'] = 'Активное обращение не найдено.';
        header('Location: support.php', true, 303);
    } else {
        $_SESSION['success_message'] = 'Обращение перенесено в закрытые.';
        header('Location: support.php?tab=closed&ticket_id=' . $ticketId, true, 303);
    }
    exit;
}

// Обработка создания нового тикета
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    try {
        $subject = trim(is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '');
        $message = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');
        $department = $_POST['department'] ?? '';
        $priority = $_POST['priority'] ?? 'medium';

        // Валидация
        if (empty($subject) || empty($message)) {
            throw new Exception("Все поля обязательны для заполнения");
        }

        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200) {
            throw new Exception("Тема должна содержать от 5 до 200 символов");
        }

        if (!in_array($department, ['technical', 'billing', 'general'], true) ||
            !in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            throw new RuntimeException('Выберите отдел и приоритет.');
        }

        if (mb_strlen($message) < 20 || mb_strlen($message) > 5000) {
            throw new Exception("Описание должно содержать от 20 до 5000 символов");
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
        header("Location: support.php?ticket_id=" . $ticket_id, true, 303);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "❌ Ошибка: " . $e->getMessage();
    }
}

// Обработка ответа на тикет
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket'])) {
    try {
        $ticket_id = (int)$_POST['ticket_id'];
        $message = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');

        if (empty($message)) {
            throw new Exception("Сообщение не может быть пустым");
        }

        if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
            throw new Exception("Ответ должен содержать от 10 до 2000 символов");
        }

        // Проверяем принадлежность тикета пользователю
        $pdo->beginTransaction();
        $check = $pdo->prepare("SELECT id, status FROM tickets WHERE id = ? AND user_id = ? FOR UPDATE");
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
        $replyId = (int) $pdo->lastInsertId();

        // Обновляем статус тикета
        $pdo->prepare("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?")
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
                            (ticket_id, reply_id, file_name, file_path, file_size, mime_type)
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$ticket_id, $replyId, $fileName, $filePath, $fileSize, $mimeType]);
                    }
                }
            }
        }

        $pdo->commit();

        // Отправляем уведомление администраторам
        if (function_exists('sendNotificationToSupport')) {
            sendNotificationToSupport($ticket_id);
        }

        $_SESSION['success_message'] = "✅ Ваш ответ отправлен!";
        header("Location: support.php?ticket_id=" . $ticket_id, true, 303);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "❌ Ошибка: " . $e->getMessage();
    }
}

$ticketId = filter_var($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selected = null;
$replies = $attachments = [];
if ($ticketId) {
    $find = $pdo->prepare('SELECT * FROM tickets WHERE user_id = ? AND id = ?');
    $find->execute([$user_id, $ticketId]);
    $selected = $find->fetch();
    if ($selected) {
        $tab = $selected['status'] === 'closed' ? 'closed' : 'active';
        $thread = $pdo->prepare('SELECT user_id, message, created_at FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at, id');
        $thread->execute([$ticketId]);
        $replies = $thread->fetchAll();
        $files = $pdo->prepare('SELECT id, file_name, file_size FROM ticket_attachments WHERE ticket_id = ? ORDER BY id');
        $files->execute([$ticketId]);
        $attachments = $files->fetchAll();
    } else {
        http_response_code(404);
    }
}

$counts = $pdo->prepare("SELECT COUNT(*) AS total, COUNT(CASE WHEN status = 'closed' THEN 1 END) AS closed
    FROM tickets WHERE user_id = ?");
$counts->execute([$user_id]);
$counts = $counts->fetch();
$activeCount = (int) $counts['total'] - (int) $counts['closed'];
$total = $tab === 'closed' ? (int) $counts['closed'] : $activeCount;
$pageCount = max(1, (int) ceil($total / 10));
$page = max(1, min($pageCount, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * 10;
$list = [];
if (!$selected && !$showCreate) {
    $filter = $tab === 'closed' ? "status = 'closed'" : "(status IS NULL OR status <> 'closed')";
    $query = $pdo->prepare("SELECT id, subject, status, department, updated_at FROM tickets
        WHERE user_id = ? AND {$filter} ORDER BY updated_at DESC, id DESC LIMIT 10 OFFSET {$offset}");
    $query->execute([$user_id]);
    $list = $query->fetchAll();
}
$statuses = ['open' => 'Открыто', 'pending' => 'В работе', 'answered' => 'Есть ответ', 'closed' => 'Закрыто'];
$departments = ['technical' => 'Технический вопрос', 'billing' => 'Оплата и баланс', 'general' => 'Другой вопрос'];
$priorities = ['low' => 'Низкий', 'medium' => 'Обычный', 'high' => 'Высокий', 'critical' => 'Критичный'];
$csrf = $escape($_SESSION['ticket_csrf']);
$formValue = static fn ($name, $default = '') => is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
$date = static fn ($value) => $value ? date('d.m.Y в H:i', strtotime($value)) : '';
$listUrl = '/templates/support.php?tab=' . $tab;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <title>Поддержка | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/themes.css">
    <link rel="stylesheet" href="/css/support.css">
</head>
<body class="support-page">
<?php include __DIR__ . '/headers/user_header.php'; ?>
<?php include __DIR__ . '/headers/user_sidebar.php'; ?>
<main class="main-content support-main">
    <div class="support-shell">
        <header class="support-heading">
            <div>
                <p class="support-kicker">HomeVlad Cloud</p>
                <h1>Поддержка</h1>
                <p>Создавайте обращения и следите за ответами в одном месте.</p>
            </div>
            <a class="support-button support-primary" href="?action=create">＋ Новое обращение</a>
        </header>

        <nav class="support-tabs" aria-label="Разделы поддержки">
            <a href="?tab=active" <?= !$showCreate && $tab === 'active' ? 'aria-current="page"' : '' ?>>Активные <span><?= $activeCount ?></span></a>
            <a href="?tab=closed" <?= !$showCreate && $tab === 'closed' ? 'aria-current="page"' : '' ?>>Закрытые <span><?= (int) $counts['closed'] ?></span></a>
            <a href="/docs/docs.php">База знаний ↗</a>
        </nav>

        <?php foreach (['success_message' => 'success', 'error_message' => 'error'] as $key => $kind): ?>
            <?php if (isset($_SESSION[$key])): ?>
                <p class="support-notice <?= $kind ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><?= $escape($_SESSION[$key]) ?></p>
                <?php unset($_SESSION[$key]); ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($showCreate): ?>
            <section class="support-panel">
                <a class="support-back" href="<?= $listUrl ?>&amp;page=<?= $page ?>">← К обращениям</a>
                <h2>Новое обращение</h2>
                <p class="support-muted">Укажите, что произошло и какой результат вы ожидали.</p>
                <form class="support-form" method="post" action="?action=create" enctype="multipart/form-data">
                    <input type="hidden" name="ticket_csrf" value="<?= $csrf ?>">
                    <label>Тема
                        <input name="subject" required minlength="5" maxlength="200" placeholder="Например, не открывается консоль ВМ" value="<?= $escape($formValue('subject')) ?>">
                    </label>
                    <div class="support-fields">
                        <label>Отдел
                            <select name="department" required>
                                <?php foreach ($departments as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $formValue('department', 'technical') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Приоритет
                            <select name="priority" required>
                                <?php foreach ($priorities as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $formValue('priority', 'medium') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label>Описание
                        <textarea name="message" rows="6" required minlength="20" maxlength="5000" placeholder="Номер ВМ или платежа, время ошибки и шаги, которые уже попробовали"><?= $escape($formValue('message')) ?></textarea>
                    </label>
                    <label class="support-upload">Вложения <small>До 5 МБ на файл. Проверьте, что на снимках нет паролей или токенов.</small>
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,.csv">
                    </label>
                    <div class="support-actions">
                        <button class="support-button support-primary" type="submit" name="create_ticket">Отправить обращение</button>
                        <a class="support-button" href="<?= $listUrl ?>">Отмена</a>
                    </div>
                </form>
            </section>
        <?php elseif ($selected): ?>
            <section class="support-panel">
                <a class="support-back" href="<?= $listUrl ?>&amp;page=<?= $page ?>">← К обращениям</a>
                <div class="support-thread-heading">
                    <div>
                        <p class="support-kicker">Обращение №<?= (int) $selected['id'] ?></p>
                        <h2><?= $escape($selected['subject']) ?></h2>
                        <p class="support-muted"><?= $escape($departments[$selected['department']] ?? 'Поддержка') ?> · <?= $escape($date($selected['created_at'])) ?></p>
                    </div>
                    <span class="support-status <?= $selected['status'] === 'answered' ? 'support-answer' : '' ?>"><?= $escape($statuses[$selected['status']] ?? 'Открыто') ?></span>
                </div>
                <div class="support-conversation" aria-label="Переписка">
                    <?php foreach (array_merge([$selected], $replies) as $message): ?>
                        <?php $mine = (int) $message['user_id'] === (int) $user_id; ?>
                        <article class="support-message <?= $mine ? 'support-message-own' : '' ?>">
                            <header><strong><?= $mine ? 'Вы' : 'Поддержка' ?></strong><span><?= $escape($date($message['created_at'])) ?></span></header>
                            <div><?= nl2br($escape($message['message'])) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($attachments): ?>
                    <div class="support-files" aria-label="Вложения">
                        <?php foreach ($attachments as $file): ?>
                            <a href="/download.php?id=<?= (int) $file['id'] ?>">📎 <?= $escape($file['file_name']) ?> <small><?= $escape(formatFileSize($file['file_size'])) ?></small></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($selected['status'] !== 'closed'): ?>
                    <form class="support-form support-reply" method="post" enctype="multipart/form-data" action="?ticket_id=<?= (int) $ticketId ?>">
                        <input type="hidden" name="ticket_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                        <label>Ответить
                            <textarea name="message" rows="4" required minlength="10" maxlength="2000" placeholder="Добавьте подробности или ответьте на вопрос поддержки"><?= $escape($formValue('message')) ?></textarea>
                        </label>
                        <label class="support-upload">Вложения <small>До 5 МБ на файл</small>
                            <input type="file" name="reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,.csv">
                        </label>
                        <button class="support-button support-primary" type="submit" name="reply_ticket">Отправить ответ</button>
                    </form>
                    <details class="support-close">
                        <summary>Закрыть обращение</summary>
                        <form method="post" action="?ticket_id=<?= (int) $ticketId ?>">
                            <input type="hidden" name="ticket_csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                            <p>Закрытое обращение останется в истории. Для продолжения обсуждения потребуется новое.</p>
                            <button class="support-button" type="submit" name="close_ticket">Подтвердить закрытие</button>
                        </form>
                    </details>
                <?php else: ?>
                    <p class="support-notice">Это обращение закрыто. Если вопрос остался, <a href="?action=create">создайте новое обращение</a>.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="support-panel">
                <h2><?= $ticketId ? 'Обращение недоступно' : ($tab === 'closed' ? 'Закрытые обращения' : 'Активные обращения') ?></h2>
                <?php if (!$list): ?>
                    <p class="support-muted"><?= $tab === 'closed' ? 'Здесь появятся завершённые обращения.' : 'Активных обращений пока нет.' ?></p>
                <?php else: ?>
                    <ul class="support-list">
                        <?php foreach ($list as $ticket): ?>
                            <li><a href="?tab=<?= $tab ?>&amp;page=<?= $page ?>&amp;ticket_id=<?= (int) $ticket['id'] ?>">
                                <div>
                                    <small>№<?= (int) $ticket['id'] ?> · <?= $escape($departments[$ticket['department']] ?? 'Поддержка') ?></small>
                                    <strong><?= $escape($ticket['subject']) ?></strong>
                                    <small>Обновлено <?= $escape($date($ticket['updated_at'])) ?></small>
                                </div>
                                <span class="support-status <?= $ticket['status'] === 'answered' ? 'support-answer' : '' ?>"><?= $escape($statuses[$ticket['status']] ?? 'Открыто') ?></span>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($pageCount > 1): ?>
                    <nav class="support-pages" aria-label="Страницы обращений">
                        <?php if ($page > 1): ?><a href="<?= $listUrl ?>&amp;page=<?= $page - 1 ?>">← Назад</a><?php endif; ?>
                        <span>Страница <?= $page ?> из <?= $pageCount ?></span>
                        <?php if ($page < $pageCount): ?><a href="<?= $listUrl ?>&amp;page=<?= $page + 1 ?>">Далее →</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
