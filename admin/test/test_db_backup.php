<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    echo "<h2>Тест подключения к БД</h2>";
    echo "Подключение успешно<br>";
    
    // Пробуем получить список таблиц
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Найдено таблиц: " . count($tables) . "<br>";
    
    // Пробуем получить данные из первой таблицы
    if (count($tables) > 0) {
        $table = $tables[0];
        echo "Тестируем таблицу: $table<br>";
        
        // Получаем структуру
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $create = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "Структура получена успешно<br>";
        
        // Пробуем получить несколько записей
        $stmt = $pdo->query("SELECT * FROM `$table` LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Данные получены: " . count($rows) . " записей<br>";
        
        // Пробуем создать простой дамп
        $dump = "-- Тестовый дамп таблицы $table\n";
        $dump .= $create['Create Table'] . ";\n\n";
        
        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $dump .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
            
            foreach ($rows as $i => $row) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = $pdo->quote($value);
                }
                $dump .= "(" . implode(', ', $values) . ")";
                if ($i < count($rows) - 1) $dump .= ",\n";
            }
            $dump .= ";\n";
        }
        
        echo "<pre>" . htmlspecialchars($dump) . "</pre>";
        
        // Пробуем сохранить в файл
        $test_file = __DIR__ . '/../backups/test_dump.sql';
        file_put_contents($test_file, $dump);
        
        echo "Дамп сохранен в: $test_file<br>";
        echo "Размер файла: " . filesize($test_file) . " байт<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 10px; background: #ffe6e6;'>";
    echo "<strong>Ошибка:</strong> " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>