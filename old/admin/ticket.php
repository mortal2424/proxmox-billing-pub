<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Проверяем AJAX-запрос
$is_ajax = isset($_GET['ajax']);

// Получаем список всех тикетов
$status = $_GET['status'] ?? 'all';
$department = $_GET['department'] ?? 'all';

$query = "SELECT t.*, u.email, u.full_name 
          FROM tickets t
          JOIN users u ON t.user_id = u.id
          WHERE 1=1";

$params = [];

if ($status !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status;
}

if ($department !== 'all') {
    $query .= " AND t.department = ?";
    $params[] = $department;
}

$query .= " ORDER BY 
            CASE WHEN t.status = 'open' THEN 1
                 WHEN t.status = 'pending' THEN 2
                 WHEN t.status = 'answered' THEN 3
                 ELSE 4 END,
            CASE WHEN t.priority = 'critical' THEN 1
                 WHEN t.priority = 'high' THEN 2
                 WHEN t.priority = 'medium' THEN 3
                 ELSE 4 END,
            t.created_at DESC";

$tickets = $pdo->prepare($query);
$tickets->execute($params);
$tickets = $tickets->fetchAll(PDO::FETCH_ASSOC);

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_id = (int)$_POST['ticket_id'];
        $response = ['success' => false];
        
        // Определяем тип действия
        if (isset($_POST['change_priority'])) {
            $priority = $_POST['priority'];
            
            $stmt = $pdo->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$priority, $ticket_id]);
            
            $response = ['success' => true, 'message' => 'Приоритет обновлен'];
        }
        elseif (isset($_POST['change_status'])) {
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $ticket_id]);
            
            // Отправляем уведомление пользователю об изменении статуса
            sendNotificationToUser($ticket_id, null, $status);
            
            $response = ['success' => true, 'message' => 'Статус обновлен'];
        }
        elseif (isset($_POST['reply_ticket'])) {
            $message = trim($_POST['message']);
            $status = $_POST['status'];
            
            if (empty($message)) {
                throw new Exception("Сообщение не может быть пустым");
            }
            
            // Добавляем ответ
            $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, TRUE)");
            $stmt->execute([$ticket_id, $_SESSION['user']['id'], $message]);
            
            // Обновляем статус тикета
            $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $ticket_id]);
            
            // Отправляем уведомление пользователю
            sendNotificationToUser($ticket_id, $message);
            
            $response = ['success' => true, 'message' => 'Ответ отправлен'];
        }
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        
        $_SESSION['success'] = $response['message'];
        header("Location: ticket.php?ticket_id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        
        $_SESSION['error'] = $e->getMessage();
        header("Location: ticket.php" . (isset($ticket_id) ? "?ticket_id=" . $ticket_id : ''));
        exit;
    }
}

// Получаем данные тикета для AJAX или обычного запроса
if (isset($_GET['ticket_id'])) {
    $ticket_id = (int)$_GET['ticket_id'];
    $ticket = $pdo->prepare("SELECT t.*, u.email, u.full_name 
                            FROM tickets t
                            JOIN users u ON t.user_id = u.id
                            WHERE t.id = ?");
    $ticket->execute([$ticket_id]);
    $ticket = $ticket->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        $replies = $pdo->prepare("SELECT r.*, u.email, u.full_name, u.is_admin 
                                FROM ticket_replies r
                                JOIN users u ON r.user_id = u.id
                                WHERE r.ticket_id = ?
                                ORDER BY r.created_at ASC");
        $replies->execute([$ticket_id]);
        $replies = $replies->fetchAll(PDO::FETCH_ASSOC);

        $attachments = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ?");
        $attachments->execute([$ticket_id]);
        $attachments = $attachments->fetchAll(PDO::FETCH_ASSOC);
        
        // Если это AJAX-запрос, возвращаем JSON
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'ticket' => $ticket,
                'replies' => $replies,
                'attachments' => $attachments
            ]);
            exit;
        }
    } elseif ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Тикет не найден']);
        exit;
    }
}

// Если это AJAX-запрос, но ticket_id не указан
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Не указан ID тикета']);
    exit;
}

