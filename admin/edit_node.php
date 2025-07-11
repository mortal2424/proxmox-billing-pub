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

$node = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$node) {
    $_SESSION['error'] = "Нода не найдена";
    header("Location: nodes.php");
    exit;
}

$clusters = $pdo->query("SELECT id, name FROM proxmox_clusters ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_node'])) {
    try {
        $cluster_id = intval($_POST['cluster_id']);
        $node_name = trim($_POST['node_name']);
        $hostname = trim($_POST['hostname']);
        $api_port = intval($_POST['api_port'] ?? 8006);
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $is_cluster_master = isset($_POST['is_cluster_master']) ? 1 : 0;

        // Проверяем, что для кластера нет уже главной ноды (кроме текущей)
        if ($is_cluster_master) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_nodes 
                                 WHERE cluster_id = ? AND is_cluster_master = 1 AND id != ?");
            $stmt->execute([$cluster_id, $node['id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("В этом кластере уже есть главная нода");
            }
        }

        $stmt = $pdo->prepare("UPDATE proxmox_nodes SET 
                             cluster_id = ?, node_name = ?, hostname = ?, api_port = ?,
                             username = ?, password = ?, is_active = ?, description = ?,
                             is_cluster_master = ?
                             WHERE id = ?");
        $stmt->execute([
            $cluster_id, $node_name, $hostname, $api_port,
            $username, $password, $is_active, $description,
            $is_cluster_master, $node['id']
        ]);

        $_SESSION['success'] = "Нода {$node_name} успешно обновлена";
        header("Location: nodes.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

$title = "Редактирование ноды | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="page-title">Редактирование ноды</h1>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <form method="POST" class="node-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Кластер*</label>
                        <select name="cluster_id" class="form-input" required>
                            <?php foreach ($clusters as $cluster): ?>
                                <option value="<?= $cluster['id'] ?>" <?= $cluster['id'] == $node['cluster_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cluster['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Имя ноды*</label>
                        <input type="text" name="node_name" class="form-input" value="<?= htmlspecialchars($node['node_name']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Адрес сервера*</label>
                        <input type="text" name="hostname" class="form-input" value="<?= htmlspecialchars($node['hostname']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Порт API</label>
                        <input type="number" name="api_port" class="form-input" value="<?= htmlspecialchars($node['api_port']) ?>" min="1" max="65535">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Пользователь</label>
                        <input type="text" name="username" class="form-input" value="<?= htmlspecialchars($node['username']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Пароль</label>
                        <input type="password" name="password" class="form-input" value="<?= htmlspecialchars($node['password']) ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-input"><?= htmlspecialchars($node['description']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="is_active" <?= $node['is_active'] ? 'checked' : '' ?>>
                        <span class="checkmark"></span>
                        Активная нода
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="is_cluster_master" <?= $node['is_cluster_master'] ? 'checked' : '' ?>>
                        <span class="checkmark"></span>
                        Главная нода кластера
                    </label>
                    <small class="form-hint">(Используется для VNC консоли всех нод кластера)</small>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_node" class="btn btn-primary">
                        <i class="fas fa-save"></i> Сохранить изменения
                    </button>
                    <a href="nodes.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Отмена
                    </a>
                </div>
            </form>
        </main>
    </div>
</div>

<?php require 'admin_footer.php'; ?>