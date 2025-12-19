<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

echo "<h1>Простой тест создания бэкапа</h1>";

$project_root = dirname(__DIR__);
$backup_dir = $project_root . '/backups/';

// Создаем директорию если нужно
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// 1. Тест создания ZIP архива с небольшим файлом
echo "<h2>1. Тест создания ZIP архива</h2>";

$test_zip = $backup_dir . 'test_simple_' . time() . '.zip';

if (!class_exists('ZipArchive')) {
    die("Класс ZipArchive не доступен!");
}

$zip = new ZipArchive();
if ($zip->open($test_zip, ZipArchive::CREATE) !== TRUE) {
    die("Не удалось создать ZIP архив");
}

// Добавляем несколько маленьких файлов
$zip->addFromString('test1.txt', 'Это тестовый файл 1');
$zip->addFromString('test2.txt', 'Это тестовый файл 2');
$zip->addFromString('backup_meta.json', json_encode([
    'type' => 'test',
    'comment' => 'Тестовый бэкап',
    'created' => date('Y-m-d H:i:s'),
    'files' => ['test1.txt', 'test2.txt']
]));

$zip->close();

echo "ZIP архив создан: " . basename($test_zip) . "<br>";
echo "Размер: " . filesize($test_zip) . " байт<br>";

// 2. Тест дампа базы данных (только структура)
echo "<h2>2. Тест дампа базы данных</h2>";

try {
    require_once $project_root . '/includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();
    
    // Получаем список таблиц
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Найдено таблиц: " . count($tables) . "<br>";
    
    // Создаем простой дамп только структуры первой таблицы
    if (count($tables) > 0) {
        $table_name = $tables[0];
        $stmt = $pdo->query("SHOW CREATE TABLE `$table_name`");
        $create_table = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $dump_file = $backup_dir . 'test_dump.sql';
        file_put_contents($dump_file, $create_table['Create Table'] . ";\n\n");
        
        echo "Структура таблицы '$table_name' сохранена в дамп<br>";
        
        // Добавляем дамп в ZIP
        $zip2 = new ZipArchive();
        $zip2_file = $backup_dir . 'test_with_sql.zip';
        
        if ($zip2->open($zip2_file, ZipArchive::CREATE) === TRUE) {
            $zip2->addFile($dump_file, 'database_structure.sql');
            $zip2->addFromString('info.txt', 'Тестовый бэкап с SQL');
            $zip2->close();
            echo "ZIP с SQL создан: " . basename($zip2_file) . "<br>";
        }
        
        // Удаляем временный файл
        unlink($dump_file);
    }
    
} catch (Exception $e) {
    echo "Ошибка при работе с БД: " . $e->getMessage() . "<br>";
}

// 3. Тест производительности
echo "<h2>3. Тест производительности</h2>";

$start = microtime(true);

// Создаем 10 маленьких файлов в архиве
$zip3 = new ZipArchive();
$zip3_file = $backup_dir . 'test_performance.zip';

if ($zip3->open($zip3_file, ZipArchive::CREATE) === TRUE) {
    for ($i = 0; $i < 10; $i++) {
        $zip3->addFromString("file_$i.txt", str_repeat("Содержимое файла $i\n", 100));
    }
    $zip3->close();
    
    $time = microtime(true) - $start;
    echo "Создано 10 файлов в архиве за " . round($time, 2) . " секунд<br>";
    echo "Размер архива: " . filesize($zip3_file) . " байт<br>";
}

// 4. Проверка оригинальной функции через отражение
echo "<h2>4. Анализ оригинальной функции createBackup</h2>";

if (file_exists($project_root . '/includes/backup_functions.php')) {
    // Читаем файл и ищем функцию createBackup
    $content = file_get_contents($project_root . '/includes/backup_functions.php');
    
    if (preg_match('/function\s+createBackup\s*\([^)]*\)\s*\{([^}]+(?:\{[^{}]*\}[^}]*)*)\}/s', $content, $matches)) {
        echo "Функция createBackup найдена в файле<br>";
        echo "Примерный размер функции: " . strlen($matches[0]) . " символов<br>";
        
        // Ищем потенциально опасные вызовы
        $dangerous_patterns = [
            'exec(' => 'Вызов exec()',
            'shell_exec(' => 'Вызов shell_exec()',
            'system(' => 'Вызов system()',
            'passthru(' => 'Вызов passthru()',
            '`' => 'Обратные кавычки',
            'proc_open(' => 'Вызов proc_open()',
            'popen(' => 'Вызов popen()',
            'while\s*\(true\)' => 'Бесконечный цикл',
            'for\s*\([^;]*;\s*;\s*[^)]*\)' => 'Бесконечный цикл for',
            'mysqldump' => 'Вызов mysqldump',
            'tar\s+' => 'Вызов tar',
            'zip\s+-r' => 'Рекурсивное создание zip через команду',
        ];
        
        echo "Поиск потенциальных проблем:<br>";
        foreach ($dangerous_patterns as $pattern => $description) {
            if (preg_match("/$pattern/", $matches[0])) {
                echo "⚠ Найдено: $description<br>";
            }
        }
    } else {
        echo "Не удалось найти функцию createBackup в файле<br>";
    }
}

echo "<h2>Итог</h2>";
echo "Если все тесты прошли успешно, но оригинальная функция createBackup зависает, возможно:<br>";
echo "1. Она пытается создать бэкап слишком большого объема<br>";
echo "2. Есть бесконечный цикл или рекурсия<br>";
echo "3. Проблема с правами доступа к файлам<br>";
echo "4. Не хватает памяти или времени выполнения<br>";

// Показываем список созданных файлов
echo "<h2>Созданные файлы:</h2>";
$files = glob($backup_dir . 'test_*');
foreach ($files as $file) {
    echo basename($file) . " - " . filesize($file) . " байт<br>";
}
?>