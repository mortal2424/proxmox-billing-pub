<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';
require_once '../includes/payment_actions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

// Проверка CSRF-токена
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf_token'] ?? null) ||
    !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка безопасности: неверный CSRF-токен'];
    header("Location: payments.php");
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$payment_id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

try {
    processPendingPayment($pdo, $payment_id, $action);
    $_SESSION['admin_message'] = [
        'type' => 'success',
        'text' => $action === 'approve' ? 'Платёж подтверждён и баланс пополнен' : 'Платёж отклонён',
    ];
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['admin_message'] = ['type' => 'danger', 'text' => 'Ошибка: ' . $e->getMessage()];
}

header("Location: payment_details.php?id=$payment_id");
exit;