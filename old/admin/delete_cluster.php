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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        // Внешний ключ с ON DELETE CASCADE автоматически удалит связанные ноды
        $stmt = $pdo->prepare("DELETE FROM proxmox_clusters WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $_SESSION['success'] = "Кластер и все связанные ноды успешно удалены";
    } catch (Exception $e) {
        $_SESSION['error'] = "Ошибка при удалении кластера: " . $e->getMessage();
    }
}

header("Location: nodes.php");
exit;
?>