<?php
// Тест только функции createBackup
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Тест функции createBackup</h1>";
echo "<pre>";

// Увеличиваем лимиты
set_time_limit(300); // 5 минут
ini_set('memory_limit', '512M');

// Определяем пути
$project_root = dirname(__DIR__);

// Подключаем только необходимые файлы
require_once $project_root . '/includes/db.php';
require_once $project_root . '/includes/backup_functions.php';

try {
    echo "1. Создаем подключение к БД...\n";
    $db = new Database();
    $pdo = $db->getConnection();
    echo "✓ Подключение успешно\n\n";
    
    // Проверяем, что функция существует
    echo "2. Проверка существования функции createBackup...\n";
    if (!function_exists('createBackup')) {
        die("✗ Функция createBackup не существует!\n");
    }
    echo "✓ Функция существует\n\n";
    
    // Пробуем создать небольшой бэкап БД
    echo "3. Пробуем создать бэкап только БД (самый быстрый вариант)...\n";
    
    // Устанавливаем ограничение по времени для этой операции
    $start_time = microtime(true);
    
    // Вызываем функцию с ограниченным набором данных
    $result = createBackup('db', 'Тестовый бэкап из отдельного скрипта', false);
    
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    
    echo "✓ Функция выполнена за " . round($execution_time, 2) . " секунд\n";
    echo "Результат:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n✓ Бэкап успешно создан!\n";
        echo "Файл: " . $result['filename'] . "\n";
        echo "Путь: " . $result['filepath'] . "\n";
        echo "Размер: " . $result['size'] . " байт\n";
    } else {
        echo "\n✗ Ошибка при создании бэкапа:\n";
        echo $result['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Исключение: " . $e->getMessage() . "\n";
    echo "Трейс:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";

// Дополнительная диагностика
echo "<h2>Дополнительная информация:</h2>";
echo "<pre>";

// Проверяем системные ограничения
echo "Проверка системных ограничений:\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";

// Проверяем размер базы данных
try {
    $stmt = $pdo->query("SELECT 
        table_schema as 'Database', 
        SUM(data_length + index_length) as 'Size'
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
        GROUP BY table_schema");
    
    $db_info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nРазмер базы данных: " . formatBytes($db_info['Size'] ?? 0) . "\n";
} catch (Exception $e) {
    echo "\nНе удалось получить размер БД: " . $e->getMessage() . "\n";
}

// Проверяем количество таблиц
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Количество таблиц: " . count($tables) . "\n";
} catch (Exception $e) {
    echo "Не удалось получить список таблиц\n";
}

echo "</pre>";

// Вспомогательная функция для форматирования размера
function formatBytes($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>