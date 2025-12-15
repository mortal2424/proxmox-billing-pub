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

$payment_id = (int)($_GET['id'] ?? 0);

// Получение подробных данных платежа
$stmt = $pdo->prepare("
    SELECT p.*, u.email, u.full_name, u.balance, u.created_at as user_created,
           (SELECT SUM(amount) FROM payments WHERE user_id = u.id AND status = 'completed') as total_deposits,
           (SELECT COUNT(*) FROM payments WHERE user_id = u.id) as payments_count
    FROM payments p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Платеж не найден'];
    header("Location: payments.php");
    exit;
}

// Получаем историю платежей пользователя
$history_stmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$history_stmt->execute([$payment['user_id']]);
$payment_history = $history_stmt->fetchAll();

// Если есть таблица balance_history, получаем историю баланса
$balance_history = [];
if (safeQuery($pdo, "SHOW TABLES LIKE 'balance_history'")->rowCount() > 0) {
    $balance_stmt = $pdo->prepare("
        SELECT * FROM balance_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $balance_stmt->execute([$payment['user_id']]);
    $balance_history = $balance_stmt->fetchAll();
}

$title = "Детали платежа #{$payment['id']} | HomeVlad Cloud";
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

/* ========== ОСНОВНЫЕ СТИЛИ ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

/* ========== ШАПКА СТРАНИЦЫ ========== */
.dashboard-header {
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

.header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--db-accent);
}

.header-left p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.dashboard-action-btn {
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

.dashboard-action-btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.dashboard-action-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

/* ========== ОСНОВНАЯ СЕТКА ========== */
.dashboard-main-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    margin-bottom: 30px;
}

@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }

    .dashboard-main-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .dashboard-main-grid {
        gap: 20px;
    }
}

/* ========== КАРТОЧКИ ========== */
.dashboard-widget {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.dashboard-widget-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-widget-header h3 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-widget-header h3 i {
    color: var(--db-accent);
}

.dashboard-widget-body {
    padding: 20px;
}

/* ========== ИНФОРМАЦИЯ О ПЛАТЕЖЕ ========== */
.payment-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.payment-info-card {
    grid-column: 1 / -1;
}

.payment-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid var(--db-border);
}

.payment-detail-row:last-child {
    border-bottom: none;
}

.payment-detail-label {
    color: var(--db-text-secondary);
    font-size: 14px;
    font-weight: 500;
    flex: 0 0 200px;
}

.payment-detail-value {
    color: var(--db-text);
    font-size: 14px;
    flex: 1;
    text-align: right;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
}

.payment-detail-value.amount {
    font-size: 20px;
    font-weight: 700;
    color: var(--db-success);
}