$title = "Управление тикетами | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-headset"></i> Управление тикетами
            </h1>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="filters">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label>Статус:</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все</option>
                            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Открытые</option>
                            <option value="answered" <?= $status === 'answered' ? 'selected' : '' ?>>Отвеченные</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>В ожидании</option>
                            <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Закрытые</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Отдел:</label>
                        <select name="department" onchange="this.form.submit()">
                            <option value="all" <?= $department === 'all' ? 'selected' : '' ?>>Все</option>
                            <option value="technical" <?= $department === 'technical' ? 'selected' : '' ?>>Технический</option>
                            <option value="billing" <?= $department === 'billing' ? 'selected' : '' ?>>Биллинг</option>
                            <option value="general" <?= $department === 'general' ? 'selected' : '' ?>>Общие</option>
                        </select>
                    </div>
                </form>
            </div>

            <!-- Список тикетов -->
            <div class="ticket-list">
                <?php if (empty($tickets)): ?>
                    <div class="no-data">
                        <i class="fas fa-ticket-alt fa-3x"></i>
                        <p>Нет тикетов по выбранным критериям</p>
                    </div>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Тема</th>
                                <th>Пользователь</th>
                                <th>Отдел</th>
                                <th>Приоритет</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>#<?= $ticket['id'] ?></td>
                                    <td>
                                        <a href="#" class="view-ticket" data-ticket-id="<?= $ticket['id'] ?>">
                                            <?= htmlspecialchars($ticket['subject']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($ticket['full_name'] ?: $ticket['email']) ?></td>
                                    <td><?= getDepartmentText($ticket['department']) ?></td>
                                    <td>
                                        <span class="priority-badge <?= $ticket['priority'] ?>">
                                            <?= getPriorityText($ticket['priority']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $ticket['status'] ?>">
                                            <?= getStatusText($ticket['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></td>
                                    <td>
                                        <a href="#" class="action-btn action-btn-edit view-ticket" data-ticket-id="<?= $ticket['id'] ?>">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Модальное окно просмотра тикета -->
<div class="modal" id="ticketModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTicketTitle">Загрузка тикета...</h2>
            <span class="close-modal">&times;</span>
        </div>
        <div class="modal-body" id="ticketModalBody">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin fa-3x"></i>
                <p>Загрузка данных тикета...</p>
            </div>
        </div>
    </div>
</div>

<?php require 'admin_footer.php'; ?>

<style>
    <?php include '../admin/css/ticket_styles.css'; ?>
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Получаем элементы модального окна
    const modal = document.getElementById('ticketModal');
    const modalTitle = document.getElementById('modalTicketTitle');
    const modalBody = document.getElementById('ticketModalBody');
    const closeBtn = document.querySelector('.close-modal');
    
    // Функция для генерации HTML тикета
    function generateTicketHTML(ticket, replies, attachments) {
        return `
            <div class="ticket-view">
                <div class="ticket-header">
                    <div class="ticket-meta">
                        <span class="user">Пользователь: ${escapeHtml(ticket.full_name || ticket.email)}</span>
                        <span class="status-badge ${ticket.status}">${getStatusText(ticket.status)}</span>
                        <span class="priority-badge ${ticket.priority}">${getPriorityText(ticket.priority)}</span>
                        <span class="department-badge">${getDepartmentText(ticket.department)}</span>
                        <span class="date">Создан: ${formatDate(ticket.created_at)}</span>
                    </div>
                </div>

                <div class="ticket-conversation">
                    <!-- Сообщение тикета -->
                    <div class="message user-message">
                        <div class="message-header">
                            <span class="user">${escapeHtml(ticket.full_name || ticket.email)}</span>
                            <span class="date">${formatDate(ticket.created_at)}</span>
                        </div>
                        <div class="message-body">
                            ${nl2br(escapeHtml(ticket.message))}
                        </div>
                        ${generateAttachmentsHTML(attachments)}
                    </div>

                    <!-- Ответы -->
                    ${replies.map(reply => `
                        <div class="message ${reply.is_admin ? 'admin-message' : 'user-message'}">
                            <div class="message-header">
                                <span class="user">${reply.is_admin ? 'Вы' : escapeHtml(reply.full_name || reply.email)}</span>
                                <span class="date">${formatDate(reply.created_at)}</span>
                            </div>
                            <div class="message-body">
                                ${nl2br(escapeHtml(reply.message))}
                            </div>
                        </div>
                    `).join('')}

                    <!-- Форма изменения приоритета -->
                    <form method="POST" class="priority-form" onsubmit="return submitForm(this)">
                        <input type="hidden" name="ticket_id" value="${ticket.id}">
                        <input type="hidden" name="change_priority" value="1">
                        <div class="form-group">
                            <label class="form-label">Изменить приоритет</label>
                            <select name="priority" class="form-input">
                                <option value="low" ${ticket.priority === 'low' ? 'selected' : ''}>Низкий</option>
                                <option value="medium" ${ticket.priority === 'medium' ? 'selected' : ''}>Средний</option>
                                <option value="high" ${ticket.priority === 'high' ? 'selected' : ''}>Высокий</option>
                                <option value="critical" ${ticket.priority === 'critical' ? 'selected' : ''}>Критический</option>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-exclamation-circle"></i> Изменить приоритет
                            </button>
                        </div>
                    </form>

                    <!-- Форма изменения статуса -->
                    <form method="POST" class="status-form" onsubmit="return submitForm(this)">
                        <input type="hidden" name="ticket_id" value="${ticket.id}">
                        <input type="hidden" name="change_status" value="1">
                        <div class="form-group">
                            <label class="form-label">Изменить статус</label>
                            <select name="status" class="form-input">
                                <option value="answered" ${ticket.status === 'answered' ? 'selected' : ''}>Отвечен</option>
                                <option value="open" ${ticket.status === 'open' ? 'selected' : ''}>Открыт</option>
                                <option value="pending" ${ticket.status === 'pending' ? 'selected' : ''}>В ожидании</option>
                                <option value="closed" ${ticket.status === 'closed' ? 'selected' : ''}>Закрыт</option>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-exchange-alt"></i> Изменить статус
                            </button>
                        </div>
                    </form>

                    <!-- Форма ответа -->
                    <form method="POST" class="reply-form" onsubmit="return submitForm(this)">
                        <input type="hidden" name="ticket_id" value="${ticket.id}">
                        <input type="hidden" name="reply_ticket" value="1">
                        <div class="form-group">
                            <label class="form-label">Ваш ответ*</label>
                            <textarea name="message" class="form-input" rows="4" required></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Новый статус</label>
                            <select name="status" class="form-input">
                                <option value="answered" ${ticket.status === 'answered' ? 'selected' : ''}>Отвечен</option>
                                <option value="open" ${ticket.status === 'open' ? 'selected' : ''}>Открыт</option>
                                <option value="pending" ${ticket.status === 'pending' ? 'selected' : ''}>В ожидании</option>
                                <option value="closed" ${ticket.status === 'closed' ? 'selected' : ''}>Закрыт</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Вложения</label>
                            <input type="file" name="attachments[]" multiple class="form-input">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-reply"></i> Ответить
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
    }

    // Вспомогательные функции
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function nl2br(text) {
        return text.replace(/\n/g, '<br>');
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU');
    }

    function getStatusText(status) {
        const statuses = {
            'open': 'Открыт',
            'answered': 'Отвечен',
            'pending': 'В ожидании',
            'closed': 'Закрыт'
        };
        return statuses[status] || status;
    }

    function getPriorityText(priority) {
        const priorities = {
            'low': 'Низкий',
            'medium': 'Средний',
            'high': 'Высокий',
            'critical': 'Критический'
        };
        return priorities[priority] || priority;
    }

    function getDepartmentText(department) {
        const departments = {
            'technical': 'Технический',
            'billing': 'Биллинг',
            'general': 'Общие'
        };
        return departments[department] || department;
    }

    function generateAttachmentsHTML(attachments) {
        if (!attachments || attachments.length === 0) return '';
        
        return `
            <div class="message-attachments">
                <h4>Вложения:</h4>
                ${attachments.map(file => `
                    <a href="/admin/download.php?file=${encodeURIComponent(file.file_path)}" class="attachment">
                        <i class="fas fa-paperclip"></i> ${escapeHtml(file.file_name)}
                        (${formatFileSize(file.file_size)})
                    </a>
                `).join('')}
            </div>
        `;
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Функция для отправки форм
    function submitForm(form) {
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalContent = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обработка...';
        
        fetch('ticket.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message || 'Действие выполнено успешно');
                // Обновляем данные тикета через 1 секунду
                setTimeout(() => {
                    const ticketId = formData.get('ticket_id');
                    loadTicketData(ticketId);
                }, 1000);
            } else {
                showAlert('danger', data.error || 'Произошла ошибка');
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            showAlert('danger', 'Произошла ошибка при отправке формы');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalContent;
        });
        
        return false;
    }

    // Функция для показа уведомлений
    function showAlert(type, message) {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.innerHTML = message;
        
        const firstForm = document.querySelector('.ticket-view form');
        if (firstForm) {
            firstForm.parentNode.insertBefore(alert, firstForm);
            
            // Удаляем уведомление через 5 секунд
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
    }

    // Функция для загрузки данных тикета
    function loadTicketData(ticketId) {
        fetch(`ticket.php?ticket_id=${ticketId}&ajax=1`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Ошибка сети');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Генерируем HTML на основе полученных данных
                    const ticketHTML = generateTicketHTML(data.ticket, data.replies, data.attachments);
                    modalTitle.textContent = `Тикет #${data.ticket.id}: ${escapeHtml(data.ticket.subject)}`;
                    modalBody.innerHTML = ticketHTML;
                } else {
                    showAlert('danger', data.error || 'Не удалось загрузить данные тикета');
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки тикета:', error);
                showAlert('danger', `Произошла ошибка при загрузке тикета: ${escapeHtml(error.message)}`);
            });
    }

    // Обработчики для кнопок просмотра тикета
    const viewTicketButtons = document.querySelectorAll('.view-ticket');
    
    viewTicketButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const ticketId = this.getAttribute('data-ticket-id');
            
            // Показываем заголовок и спиннер загрузки
            modalTitle.textContent = `Тикет #${ticketId}`;
            modalBody.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin fa-3x"></i>
                    <p>Загрузка данных тикета...</p>
                </div>
            `;
            
            // Показываем модальное окно
            modal.style.display = 'block';
            
            // Загружаем данные тикета
            loadTicketData(ticketId);
        });
    });
    
    // Закрытие модального окна
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Закрытие при клике вне модального окна
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>