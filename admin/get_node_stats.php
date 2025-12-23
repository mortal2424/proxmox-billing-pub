<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Настройка ошибок
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/node_stats_errors.log');
error_reporting(E_ALL);

// Функция для отправки JSON-ответа
function sendResponse($data, $statusCode = 200) {
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Проверка прав администратора
if (!isAdmin()) {
    sendResponse(['error' => 'Доступ запрещен'], 403);
}

// Проверка обязательных параметров
$requiredParams = ['id', 'hours', 'interval'];
foreach ($requiredParams as $param) {
    if (!isset($_GET[$param])) {
        sendResponse(['error' => "Не указан обязательный параметр: $param"], 400);
    }
}

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $nodeId = intval($_GET['id']);
    $hours = intval($_GET['hours']);
    $interval = intval($_GET['interval']);

    // Валидация параметров
    if ($nodeId <= 0 || $hours <= 0 || $interval <= 0) {
        sendResponse(['error' => 'Некорректные параметры: значения должны быть положительными числами'], 400);
    }

    // Максимальный интервал - 6 часов (360 минут)
    if ($interval > 360) {
        $interval = 360;
    }

    // Получаем статистику с учетом интервала
    $query = "SELECT 
                cpu_usage as cpu,
                ram_usage as memory,
                ram_total as memory_total,
                network_rx_mbits as network_rx,
                network_tx_mbits as network_tx,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as timestamp
              FROM node_stats 
              WHERE node_id = ? 
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
              AND MINUTE(created_at) % ? = 0
              ORDER BY created_at ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$nodeId, $hours, $interval]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Если данных слишком много, берем каждый N-й элемент
    if (count($stats) > 500) {
        $step = ceil(count($stats) / 300);
        $filteredStats = [];
        for ($i = 0; $i < count($stats); $i += $step) {
            $filteredStats[] = $stats[$i];
        }
        $stats = $filteredStats;
    }

    // Преобразование данных для фронтенда
    $result = [];
    foreach ($stats as $stat) {
        $result[] = [
            'timestamp' => $stat['timestamp'],
            'cpu' => (float)$stat['cpu'],
            'memory' => (float)$stat['memory'],
            'memory_total' => (int)$stat['memory_total'],
            'network_rx' => (int)$stat['network_rx'],
            'network_tx' => (int)$stat['network_tx']
        ];
    }

    sendResponse($result);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    sendResponse(['error' => 'Ошибка базы данных'], 500);
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    sendResponse(['error' => 'Внутренняя ошибка сервера'], 500);
}