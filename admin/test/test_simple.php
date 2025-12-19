<?php
echo "Test 1: PHP работает<br>";

// Проверяем сессии
session_start();
echo "Test 2: Сессия стартовала<br>";
echo "Session ID: " . session_id() . "<br>";

// Проверяем базу данных
try {
    require_once __DIR__ . '/../includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();
    echo "Test 3: База данных подключена<br>";
} catch (Exception $e) {
    echo "Test 3: Ошибка БД: " . $e->getMessage() . "<br>";
}

// Проверяем пути к файлам
$files = [
    'db.php' => __DIR__ . '/../includes/db.php',
    'auth.php' => __DIR__ . '/../includes/auth.php',
    'backup_functions.php' => __DIR__ . '/../includes/backup_functions.php',
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "Test 4: $name найден<br>";
    } else {
        echo "Test 4: $name НЕ найден по пути: $path<br>";
    }
}

echo "Test 5: Все тесты завершены";
?>