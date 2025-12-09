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

// Получаем список всех пользователей
$users = $pdo->query("SELECT id, email, full_name, balance, is_admin, user_type, created_at FROM users ORDER BY id DESC")->fetchAll();

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $userId = (int)$_POST['user_id'];
        try {
            $pdo->beginTransaction();
            
            // Сначала удаляем все связанные данные
            $tables = [
                'balance_history',
                'payment_info',
                'ticket_replies',
                'ticket_attachments',
                'tickets'
            ];
            
            foreach ($tables as $table) {
                $pdo->exec("DELETE FROM $table WHERE user_id = $userId");
            }
            
            // Затем удаляем самого пользователя
            $pdo->exec("DELETE FROM users WHERE id = $userId");
            
            $pdo->commit();
            $_SESSION['success'] = "Пользователь успешно удален";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Ошибка при удалении пользователя: " . $e->getMessage();
        }
        header("Location: users.php");
        exit;
    }
}

$title = "Управление пользователями | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="admin-header-container">
                <div class="admin-header-content">
                    <h1 class="admin-title">
                        <i class="fas fa-users"></i> Управление пользователями
                    </h1>
                    <a href="user_add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Добавить пользователя
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <section class="section">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>ФИО</th>
                                <th>Тип</th>
                                <th>Баланс</th>
                                <th>Роль</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td>
                                <td>
                                    <span class="account-type-badge <?= $user['user_type'] ?>-badge">
                                        <?= $user['user_type'] === 'individual' ? 'Физ. лицо' : 
                                           ($user['user_type'] === 'entrepreneur' ? 'ИП' : 'Юр. лицо') ?>
                                    </span>
                                </td>
                                <td><?= number_format($user['balance'], 2) ?> руб.</td>
                                <td><?= $user['is_admin'] ? '<span class="badge badge-danger">Администратор</span>' : '<span class="badge badge-success">Пользователь</span>' ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                                <td class="actions">
                                    <a href="user_edit.php?id=<?= $user['id'] ?>" class="action-btn action-btn-edit" title="Редактировать">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Вы уверены, что хотите удалить этого пользователя? Все связанные данные (тикеты, баланс и т.д.) также будут удалены.')">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <button type="submit" name="delete_user" class="action-btn action-btn-delete" title="Удалить">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/users_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>