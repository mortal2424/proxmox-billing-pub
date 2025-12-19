<?php
// Простейший тест создания бэкапа БД
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '512M');

echo "<h1>Тест создания бэкапа БД</h1>";
echo "<pre>";

// Определяем пути
define('PROJECT_ROOT', dirname(__DIR__));
define('BACKUP_DIR', PROJECT_ROOT . '/backups/');

// Создаем директорию для бэкапов
if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

// Подключаем необходимые файлы
require_once PROJECT_ROOT . '/includes/db.php';
require_once PROJECT_ROOT . '/includes/backup_functions.php';

try {
    echo "1. Подключаемся к БД...\n";
    $db = new Database();
    $pdo = $db->getConnection();
    echo "✓ Подключение успешно\n\n";
    
    // Проверяем существование функции createBackup
    echo "2. Проверяем функцию createBackup...\n";
    if (!function_exists('createBackup')) {
        die("✗ Функция createBackup не существует!\n");
    }
    echo "✓ Функция существует\n\n";
    
    // Временно переопределяем функцию логирования
    echo "3. Временно отключаем логирование...\n";
    function logBackupAction($pdo, $user_id, $action, $filename, $details = '') {
        echo "   Логирование: $action - $filename\n";
        return true;
    }
    echo "✓ Логирование отключено\n\n";
    
    // Создаем бэкап только БД
    echo "4. Создаем бэкап только БД...\n";
    $start_time = microtime(true);
    
    $result = createBackup('db', 'Тестовый бэкап БД', false);
    
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    
    echo "✓ Выполнено за " . round($execution_time, 2) . " секунд\n\n";
    
    echo "5. Результат:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n✓ Бэкап успешно создан!\n";
        echo "   Файл: " . $result['filename'] . "\n";
        echo "   Путь: " . $result['filepath'] . "\n";
        echo "   Размер: " . $result['size'] . " байт\n";
        
        // Проверяем существование файла
        if (file_exists($result['filepath'])) {
            echo "   Файл существует на диске\n";
            
            // Проверяем содержимое архива
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($result['filepath']) === TRUE) {
                    echo "   Содержимое архива:\n";
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        echo "   - " . $stat['name'] . " (" . $stat['size'] . " байт)\n";
                    }
                    $zip->close();
                }
            }
        } else {
            echo "   ✗ Файл НЕ существует на диске!\n";
        }
    } else {
        echo "\n✗ Ошибка при создании бэкапа:\n";
        echo "   " . $result['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Исключение: " . $e->getMessage() . "\n";
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

// Проверяем размер базы данных
try {
    $stmt = $pdo->query("SELECT 
        table_schema as 'Database', 
        SUM(data_length + index_length) as 'Size'
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
        GROUP BY table_schema");
    
    $db_info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Размер базы данных: " . formatBytes($db_info['Size'] ?? 0) . "\n";
} catch (Exception $e) {
    echo "Не удалось получить размер БД: " . $e->getMessage() . "\n";
}

// Проверяем количество таблиц
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Количество таблиц: " . count($tables) . "\n";
    echo "Первые 5 таблиц: " . implode(', ', array_slice($tables, 0, 5)) . "\n";
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