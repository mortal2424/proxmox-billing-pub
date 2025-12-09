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

            // Проверяем существование пользователя
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userExists = $stmt->fetch();
            
            if (!$userExists) {
                throw new Exception("Пользователь не найден");
            }

            // Сначала удаляем все связанные данные, проверяя существование столбцов
            
            // Таблица vms (виртуальные машины)
            try {
                $pdo->exec("DELETE FROM vms WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из vms: " . $e->getMessage());
            }
            
            // Таблица user_quotas
            try {
                $pdo->exec("DELETE FROM user_quotas WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из user_quotas: " . $e->getMessage());
            }
            
            // Таблица api_tokens
            try {
                $pdo->exec("DELETE FROM api_tokens WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из api_tokens: " . $e->getMessage());
            }
            
            // Таблица user_sessions
            try {
                $pdo->exec("DELETE FROM user_sessions WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удаления из user_sessions: " . $e->getMessage());
            }
            
            // Таблица payments (критически важная - вызывает ошибку внешнего ключа)
            try {
                $pdo->exec("DELETE FROM payments WHERE user_id = $userId");
            } catch (Exception $e) {
                error_log("Ошибка при удалении из payments: " . $e->getMessage());
            }

            // Проверяем существование таблицы balance_history
            $checkBalanceHistory = $pdo->query("SHOW TABLES LIKE 'balance_history'")->fetch();
            if ($checkBalanceHistory) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM balance_history LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    $pdo->exec("DELETE FROM balance_history WHERE user_id = $userId");
                }
            }

            // Проверяем существование таблицы payment_info
            $checkPaymentInfo = $pdo->query("SHOW TABLES LIKE 'payment_info'")->fetch();
            if ($checkPaymentInfo) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM payment_info LIKE 'user_id'")->fetch();
                if (!$checkColumn) {
                    $checkColumn = $pdo->query("SHOW COLUMNS FROM payment_info LIKE 'customer_id'")->fetch();
                    if ($checkColumn) {
                        $pdo->exec("DELETE FROM payment_info WHERE customer_id = $userId");
                    }
                } else {
                    $pdo->exec("DELETE FROM payment_info WHERE user_id = $userId");
                }
            }

            // Проверяем существование таблицы tickets
            $checkTickets = $pdo->query("SHOW TABLES LIKE 'tickets'")->fetch();
            if ($checkTickets) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    // Сначала получаем ID тикетов пользователя
                    $ticketIds = $pdo->query("SELECT id FROM tickets WHERE user_id = $userId")->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($ticketIds)) {
                        $ticketIdsStr = implode(',', $ticketIds);
                        
                        // Удаляем вложения тикетов
                        $checkAttachments = $pdo->query("SHOW TABLES LIKE 'ticket_attachments'")->fetch();
                        if ($checkAttachments) {
                            $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_attachments LIKE 'ticket_id'")->fetch();
                            if ($checkColumn) {
                                $pdo->exec("DELETE FROM ticket_attachments WHERE ticket_id IN ($ticketIdsStr)");
                            }
                        }
                        
                        // Удаляем ответы тикетов
                        $checkReplies = $pdo->query("SHOW TABLES LIKE 'ticket_replies'")->fetch();
                        if ($checkReplies) {
                            $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_replies LIKE 'ticket_id'")->fetch();
                            if ($checkColumn) {
                                $pdo->exec("DELETE FROM ticket_replies WHERE ticket_id IN ($ticketIdsStr)");
                            }
                        }
                        
                        // Удаляем сами тикеты
                        $pdo->exec("DELETE FROM tickets WHERE user_id = $userId");
                    }
                }
            }

            // Также удаляем связанные записи из ticket_replies, если они ссылаются на пользователя напрямую
            $checkTicketRepliesUser = $pdo->query("SHOW TABLES LIKE 'ticket_replies'")->fetch();
            if ($checkTicketRepliesUser) {
                $checkColumn = $pdo->query("SHOW COLUMNS FROM ticket_replies LIKE 'user_id'")->fetch();
                if ($checkColumn) {
                    $pdo->exec("DELETE FROM ticket_replies WHERE user_id = $userId");
                }
            }

            // Дополнительные таблицы, которые могут быть связаны с пользователем
            $additionalTables = [
                'invoices',
                'invoices_history',
                'refunds',
                'transactions',
                'user_logs',
                'user_activity',
                'notifications',
                'user_settings'
            ];
            
            foreach ($additionalTables as $table) {
                $checkTable = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
                if ($checkTable) {
                    $checkColumn = $pdo->query("SHOW COLUMNS FROM $table LIKE 'user_id'")->fetch();
                    if (!$checkColumn) {
                        $checkColumn = $pdo->query("SHOW COLUMNS FROM $table LIKE 'customer_id'")->fetch();
                        if ($checkColumn) {
                            try {
                                $pdo->exec("DELETE FROM $table WHERE customer_id = $userId");
                            } catch (Exception $e) {
                                error_log("Ошибка при удалении из $table: " . $e->getMessage());
                            }
                        }
                    } else {
                        try {
                            $pdo->exec("DELETE FROM $table WHERE user_id = $userId");
                        } catch (Exception $e) {
                            error_log("Ошибка при удалении из $table: " . $e->getMessage());
                        }
                    }
                }
            }

            // Затем удаляем самого пользователя
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows > 0) {
                $pdo->commit();
                $_SESSION['success'] = "Пользователь успешно удален";
            } else {
                throw new Exception("Пользователь не найден или не удален");
            }
            
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
                                    <form method="POST" onsubmit="return confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['email']) ?>')">
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

<script>
function confirmDelete(userId, userEmail) {
    return confirm('Вы уверены, что хотите удалить пользователя #' + userId + ' (' + userEmail + ')? Все связанные данные (платежи, тикеты, баланс, виртуальные машины и т.д.) также будут удалены. Это действие нельзя отменить!');
}
</script>

<style>
    <?php include '../admin/css/users_styles.css'; ?>
    
    /* Дополнительные стили */
    .alert {
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background-color: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }
    
    .alert-danger {
        background-color: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }
    
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 5px;
        margin: 0 3px;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s;
    }
    
    .action-btn-edit {
        background-color: #3490dc;
        color: white;
    }
    
    .action-btn-edit:hover {
        background-color: #2779bd;
    }
    
    .action-btn-delete {
        background-color: #e3342f;
        color: white;
        border: none;
    }
    
    .action-btn-delete:hover {
        background-color: #cc1f1a;
    }
    
    .account-type-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .individual-badge {
        background-color: #dbeafe;
        color: #1e40af;
    }
    
    .entrepreneur-badge {
        background-color: #f0f9ff;
        color: #0c4a6e;
    }
    
    .legal-badge {
        background-color: #fef3c7;
        color: #92400e;
    }
</style>

<?php require 'admin_footer.php'; ?>