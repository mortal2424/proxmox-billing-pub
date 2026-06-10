<?php
// Настройка error reporting
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', 1);
ini_set('error_log', '/home/mortal/web/homevlad.ru/public_html/bots/logs/php_errors.log');

// Инициализация логов
$logDir = '/home/mortal/web/homevlad.ru/public_html/bots/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

function logMessage($message) {
    global $logDir;
    $logFile = $logDir . '/bot.log';
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

// Класс для управления подключением к БД с переподключением
class DatabaseManager {
    private $dbConfig;
    private $pdo;

    public function __construct() {
        $this->dbConfig = [
            'host' => 'localhost',
            'dbname' => '',
            'user' => '',
            'pass' => ''
        ];
        $this->connect();
    }

    private function connect() {
        $dsn = "mysql:host={$this->dbConfig['host']};dbname={$this->dbConfig['dbname']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5
        ];

        try {
            $this->pdo = new PDO($dsn, $this->dbConfig['user'], $this->dbConfig['pass'], $options);
            logMessage("Database connection established");
        } catch (PDOException $e) {
            logMessage("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function getConnection() {
        try {
            // Проверяем соединение
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            logMessage("Database connection lost, reconnecting...");
            $this->connect();
        }
        return $this->pdo;
    }
}

// Функция safeQuery с обработкой переподключения
function safeQuery($pdo, $query, $params = [], $requiredTables = ['users']) {
    $maxAttempts = 2;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        try {
            // Проверка существования таблиц
            foreach ($requiredTables as $table) {
                $checkTable = $pdo->prepare("SELECT 1 FROM information_schema.tables
                                           WHERE table_schema = DATABASE()
                                           AND table_name = ?");
                $checkTable->execute([$table]);

                if ($checkTable->rowCount() == 0) {
                    throw new PDOException("Таблица $table не существует");
                }

                if ($table === 'users') {
                    $checkColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns
                                               WHERE table_schema = DATABASE()
                                               AND table_name = 'users'
                                               AND column_name = 'telegram_id'");
                    $checkColumn->execute();

                    if ($checkColumn->rowCount() == 0) {
                        throw new PDOException("Столбец telegram_id отсутствует в таблице users");
                    }
                }
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;

        } catch (PDOException $e) {
            $attempt++;
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            sleep(1);
        }
    }
}

// Инициализация базы данных
try {
    require_once __DIR__ . '/../includes/proxmox_functions.php';
    $dbManager = new DatabaseManager();
    $pdo = $dbManager->getConnection();
    safeQuery($pdo, "SELECT 1");
} catch (PDOException $e) {
    logMessage("Database initialization ERROR: " . $e->getMessage());
    exit(1);
}

class TelegramBot {
    private $pdo;
    private $token;
    private $processingChats = [];
    private $userVMs = [];
    private $proxmoxApi;
    private $processedCallbacks = [];
    private $lastActionTime = [];
    private $dbManager;

    public function __construct($dbManager, $token) {
        $this->dbManager = $dbManager;
        $this->pdo = $dbManager->getConnection();
        $this->token = $token;
        $this->initializeProxmoxApi();
    }

    private function reconnectDatabase() {
        try {
            logMessage("Attempting to reconnect to database...");
            $this->pdo = $this->dbManager->getConnection();
            return true;
        } catch (PDOException $e) {
            logMessage("Reconnection failed: " . $e->getMessage());
            return false;
        }
    }

    private function initializeProxmoxApi() {
        try {
            $nodeInfo = $this->getMainProxmoxNode();
            if ($nodeInfo) {
                $this->proxmoxApi = new ProxmoxAPI(
                    $nodeInfo['hostname'],
                    $nodeInfo['username'],
                    $nodeInfo['password'],
                    22,
                    $nodeInfo['node_name'],
                    $nodeInfo['id'],
                    $this->pdo
                );
                logMessage("Proxmox API initialized successfully");
            }
        } catch (Exception $e) {
            logMessage("Proxmox API initialization error: " . $e->getMessage());
        }
    }

    private function getMainProxmoxNode() {
        try {
            $stmt = safeQuery($this->pdo, "SELECT * FROM proxmox_nodes ORDER BY id LIMIT 1");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            logMessage("Failed to get Proxmox node: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $stmt = safeQuery($this->pdo, "SELECT * FROM proxmox_nodes ORDER BY id LIMIT 1");
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            throw $e;
        }
    }

    public function handleUpdate($update) {
        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (PDOException $e) {
            logMessage("Database error in handleUpdate: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                // Повторяем обработку после переподключения
                $this->handleUpdate($update);
            }
        } catch (Exception $e) {
            logMessage("Error in handleUpdate: " . $e->getMessage());
        }
    }

    private function handleMessage($message) {
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        try {
            if ($text === '/start') {
                $this->handleStartCommand($chatId);
            } elseif ($text === '/vms') {
                $this->handleVmsCommand($chatId, 1);
            } elseif (preg_match('/^\/vm_(\d+)$/', $text, $matches)) {
                $vmId = $matches[1];
                $this->showVMManagement($chatId, $vmId);
            }
        } catch (PDOException $e) {
            logMessage("Database ERROR in handleMessage: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->handleMessage($message);
            } else {
                $this->sendMessage($chatId, "⚠️ Ошибка базы данных. Попробуйте позже.");
            }
        } catch (Exception $e) {
            logMessage("Error in handleMessage: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Произошла ошибка: " . $e->getMessage());
        }
    }

    private function handleCallback($callback) {
        $chatId = $callback['message']['chat']['id'];
        $callbackId = $callback['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'];

        // Уникальный ключ для идентификации callback'а
        $callbackKey = $chatId . '_' . $messageId . '_' . $data;

        // Проверяем, не обрабатывался ли уже этот callback
        if (isset($this->processedCallbacks[$callbackKey])) {
            $this->answerCallbackQuery($callbackId, "Команда уже обработана");
            return;
        }

        // Помечаем callback как обработанный
        $this->processedCallbacks[$callbackKey] = true;

        // Очищаем старые записи (чтобы не накапливались)
        if (count($this->processedCallbacks) > 100) {
            $this->processedCallbacks = array_slice($this->processedCallbacks, -50, null, true);
        }

        try {
            if (strpos($data, 'vms_page_') === 0) {
                $page = (int) str_replace('vms_page_', '', $data);
                $this->handleVmsCommand($chatId, $page);
            }
            elseif (strpos($data, 'vm_manage_') === 0) {
                $parts = explode('_', $data);
                $vmId = $parts[2];
                $this->showVMManagement($chatId, $vmId);
            }
            elseif (strpos($data, 'vm_action_') === 0) {
                $parts = explode('_', $data);
                $vmId = $parts[2];
                $action = $parts[3];

                // Проверяем время последнего действия для этой VM
                $actionKey = $chatId . '_' . $vmId;
                $currentTime = time();
                $lastActionTime = $this->lastActionTime[$actionKey] ?? 0;

                if ($currentTime - $lastActionTime < 5) {
                    $this->answerCallbackQuery($callbackId, "⚠️ Подождите 5 секунд перед следующим действием");
                    return;
                }

                $this->lastActionTime[$actionKey] = $currentTime;
                $this->handleVMAction($chatId, $vmId, $action, $callbackId);
            }
            elseif (strpos($data, 'vm_metrics_') === 0) {
                $parts = explode('_', $data);
                $vmId = $parts[2];
                $this->handleVMMetrics($chatId, $vmId, $callbackId);
            }
            elseif ($data === 'main_menu') {
                $this->showMainMenu($chatId);
            }
            elseif ($data === 'balance') {
                $this->handleBalanceCommand($chatId);
            }
            elseif ($data === 'support') {
                $this->handleSupportCommand($chatId);
            }
            elseif ($data === 'deposit') {
                $this->handleDepositCommand($chatId);
            }
            elseif ($data === 'refresh_vms') {
                unset($this->userVMs[$chatId]);
                $this->handleVmsCommand($chatId, 1);
            }

            $this->answerCallbackQuery($callbackId);

        } catch (PDOException $e) {
            logMessage("Database ERROR in handleCallback: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->handleCallback($callback);
            } else {
                $this->answerCallbackQuery($callbackId, "⚠️ Ошибка базы данных");
                $this->sendMessage($chatId, "⚠️ Ошибка обработки запроса: проблема с базой данных");
            }
        } catch (Exception $e) {
            logMessage("Error in handleCallback: " . $e->getMessage());
            $this->answerCallbackQuery($callbackId, "⚠️ Ошибка: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка обработки запроса: " . $e->getMessage());
        }
    }

    private function handleVMMetrics($chatId, $vmId, $callbackId = null) {
        try {
            // Проверяем права пользователя на эту VM
            $stmt = safeQuery($this->pdo, "
                SELECT v.*, n.hostname as node_hostname, n.node_name, n.username, n.password
                FROM vms v
                JOIN users u ON u.id = v.user_id
                JOIN proxmox_nodes n ON n.id = v.node_id
                WHERE u.telegram_id = ? AND v.vm_id = ?
            ", [$chatId, $vmId]);
            $vm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vm) {
                throw new Exception("Виртуальная машина #{$vmId} не найдена или у вас нет к ней доступа");
            }

            $this->answerCallbackQuery($callbackId, "⏳ Загружаем метрики...");

            // Получаем метрики из API
            $metricsUrl = "https://homevlad.ru/api/get_vm_metrics.php?vm_id={$vmId}&timeframe=hour";
            $response = file_get_contents($metricsUrl);
            $metrics = json_decode($response, true);

            if (!$metrics || !$metrics['success']) {
                throw new Exception("Не удалось получить метрики: " . ($metrics['error'] ?? 'Неизвестная ошибка'));
            }

            // Отправляем графики по одному
            $this->sendCpuChart($chatId, $vmId, $metrics);
            $this->sendMemoryChart($chatId, $vmId, $metrics);
            $this->sendNetworkChart($chatId, $vmId, $metrics);
            $this->sendDiskChart($chatId, $vmId, $metrics);

        } catch (Exception $e) {
            logMessage("VM Metrics ERROR: " . $e->getMessage());
            $this->answerCallbackQuery($callbackId, "⚠️ Ошибка: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка при получении метрик: " . $e->getMessage());
        }
    }

    private function sendCpuChart($chatId, $vmId, $metrics) {
        $labels = $metrics['labels'];
        $cpuData = $metrics['cpuData'];

        // Создаем изображение графика
        $width = 800;
        $height = 400;
        $padding = 50;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $red = imagecolorallocate($image, 255, 99, 132);
        $lightRed = imagecolorallocate($image, 255, 182, 193);

        imagefill($image, 0, 0, $white);

        // Рисуем оси
        imageline($image, $padding, $padding, $padding, $height - $padding, $black);
        imageline($image, $padding, $height - $padding, $width - $padding, $height - $padding, $black);

        // Подписи осей
        imagestring($image, 3, $width / 2 - 30, $height - $padding + 10, 'Время', $black);
        imagestringup($image, 3, 10, $height / 2, 'Использование CPU (%)', $black);

        // Рисуем сетку и подписи
        $maxY = 100;
        $stepY = 20;
        $stepX = ($width - 2 * $padding) / (count($labels) - 1);

        // Горизонтальные линии и подписи Y
        for ($y = 0; $y <= $maxY; $y += $stepY) {
            $yPos = $height - $padding - ($y / $maxY) * ($height - 2 * $padding);
            imageline($image, $padding, $yPos, $width - $padding, $yPos, imagecolorallocate($image, 200, 200, 200));
            imagestring($image, 2, $padding - 30, $yPos - 8, $y . '%', $black);
        }

        // Вертикальные линии и подписи X (каждую 5-ю точку)
        $labelStep = max(1, floor(count($labels) / 5));
        for ($i = 0; $i < count($labels); $i++) {
            $xPos = $padding + $i * $stepX;
            if ($i % $labelStep === 0) {
                imagestring($image, 2, $xPos - 10, $height - $padding + 5, substr($labels[$i], 0, 5), $black);
            }
        }

        // Рисуем график
        $points = [];
        for ($i = 0; $i < count($cpuData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($cpuData[$i] / $maxY) * ($height - 2 * $padding);
            $points[] = $x;
            $points[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $red);
        }

        // Линия графика
        if (count($points) > 2) {
            imagepolygon($image, $points, count($points) / 2, $red);
        }

        // Заливка под графиком
        $pointsWithBottom = $points;
        array_push($pointsWithBottom, $width - $padding, $height - $padding);
        array_push($pointsWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsWithBottom, count($pointsWithBottom) / 2, $lightRed);

        // Заголовок
        imagestring($image, 5, $width / 2 - 100, 10, "CPU Usage (VM #{$vmId})", $black);

        // Сохраняем изображение во временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'cpu_chart') . '.png';
        imagepng($image, $tempFile);
        imagedestroy($image);

        // Отправляем изображение
        $this->sendPhoto($chatId, $tempFile, "🖥 <b>Использование CPU виртуальной машины #{$vmId}</b>\n\nГрафик показывает загрузку процессора за последний час.");

        // Удаляем временный файл
        unlink($tempFile);
    }

    private function sendMemoryChart($chatId, $vmId, $metrics) {
        $labels = $metrics['labels'];
        $memData = $metrics['memData'];
        $memTotal = $metrics['memTotalData'][0] ?? 1; // Берем первое значение как общий объем

        // Создаем изображение графика
        $width = 800;
        $height = 400;
        $padding = 50;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $blue = imagecolorallocate($image, 54, 162, 235);
        $lightBlue = imagecolorallocate($image, 173, 216, 230);

        imagefill($image, 0, 0, $white);

        // Рисуем оси
        imageline($image, $padding, $padding, $padding, $height - $padding, $black);
        imageline($image, $padding, $height - $padding, $width - $padding, $height - $padding, $black);

        // Подписи осей
        imagestring($image, 3, $width / 2 - 30, $height - $padding + 10, 'Время', $black);
        imagestringup($image, 3, 10, $height / 2, 'Использование памяти (ГБ)', $black);

        // Рисуем сетку и подписи
        $maxY = $memTotal * 1.1; // 10% сверху для наглядности
        $stepY = max(0.5, round($memTotal / 5, 1));
        $stepX = ($width - 2 * $padding) / (count($labels) - 1);

        // Горизонтальные линии и подписи Y
        for ($y = 0; $y <= $maxY; $y += $stepY) {
            $yPos = $height - $padding - ($y / $maxY) * ($height - 2 * $padding);
            imageline($image, $padding, $yPos, $width - $padding, $yPos, imagecolorallocate($image, 200, 200, 200));
            imagestring($image, 2, $padding - 30, $yPos - 8, round($y, 1) . ' ГБ', $black);
        }

        // Вертикальные линии и подписи X (каждую 5-ю точку)
        $labelStep = max(1, floor(count($labels) / 5));
        for ($i = 0; $i < count($labels); $i++) {
            $xPos = $padding + $i * $stepX;
            if ($i % $labelStep === 0) {
                imagestring($image, 2, $xPos - 10, $height - $padding + 5, substr($labels[$i], 0, 5), $black);
            }
        }

        // Линия общего объема памяти
        $totalY = $height - $padding - ($memTotal / $maxY) * ($height - 2 * $padding);
        imageline($image, $padding, $totalY, $width - $padding, $totalY, imagecolorallocate($image, 75, 192, 192));
        imagestring($image, 2, $width - $padding + 5, $totalY - 8, "Всего: " . round($memTotal, 1) . " ГБ", imagecolorallocate($image, 75, 192, 192));

        // Рисуем график
        $points = [];
        for ($i = 0; $i < count($memData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($memData[$i] / $maxY) * ($height - 2 * $padding);
            $points[] = $x;
            $points[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $blue);
        }

        // Линия графика
        if (count($points) > 2) {
            imagepolygon($image, $points, count($points) / 2, $blue);
        }

        // Заливка под графиком
        $pointsWithBottom = $points;
        array_push($pointsWithBottom, $width - $padding, $height - $padding);
        array_push($pointsWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsWithBottom, count($pointsWithBottom) / 2, $lightBlue);

        // Заголовок
        imagestring($image, 5, $width / 2 - 100, 10, "Memory Usage (VM #{$vmId})", $black);

        // Сохраняем изображение во временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'mem_chart') . '.png';
        imagepng($image, $tempFile);
        imagedestroy($image);

        // Отправляем изображение
        $this->sendPhoto($chatId, $tempFile, "🧠 <b>Использование памяти виртуальной машины #{$vmId}</b>\n\nГрафик показывает использование оперативной памяти за последний час.");

        // Удаляем временный файл
        unlink($tempFile);
    }

    private function sendNetworkChart($chatId, $vmId, $metrics) {
        $labels = $metrics['labels'];
        $netInData = $metrics['netInData'];
        $netOutData = $metrics['netOutData'];

        // Создаем изображение графика
        $width = 800;
        $height = 400;
        $padding = 50;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $purple = imagecolorallocate($image, 153, 102, 255);
        $lightPurple = imagecolorallocate($image, 216, 191, 255);
        $orange = imagecolorallocate($image, 255, 159, 64);
        $lightOrange = imagecolorallocate($image, 255, 209, 148);

        imagefill($image, 0, 0, $white);

        // Рисуем оси
        imageline($image, $padding, $padding, $padding, $height - $padding, $black);
        imageline($image, $padding, $height - $padding, $width - $padding, $height - $padding, $black);

        // Подписи осей
        imagestring($image, 3, $width / 2 - 30, $height - $padding + 10, 'Время', $black);
        imagestringup($image, 3, 10, $height / 2, 'Скорость передачи (Mbit/s)', $black);

        // Находим максимальное значение для масштабирования
        $maxValue = max(max($netInData), max($netOutData)) * 1.1;
        $maxValue = max(1, $maxValue); // Минимум 1 для деления
        $stepY = max(0.1, round($maxValue / 5, 1));
        $stepX = ($width - 2 * $padding) / (count($labels) - 1);

        // Горизонтальные линии и подписи Y
        for ($y = 0; $y <= $maxValue; $y += $stepY) {
            $yPos = $height - $padding - ($y / $maxValue) * ($height - 2 * $padding);
            imageline($image, $padding, $yPos, $width - $padding, $yPos, imagecolorallocate($image, 200, 200, 200));
            imagestring($image, 2, $padding - 30, $yPos - 8, round($y, 1) . ' Mbit', $black);
        }

        // Вертикальные линии и подписи X (каждую 5-ю точку)
        $labelStep = max(1, floor(count($labels) / 5));
        for ($i = 0; $i < count($labels); $i++) {
            $xPos = $padding + $i * $stepX;
            if ($i % $labelStep === 0) {
                imagestring($image, 2, $xPos - 10, $height - $padding + 5, substr($labels[$i], 0, 5), $black);
            }
        }

        // Рисуем график входящего трафика
        $pointsIn = [];
        for ($i = 0; $i < count($netInData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($netInData[$i] / $maxValue) * ($height - 2 * $padding);
            $pointsIn[] = $x;
            $pointsIn[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $purple);
        }

        // Линия графика входящего трафика
        if (count($pointsIn) > 2) {
            imagepolygon($image, $pointsIn, count($pointsIn) / 2, $purple);
        }

        // Заливка под графиком входящего трафика
        $pointsInWithBottom = $pointsIn;
        array_push($pointsInWithBottom, $width - $padding, $height - $padding);
        array_push($pointsInWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsInWithBottom, count($pointsInWithBottom) / 2, $lightPurple);

        // Рисуем график исходящего трафика
        $pointsOut = [];
        for ($i = 0; $i < count($netOutData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($netOutData[$i] / $maxValue) * ($height - 2 * $padding);
            $pointsOut[] = $x;
            $pointsOut[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $orange);
        }

        // Линия графика исходящего трафика
        if (count($pointsOut) > 2) {
            imagepolygon($image, $pointsOut, count($pointsOut) / 2, $orange);
        }

        // Заливка под графиком исходящего трафика
        $pointsOutWithBottom = $pointsOut;
        array_push($pointsOutWithBottom, $width - $padding, $height - $padding);
        array_push($pointsOutWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsOutWithBottom, count($pointsOutWithBottom) / 2, $lightOrange);

        // Легенда
        $legendX = $width - $padding - 200;
        $legendY = $padding + 20;

        imagefilledrectangle($image, $legendX, $legendY, $legendX + 20, $legendY + 10, $purple);
        imagestring($image, 3, $legendX + 25, $legendY, 'Входящий трафик', $black);

        imagefilledrectangle($image, $legendX, $legendY + 20, $legendX + 20, $legendY + 30, $orange);
        imagestring($image, 3, $legendX + 25, $legendY + 20, 'Исходящий трафик', $black);

        // Заголовок
        imagestring($image, 5, $width / 2 - 100, 10, "Network Traffic (VM #{$vmId})", $black);

        // Сохраняем изображение во временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'net_chart') . '.png';
        imagepng($image, $tempFile);
        imagedestroy($image);

        // Отправляем изображение
        $this->sendPhoto($chatId, $tempFile, "🌐 <b>Сетевой трафик виртуальной машины #{$vmId}</b>\n\nГрафик показывает входящий и исходящий трафик за последний час.");

        // Удаляем временный файл
        unlink($tempFile);
    }

    private function sendDiskChart($chatId, $vmId, $metrics) {
        $labels = $metrics['labels'];
        $diskReadData = $metrics['diskReadData'];
        $diskWriteData = $metrics['diskWriteData'];

        // Создаем изображение графика
        $width = 800;
        $height = 400;
        $padding = 50;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $red = imagecolorallocate($image, 255, 99, 132);
        $lightRed = imagecolorallocate($image, 255, 182, 193);
        $blue = imagecolorallocate($image, 54, 162, 235);
        $lightBlue = imagecolorallocate($image, 173, 216, 230);

        imagefill($image, 0, 0, $white);

        // Рисуем оси
        imageline($image, $padding, $padding, $padding, $height - $padding, $black);
        imageline($image, $padding, $height - $padding, $width - $padding, $height - $padding, $black);

        // Подписи осей
        imagestring($image, 3, $width / 2 - 30, $height - $padding + 10, 'Время', $black);
        imagestringup($image, 3, 10, $height / 2, 'Дисковые операции (МБ)', $black);

        // Находим максимальное значение для масштабирования
        $maxValue = max(max($diskReadData), max($diskWriteData)) * 1.1;
        $maxValue = max(1, $maxValue); // Минимум 1 для деления
        $stepY = max(0.1, round($maxValue / 5, 1));
        $stepX = ($width - 2 * $padding) / (count($labels) - 1);

        // Горизонтальные линии и подписи Y
        for ($y = 0; $y <= $maxValue; $y += $stepY) {
            $yPos = $height - $padding - ($y / $maxValue) * ($height - 2 * $padding);
            imageline($image, $padding, $yPos, $width - $padding, $yPos, imagecolorallocate($image, 200, 200, 200));
            imagestring($image, 2, $padding - 30, $yPos - 8, round($y, 1) . ' МБ', $black);
        }

        // Вертикальные линии и подписи X (каждую 5-ю точку)
        $labelStep = max(1, floor(count($labels) / 5));
        for ($i = 0; $i < count($labels); $i++) {
            $xPos = $padding + $i * $stepX;
            if ($i % $labelStep === 0) {
                imagestring($image, 2, $xPos - 10, $height - $padding + 5, substr($labels[$i], 0, 5), $black);
            }
        }

        // Рисуем график чтения с диска
        $pointsRead = [];
        for ($i = 0; $i < count($diskReadData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($diskReadData[$i] / $maxValue) * ($height - 2 * $padding);
            $pointsRead[] = $x;
            $pointsRead[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $red);
        }

        // Линия графика чтения
        if (count($pointsRead) > 2) {
            imagepolygon($image, $pointsRead, count($pointsRead) / 2, $red);
        }

        // Заливка под графиком чтения
        $pointsReadWithBottom = $pointsRead;
        array_push($pointsReadWithBottom, $width - $padding, $height - $padding);
        array_push($pointsReadWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsReadWithBottom, count($pointsReadWithBottom) / 2, $lightRed);

        // Рисуем график записи на диск
        $pointsWrite = [];
        for ($i = 0; $i < count($diskWriteData); $i++) {
            $x = $padding + $i * $stepX;
            $y = $height - $padding - ($diskWriteData[$i] / $maxValue) * ($height - 2 * $padding);
            $pointsWrite[] = $x;
            $pointsWrite[] = $y;

            // Точки на графике
            imagefilledellipse($image, $x, $y, 4, 4, $blue);
        }

        // Линия графика записи
        if (count($pointsWrite) > 2) {
            imagepolygon($image, $pointsWrite, count($pointsWrite) / 2, $blue);
        }

        // Заливка под графиком записи
        $pointsWriteWithBottom = $pointsWrite;
        array_push($pointsWriteWithBottom, $width - $padding, $height - $padding);
        array_push($pointsWriteWithBottom, $padding, $height - $padding);
        imagefilledpolygon($image, $pointsWriteWithBottom, count($pointsWriteWithBottom) / 2, $lightBlue);

        // Легенда
        $legendX = $width - $padding - 200;
        $legendY = $padding + 20;

        imagefilledrectangle($image, $legendX, $legendY, $legendX + 20, $legendY + 10, $red);
        imagestring($image, 3, $legendX + 25, $legendY, 'Чтение с диска', $black);

        imagefilledrectangle($image, $legendX, $legendY + 20, $legendX + 20, $legendY + 30, $blue);
        imagestring($image, 3, $legendX + 25, $legendY + 20, 'Запись на диск', $black);

        // Заголовок
        imagestring($image, 5, $width / 2 - 100, 10, "Disk I/O (VM #{$vmId})", $black);

        // Сохраняем изображение во временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'disk_chart') . '.png';
        imagepng($image, $tempFile);
        imagedestroy($image);

        // Отправляем изображение
        $this->sendPhoto($chatId, $tempFile, "💾 <b>Дисковые операции виртуальной машины #{$vmId}</b>\n\nГрафик показывает операции чтения и записи на диск за последний час.");

        // Удаляем временный файл
        unlink($tempFile);
    }

    private function sendPhoto($chatId, $photoPath, $caption = '') {
        $data = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://api.telegram.org/bot{$this->token}/sendPhoto");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $postFields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'photo' => new CURLFile($photoPath)
        ];

        curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
        $response = curl_exec($curl);

        if ($response === false) {
            logMessage("Failed to send photo: " . curl_error($curl));
        }

        curl_close($curl);
    }

    private function answerCallbackQuery($callbackId, $text = null) {
        $data = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $data['text'] = $text;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'timeout' => 2
            ]
        ]);

        @file_get_contents(
            "https://api.telegram.org/bot{$this->token}/answerCallbackQuery",
            false,
            $context
        );
    }

    private function showVMManagement($chatId, $vmId) {
        try {
            // Проверяем права пользователя на эту VM
            $stmt = safeQuery($this->pdo, "
                SELECT
                    v.*,
                    t.name as tariff_name,
                    n.node_name
                FROM vms v
                JOIN users u ON u.id = v.user_id
                LEFT JOIN tariffs t ON t.id = v.tariff_id
                JOIN proxmox_nodes n ON n.id = v.node_id
                WHERE u.telegram_id = ? AND v.vm_id = ?
            ", [$chatId, $vmId]);
            $vm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vm) {
                throw new Exception("Виртуальная машина #{$vmId} не найдена или у вас нет к ней доступа");
            }

            $ipAddress = $vm['ip_address'] ?: 'не назначен';
            $tariffName = $vm['tariff_name'] ?: 'индивидуальный';
            $statusIcon = $vm['status'] === 'running' ? '🟢' : '🔴';

            $message = "🖥 <b>Управление виртуальной машиной #{$vmId}</b>\n\n";
            $message .= "🔹 <b>ID:</b> {$vm['id']}\n";
            $message .= "🔹 <b>Имя:</b> {$vm['hostname']}\n";
            $message .= "🔹 <b>Тариф:</b> {$tariffName}\n";
            $message .= "🔹 <b>Нода:</b> {$vm['node_name']}\n";
            $message .= "🔹 <b>IP:</b> {$ipAddress}\n";
            $message .= "🔹 <b>CPU:</b> {$vm['cpu']} ядер\n";
            $message .= "🔹 <b>RAM:</b> {$vm['ram']} MB\n";
            $message .= "🔹 <b>Диск:</b> {$vm['disk']} GB\n";
            $message .= "🔹 <b>Статус:</b> {$statusIcon} {$vm['status']}\n";

            $keyboard = [];

            if ($vm['status'] === 'running') {
                $keyboard[] = [
                    ['text' => '⏹ Остановить', 'callback_data' => "vm_action_{$vmId}_stop"],
                    ['text' => '🔄 Перезагрузить', 'callback_data' => "vm_action_{$vmId}_reboot"]
                ];
            } else {
                $keyboard[] = [
                    ['text' => '▶️ Запустить', 'callback_data' => "vm_action_{$vmId}_start"]
                ];
            }

            $keyboard[] = [
                ['text' => '📊 Метрики', 'callback_data' => "vm_metrics_{$vmId}"],
                ['text' => '↩️ Назад к списку', 'callback_data' => 'vms_page_1']
            ];

            $this->sendMessage($chatId, $message, [
                'inline_keyboard' => $keyboard
            ]);

        } catch (Exception $e) {
            logMessage("VM Management ERROR: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка: " . $e->getMessage());
        }
    }

    private function handleVMAction($chatId, $vmId, $action, $callbackId = null) {
        try {
            // Проверяем права пользователя на эту VM
            $stmt = safeQuery($this->pdo, "
                SELECT v.* FROM vms v
                JOIN users u ON u.id = v.user_id
                WHERE u.telegram_id = ? AND v.vm_id = ?
            ", [$chatId, $vmId]);
            $vm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vm) {
                throw new Exception("Виртуальная машина #{$vmId} не найдена или у вас нет к ней доступа");
            }

            if (!$this->proxmoxApi) {
                throw new Exception("Ошибка подключения к серверу Proxmox");
            }

            $result = null;
            $actionName = '';
            switch ($action) {
                case 'start':
                    $result = $this->proxmoxApi->startVM($vmId);
                    $actionName = 'запуск';
                    break;
                case 'stop':
                    $result = $this->proxmoxApi->stopVM($vmId);
                    $actionName = 'остановка';
                    break;
                case 'reboot':
                    $result = $this->proxmoxApi->rebootVM($vmId);
                    $actionName = 'перезагрузка';
                    break;
                default:
                    throw new Exception("Неизвестное действие");
            }

            if ($result && $result['success']) {
                $this->answerCallbackQuery($callbackId, "✅ {$actionName} ВМ #{$vmId} выполнена");
                $this->sendMessage($chatId, "✅ Виртуальная машина #{$vmId} ({$vm['hostname']}): {$actionName} выполнена успешно!");
                $this->showVMManagement($chatId, $vmId);
            } else {
                throw new Exception($result['error'] ?? "Не удалось выполнить {$actionName} виртуальной машины");
            }

        } catch (Exception $e) {
            logMessage("VM Action ERROR: " . $e->getMessage());
            $this->answerCallbackQuery($callbackId, "⚠️ Ошибка: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка: " . $e->getMessage());
            $this->showVMManagement($chatId, $vmId);
        }
    }

    private function handleStartCommand($chatId) {
        try {
            $user = safeQuery($this->pdo,
                "SELECT * FROM users WHERE telegram_id = ?",
                [$chatId]
            )->fetch();

            if ($user) {
                $this->showMainMenu($chatId, $user);
            } else {
                $this->sendMessage($chatId,
                    "🔐 Ваш Telegram не привязан.\nАвторизуйтесь в веб-интерфейсе и укажите ID: <code>$chatId</code>",
                    [
                        'inline_keyboard' => [
                            [['text' => '🔗 Перейти в личный кабинет', 'url' => 'https://homevlad.ru']]
                        ]
                    ]
                );
            }
        } catch (PDOException $e) {
            logMessage("Database ERROR in handleStartCommand: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->handleStartCommand($chatId);
            } else {
                $this->sendMessage($chatId, "⚠️ Ошибка базы данных. Попробуйте позже.");
            }
        }
    }

    private function showMainMenu($chatId, $user = null) {
        try {
            if (!$user) {
                $user = safeQuery($this->pdo,
                    "SELECT * FROM users WHERE telegram_id = ?",
                    [$chatId]
                )->fetch();
            }

            if (!$user) {
                $this->handleStartCommand($chatId);
                return;
            }

            $vmsCount = safeQuery($this->pdo,
                "SELECT COUNT(*) FROM vms WHERE user_id = ?",
                [$user['id']]
            )->fetchColumn();

            $message = "👋 Добро пожаловать, <b>" . htmlspecialchars($user['full_name']) . "</b>!\n\n";
            $message .= "💳 Баланс: <b>" . number_format($user['balance'], 2) . " руб.</b>\n";
            $message .= "🎁 Бонусный баланс: <b>" . number_format($user['bonus_balance'], 2) . " руб.</b>\n";
            $message .= "🖥 Виртуальных машин: <b>$vmsCount</b>";

            $this->sendMessage($chatId, $message, [
                'inline_keyboard' => [
                    [
                        ['text' => '🖥 Мои ВМ', 'callback_data' => 'vms_page_1'],
                        ['text' => '💰 Баланс', 'callback_data' => 'balance']
                    ],
                    [
                        ['text' => '🆘 Поддержка', 'callback_data' => 'support'],
                        ['text' => '💳 Пополнить', 'callback_data' => 'deposit']
                    ],
                    [
                        ['text' => '🔄 Обновить данные', 'callback_data' => 'main_menu']
                    ]
                ]
            ]);

        } catch (PDOException $e) {
            logMessage("Database ERROR in showMainMenu: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->showMainMenu($chatId, $user);
            } else {
                $this->sendMessage($chatId, "⚠️ Ошибка базы данных. Попробуйте позже.");
            }
        }
    }

    private function handleVmsCommand($chatId, $page = 1) {
        try {
            if (!isset($this->userVMs[$chatId])) {
                $this->userVMs[$chatId] = safeQuery($this->pdo,
                    "SELECT
                        v.id, v.vm_id, v.hostname, v.status, v.sdn, v.cpu, v.ram, v.disk,
                        v.ip_address, t.name as tariff_name, n.node_name
                     FROM vms v
                     JOIN users u ON v.user_id = u.id
                     LEFT JOIN tariffs t ON t.id = v.tariff_id
                     JOIN proxmox_nodes n ON n.id = v.node_id
                     WHERE u.telegram_id = ?
                     ORDER BY v.id DESC",
                    [$chatId]
                )->fetchAll();
            }

            $vms = $this->userVMs[$chatId];

            if (empty($vms)) {
                $this->sendMessage($chatId, "У вас нет виртуальных машин.", [
                    'inline_keyboard' => [
                        [['text' => '↩️ В меню', 'callback_data' => 'main_menu']]
                    ]
                ]);
                return;
            }

            $perPage = 5;
            $totalPages = ceil(count($vms) / $perPage);
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $perPage;
            $currentVMs = array_slice($vms, $offset, $perPage);

            $message = "🖥 <b>Ваши виртуальные машины (стр. $page из $totalPages):</b>\n\n";
            foreach ($currentVMs as $vm) {
                $statusIcon = $vm['status'] === 'running' ? '🟢' : '🔴';
                $ipAddress = $vm['ip_address'] ?: 'не назначен';
                $tariffName = $vm['tariff_name'] ?: 'индивидуальный';

                $message .= sprintf(
                    "%s <b>%s</b>\n" .
                    "📋 Тариф: %s\n" .
                    "🆔 VMID: %s\n" .
                    "📍 IP: %s\n" .
                    "⚙️ CPU: %s ядер\n" .
                    "🧠 RAM: %d MB\n" .
                    "💾 SSD: %d GB\n" .
                    "🔌 Статус: %s\n\n",
                    $statusIcon,
                    htmlspecialchars($vm['hostname']),
                    htmlspecialchars($tariffName),
                    $vm['vm_id'],
                    $ipAddress,
                    $vm['cpu'],
                    $vm['ram'],
                    $vm['disk'],
                    $vm['status']
                );
            }

            $keyboard = [];

            foreach ($currentVMs as $vm) {
                $keyboard[] = [
                    ['text' => "Управление #{$vm['id']} ({$vm['hostname']})", 'callback_data' => "vm_manage_{$vm['vm_id']}"]
                ];
            }

            if ($totalPages > 1) {
                $paginationRow = [];
                if ($page > 1) {
                    $paginationRow[] = ['text' => '◀️ Назад', 'callback_data' => 'vms_page_' . ($page - 1)];
                }
                $paginationRow[] = ['text' => "$page/$totalPages", 'callback_data' => 'none'];
                if ($page < $totalPages) {
                    $paginationRow[] = ['text' => 'Вперед ▶️', 'callback_data' => 'vms_page_' . ($page + 1)];
                }
                $keyboard[] = $paginationRow;
            }

            $keyboard[] = [
                ['text' => '🔄 Обновить', 'callback_data' => 'refresh_vms'],
                ['text' => '↩️ В меню', 'callback_data' => 'main_menu']
            ];

            $this->sendMessage($chatId, $message, [
                'inline_keyboard' => $keyboard
            ]);

        } catch (PDOException $e) {
            logMessage("Database ERROR in handleVmsCommand: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->handleVmsCommand($chatId, $page);
            } else {
                $this->sendMessage($chatId, "⚠️ Ошибка при получении списка ВМ");
            }
        } catch (Exception $e) {
            logMessage("Error in handleVmsCommand: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка при получении списка ВМ");
        }
    }

    private function handleBalanceCommand($chatId) {
        try {
            $user = safeQuery($this->pdo,
                "SELECT balance, bonus_balance FROM users WHERE telegram_id = ?",
                [$chatId]
            )->fetch();

            if (!$user) {
                $this->sendMessage($chatId, "❌ Пользователь не найден", [
                    'inline_keyboard' => [
                        [['text' => '↩️ В меню', 'callback_data' => 'main_menu']]
                    ]
                ]);
                return;
            }

            $transactions = safeQuery($this->pdo,
                "SELECT amount, description, created_at
                 FROM transactions
                 WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?)
                 ORDER BY created_at DESC
                 LIMIT 5",
                [$chatId]
            )->fetchAll();

            $message = "💰 <b>Ваш баланс:</b> " . number_format($user['balance'], 2) . " руб.\n";
            $message .= "🎁 <b>Бонусный баланс:</b> " . number_format($user['bonus_balance'], 2) . " руб.\n\n";
            $message .= "📝 <b>Последние операции:</b>\n";

            foreach ($transactions as $tx) {
                $amountColor = $tx['amount'] >= 0 ? '🟢' : '🔴';
                $message .= sprintf(
                    "%s %s: %s руб. (%s)\n",
                    $amountColor,
                    date('d.m H:i', strtotime($tx['created_at'])),
                    number_format($tx['amount'], 2),
                    $tx['description']
                );
            }

            $this->sendMessage($chatId, $message, [
                'inline_keyboard' => [
                    [
                        ['text' => '💳 Пополнить баланс', 'callback_data' => 'deposit'],
                        ['text' => '📊 История операций', 'url' => 'https://homevlad.ru/templates/billing.php']
                    ],
                    [
                        ['text' => '↩️ В меню', 'callback_data' => 'main_menu']
                    ]
                ]
            ]);

        } catch (PDOException $e) {
            logMessage("Database ERROR in handleBalanceCommand: " . $e->getMessage());
            if ($this->reconnectDatabase()) {
                $this->handleBalanceCommand($chatId);
            } else {
                $this->sendMessage($chatId, "⚠️ Ошибка при получении информации о балансе");
            }
        }
    }

    private function handleDepositCommand($chatId) {
        $this->sendMessage($chatId, "💳 <b>Пополнение баланса</b>\n\nВыберите способ оплаты:", [
            'inline_keyboard' => [
                [
                    ['text' => '🔹 Банковская карта', 'url' => 'https://homevlad.ru/templates/billing.php?method=card'],
                    ['text' => '🔸 Криптовалюта', 'url' => 'https://homevlad.ru/templates/billing.php?method=crypto']
                ],
                [
                    ['text' => '🔙 Назад', 'callback_data' => 'balance'],
                    ['text' => '↩️ В меню', 'callback_data' => 'main_menu']
                ]
            ]
        ]);
    }

    private function handleSupportCommand($chatId) {
        $this->sendMessage($chatId, "🆘 <b>Техническая поддержка</b>\n\nВыберите действие:", [
            'inline_keyboard' => [
                [
                    ['text' => '📨 Создать тикет', 'url' => 'https://homevlad.ru/templates/support.php?action=create'],
                    ['text' => '📋 Мои тикеты', 'url' => 'https://homevlad.ru/templates/support.php?action=list']
                ],
                [
                    ['text' => '📞 Контакты', 'url' => 'https://homevlad.ru/templates/support.php?action=contacts']
                ],
                [
                    ['text' => '🔙 Назад', 'callback_data' => 'main_menu']
                ]
            ]
        ]);
    }

    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'timeout' => 5
            ]
        ]);

        $result = @file_get_contents(
            "https://api.telegram.org/bot{$this->token}/sendMessage",
            false,
            $context
        );

        if ($result === false) {
            logMessage("Failed to send message to chat $chatId");
        }

        return $result;
    }
}

