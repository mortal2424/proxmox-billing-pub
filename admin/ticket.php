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
$is_ajax = isset($_GET['ajax']) || isset($_POST['ajax']) || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

// Получаем статистику тикетов для карточек (упрощенная версия)
function getTicketStats($pdo) {
    $stats = [
        'total' => 0,
        'open' => 0,
        'answered' => 0,
        'pending' => 0,
        'closed' => 0
    ];

    try {
        // Общее количество тикетов
        $result = $pdo->query("SELECT COUNT(*) as count FROM tickets")->fetch(PDO::FETCH_ASSOC);
        $stats['total'] = (int)$result['count'];

        // Тикеты по статусам
        $statuses = ['open', 'answered', 'pending', 'closed'];
        foreach ($statuses as $status) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE status = ?");
            $stmt->execute([$status]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats[$status] = (int)$result['count'];
        }
    } catch (Exception $e) {
        error_log("Error in getTicketStats: " . $e->getMessage());
    }

    return $stats;
}

$stats = getTicketStats($pdo);

// Получаем список всех тикетов (как в оригинале)
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

// Сортировка как в оригинале
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

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tickets = [];
    error_log("Error fetching tickets: " . $e->getMessage());
}

// Обработка POST-запросов (как в оригинале)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_id = (int)$_POST['ticket_id'];
        $response = ['success' => false, 'message' => ''];

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
            if (function_exists('sendNotificationToUser')) {
                sendNotificationToUser($ticket_id, null, $status);
            }

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
            if (function_exists('sendNotificationToUser')) {
                sendNotificationToUser($ticket_id, $message);
            }

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
        $error_message = $e->getMessage();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error_message]);
            exit;
        }

        $_SESSION['error'] = $error_message;
        header("Location: ticket.php" . (isset($ticket_id) ? "?ticket_id=" . $ticket_id : ''));
        exit;
    }
}

