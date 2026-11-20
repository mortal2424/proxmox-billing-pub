<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/node_credentials.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_api_info.php';

// Настройка логирования
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/../logs/node_stats_update.log');
error_reporting(E_ALL);

$log = function($message) {
    error_log('['.date('Y-m-d H:i:s').'] '.$message);
};

$db = new Database();
$pdo = $db->getConnection();

$log('=== Начало обновления статистики нод ===');

// 1. Сначала проверим существование колонок
try {
    $columns = $pdo->query("SHOW COLUMNS FROM node_stats LIKE 'network_rx_mbits'")->fetch();
    if (!$columns) {
        $pdo->exec("ALTER TABLE node_stats
                   ADD COLUMN network_rx_mbits DECIMAL(10,2) DEFAULT 0,
                   ADD COLUMN network_tx_mbits DECIMAL(10,2) DEFAULT 0");
        $log('Добавлены новые колонки для хранения скорости сети');
    }
} catch (PDOException $e) {
    $log('Ошибка проверки/добавления колонок: '.$e->getMessage());
}

// 2. Получаем все активные ноды с информацией о кластере
try {
    $nodes = $pdo->query("
        SELECT n.*, c.name as cluster_name
        FROM proxmox_nodes n
        JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($nodes)) {
        $log('Нет активных нод для обновления');
        exit(0);
    }

    $log('Найдено активных нод: '.count($nodes));
} catch (PDOException $e) {
    $log('Ошибка при получении нод: '.$e->getMessage());
    exit(1);
}

// 4. Обрабатываем каждую ноду
foreach ($nodes as $node) {
    $nodeId = $node['id'];
    $log("Обработка ноды ID: $nodeId (".$node['hostname'].")");

    try {
        // Получаем текущую статистику с ноды
        $stats = panelNodeApiInfo($node)['stats'];

        if (isset($stats['error'])) {
            $log("Ошибка получения статистики: ".$stats['error']);
            continue;
        }

        // Проверяем наличие всех обязательных полей
        $required = ['cpu_usage', 'ram_usage', 'ram_total', 'network_rx_bytes', 'network_tx_bytes'];
        foreach ($required as $field) {
            if (!isset($stats[$field])) {
                $log("Отсутствует обязательное поле $field в статистике");
                continue 2;
            }
        }

        $rx_mbits = $stats['network_rx_mbits'];
        $tx_mbits = $stats['network_tx_mbits'];

        // Сохраняем в базу
        $stmt = $pdo->prepare("
            INSERT INTO node_stats
            (node_id, cluster_name, node_name, cpu_usage, ram_usage, ram_total,
             network_rx_bytes, network_tx_bytes, network_rx_mbits, network_tx_mbits, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $nodeId,
            $node['cluster_name'],
            $node['node_name'],
            round($stats['cpu_usage'], 2),
            round($stats['ram_usage'], 2),
            $stats['ram_total'],
            $stats['network_rx_bytes'],
            $stats['network_tx_bytes'],
            round($rx_mbits, 2),
            round($tx_mbits, 2)
        ]);

        if (!$result) {
            $error = $stmt->errorInfo();
            $log("Ошибка сохранения: ".$error[2]);
        } else {
            $log("Данные успешно сохранены (RX: ".round($rx_mbits, 2)." Мбит/с, TX: ".round($tx_mbits, 2)." Мбит/с)");
        }

    } catch (Exception $e) {
        $log("Ошибка обработки ноды: ".$e->getMessage());
    }
}

$log('=== Обновление статистики завершено ===');
exit(0);

function execSSHCommand($connection, $command) {
    $stream = @ssh2_exec($connection, $command);
    if (!$stream) {
        throw new Exception("Не удалось выполнить команду: $command");
    }

    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);

    if ($output === false) {
        throw new Exception("Ошибка чтения вывода команды");
    }

    return trim($output);
}
