<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

checkAuth();

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

$user = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
$user->execute([$user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

if (empty($user['telegram_id'])) {
    echo json_encode(['success' => false, 'error' => 'Telegram ID не привязан']);
    exit;
}

try {
    $message = "🔔 <b>Информационное уведомление</b>\n";
    $message .= "┌─────────────────\n";
    $message .= "│ ✅ Ваш аккаунт успешно подключен\n";
    $message .= "│ 📅 Дата: " . date('d.m.Y H:i') . "\n";
    $message .= "└─────────────────\n";
    $message .= "Вы будете получать уведомления о статусе ваших тикетов и важных событиях.";

    require_once __DIR__ . '/../admin/admin_functions.php';
    $result = sendTelegramNotification($user['telegram_id'], $message);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}