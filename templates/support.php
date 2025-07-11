<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../admin/admin_functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Получаем список тикетов пользователя
$tickets = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC");
$tickets->execute([$user_id]);
$tickets = $tickets->fetchAll(PDO::FETCH_ASSOC);

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

        // Создаем тикет
        $stmt = $pdo->prepare("INSERT INTO tickets 
            (user_id, subject, message, department, priority) 
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $subject, $message, $department, $priority]);
        $ticket_id = $pdo->lastInsertId();

        // Обработка вложений
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/uploads/tickets/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                $fileName = basename($_FILES['attachments']['name'][$key]);
                $filePath = $uploadDir . uniqid() . '_' . $fileName;
                $fileSize = $_FILES['attachments']['size'][$key];
                $mimeType = $_FILES['attachments']['type'][$key];

                if (move_uploaded_file($tmpName, $filePath)) {
                    $stmt = $pdo->prepare("INSERT INTO ticket_attachments 
                        (ticket_id, file_name, file_path, file_size, mime_type) 
                        VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$ticket_id, $fileName, $filePath, $fileSize, $mimeType]);
                }
            }
        }

        // Отправляем уведомление поддержке
        sendNotificationToSupport($ticket_id);

        $_SESSION['success'] = "Тикет успешно создан! Номер вашего тикета: #" . $ticket_id;
        header("Location: support.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
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

        // Проверяем принадлежность тикета пользователю
        $check = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
        $check->execute([$ticket_id, $user_id]);
        if (!$check->fetch()) {
            throw new Exception("Тикет не найден");
        }

        // Добавляем ответ
        $stmt = $pdo->prepare("INSERT INTO ticket_replies 
            (ticket_id, user_id, message) 
            VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $user_id, $message]);

        // Обновляем статус тикета
        $pdo->prepare("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?")
            ->execute([$ticket_id]);

        // Отправляем уведомление администраторам
        sendNotificationToSupport($ticket_id);

        $_SESSION['success'] = "Ваш ответ отправлен";
        header("Location: support.php?ticket_id=" . $ticket_id);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

