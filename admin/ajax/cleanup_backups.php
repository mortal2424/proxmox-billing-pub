<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/backup_functions.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = (new Database())->getConnection();
    $query = $pdo->prepare('SELECT is_admin,is_active FROM users WHERE id=?');
    $query->execute([(int)($_SESSION['user']['id'] ?? 0)]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['is_admin'] || !$user['is_active'] || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(403);
        throw new RuntimeException('Доступ запрещён');
    }
    $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    if (!is_string($input['csrf_token'] ?? null) || !isset($_SESSION['backup_csrf']) ||
        !hash_equals($_SESSION['backup_csrf'], $input['csrf_token'])) {
        http_response_code(403);
        throw new RuntimeException('Неверный CSRF-токен');
    }
    $keep = filter_var($input['keep'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $days = filter_var($input['days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]);
    if (!$keep || !$days) {
        throw new RuntimeException('Некорректные параметры хранения');
    }
    $lock = fopen(BACKUP_DIR . '.retention.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Очистка временно недоступна');
    }
    $deleted = 0;
    try {
        foreach (backupRetentionCandidates(BACKUP_DIR, $keep) as $file) {
            if (filemtime($file) < time() - $days * 86400) {
                if (!unlink($file)) throw new RuntimeException('Не удалось удалить копию');
                $deleted++;
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'error' => $error instanceof JsonException
        ? 'Некорректный запрос' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