// Получаем данные тикета для AJAX или обычного запроса (как в оригинале)
if (isset($_GET['ticket_id'])) {
    $ticket_id = (int)$_GET['ticket_id'];

    try {
        $ticket_stmt = $pdo->prepare("SELECT t.*, u.email, u.full_name
                                    FROM tickets t
                                    JOIN users u ON t.user_id = u.id
                                    WHERE t.id = ?");
        $ticket_stmt->execute([$ticket_id]);
        $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

        if ($ticket) {
            $replies_stmt = $pdo->prepare("SELECT r.*, u.email, u.full_name, u.is_admin
                                        FROM ticket_replies r
                                        JOIN users u ON r.user_id = u.id
                                        WHERE r.ticket_id = ?
                                        ORDER BY r.created_at ASC");
            $replies_stmt->execute([$ticket_id]);
            $replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);

            $attachments_stmt = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ?");
            $attachments_stmt->execute([$ticket_id]);
            $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    } catch (Exception $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки тикета: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Если это AJAX-запрос, но ticket_id не указан
if ($is_ajax && !isset($_GET['stats'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Не указан ID тикета']);
    exit;
}

// Если AJAX запрос статистики
if ($is_ajax && isset($_GET['stats'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    exit;
}

$title = "Управление тикетами | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

/* ========== ОСНОВНЫЕ СТИЛИ ДЛЯ ТИКЕТОВ ========== */
.ticket-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .ticket-wrapper {
    margin-left: 70px;
}

.ticket-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.ticket-header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.ticket-header-left h1 i {
    color: var(--db-accent);
}

.ticket-header-left p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.ticket-quick-actions {
    display: flex;
    gap: 12px;
}

.ticket-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.ticket-action-btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.ticket-action-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.ticket-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

/* ========== СТАТИСТИКА ТИКЕТОВ ========== */
.ticket-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.ticket-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.ticket-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--stat-color);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
}

.ticket-stat-card:hover::before {
    transform: translateX(0);
}

.ticket-stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--db-accent);
    box-shadow: var(--db-shadow-hover);
}

.ticket-stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.ticket-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    background: var(--stat-color);
}

.ticket-stat-trend {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.ticket-stat-trend-positive {
    background: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.ticket-stat-trend-warning {
    background: rgba(245, 158, 11, 0.2);
    color: var(--db-warning);
}

.ticket-stat-trend-danger {
    background: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.ticket-stat-trend-info {
    background: rgba(59, 130, 246, 0.2);
    color: var(--db-info);
}

.ticket-stat-content h3 {
    color: var(--db-text-secondary);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ticket-stat-value {
    color: var(--db-text);
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 4px 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.ticket-stat-subtext {
    color: var(--db-text-muted);
    font-size: 12px;
    margin: 0;
}

/* Цвета для карточек тикетов */
.ticket-stat-card-total { --stat-color: var(--db-info); }
.ticket-stat-card-open { --stat-color: var(--db-danger); }
.ticket-stat-card-pending { --stat-color: var(--db-warning); }
.ticket-stat-card-answered { --stat-color: var(--db-success); }
.ticket-stat-card-closed { --stat-color: var(--db-success); }

/* ========== ФИЛЬТРЫ ТИКЕТОВ ========== */
.ticket-filters {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: var(--db-shadow);
}

.ticket-filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.ticket-filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ticket-filter-label {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

.ticket-filter-select {
    padding: 10px 16px;
    border: 1px solid var(--db-border);
    border-radius: 8px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.ticket-filter-select:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
}

.ticket-filter-actions {
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.ticket-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.ticket-btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.ticket-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.2);
}

.ticket-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.ticket-btn-secondary:hover {
    background: var(--db-hover);
    transform: translateY(-2px);
}

/* ========== СПИСОК ТИКЕТОВ ========== */
.ticket-list-container {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.ticket-table {
    width: 100%;
    border-collapse: collapse;
}

.ticket-table thead {
    background: var(--db-hover);
}

.ticket-table th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
}

.ticket-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.ticket-table tbody tr:hover {
    background: var(--db-hover);
}

.ticket-table td {
    color: var(--db-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

/* Бейджи для статусов и приоритетов */
.ticket-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ticket-status-open { background: rgba(239, 68, 68, 0.2); color: var(--db-danger); }
.ticket-status-answered { background: rgba(59, 130, 246, 0.2); color: var(--db-info); }
.ticket-status-pending { background: rgba(245, 158, 11, 0.2); color: var(--db-warning); }
.ticket-status-closed { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }

.ticket-priority-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.ticket-priority-low { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }
.ticket-priority-medium { background: rgba(245, 158, 11, 0.2); color: var(--db-warning); }
.ticket-priority-high { background: rgba(239, 68, 68, 0.2); color: var(--db-danger); }
.ticket-priority-critical {
    background: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
    animation: ticket-pulse 2s infinite;
}

.ticket-department-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(139, 92, 246, 0.2);
    color: var(--db-purple);
}

@keyframes ticket-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Информация о тикете в таблице */
.ticket-info-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ticket-id {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 600;
}

.ticket-subject {
    color: var(--db-text);
    font-weight: 600;
    cursor: pointer;
    transition: color 0.3s ease;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 300px;
}

.ticket-subject:hover {
    color: var(--db-accent);
}

.ticket-user-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ticket-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--db-purple), #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

.ticket-user-info {
    display: flex;
    flex-direction: column;
}

.ticket-user-name {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
}

.ticket-user-email {
    color: var(--db-text-secondary);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 150px;
}

.ticket-date-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.ticket-date-main {
    color: var(--db-text);
    font-size: 14px;
}

.ticket-date-time {
    color: var(--db-text-secondary);
    font-size: 12px;
}

.ticket-actions-cell {
    display: flex;
    gap: 8px;
}

.ticket-action-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    background: rgba(59, 130, 246, 0.1);
    color: var(--db-info);
}

.ticket-action-icon-btn:hover {
    background: rgba(59, 130, 246, 0.2);
    transform: translateY(-2px);
}

/* Пустое состояние */
.ticket-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--db-text-secondary);
}

.ticket-empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.ticket-empty-state h3 {
    font-size: 18px;
    margin-bottom: 8px;
    color: var(--db-text);
}

.ticket-empty-state p {
    font-size: 14px;
    margin: 0;
}

/* ========== МОДАЛЬНОЕ ОКНО ПРОСМОТРА ТИКЕТА ========== */
.ticket-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
    padding: 20px;
}

.ticket-modal-container {
    background: var(--db-card-bg);
    border-radius: 12px;
    margin: 0 auto;
    max-width: 900px;
    animation: ticket-slideIn 0.3s ease;
    box-shadow: var(--db-shadow-hover);
    border: 1px solid var(--db-border);
}

.ticket-modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.ticket-modal-title {
    font-size: 20px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ticket-modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.ticket-modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.ticket-modal-body {
    padding: 25px;
    max-height: 70vh;
    overflow-y: auto;
}

@keyframes ticket-slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Стили для просмотра тикета внутри модального окна */
.ticket-view-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.ticket-overview-section {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 16px;
}

.ticket-overview-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.ticket-overview-header h4 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0;
    flex: 1;
}

.ticket-tags-container {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.ticket-overview-info {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ticket-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.ticket-info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ticket-info-label {
    color: var(--db-text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ticket-info-value {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

/* Вложения */
.ticket-attachments-section {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 16px;
}

.ticket-attachments-section h4 {
    color: var(--db-text);
    font-size: 16px;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ticket-attachments-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ticket-attachment-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 6px;
    transition: all 0.3s ease;
}

.ticket-attachment-item:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
}

.ticket-attachment-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--db-text);
    text-decoration: none;
    flex: 1;
}

.ticket-attachment-link:hover {
    color: var(--db-accent);
}

.ticket-attachment-name {
    flex: 1;
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ticket-attachment-size {
    color: var(--db-text-secondary);
    font-size: 12px;
}

/* История переписки */
.ticket-conversation-section {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 16px;
}

.ticket-conversation-section h4 {
    color: var(--db-text);
    font-size: 16px;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ticket-message {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}

.ticket-message-user {
    border-left: 4px solid var(--db-info);
}

.ticket-message-admin {
    border-left: 4px solid var(--db-success);
}

.ticket-message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.ticket-message-user-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ticket-message-avatar {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    background: linear-gradient(135deg, var(--db-success), #0ca678);
    color: white;
}

.ticket-message-avatar-user {
    background: linear-gradient(135deg, var(--db-info), #2563eb);
}

.ticket-message-user-details {
    display: flex;
    flex-direction: column;
}

.ticket-message-user-name {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

.ticket-message-user-role {
    color: var(--db-text-secondary);
    font-size: 12px;
}

.ticket-message-time {
    color: var(--db-text-secondary);
    font-size: 12px;
}

.ticket-message-body {
    color: var(--db-text);
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
}

/* Формы управления в модальном окне */
.ticket-forms-section {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 16px;
}

.ticket-forms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.ticket-form-wrapper {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 12px;
}

.ticket-form-wrapper h5 {
    color: var(--db-text);
    font-size: 14px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ticket-form-group {
    margin-bottom: 12px;
}

.ticket-form-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--db-border);
    border-radius: 6px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
}

.ticket-form-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--db-border);
    border-radius: 6px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    min-height: 120px;
    resize: vertical;
    font-family: inherit;
}

.ticket-form-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.ticket-form-textarea:focus,
.ticket-form-select:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
}

.ticket-btn-block {
    width: 100%;
}

/* Загрузка */
.ticket-loading-spinner {
    text-align: center;
    padding: 40px;
    color: var(--db-text-secondary);
}

.ticket-loading-spinner i {
    font-size: 32px;
    margin-bottom: 16px;
    animation: fa-spin 2s infinite linear;
}

/* Уведомления */
.ticket-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 20px;
    border-radius: 8px;
    color: white;
    font-size: 14px;
    z-index: 1100;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: ticket-slideIn 0.3s ease;
    box-shadow: var(--db-shadow-hover);
}

.ticket-notification-success {
    background: linear-gradient(135deg, var(--db-success), #0ca678);
}

.ticket-notification-error {
    background: linear-gradient(135deg, var(--db-danger), #dc2626);
}

.ticket-notification-warning {
    background: linear-gradient(135deg, var(--db-warning), #d97706);
}

.ticket-notification-info {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
}

/* Адаптивность */
@media (max-width: 1200px) {
    .ticket-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .ticket-filter-form {
        grid-template-columns: 1fr;
    }

    .ticket-table {
        display: block;
        overflow-x: auto;
    }
}

@media (max-width: 768px) {
    .ticket-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .ticket-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .ticket-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .ticket-info-cell {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .ticket-actions-cell {
        justify-content: flex-start;
    }

    .ticket-modal-container {
        margin: 10px;
        max-width: calc(100% - 20px);
    }
}

@media (max-width: 480px) {
    .ticket-stats-grid {
        grid-template-columns: 1fr;
    }

    .ticket-quick-actions {
        flex-direction: column;
    }

    .ticket-forms-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Основной контент -->
<div class="ticket-wrapper">
    <!-- Шапка -->
    <div class="ticket-header">
        <div class="ticket-header-left">
            <h1><i class="fas fa-headset"></i> Управление тикетами</h1>
            <p>Обработка обращений пользователей и техническая поддержка</p>
        </div>
        <div class="ticket-quick-actions">
            <a href="/admin/" class="ticket-action-btn ticket-action-btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад в дашборд
            </a>
        </div>
    </div>

    <!-- Статистика -->
    <div class="ticket-stats-grid">
        <div class="ticket-stat-card ticket-stat-card-total">
            <div class="ticket-stat-header">
                <div class="ticket-stat-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
            </div>
            <div class="ticket-stat-content">
                <h3>Всего тикетов</h3>
                <div class="ticket-stat-value"><?= number_format($stats['total']) ?></div>
                <p class="ticket-stat-subtext">Всего обращений в поддержку</p>
            </div>
        </div>

        <div class="ticket-stat-card ticket-stat-card-open">
            <div class="ticket-stat-header">
                <div class="ticket-stat-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
            </div>
            <div class="ticket-stat-content">
                <h3>Открытые</h3>
                <div class="ticket-stat-value"><?= number_format($stats['open']) ?></div>
                <p class="ticket-stat-subtext">Требуют внимания</p>
            </div>
        </div>

        <div class="ticket-stat-card ticket-stat-card-pending">
            <div class="ticket-stat-header">
                <div class="ticket-stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
            <div class="ticket-stat-content">
                <h3>В ожидании</h3>
                <div class="ticket-stat-value"><?= number_format($stats['pending']) ?></div>
                <p class="ticket-stat-subtext">Ожидают ответа пользователя</p>
            </div>
        </div>

        <div class="ticket-stat-card ticket-stat-card-answered">
            <div class="ticket-stat-header">
                <div class="ticket-stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
            <div class="ticket-stat-content">
                <h3>Отвеченные</h3>
                <div class="ticket-stat-value"><?= number_format($stats['answered']) ?></div>
                <p class="ticket-stat-subtext">Обработаны поддержкой</p>
            </div>
        </div>

        <div class="ticket-stat-card ticket-stat-card-closed">
            <div class="ticket-stat-header">
                <div class="ticket-stat-icon">
                    <i class="fas fa-lock"></i>
                </div>
            </div>
            <div class="ticket-stat-content">
                <h3>Закрытые</h3>
                <div class="ticket-stat-value"><?= number_format($stats['closed']) ?></div>
                <p class="ticket-stat-subtext">Решённые проблемы</p>
            </div>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="ticket-filters">
        <form method="GET" class="ticket-filter-form">
            <div class="ticket-filter-group">
                <label class="ticket-filter-label">Статус</label>
                <select name="status" class="ticket-filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все статусы</option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Открытые</option>
                    <option value="answered" <?= $status === 'answered' ? 'selected' : '' ?>>Отвеченные</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>В ожидании</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Закрытые</option>
                </select>
            </div>

            <div class="ticket-filter-group">
                <label class="ticket-filter-label">Отдел</label>
                <select name="department" class="ticket-filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $department === 'all' ? 'selected' : '' ?>>Все отделы</option>
                    <option value="technical" <?= $department === 'technical' ? 'selected' : '' ?>>Технический</option>
                    <option value="billing" <?= $department === 'billing' ? 'selected' : '' ?>>Биллинг</option>
                    <option value="general" <?= $department === 'general' ? 'selected' : '' ?>>Общие</option>
                    <option value="sales" <?= $department === 'sales' ? 'selected' : '' ?>>Продажи</option>
                    <option value="support" <?= $department === 'support' ? 'selected' : '' ?>>Поддержка</option>
                </select>
            </div>

            <div class="ticket-filter-actions">
                <button type="submit" class="ticket-btn ticket-btn-primary">
                    <i class="fas fa-filter"></i> Применить фильтры
                </button>
                <a href="ticket.php" class="ticket-btn ticket-btn-secondary">
                    <i class="fas fa-redo"></i> Сбросить
                </a>
            </div>
        </form>
    </div>

    <!-- Список тикетов -->
    <div class="ticket-list-container">
        <?php if (empty($tickets)): ?>
            <div class="ticket-empty-state">
                <i class="fas fa-ticket-alt"></i>
                <h3>Нет тикетов по выбранным критериям</h3>
                <p>Попробуйте изменить параметры фильтрации или дождитесь новых обращений</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="ticket-table">
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
                        <tr data-ticket-id="<?= $ticket['id'] ?>">
                            <td data-label="ID">#<?= $ticket['id'] ?></td>
                            <td data-label="Тема">
                                <div class="ticket-info-cell">
                                    <span class="ticket-subject" onclick="openTicketModal(<?= $ticket['id'] ?>)">
                                        <?= htmlspecialchars($ticket['subject']) ?>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Пользователь">
                                <div class="ticket-user-cell">
                                    <div class="ticket-user-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="ticket-user-info">
                                        <div class="ticket-user-name"><?= htmlspecialchars($ticket['full_name'] ?: $ticket['email']) ?></div>
                                        <div class="ticket-user-email"><?= htmlspecialchars($ticket['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Отдел">
                                <span class="ticket-department-badge">
                                    <?= getDepartmentText($ticket['department']) ?>
                                </span>
                            </td>
                            <td data-label="Приоритет">
                                <span class="ticket-priority-badge ticket-priority-<?= $ticket['priority'] ?>">
                                    <i class="fas fa-<?= $ticket['priority'] === 'critical' ? 'skull-crossbones' :
                                                        ($ticket['priority'] === 'high' ? 'exclamation-triangle' :
                                                        ($ticket['priority'] === 'medium' ? 'exclamation-circle' : 'info-circle')) ?>"></i>
                                    <?= getPriorityText($ticket['priority']) ?>
                                </span>
                            </td>
                            <td data-label="Статус">
                                <span class="ticket-status-badge ticket-status-<?= $ticket['status'] ?>">
                                    <i class="fas fa-<?= $ticket['status'] === 'open' ? 'exclamation-circle' :
                                                        ($ticket['status'] === 'answered' ? 'check-circle' :
                                                        ($ticket['status'] === 'pending' ? 'clock' : 'lock')) ?>"></i>
                                    <?= getStatusText($ticket['status']) ?>
                                </span>
                            </td>
                            <td data-label="Дата">
                                <div class="ticket-date-cell">
                                    <span class="ticket-date-main"><?= date('d.m.Y', strtotime($ticket['created_at'])) ?></span>
                                    <span class="ticket-date-time"><?= date('H:i', strtotime($ticket['created_at'])) ?></span>
                                </div>
                            </td>
                            <td data-label="Действия">
                                <div class="ticket-actions-cell">
                                    <button type="button" class="ticket-action-icon-btn"
                                            onclick="openTicketModal(<?= $ticket['id'] ?>)"
                                            title="Просмотреть">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно просмотра тикета -->
<div id="ticketModal" class="ticket-modal-overlay">
    <div class="ticket-modal-container">
        <div class="ticket-modal-header">
            <h3 class="ticket-modal-title">
                <i class="fas fa-ticket-alt"></i> Тикет #<span id="ticketModalTicketId"></span>
            </h3>
            <button type="button" class="ticket-modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="ticket-modal-body" id="ticketModalBody">
            <div class="ticket-loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Загрузка данных тикета...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Глобальные переменные
let currentTicketId = null;

document.addEventListener('DOMContentLoaded', function() {
    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const content = document.querySelector('.ticket-wrapper');

    if (sidebar && content) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        content.style.marginLeft = '70px';
                    } else {
                        content.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }
});

// Открытие модального окна тикета
function openTicketModal(ticketId) {
    currentTicketId = ticketId;
    const modal = document.getElementById('ticketModal');
    const modalBody = document.getElementById('ticketModalBody');

    // Показываем модальное окно
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Загружаем данные тикета
    loadTicketData(ticketId);
}

// Закрытие модального окна
function closeModal() {
    const modal = document.getElementById('ticketModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    currentTicketId = null;
}

// Загрузка данных тикета
function loadTicketData(ticketId) {
    const modalBody = document.getElementById('ticketModalBody');

    modalBody.innerHTML = `
        <div class="ticket-loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Загрузка данных тикета...</p>
        </div>
    `;

    fetch(`ticket.php?ticket_id=${ticketId}&ajax=1`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Ошибка сети');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Обновляем ID в заголовке
                document.getElementById('ticketModalTicketId').textContent = ticketId;

                // Генерируем HTML тикета
                modalBody.innerHTML = generateTicketHTML(data);

                // Добавляем обработчики событий для форм
                initTicketForms();
            } else {
                showNotification('error', data.error || 'Не удалось загрузить данные тикета');
                modalBody.innerHTML = `
                    <div class="ticket-empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <h3>Ошибка загрузки тикета</h3>
                        <p>${escapeHtml(data.error || 'Неизвестная ошибка')}</p>
                        <button class="ticket-btn ticket-btn-primary" onclick="loadTicketData(${ticketId})">
                            <i class="fas fa-redo"></i> Попробовать снова
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки тикета:', error);
            modalBody.innerHTML = `
                <div class="ticket-empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <h3>Ошибка загрузки тикета</h3>
                    <p>${escapeHtml(error.message)}</p>
                    <button class="ticket-btn ticket-btn-primary" onclick="loadTicketData(${ticketId})">
                        <i class="fas fa-redo"></i> Попробовать снова
                    </button>
                </div>
            `;
        });
}

// Генерация HTML для тикета (оригинальная версия)
function generateTicketHTML(data) {
    const ticket = data.ticket;
    const replies = data.replies || [];
    const attachments = data.attachments || [];

    // Форматирование даты
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ru-RU', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Форматирование времени
    function formatTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString('ru-RU', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Экранирование HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Преобразование переносов строк в <br>
    function nl2br(text) {
        return (text || '').replace(/\n/g, '<br>');
    }

    // Генерация HTML для вложений
    function generateAttachmentsHTML(attachments) {
        if (!attachments || attachments.length === 0) return '';

        return `
            <div class="ticket-attachments-section">
                <h4><i class="fas fa-paperclip"></i> Вложения (${attachments.length})</h4>
                <div class="ticket-attachments-list">
                    ${attachments.map(file => `
                        <div class="ticket-attachment-item">
                            <a href="/admin/download.php?file=${encodeURIComponent(file.file_path)}" class="ticket-attachment-link" target="_blank">
                                <i class="fas fa-file"></i>
                                <span class="ticket-attachment-name">${escapeHtml(file.file_name)}</span>
                            </a>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    return `
        <div class="ticket-view-container">
            <!-- Информация о тикете -->
            <div class="ticket-overview-section">
                <div class="ticket-overview-header">
                    <h4>${escapeHtml(ticket.subject)}</h4>
                    <div class="ticket-tags-container">
                        <span class="ticket-status-badge ticket-status-${ticket.status}">
                            ${getStatusText(ticket.status)}
                        </span>
                        <span class="ticket-priority-badge ticket-priority-${ticket.priority}">
                            ${getPriorityText(ticket.priority)}
                        </span>
                        <span class="ticket-department-badge">
                            ${getDepartmentText(ticket.department)}
                        </span>
                    </div>
                </div>

                <div class="ticket-overview-info">
                    <div class="ticket-info-grid">
                        <div class="ticket-info-item">
                            <span class="ticket-info-label">Пользователь:</span>
                            <span class="ticket-info-value">
                                ${escapeHtml(ticket.full_name || ticket.email)}
                                <small>(${escapeHtml(ticket.email)})</small>
                            </span>
                        </div>
                        <div class="ticket-info-item">
                            <span class="ticket-info-label">Создан:</span>
                            <span class="ticket-info-value">${formatDate(ticket.created_at)}</span>
                        </div>
                        <div class="ticket-info-item">
                            <span class="ticket-info-label">Обновлен:</span>
                            <span class="ticket-info-value">${formatDate(ticket.updated_at)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Вложения -->
            ${generateAttachmentsHTML(attachments)}

            <!-- История переписки -->
            <div class="ticket-conversation-section">
                <h4><i class="fas fa-comments"></i> История переписки</h4>

                <!-- Сообщение тикета -->
                <div class="ticket-message ticket-message-user">
                    <div class="ticket-message-header">
                        <div class="ticket-message-user-info">
                            <div class="ticket-message-avatar ticket-message-avatar-user">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="ticket-message-user-details">
                                <div class="ticket-message-user-name">${escapeHtml(ticket.full_name || ticket.email)}</div>
                                <div class="ticket-message-user-role">Пользователь</div>
                            </div>
                        </div>
                        <div class="ticket-message-time">
                            ${formatTime(ticket.created_at)}
                        </div>
                    </div>
                    <div class="ticket-message-body">
                        ${nl2br(escapeHtml(ticket.message))}
                    </div>
                </div>

                <!-- Ответы -->
                ${replies.map(reply => `
                    <div class="ticket-message ${reply.is_admin ? 'ticket-message-admin' : 'ticket-message-user'}">
                        <div class="ticket-message-header">
                            <div class="ticket-message-user-info">
                                <div class="ticket-message-avatar ${reply.is_admin ? '' : 'ticket-message-avatar-user'}">
                                    <i class="fas fa-${reply.is_admin ? 'user-shield' : 'user'}"></i>
                                </div>
                                <div class="ticket-message-user-details">
                                    <div class="ticket-message-user-name">${escapeHtml(reply.full_name || reply.email)}</div>
                                    <div class="ticket-message-user-role">${reply.is_admin ? 'Администратор' : 'Пользователь'}</div>
                                </div>
                            </div>
                            <div class="ticket-message-time">
                                ${formatTime(reply.created_at)}
                            </div>
                        </div>
                        <div class="ticket-message-body">
                            ${nl2br(escapeHtml(reply.message))}
                        </div>
                    </div>
                `).join('')}
            </div>

            <!-- Формы управления -->
            <div class="ticket-forms-section">
                <div class="ticket-forms-grid">
                    <!-- Форма изменения приоритета -->
                    <form class="ticket-form-wrapper ticket-priority-form" data-action="change_priority">
                        <input type="hidden" name="ticket_id" value="${ticket.id}">
                        <input type="hidden" name="change_priority" value="1">
                        <input type="hidden" name="ajax" value="1">

                        <h5><i class="fas fa-exclamation-circle"></i> Изменить приоритет</h5>
                        <div class="ticket-form-group">
                            <select name="priority" class="ticket-form-select">
                                <option value="low" ${ticket.priority === 'low' ? 'selected' : ''}>Низкий</option>
                                <option value="medium" ${ticket.priority === 'medium' ? 'selected' : ''}>Средний</option>
                                <option value="high" ${ticket.priority === 'high' ? 'selected' : ''}>Высокий</option>
                                <option value="critical" ${ticket.priority === 'critical' ? 'selected' : ''}>Критический</option>
                            </select>
                        </div>
                        <button type="submit" class="ticket-btn ticket-btn-secondary ticket-btn-block">
                            <i class="fas fa-sync-alt"></i> Обновить
                        </button>
                    </form>

                    <!-- Форма изменения статуса -->
                    <form class="ticket-form-wrapper ticket-status-form" data-action="change_status">
                        <input type="hidden" name="ticket_id" value="${ticket.id}">
                        <input type="hidden" name="change_status" value="1">
                        <input type="hidden" name="ajax" value="1">

                        <h5><i class="fas fa-exchange-alt"></i> Изменить статус</h5>
                        <div class="ticket-form-group">
                            <select name="status" class="ticket-form-select">
                                <option value="answered" ${ticket.status === 'answered' ? 'selected' : ''}>Отвечен</option>
                                <option value="open" ${ticket.status === 'open' ? 'selected' : ''}>Открыт</option>
                                <option value="pending" ${ticket.status === 'pending' ? 'selected' : ''}>В ожидании</option>
                                <option value="closed" ${ticket.status === 'closed' ? 'selected' : ''}>Закрыт</option>
                            </select>
                        </div>
                        <button type="submit" class="ticket-btn ticket-btn-secondary ticket-btn-block">
                            <i class="fas fa-sync-alt"></i> Обновить
                        </button>
                    </form>
                </div>

                <!-- Форма ответа -->
                <form class="ticket-form-wrapper ticket-reply-form" data-action="reply_ticket">
                    <input type="hidden" name="ticket_id" value="${ticket.id}">
                    <input type="hidden" name="reply_ticket" value="1">
                    <input type="hidden" name="ajax" value="1">

                    <h5><i class="fas fa-reply"></i> Ответить на тикет</h5>

                    <div class="ticket-form-group">
                        <textarea name="message" class="ticket-form-textarea" rows="4"
                                  placeholder="Введите ваш ответ..." required></textarea>
                    </div>

                    <div class="ticket-form-group">
                        <label class="ticket-info-label">Новый статус</label>
                        <select name="status" class="ticket-form-select">
                            <option value="answered">Отвечен</option>
                            <option value="open">Открыт</option>
                            <option value="pending">В ожидании</option>
                            <option value="closed">Закрыт</option>
                        </select>
                    </div>

                    <div class="ticket-form-actions">
                        <button type="submit" class="ticket-btn ticket-btn-primary">
                            <i class="fas fa-paper-plane"></i> Отправить ответ
                        </button>
                        <button type="button" class="ticket-btn ticket-btn-secondary" onclick="this.form.reset()">
                            <i class="fas fa-times"></i> Очистить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
}

// Инициализация форм в модальном окне
function initTicketForms() {
    const forms = document.querySelectorAll('.ticket-priority-form, .ticket-status-form, .ticket-reply-form');

    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitTicketForm(this);
        });
    });
}

// Отправка формы тикета
function submitTicketForm(form) {
    const formData = new FormData(form);
    const action = form.dataset.action;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalContent = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обработка...';

    fetch('ticket.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Ошибка сети: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('success', data.message || 'Действие выполнено успешно');

            // Обновляем данные тикета
            setTimeout(() => {
                loadTicketData(currentTicketId);

                // Если это ответ, очищаем форму
                if (action === 'reply_ticket') {
                    form.reset();
                }
            }, 1000);
        } else {
            showNotification('error', data.error || 'Произошла ошибка');
        }
    })
    .catch(error => {
        console.error('Ошибка:', error);
        showNotification('error', 'Произошла ошибка при отправке формы: ' + error.message);
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalContent;
    });
}

// Функции для получения текста статуса и приоритета (из admin_functions.php)
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
        'general': 'Общие',
        'sales': 'Продажи',
        'support': 'Поддержка'
    };
    return departments[department] || department;
}

// Показать уведомление
function showNotification(type, message) {
    // Удаляем предыдущие уведомления
    const existingNotifications = document.querySelectorAll('.ticket-notification');
    existingNotifications.forEach(notification => notification.remove());

    const notification = document.createElement('div');
    notification.className = `ticket-notification ticket-notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' :
                          type === 'error' ? 'exclamation-circle' :
                          type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
        <span>${escapeHtml(message)}</span>
    `;

    document.body.appendChild(notification);

    // Автоматическое скрытие через 5 секунд
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }
    }, 5000);
}

// Вспомогательные функции
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Закрытие при клике вне модального окна
window.addEventListener('click', function(event) {
    const modal = document.getElementById('ticketModal');
    if (event.target === modal) {
        closeModal();
    }
});

// Закрытие при нажатии Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
    }
});
</script>

<?php require 'admin_footer.php'; ?>