$title = "Техническая поддержка | HomeVlad Cloud";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/support_styles.css'; ?>
        <?php include '../css/header_styles.css'; ?>  
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <main class="admin-main">
                <!-- Заголовок страницы -->
                <div class="admin-header-container">
                    <h1 class="admin-title">
                        <i class="fas fa-headset"></i> Техническая поддержка
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($_SESSION['success']) ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($_SESSION['error']) ?>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="new-ticket">Создать тикет</button>
                    <button class="tab-btn" data-tab="my-tickets">Мои тикеты</button>
                </div>

                <!-- Форма создания тикета -->
                <div class="tab-content active" id="new-ticket">
                    <form method="POST" enctype="multipart/form-data" class="ticket-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Тема*</label>
                                <input type="text" name="subject" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Отдел*</label>
                                <select name="department" class="form-input" required>
                                    <option value="technical">Технические вопросы</option>
                                    <option value="billing">Биллинг и оплата</option>
                                    <option value="general">Общие вопросы</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Приоритет*</label>
                                <select name="priority" class="form-input" required>
                                    <option value="medium">Средний</option>
                                    <option value="low">Низкий</option>
                                    <option value="high">Высокий</option>
                                    <option value="critical">Критичный</option>
                                </select>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Сообщение*</label>
                                <textarea name="message" class="form-input" rows="6" required></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Вложения (макс. 3 файла)</label>
                                <input type="file" name="attachments[]" multiple class="form-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                                <small class="form-hint">Максимальный размер файла: 5MB</small>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="create_ticket" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Отправить запрос
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Список тикетов -->
                <div class="tab-content" id="my-tickets">
                    <?php if (empty($tickets)): ?>
                        <div class="no-data">
                            <i class="fas fa-ticket-alt"></i>
                            <p>У вас нет созданных тикетов</p>
                        </div>
                    <?php else: ?>
                        <div class="ticket-list">
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="ticket-card <?= $ticket['status'] ?> <?= isset($_GET['ticket_id']) && $_GET['ticket_id'] == $ticket['id'] ? 'expanded' : '' ?>"
                                     data-ticket-id="<?= $ticket['id'] ?>">
                                    <div class="ticket-header" onclick="toggleTicket(this.parentElement, event)">
                                        <h3 class="ticket-title">
                                            <span>#<?= $ticket['id'] ?>: <?= htmlspecialchars($ticket['subject']) ?></span>
                                            <i class="fas fa-chevron-down toggle-icon"></i>
                                        </h3>
                                        <span class="ticket-status <?= $ticket['status'] ?>">
                                            <?= getStatusText($ticket['status']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="ticket-content">
                                        <div class="ticket-preview">
                                            <div class="ticket-meta">
                                                <span><i class="fas fa-layer-group"></i> <?= getDepartmentText($ticket['department']) ?></span>
                                                <span><i class="fas fa-exclamation-circle"></i> <?= getPriorityText($ticket['priority']) ?></span>
                                                <span><i class="fas fa-calendar-alt"></i> <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></span>
                                            </div>
                                            <div class="ticket-message-preview">
                                                <?= mb_substr(htmlspecialchars($ticket['message']), 0, 100) ?>...
                                            </div>
                                            <div class="ticket-actions">
                                                <a href="support.php?ticket_id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Подробнее
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="ticket-full-view">
                                            <?php
                                            $replies = $pdo->prepare("SELECT r.*, u.email, u.is_admin 
                                                                    FROM ticket_replies r
                                                                    JOIN users u ON r.user_id = u.id
                                                                    WHERE r.ticket_id = ?
                                                                    ORDER BY r.created_at ASC");
                                            $replies->execute([$ticket['id']]);
                                            $replies = $replies->fetchAll(PDO::FETCH_ASSOC);

                                            $attachments = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ?");
                                            $attachments->execute([$ticket['id']]);
                                            $attachments = $replies ? $attachments->fetchAll(PDO::FETCH_ASSOC) : [];
                                            ?>
                                            
                                            <div class="ticket-conversation">
                                                <!-- Сообщение тикета -->
                                                <div class="message user-message">
                                                    <div class="message-header">
                                                        <span class="user">Вы</span>
                                                        <span class="date"><?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></span>
                                                    </div>
                                                    <div class="message-body">
                                                        <?= nl2br(htmlspecialchars($ticket['message'])) ?>
                                                    </div>
                                                    <?php if ($attachments): ?>
                                                        <div class="message-attachments">
                                                            <h4>Вложения:</h4>
                                                            <?php foreach ($attachments as $file): ?>
                                                                <a href="/download.php?file=<?= urlencode($file['file_path']) ?>" class="attachment">
                                                                    <i class="fas fa-paperclip"></i> <?= htmlspecialchars($file['file_name']) ?>
                                                                    (<?= formatFileSize($file['file_size']) ?>)
                                                                </a>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Ответы -->
                                                <?php foreach ($replies as $reply): ?>
                                                    <div class="message <?= $reply['is_admin'] ? 'admin-message' : 'user-message' ?>">
                                                        <div class="message-header">
                                                            <span class="user"><?= $reply['is_admin'] ? 'Поддержка' : 'Вы' ?></span>
                                                            <span class="date"><?= date('d.m.Y H:i', strtotime($reply['created_at'])) ?></span>
                                                        </div>
                                                        <div class="message-body">
                                                            <?= nl2br(htmlspecialchars($reply['message'])) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <!-- Форма ответа - показываем только если тикет не закрыт -->
                                                <?php if ($ticket['status'] !== 'closed'): ?>
                                                    <form method="POST" class="reply-form" enctype="multipart/form-data">
                                                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                        <div class="form-group">
                                                            <label class="form-label">Ваш ответ*</label>
                                                            <textarea name="message" class="form-input" rows="4" required></textarea>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label">Вложения</label>
                                                            <input type="file" name="attachments[]" multiple class="form-input">
                                                        </div>
                                                        <div class="form-actions">
                                                            <button type="submit" name="reply_ticket" class="btn btn-primary">
                                                                <i class="fas fa-reply"></i> Ответить
                                                            </button>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="alert alert-info">
                                                        <i class="fas fa-info-circle"></i> Работы по тикету завершены. Если у вас есть вопросы и проблема, пожалуйста создайте новый тикет.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
    // Переключение между вкладками
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Удаляем активный класс у всех кнопок и контента
            document.querySelectorAll('.tab-btn, .tab-content').forEach(el => {
                el.classList.remove('active');
            });
            
            // Добавляем активный класс текущей кнопке
            this.classList.add('active');
            
            // Показываем соответствующий контент
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });

    // Переключение состояния тикета
    function toggleTicket(element, event) {
        // Игнорируем клики по ссылкам внутри тикета
        if (event.target.tagName === 'A' || event.target.closest('a')) {
            return;
        }
        
        // Закрываем все другие открытые тикеты
        document.querySelectorAll('.ticket-card.expanded').forEach(card => {
            if (card !== element) {
                card.classList.remove('expanded');
            }
        });
        
        // Переключаем текущий тикет
        element.classList.toggle('expanded');
        
        // Обновляем URL
        if (element.classList.contains('expanded')) {
            const ticketId = element.dataset.ticketId;
            window.history.pushState(null, null, `?ticket_id=${ticketId}`);
        } else {
            window.history.pushState(null, null, 'support.php');
        }
    }

    // При загрузке страницы разворачиваем нужный тикет
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const ticketId = urlParams.get('ticket_id');
        
        if (ticketId) {
            const ticketElement = document.querySelector(`.ticket-card[data-ticket-id="${ticketId}"]`);
            if (ticketElement) {
                ticketElement.classList.add('expanded');
            }
        }
    });

    // Обработка кнопки "Назад" в браузере
    window.addEventListener('popstate', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const ticketId = urlParams.get('ticket_id');
        
        document.querySelectorAll('.ticket-card').forEach(card => {
            card.classList.remove('expanded');
        });
        
        if (ticketId) {
            const ticketElement = document.querySelector(`.ticket-card[data-ticket-id="${ticketId}"]`);
            if (ticketElement) {
                ticketElement.classList.add('expanded');
            }
        }
    });

    // Если в URL есть ticket_id, переключаемся на вкладку "Мои тикеты"
    if (window.location.search.includes('ticket_id=')) {
        document.querySelector('[data-tab="my-tickets"]').click();
    }
    </script>
</body>
</html>