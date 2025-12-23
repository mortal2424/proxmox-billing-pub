<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

// Проверка CSRF-токена
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка безопасности: неверный CSRF-токен'];
    header("Location: payments.php");
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$payment_id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

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
    } else {
        throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка: ' . $e->getMessage()];
}

header("Location: payment_details.php?id=$payment_id");
exit;