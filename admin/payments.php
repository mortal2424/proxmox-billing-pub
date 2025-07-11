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

// Обработка подтверждения платежа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id']) && isset($_POST['action'])) {
    $payment_id = (int)$_POST['payment_id'];
    $action = $_POST['action'];
    
    try {
        // Получаем данные платежа
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            throw new Exception('Платеж не найден');
        }
        
        if ($action === 'approve') {
            // Подтверждаем платеж и пополняем баланс
            $pdo->beginTransaction();
            
            // Обновляем статус платежа
            $stmt = $pdo->prepare("UPDATE payments SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payment_id]);
            
            // Пополняем баланс пользователя
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$payment['amount'], $payment['user_id']]);
            
            $pdo->commit();
            
            $_SESSION['admin_message'] = ['type' => 'success', 'text' => 'Платеж успешно подтвержден и баланс пополнен'];
            
        } elseif ($action === 'reject') {
            // Отклоняем платеж
            $stmt = $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payment_id]);
            
            $_SESSION['admin_message'] = ['type' => 'success', 'text' => 'Платеж отклонен'];
        }
        
        header("Location: payments.php");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка: ' . $e->getMessage()];
        header("Location: payments.php");
        exit;
    }
}

// Фильтрация и поиск
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

// Получение списка платежей
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(u.email LIKE ? OR p.control_word LIKE ? OR p.id = ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if ($status !== 'all') {
    $where[] = "p.status = ?";
    $params[] = $status;
}

if (!empty($date_from)) {
    $where[] = "p.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where[] = "p.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Общее количество платежей для пагинации
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN users u ON p.user_id = u.id $where_clause");
$total_stmt->execute($params);
$total_payments = $total_stmt->fetchColumn();
$total_pages = ceil($total_payments / $per_page);

// Получение платежей для текущей страницы
$payments = [];
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
    "SELECT p.*, u.email 
     FROM payments p 
     JOIN users u ON p.user_id = u.id 
     $where_clause
     ORDER BY p.created_at DESC 
     LIMIT ? OFFSET ?"
);

$stmt->execute(array_merge($params, [$per_page, $offset]));
$payments = $stmt->fetchAll() ?: [];

$title = "Управление платежами | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>

        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-credit-card"></i> Управление платежами
            </h1>

            <?php if (isset($_SESSION['admin_message'])): ?>
                <div class="alert alert-<?= $_SESSION['admin_message']['type'] ?>">
                    <?= $_SESSION['admin_message']['text'] ?>
                </div>
                <?php unset($_SESSION['admin_message']); ?>
            <?php endif; ?>

            <section class="section">
                <div class="admin-table-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i> История платежей
                    </h2>
                    <div class="admin-filters-container">
                        <div class="admin-filters-row">
                            <a href="payment_add.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Создать платеж
                            </a>
                            <form method="GET" class="admin-filters-form">
                                <div class="filter-group">
                                    <select name="status" onchange="this.form.submit()">
                                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все статусы</option>
                                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Ожидание</option>
                                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Завершен</option>
                                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Ошибка</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <input type="text" name="search" placeholder="Поиск..." 
                                           value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit"><i class="fas fa-search"></i></button>
                                </div>
                                <div class="filter-group date-filter-group">
                                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"
                                           placeholder="От даты" onchange="this.form.submit()">
                                    <span class="date-separator">—</span>
                                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"
                                           placeholder="До даты" onchange="this.form.submit()">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if (!empty($payments)): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Пользователь</th>
                                    <th>Сумма</th>
                                    <th>Контр. слово</th>
                                    <th>Описание</th>
                                    <th>Статус</th>
                                    <th>Дата</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= $payment['id'] ?></td>
                                    <td><?= htmlspecialchars($payment['email']) ?></td>
                                    <td><?= number_format($payment['amount'], 2) ?> ₽</td>
                                    <td><?= $payment['control_word'] ?? '—' ?></td>
                                    <td><?= htmlspecialchars($payment['description']) ?></td>
                                    <td>
                                        <span class="status-badge 
                                            <?= $payment['status'] === 'completed' ? 'status-active' : 
                                               ($payment['status'] === 'pending' ? 'status-warning' : 'status-inactive') ?>">
                                            <?= $payment['status'] === 'completed' ? 'Завершен' : 
                                               ($payment['status'] === 'pending' ? 'Ожидание' : 'Ошибка') ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?></td>
                                    <td class="actions-cell">
                                        <?php if ($payment['status'] === 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="action-btn action-btn-success" 
                                                        title="Подтвердить платеж" onclick="return confirm('Подтвердить платеж? Баланс пользователя будет пополнен.')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="action-btn action-btn-danger" 
                                                        title="Отклонить платеж" onclick="return confirm('Отклонить платеж?')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="payment_details.php?id=<?= $payment['id'] ?>" class="action-btn action-btn-info" title="Подробнее">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?= $page-1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php 
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1) echo '<span>...</span>';
                            
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?page=<?= $i ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                                   class="<?= $i == $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor;
                            
                            if ($end < $total_pages) echo '<span>...</span>';
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page+1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?= $total_pages ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Платежи не найдены
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/payments_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>