<?php
/**
 * Обработчик создания бэкапа через AJAX
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_functions.php';
require_once __DIR__ . '/../admin_functions.php';

session_start();

// Проверяем авторизацию
/*if (!checkAuth()) {
    header('Content-Type: application/json');
    http_response_code(401);
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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
    exit;
}

if (!$user || !$user['is_admin']) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен. Требуются права администратора']);
    exit;
}

// Получаем данные из POST
$backup_type = $_POST['backup_type'] ?? 'full';
$comment = trim($_POST['comment'] ?? '');
$is_manual = !empty($_POST['is_manual']) && $_POST['is_manual'] == 'true';

// Валидация типа бэкапа
$backup_types = ['full', 'files', 'db'];
if (!in_array($backup_type, $backup_types)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Неверный тип бэкапа']);
    exit;
}

// Создаем бэкап
try {
    $result = createBackup($backup_type, $comment, !$is_manual);
    
    if ($result['success']) {
        // Логируем действие
        logBackupAction($pdo, $user_id, 'create', $result['filename'], "Тип: {$backup_type}, Комментарий: {$comment}");
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Бэкап успешно создан',
            'filename' => $result['filename'],
            'backup' => $result
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Неизвестная ошибка при создании бэкапа'
        ]);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при создании бэкапа: ' . $e->getMessage()
    ]);
}

exit;