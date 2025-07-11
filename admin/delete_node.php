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
        $stmt = $pdo->prepare("DELETE FROM proxmox_nodes WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $_SESSION['success'] = "Нода успешно удалена";
    } catch (Exception $e) {
        $_SESSION['error'] = "Ошибка при удалении ноды: " . $e->getMessage();
    }
}

header("Location: nodes.php");
exit;
?>