<?php
// ВКЛЮЧАЕМ ВСЕ ОШИБКИ
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>DEBUG: Резервное копирование - пошаговая проверка</h1>";
echo "<pre>";

// Шаг 1: Проверка базовой функциональности PHP
echo "Шаг 1: Базовая проверка PHP\n";
echo "PHP версия: " . phpversion() . "\n";
echo "Память: " . ini_get('memory_limit') . "\n";
echo "Максимальное время выполнения: " . ini_get('max_execution_time') . "\n\n";

// Шаг 2: Проверка сессии
echo "Шаг 2: Проверка сессии\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Статус сессии: " . session_status() . "\n";
echo "ID сессии: " . session_id() . "\n";

// Имитируем сессию администратора для теста
$_SESSION['user'] = [
    'id' => 1,
    'is_admin' => 1,
    'email' => 'vadim@korbanov.ru'
];
echo "Данные сессии установлены\n\n";

// Шаг 3: Определение путей
echo "Шаг 3: Проверка путей\n";
$current_dir = __DIR__;
echo "Текущая директория: $current_dir\n";

// Пробуем разные варианты определения корня
$possible_paths = [
    dirname(__DIR__), // на уровень выше
    dirname(dirname(__DIR__)), // на два уровня выше
    $_SERVER['DOCUMENT_ROOT'],
    '/home/mortal/web/homevlad.ru/public_html' // предположительный путь на хостинге
];

foreach ($possible_paths as $i => $path) {
    if (file_exists($path)) {
        echo "Путь $i существует: $path\n";
    }
}

// Используем наиболее вероятный путь
$project_root = dirname(__DIR__);
echo "Выбран PROJECT_ROOT: $project_root\n\n";

// Шаг 4: Проверка необходимых файлов
echo "Шаг 4: Проверка необходимых файлов\n";
$required_files = [
    'db.php' => $project_root . '/includes/db.php',
    'backup_functions.php' => $project_root . '/includes/backup_functions.php'
];

foreach ($required_files as $name => $path) {
    if (file_exists($path)) {
        echo "✓ $name найден: $path\n";

        // Пробуем прочитать первые 5 строк для проверки синтаксиса
        $lines = file($path);
        echo "  Первые 3 строки файла:\n";
        for ($i = 0; $i < min(3, count($lines)); $i++) {
            echo "  " . htmlspecialchars($lines[$i]) . "\n";
        }
    } else {
        echo "✗ $name НЕ найден: $path\n";
    }
    echo "\n";
}

// Шаг 5: Подключение db.php
echo "Шаг 5: Попытка подключения к базе данных\n";
try {
    require_once $project_root . '/includes/db.php';
    echo "✓ db.php успешно подключен\n";

    // Пробуем создать экземпляр Database
    $db = new Database();
    echo "✓ Класс Database создан\n";

    $pdo = $db->getConnection();
    echo "✓ Подключение к БД получено\n";

    // Простой запрос для проверки
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "✓ Тестовый запрос выполнен: " . ($result['test'] == 1 ? "OK" : "FAIL") . "\n\n";
} catch (Exception $e) {
    echo "✗ Ошибка подключения к БД: " . $e->getMessage() . "\n\n";
}

// Шаг 6: Проверка backup_functions.php
echo "Шаг 6: Проверка функций бэкапа\n";
try {
    require_once $project_root . '/includes/backup_functions.php';
    echo "✓ backup_functions.php успешно подключен\n";

    // Проверяем существование основных функций
    $required_functions = ['createBackup', 'formatBytes'];
    foreach ($required_functions as $func) {
        if (function_exists($func)) {
            echo "✓ Функция $func существует\n";
        } else {
            echo "✗ Функция $func не найдена\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Ошибка в backup_functions.php: " . $e->getMessage() . "\n\n";
}

// Шаг 7: Проверка директорий
echo "Шаг 7: Проверка директорий\n";
$backup_dir = $project_root . '/backups/';
echo "Путь к бэкапам: $backup_dir\n";

if (!file_exists($backup_dir)) {
    echo "Директория не существует, пытаемся создать...\n";
    if (mkdir($backup_dir, 0755, true)) {
        echo "✓ Директория создана\n";
    } else {
        echo "✗ Не удалось создать директорию\n";
    }
} else {
    echo "✓ Директория существует\n";
}

if (is_writable($backup_dir)) {
    echo "✓ Директория доступна для записи\n";
} else {
    echo "✗ Директория недоступна для записи\n";
    echo "Права: " . substr(sprintf('%o', fileperms($backup_dir)), -4) . "\n";
}
echo "\n";

// Шаг 8: Проверка ZipArchive
echo "Шаг 8: Проверка ZipArchive\n";
if (class_exists('ZipArchive')) {
    echo "✓ Класс ZipArchive доступен\n";

    // Пробуем создать тестовый архив
    $test_zip = $backup_dir . 'test_' . time() . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($test_zip, ZipArchive::CREATE) === TRUE) {
        $zip->addFromString('test.txt', 'Тестовый файл создан ' . date('Y-m-d H:i:s'));
        $zip->close();
        echo "✓ Тестовый ZIP архив создан: $test_zip\n";
        echo "  Размер: " . filesize($test_zip) . " байт\n";
    } else {
        echo "✗ Не удалось создать ZIP архив\n";
    }
} else {
    echo "✗ Класс ZipArchive не доступен\n";
    echo "  Установите расширение: sudo apt-get install php-zip\n";
}
echo "\n";

// Шаг 9: Проверка выполнения простого бэкапа
echo "Шаг 9: Тест создания бэкапа\n";
if (function_exists('createBackup')) {
    try {
        echo "Вызываем функцию createBackup...\n";
        $result = createBackup('db', 'Тестовый бэкап', false);
        echo "Результат:\n";
        print_r($result);
    } catch (Exception $e) {
        echo "✗ Ошибка при вызове createBackup: " . $e->getMessage() . "\n";
    }
} else {
    echo "Функция createBackup не найдена, пропускаем тест\n";
}
echo "\n";

echo "</pre>";

// Шаг 10: Простой HTML интерфейс для тестирования
echo "<h2>Простой интерфейс для тестирования</h2>";
echo "<div style='background: #f5f5f5; padding: 20px; border-radius: 10px;'>";
echo "<h3>Быстрые действия:</h3>";
echo "<form method='POST'>";
echo "<input type='hidden' name='action' value='test_backup'>";
echo "<button type='submit' style='padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;'>Создать тестовый бэкап</button>";
echo "</form>";

echo "<h3>Информация о системе:</h3>";
echo "<ul>";
echo "<li>Версия PHP: " . phpversion() . "</li>";
echo "<li>ОС: " . PHP_OS . "</li>";
echo "<li>Включенные модули: " . implode(', ', get_loaded_extensions()) . "</li>";
echo "</ul>";

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    echo "<hr><h3>Результат выполнения:</h3>";

    if ($_POST['action'] === 'test_backup') {
        echo "Попытка создания тестового бэкапа...<br>";

        $backup_dir = $project_root . '/backups/';
        $test_file = $backup_dir . 'manual_test_' . date('Y-m-d_H-i-s') . '.txt';

        if (file_put_contents($test_file, "Тестовый файл создан " . date('Y-m-d H:i:s'))) {
            echo "✓ Тестовый файл создан: " . basename($test_file) . "<br>";
            echo "Размер: " . filesize($test_file) . " байт<br>";
        } else {
            echo "✗ Не удалось создать тестовый файл<br>";
        }
    }
}

echo "</div>";

// Добавляем стили для красоты
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    pre { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
</style>";
?>
