<?php
session_start();
require_once '../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    // Статистика пользователей
    $users_stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $total_users = $users_stmt->fetch(PDO::FETCH_ASSOC)['total_users'] ?? 0;
    
    // Новые пользователи сегодня
    $new_users_stmt = $pdo->query("
        SELECT COUNT(*) as new_users_today 
        FROM users 
        WHERE DATE(created_at) = CURDATE()
    ");
    $new_users_today = $new_users_stmt->fetch(PDO::FETCH_ASSOC)['new_users_today'] ?? 0;
    
    // Активные ВМ
    $active_vms_stmt = $pdo->query("
        SELECT COUNT(*) as active_vms 
        FROM vms 
        WHERE status = 'running'
    ");
    $active_vms = $active_vms_stmt->fetch(PDO::FETCH_ASSOC)['active_vms'] ?? 0;
    
    // Открытые тикеты
    $open_tickets = 0;
    $tickets_exists = $pdo->query("SHOW TABLES LIKE 'tickets'")->rowCount() > 0;
    if ($tickets_exists) {
        $tickets_stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
        $open_tickets = $tickets_stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    }
    
    // Доход за текущий месяц
    $monthly_income = 0;
    $payments_exists = $pdo->query("SHOW TABLES LIKE 'payments'")->rowCount() > 0;
    if ($payments_exists) {
        $current_month = date('Y-m');
        $income_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as monthly_income 
            FROM payments 
            WHERE status = 'completed' 
            AND DATE_FORMAT(created_at, '%Y-%m') = ?
        ");
        $income_stmt->execute([$current_month]);
        $monthly_income = $income_stmt->fetch(PDO::FETCH_ASSOC)['monthly_income'] ?? 0;
    }
    
    echo json_encode([
        'success' => true,
        'total_users' => (int)$total_users,
        'new_users_today' => (int)$new_users_today,
        'active_vms' => (int)$active_vms,
        'open_tickets' => (int)$open_tickets,
        'monthly_income' => (float)$monthly_income
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}