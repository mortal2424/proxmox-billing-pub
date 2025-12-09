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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cluster'])) {
    try {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            throw new Exception("Имя кластера обязательно");
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_clusters WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Кластер с таким именем уже существует");
        }

        $stmt = $pdo->prepare("INSERT INTO proxmox_clusters (name, description, is_active) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description, $is_active]);

        $_SESSION['success'] = "Кластер {$name} успешно создан";
        header("Location: nodes.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

$title = "Добавление кластера | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="page-title">Добавление нового кластера</h1>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <form method="POST" class="cluster-form">
                <div class="form-group">
                    <label class="form-label">Имя кластера*</label>
                    <input type="text" name="name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-input"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="is_active" checked>
                        <span class="checkmark"></span>
                        Активный кластер
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="add_cluster" class="btn btn-primary">
                        <i class="fas fa-save"></i> Создать кластер
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