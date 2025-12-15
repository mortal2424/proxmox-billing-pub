<?php
/**
 * Получение информации о расписании бэкапов
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../admin_functions.php';

session_start();

// Проверяем авторизацию
/*if (!checkAuth()) {
    header('Content-Type: application/json');
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}*/

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'] ?? 0;

// Проверяем, является ли пользователь администратором
try {
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
    exit;
}

if (!$user || !$user['is_admin']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен. Требуются права администратора']);
    exit;
}

// Получаем ID расписания
$schedule_id = $_GET['id'] ?? 0;

if (!$schedule_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Не указан ID расписания']);
    exit;
}

// Получаем информацию о расписании
try {
    $stmt = $pdo->prepare("SELECT * FROM backup_schedules WHERE id = ?");
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Расписание не найдено']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ошибка при получении расписания: ' . $e->getMessage()]);
}

exit;
