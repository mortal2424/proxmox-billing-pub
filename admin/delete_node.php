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
    $nodeId = (int)$_POST['id'];
    
    try {
        // Начинаем транзакцию
        $pdo->beginTransaction();
        
        // 1. Удаляем статистику ноды (node_stats)
        $stmt = $pdo->prepare("DELETE FROM node_stats WHERE node_id = ?");
        $stmt->execute([$nodeId]);
        
        // 2. Удаляем саму ноду
        $stmt = $pdo->prepare("DELETE FROM proxmox_nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        
        // Фиксируем изменения
        $pdo->commit();
        
        $_SESSION['success'] = "Нода успешно удалена";
    } catch (Exception $e) {
        // Откат при ошибке
        $pdo->rollBack();
        $_SESSION['error'] = "Ошибка при удалении ноды: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "Неверный запрос";
}

header("Location: nodes.php");
exit;
?>