/* ========== СТАТУС ПЛАТЕЖА ========== */
.payment-status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.payment-status-pending {
    background: rgba(245, 158, 11, 0.15);
    color: var(--db-warning);
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.payment-status-completed {
    background: rgba(16, 185, 129, 0.15);
    color: var(--db-success);
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.payment-status-failed {
    background: rgba(239, 68, 68, 0.15);
    color: var(--db-danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* ========== ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ ========== */
.user-info-card {
    grid-column: 1 / -1;
}

.user-info-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--db-border);
}

.user-avatar {
    width: 64px;
    height: 64px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--db-purple), #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
}

.user-info-content {
    flex: 1;
}

.user-name {
    color: var(--db-text);
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 4px 0;
}

.user-email {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.user-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.user-stat-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.user-stat-label {
    color: var(--db-text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.user-stat-value {
    color: var(--db-text);
    font-size: 18px;
    font-weight: 700;
}

.user-stat-value.success {
    color: var(--db-success);
}

/* ========== ДЕЙСТВИЯ ========== */
.payment-actions-container {
    grid-column: 1 / -1;
}

.payment-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.payment-action-form {
    margin: 0;
}

.payment-action-btn {
    width: 100%;
    height: 48px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.payment-action-btn-approve {
    background: linear-gradient(135deg, var(--db-success), #059669);
    color: white;
}

.payment-action-btn-reject {
    background: linear-gradient(135deg, var(--db-danger), #dc2626);
    color: white;
}

.payment-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.payment-action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ========== ИСТОРИЯ ========== */
.history-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.history-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.history-item:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
    transform: translateX(5px);
}

.history-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.history-icon-payment { background: rgba(0, 188, 212, 0.1); color: var(--db-accent); }
.history-icon-balance { background: rgba(16, 185, 129, 0.1); color: var(--db-success); }

.history-content {
    flex: 1;
    min-width: 0;
}

.history-title {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.history-subtitle {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin: 0;
}

.history-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
}

.history-amount {
    font-size: 14px;
    font-weight: 600;
}

.history-amount.positive {
    color: var(--db-success);
}

.history-amount.negative {
    color: var(--db-danger);
}

.history-time {
    color: var(--db-text-muted);
    font-size: 11px;
    white-space: nowrap;
}

/* ========== НЕТ ДАННЫХ ========== */
.dashboard-no-data {
    text-align: center;
    padding: 40px 20px;
    color: var(--db-text-secondary);
    font-size: 14px;
}

.dashboard-no-data i {
    font-size: 32px;
    margin-bottom: 10px;
    opacity: 0.5;
}

/* ========== АНИМАЦИИ ========== */
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

.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(0, 188, 212, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(0, 188, 212, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(0, 188, 212, 0);
    }
}

/* ========== КОНТРОЛЬНОЕ СЛОВО ========== */
.control-word-badge {
    font-family: 'Monaco', 'Consolas', monospace;
    font-size: 14px;
    color: var(--db-text);
    background: var(--db-hover);
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid var(--db-border);
    display: inline-block;
    cursor: pointer;
    transition: all 0.3s ease;
}

.control-word-badge:hover {
    background: var(--db-accent-light);
    border-color: var(--db-accent);
}

/* ========== ОПИСАНИЕ ========== */
.payment-description {
    background: var(--db-hover);
    padding: 15px;
    border-radius: 8px;
    border: 1px solid var(--db-border);
    line-height: 1.5;
    max-height: 150px;
    overflow-y: auto;
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.dashboard-alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
    border: 1px solid transparent;
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
    color: #047857;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.alert i {
    font-size: 18px;
}

.alert-success i {
    color: #10b981;
}

.alert-danger i {
    color: #ef4444;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка дашборда -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-receipt"></i> Детали платежа #<?= $payment['id'] ?></h1>
            <p>
                <?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?> • 
                <span class="payment-status-badge payment-status-<?= $payment['status'] ?>">
                    <?= $payment['status'] === 'completed' ? 'Завершен' :
                       ($payment['status'] === 'pending' ? 'Ожидает' : 'Ошибка') ?>
                </span>
            </p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="payments.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад к платежам
            </a>
            <?php if ($payment['status'] === 'pending'): ?>
            <button class="dashboard-action-btn dashboard-action-btn-primary" onclick="approvePayment(<?= $payment['id'] ?>)">
                <i class="fas fa-check"></i> Подтвердить платеж
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Основная сетка -->
    <div class="dashboard-main-grid">
        <!-- Левая колонка -->
        <div class="left-column">
            <!-- Информация о платеже -->
            <div class="dashboard-widget payment-info-card">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-info-circle"></i> Информация о платеже</h3>
                    <?php if ($payment['status'] === 'pending'): ?>
                    <span class="pulse" style="color: var(--db-warning); font-size: 12px; font-weight: 600;">
                        <i class="fas fa-exclamation-circle"></i> Требует внимания
                    </span>
                    <?php endif; ?>
                </div>
                <div class="dashboard-widget-body">
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">ID платежа:</span>
                        <span class="payment-detail-value">#<?= $payment['id'] ?></span>
                    </div>
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">Сумма:</span>
                        <span class="payment-detail-value amount"><?= number_format($payment['amount'], 2) ?> ₽</span>
                    </div>
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">Контрольное слово:</span>
                        <span class="payment-detail-value">
                            <span class="control-word-badge" onclick="copyToClipboard('<?= htmlspecialchars($payment['control_word'] ?? '') ?>')">
                                <?= htmlspecialchars($payment['control_word'] ?? '—') ?>
                            </span>
                        </span>
                    </div>
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">Статус:</span>
                        <span class="payment-detail-value">
                            <span class="payment-status-badge payment-status-<?= $payment['status'] ?>">
                                <?= $payment['status'] === 'completed' ? 'Завершен' :
                                   ($payment['status'] === 'pending' ? 'Ожидает' : 'Ошибка') ?>
                            </span>
                        </span>
                    </div>
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">Дата создания:</span>
                        <span class="payment-detail-value"><?= date('d.m.Y H:i:s', strtotime($payment['created_at'])) ?></span>
                    </div>
                    <div class="payment-detail-row">
                        <span class="payment-detail-label">Последнее обновление:</span>
                        <span class="payment-detail-value"><?= date('d.m.Y H:i:s', strtotime($payment['updated_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Описание платежа -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-file-alt"></i> Описание</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="payment-description">
                        <?= nl2br(htmlspecialchars($payment['description'])) ?>
                    </div>
                </div>
            </div>

            <!-- Действия -->
            <?php if ($payment['status'] === 'pending'): ?>
            <div class="dashboard-widget payment-actions-container" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-bolt"></i> Действия</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="payment-actions-grid">
                        <form method="POST" action="payment_update.php" class="payment-action-form">
                            <input type="hidden" name="id" value="<?= $payment['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="payment-action-btn payment-action-btn-approve" 
                                    onclick="return confirmApprovePayment()">
                                <i class="fas fa-check"></i> Подтвердить
                            </button>
                        </form>
                        <form method="POST" action="payment_update.php" class="payment-action-form">
                            <input type="hidden" name="id" value="<?= $payment['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="payment-action-btn payment-action-btn-reject"
                                    onclick="return confirmRejectPayment()">
                                <i class="fas fa-times"></i> Отклонить
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Правая колонка -->
        <div class="right-column">
            <!-- Информация о пользователе -->
            <div class="dashboard-widget user-info-card">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-user"></i> Информация о пользователе</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="user-info-header">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-info-content">
                            <div class="user-name"><?= htmlspecialchars($payment['full_name'] ?? $payment['email']) ?></div>
                            <div class="user-email"><?= htmlspecialchars($payment['email']) ?></div>
                        </div>
                    </div>

                    <div class="user-stats-grid">
                        <div class="user-stat-item">
                            <span class="user-stat-label">Текущий баланс</span>
                            <span class="user-stat-value success"><?= number_format($payment['balance'], 2) ?> ₽</span>
                        </div>
                        <div class="user-stat-item">
                            <span class="user-stat-label">Всего пополнений</span>
                            <span class="user-stat-value"><?= number_format($payment['total_deposits'] ?? 0, 2) ?> ₽</span>
                        </div>
                        <div class="user-stat-item">
                            <span class="user-stat-label">Кол-во платежей</span>
                            <span class="user-stat-value"><?= $payment['payments_count'] ?? 0 ?></span>
                        </div>
                        <div class="user-stat-item">
                            <span class="user-stat-label">Дата регистрации</span>
                            <span class="user-stat-value"><?= date('d.m.Y', strtotime($payment['user_created'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- История платежей пользователя -->
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-history"></i> История платежей</h3>
                    <a href="payments.php?search=<?= urlencode($payment['email']) ?>" class="dashboard-widget-link">
                        Все платежи →
                    </a>
                </div>
                <div class="dashboard-widget-body">
                    <?php if (!empty($payment_history)): ?>
                        <div class="history-list">
                            <?php foreach ($payment_history as $history): ?>
                            <a href="payment_details.php?id=<?= $history['id'] ?>" class="history-item">
                                <div class="history-icon history-icon-payment">
                                    <i class="fas fa-<?= $history['status'] === 'completed' ? 'check' : 
                                                      ($history['status'] === 'pending' ? 'clock' : 'times') ?>"></i>
                                </div>
                                <div class="history-content">
                                    <div class="history-title">
                                        Платеж #<?= $history['id'] ?> • <?= htmlspecialchars($history['control_word'] ?? '—') ?>
                                    </div>
                                    <div class="history-subtitle">
                                        <?= date('d.m.Y H:i', strtotime($history['created_at'])) ?>
                                    </div>
                                </div>
                                <div class="history-meta">
                                    <span class="history-amount <?= $history['status'] === 'completed' ? 'positive' : '' ?>">
                                        <?= number_format($history['amount'], 2) ?> ₽
                                    </span>
                                    <span class="history-time">
                                        <?= $history['id'] == $payment['id'] ? 'текущий' : '' ?>
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-no-data">
                            <i class="fas fa-info-circle"></i>
                            <p>Нет истории платежей</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- История баланса -->
            <?php if (!empty($balance_history)): ?>
            <div class="dashboard-widget" style="margin-top: 25px;">
                <div class="dashboard-widget-header">
                    <h3><i class="fas fa-chart-line"></i> История баланса</h3>
                </div>
                <div class="dashboard-widget-body">
                    <div class="history-list">
                        <?php foreach ($balance_history as $history): ?>
                        <div class="history-item">
                            <div class="history-icon history-icon-balance">
                                <i class="fas fa-<?= $history['type'] === 'deposit' ? 'plus' : 'minus' ?>"></i>
                            </div>
                            <div class="history-content">
                                <div class="history-title"><?= htmlspecialchars($history['description']) ?></div>
                                <div class="history-subtitle">
                                    <?= date('d.m.Y H:i', strtotime($history['created_at'])) ?>
                                </div>
                            </div>
                            <div class="history-meta">
                                <span class="history-amount <?= $history['type'] === 'deposit' ? 'positive' : 'negative' ?>">
                                    <?= $history['type'] === 'deposit' ? '+' : '-' ?><?= number_format($history['amount'], 2) ?> ₽
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация при загрузке
    const widgets = document.querySelectorAll('.dashboard-widget');
    widgets.forEach((widget, index) => {
        widget.style.opacity = '0';
        widget.style.transform = 'translateY(20px)';

        setTimeout(() => {
            widget.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            widget.style.opacity = '1';
            widget.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const dashboard = document.querySelector('.dashboard-wrapper');

    if (sidebar && dashboard) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        dashboard.style.marginLeft = '70px';
                    } else {
                        dashboard.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }
});

// Копирование контрольного слова в буфер обмена
function copyToClipboard(text) {
    if (!text) return;
    
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({
            title: 'Скопировано!',
            text: 'Контрольное слово скопировано в буфер обмена',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    }).catch(() => {
        // Fallback для старых браузеров
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        
        Swal.fire({
            title: 'Скопировано!',
            text: 'Контрольное слово скопировано в буфер обмена',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    });
}

// Подтверждение подтверждения платежа
function confirmApprovePayment() {
    return Swal.fire({
        title: 'Подтвердить платеж?',
        html: `Вы уверены, что хотите подтвердить платеж #<?= $payment['id'] ?>?<br><br>
               <strong>Сумма:</strong> <?= number_format($payment['amount'], 2) ?> ₽<br>
               <strong>Пользователь:</strong> <?= htmlspecialchars($payment['email']) ?><br><br>
               Баланс пользователя будет пополнен на указанную сумму.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Да, подтвердить',
        cancelButtonText: 'Отмена',
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#ef4444',
        reverseButtons: true
    }).then((result) => {
        return result.isConfirmed;
    });
}

// Подтверждение отклонения платежа
function confirmRejectPayment() {
    return Swal.fire({
        title: 'Отклонить платеж?',
        html: `Вы уверены, что хотите отклонить платеж #<?= $payment['id'] ?>?<br><br>
               <strong>Пользователь:</strong> <?= htmlspecialchars($payment['email']) ?><br><br>
               Платеж будет помечен как ошибочный.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Да, отклонить',
        cancelButtonText: 'Отмена',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        reverseButtons: true
    }).then((result) => {
        return result.isConfirmed;
    });
}

// Упрощенная функция для кнопки в шапке
function approvePayment(paymentId) {
    Swal.fire({
        title: 'Подтвердить платеж?',
        html: `Вы уверены, что хотите подтвердить платеж #${paymentId}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Да, подтвердить',
        cancelButtonText: 'Отмена'
    }).then((result) => {
        if (result.isConfirmed) {
            document.querySelector('form input[name="action"][value="approve"]').closest('form').submit();
        }
    });
}

// Обновление страницы при изменении статуса
function refreshIfStatusChanged() {
    const currentStatus = '<?= $payment['status'] ?>';
    fetch(`/admin/ajax/payment_status.php?id=<?= $payment['id'] ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.status !== currentStatus) {
                Swal.fire({
                    title: 'Статус обновлен',
                    text: 'Статус платежа был изменен. Обновляю страницу...',
                    icon: 'info',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            }
        });
}

// Проверяем статус каждые 10 секунд, если платеж ожидает
<?php if ($payment['status'] === 'pending'): ?>
setInterval(refreshIfStatusChanged, 10000);
<?php endif; ?>
</script>

<?php require 'admin_footer.php'; ?>