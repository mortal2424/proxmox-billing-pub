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

// Получение данных платежа
$stmt = $pdo->prepare("
    SELECT p.*, u.email, u.balance 
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

$title = "Детали платежа #{$payment['id']} | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>

        <main class="admin-main">
            <div class="admin-header-container">
                <h1 class="admin-title">
                    <i class="fas fa-receipt"></i> Детали платежа #<?= $payment['id'] ?>
                </h1>
                <a href="payments.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Назад
                </a>
            </div>

            <div class="payment-details-container" style="margin-top: 10px;">
                <div class="payment-details-card">
                    <div class="detail-row">
                        <span class="detail-label">Пользователь:</span>
                        <span class="detail-value"><?= htmlspecialchars($payment['email']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Текущий баланс:</span>
                        <span class="detail-value"><?= number_format($payment['balance'], 2) ?> ₽</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Сумма платежа:</span>
                        <span class="detail-value"><?= number_format($payment['amount'], 2) ?> ₽</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Контрольное слово:</span>
                        <span class="detail-value"><?= $payment['control_word'] ?? '—' ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Описание:</span>
                        <span class="detail-value"><?= htmlspecialchars($payment['description']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Статус:</span>
                        <span class="detail-value status-badge 
                            <?= $payment['status'] === 'completed' ? 'status-active' : 
                               ($payment['status'] === 'pending' ? 'status-warning' : 'status-inactive') ?>">
                            <?= $payment['status'] === 'completed' ? 'Завершен' : 
                               ($payment['status'] === 'pending' ? 'Ожидание' : 'Ошибка') ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Дата создания:</span>
                        <span class="detail-value"><?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Последнее обновление:</span>
                        <span class="detail-value"><?= date('d.m.Y H:i', strtotime($payment['updated_at'])) ?></span>
                    </div>
                </div>

                <?php if ($payment['status'] === 'pending'): ?>
                <div class="payment-actions">
                    <form method="POST" action="payment_update.php">
                        <input type="hidden" name="id" value="<?= $payment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Подтвердить платеж и пополнить баланс пользователя?')">
                            <i class="fas fa-check"></i> Подтвердить платеж
                        </button>
                    </form>
                    <form method="POST" action="payment_update.php">
                        <input type="hidden" name="id" value="<?= $payment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Отклонить платеж?')">
                            <i class="fas fa-times"></i> Отклонить платеж
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/payment_details_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>