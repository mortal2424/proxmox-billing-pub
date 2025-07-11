<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/admin_functions.php';

$db = new Database();
$pdo = $db->getConnection();

// Функция для получения реального использования ресурсов из таблицы vms
function getCurrentUsage($pdo, $userId) {
    // Получаем сумму ресурсов всех ВМ пользователя
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as vms,
            SUM(cpu) as cpu,
            SUM(ram) as ram,
            SUM(disk) as disk
        FROM vms 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $usage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Если у пользователя нет ВМ, возвращаем нули
    return $usage ?: ['vms' => 0, 'cpu' => 0, 'ram' => 0, 'disk' => 0];
}

// Создаем квоты для пользователей, у которых их нет
$pdo->beginTransaction();
$allUsers = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allUsers as $user) {
    $stmt = $pdo->prepare("SELECT id FROM user_quotas WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    
    if (!$stmt->fetch()) {
        $insert = $pdo->prepare("
            INSERT INTO user_quotas (user_id, max_vms, max_cpu, max_ram, max_disk)
            VALUES (?, 3, 10, 10240, 200)
        ");
        $insert->execute([$user['id']]);
    }
}
$pdo->commit();

// Получаем список пользователей с их квотами
$users = $pdo->query("
    SELECT u.id, u.full_name, u.email, 
           q.max_vms, q.max_cpu, q.max_ram, q.max_disk
    FROM users u
    JOIN user_quotas q ON u.id = q.user_id
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// Обработка формы обновления квот
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quotas'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['quotas'] as $userId => $quota) {
            $stmt = $pdo->prepare("
                UPDATE user_quotas 
                SET max_vms = :max_vms, 
                    max_cpu = :max_cpu, 
                    max_ram = :max_ram, 
                    max_disk = :max_disk
                WHERE user_id = :user_id
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':max_vms' => (int)$quota['max_vms'],
                ':max_cpu' => (int)$quota['max_cpu'],
                ':max_ram' => (int)$quota['max_ram'],
                ':max_disk' => (int)$quota['max_disk']
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success_message'] = 'Квоты успешно обновлены!';
        header('Location: quotas.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = 'Ошибка при обновлении квот: ' . $e->getMessage();
    }
}

$title = "Управление квотами | HomeVlad Cloud";
require 'admin_header.php';
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
    <style>
        <?php include __DIR__ . '/../admin/css/admin_style.css'; ?>
        <?php include __DIR__ . '/../admin/css/quotas_styles.css'; ?>
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-content">
            <?php require 'admin_sidebar.php'; ?>
            <main class="admin-main">
                <div class="admin-header-container">
                    <h1 class="admin-title">
                        <i class="fas fa-tachometer-alt"></i> Управление квотами пользователей
                    </h1>
                </div>
                
                <?php if (!empty($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($_SESSION['error_message']) ?>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($_SESSION['success_message']) ?>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <form method="POST" action="quotas.php">
                    <table class="quotas-table">
                        <thead>
                            <tr>
                                <th>Пользователь</th>
                                <th>Макс. ВМ</th>
                                <th>Макс. CPU</th>
                                <th>Макс. RAM (MB)</th>
                                <th>Макс. Диск (GB)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                $usage = getCurrentUsage($pdo, $user['id']);
                            ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($user['full_name']) ?>
                                        <div class="text-muted"><?= htmlspecialchars($user['email']) ?></div>
                                    </td>
                                    <td>
                                        <input type="number" name="quotas[<?= $user['id'] ?>][max_vms]" 
                                               value="<?= $user['max_vms'] ?>" min="1" class="quota-input">
                                        <div class="usage-bar">
                                            <div class="usage-bar-fill" style="width: <?= $user['max_vms'] > 0 ? min(100, ($usage['vms'] / $user['max_vms'] * 100)) : 0 ?>%"></div>
                                        </div>
                                        <small><?= $usage['vms'] ?> из <?= $user['max_vms'] ?></small>
                                    </td>
                                    <td>
                                        <input type="number" name="quotas[<?= $user['id'] ?>][max_cpu]" 
                                               value="<?= $user['max_cpu'] ?>" min="1" class="quota-input">
                                        <div class="usage-bar">
                                            <div class="usage-bar-fill" style="width: <?= $user['max_cpu'] > 0 ? min(100, ($usage['cpu'] / $user['max_cpu'] * 100)) : 0 ?>%"></div>
                                        </div>
                                        <small><?= $usage['cpu'] ?> из <?= $user['max_cpu'] ?></small>
                                    </td>
                                    <td>
                                        <input type="number" name="quotas[<?= $user['id'] ?>][max_ram]" 
                                               value="<?= $user['max_ram'] ?>" min="256" class="quota-input">
                                        <div class="usage-bar">
                                            <div class="usage-bar-fill" style="width: <?= $user['max_ram'] > 0 ? min(100, ($usage['ram'] / $user['max_ram'] * 100)) : 0 ?>%"></div>
                                        </div>
                                        <small><?= round($usage['ram'] / 1024, 1) ?>GB из <?= round($user['max_ram'] / 1024, 1) ?>GB</small>
                                    </td>
                                    <td>
                                        <input type="number" name="quotas[<?= $user['id'] ?>][max_disk]" 
                                               value="<?= $user['max_disk'] ?>" min="10" class="quota-input">
                                        <div class="usage-bar">
                                            <div class="usage-bar-fill" style="width: <?= $user['max_disk'] > 0 ? min(100, ($usage['disk'] / $user['max_disk'] * 100)) : 0 ?>%"></div>
                                        </div>
                                        <small><?= $usage['disk'] ?>GB из <?= $user['max_disk'] ?>GB</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <button type="submit" name="update_quotas" class="btn btn-primary save-btn">
                        <i class="fas fa-save"></i> Сохранить изменения
                    </button>
                </form>
            </main>
        </div>
    </div>

    <script>
    // Адаптивное меню для мобильных устройств
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.createElement('div');
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        menuToggle.style.position = 'fixed';
        menuToggle.style.top = '15px';
        menuToggle.style.left = '15px';
        menuToggle.style.zIndex = '1000';
        menuToggle.style.fontSize = '1.5rem';
        menuToggle.style.color = 'var(--primary)';
        menuToggle.style.cursor = 'pointer';
        menuToggle.style.display = 'none';
        document.body.appendChild(menuToggle);

        function checkScreenSize() {
            if (window.innerWidth <= 992) {
                menuToggle.style.display = 'block';
                document.body.classList.add('sidebar-closed');
            } else {
                menuToggle.style.display = 'none';
                document.body.classList.remove('sidebar-closed');
            }
        }

        menuToggle.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-open');
        });

        window.addEventListener('resize', checkScreenSize);
        checkScreenSize();
    });
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>