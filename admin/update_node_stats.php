<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ssh_functions.php';

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
        SELECT n.id, n.hostname, n.username, n.password, n.node_name, c.name as cluster_name
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

// 3. Получаем предыдущие значения для расчета скорости
$prevStats = [];
try {
    $prevStmt = $pdo->query("
        SELECT node_id, network_rx_bytes, network_tx_bytes, created_at 
        FROM node_stats 
        WHERE node_id IN (".implode(',', array_column($nodes, 'id')).")
        ORDER BY created_at DESC
        LIMIT ".count($nodes)
    );
    $prevStats = $prevStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $log('Ошибка получения предыдущей статистики: '.$e->getMessage());
}

// Преобразуем в удобный формат [node_id => last_stats]
$prevStats = array_reduce($prevStats, function($acc, $item) {
    $acc[$item['node_id']] = $item;
    return $acc;
}, []);

// 4. Обрабатываем каждую ноду
foreach ($nodes as $node) {
    $nodeId = $node['id'];
    $log("Обработка ноды ID: $nodeId (".$node['hostname'].")");
    
    try {
        // Получаем текущую статистику с ноды
        $stats = getNodeStats(
            $node['hostname'],
            $node['username'],
            $node['password']
        );
        
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
        
        // Рассчитываем скорость в Мбит/с
        $rx_mbits = 0;
        $tx_mbits = 0;
        $time_diff = 300; // 5 минут по умолчанию
        
        if (isset($prevStats[$nodeId])) {
            $time_diff = max(1, time() - strtotime($prevStats[$nodeId]['created_at']));
            
            // Рассчитываем скорость (байты → биты → мегабиты → скорость в секунду)
            $rx_diff = $stats['network_rx_bytes'] - $prevStats[$nodeId]['network_rx_bytes'];
            $tx_diff = $stats['network_tx_bytes'] - $prevStats[$nodeId]['network_tx_bytes'];
            
            $rx_mbits = ($rx_diff * 8 / (1024 * 1024)) / $time_diff;
            $tx_mbits = ($tx_diff * 8 / (1024 * 1024)) / $time_diff;
            
            // Ограничиваем разумными значениями
            $rx_mbits = max(0, min($rx_mbits, 100000)); // 100 Гбит/с
            $tx_mbits = max(0, min($tx_mbits, 100000));
            
            $log("Расчет скорости: RX=".round($rx_mbits, 2)." Мбит/с, TX=".round($tx_mbits, 2)." Мбит/с");
        } else {
            $log("Нет предыдущих данных для расчета скорости");
        }
        
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