// Конфигурация и запуск
$botToken = 'ваш бот для показа метрик';

try {
    logMessage('=== BOT STARTED ===');
    $dbManager = new DatabaseManager();
    $bot = new TelegramBot($dbManager, $botToken);
    $lastUpdateId = 0;

    while (true) {
        try {
            $response = file_get_contents(
                "https://api.telegram.org/bot{$botToken}/getUpdates?offset=" . ($lastUpdateId + 1),
                false,
                stream_context_create(['http' => ['timeout' => 30]])
            );

            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && $data['ok'] && !empty($data['result'])) {
                    foreach ($data['result'] as $update) {
                        $bot->handleUpdate($update);
                        $lastUpdateId = $update['update_id'];
                    }
                }
            }

            sleep(1);
        } catch (PDOException $e) {
            logMessage("Database ERROR in main loop: " . $e->getMessage());
            sleep(5);
            // Пробуем переподключиться в следующей итерации
            $dbManager = new DatabaseManager();
            $bot = new TelegramBot($dbManager, $botToken);
        } catch (Exception $e) {
            logMessage("Update ERROR: " . $e->getMessage());
            sleep(5);
        }
    }
} catch (Throwable $t) {
    logMessage("FATAL ERROR: " . $t->getMessage());
    exit(1);
}
