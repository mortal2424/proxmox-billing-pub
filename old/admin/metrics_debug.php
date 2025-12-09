<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

checkAuth();
//checkAdminAccess(); // Добавьте эту функцию в auth.php для ограничения доступа

$db = new Database();
$pdo = $db->getConnection();

// Получаем последние 10 записей из логов
$logs = $pdo->query("SELECT * FROM metrics_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();

// Получаем статистику по VM
$vmStats = $pdo->query("
    SELECT 
        COUNT(*) as total_vms,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_vms,
        COUNT(DISTINCT vm_id) as monitored_vms
    FROM vms 
    WHERE vm_type = 'qemu'
")->fetch();

// Получаем статистику по LXC
$lxcStats = $pdo->query("
    SELECT 
        COUNT(*) as total_containers,
        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_containers,
        COUNT(DISTINCT vm_id) as monitored_containers
    FROM vms 
    WHERE vm_type = 'lxc'
")->fetch();

// Получаем последние метрики
$lastVMMetrics = $pdo->query("SELECT * FROM vm_metrics ORDER BY timestamp DESC LIMIT 5")->fetchAll();
$lastLXCMetrics = $pdo->query("SELECT * FROM lxc_metrics ORDER BY timestamp DESC LIMIT 5")->fetchAll();

// Проверяем состояние cron-задач
$cronStatus = [
    'vm_metrics' => [
        'last_run' => $pdo->query("SELECT MAX(timestamp) as last_run FROM vm_metrics")->fetchColumn(),
        'count' => $pdo->query("SELECT COUNT(*) FROM vm_metrics")->fetchColumn()
    ],
    'lxc_metrics' => [
        'last_run' => $pdo->query("SELECT MAX(timestamp) as last_run FROM lxc_metrics")->fetchColumn(),
        'count' => $pdo->query("SELECT COUNT(*) FROM lxc_metrics")->fetchColumn()
    ]
];

// Функция для тестового сбора метрик
if (isset($_GET['test_collect'])) {
    $type = $_GET['type'] ?? 'vm';
    $testResults = testMetricsCollection($type);
}

function testMetricsCollection($type) {
    global $pdo;
    
    $results = [];
    $table = $type === 'vm' ? 'vms' : 'vms';
    $vmType = $type === 'vm' ? 'qemu' : 'lxc';
    
    $vms = $pdo->query("SELECT vm_id, node_id FROM $table WHERE vm_type = '$vmType' AND status = 'running' LIMIT 1")->fetchAll();
    
    foreach ($vms as $vm) {
        $node = $pdo->query("SELECT * FROM proxmox_nodes WHERE id = {$vm['node_id']}")->fetch();
        
        $proxmoxApi = new ProxmoxAPI(
            $node['hostname'],
            $node['username'],
            $node['password'],
            22,
            $node['node_name'],
            $node['id'],
            $pdo
        );
        
        try {
            // Получаем данные от Proxmox
            if ($type === 'vm') {
                $info = $proxmoxApi->getVMStatus($vm['vm_id']);
                $rrdData = $proxmoxApi->getRRDData($vm['vm_id'], 'hour');
            } else {
                $info = $proxmoxApi->getLXCStatusMetric($vm['vm_id']);
                $rrdData = $proxmoxApi->getLxcRRDData($vm['vm_id'], 'hour');
            }
            
            $lastPoint = end($rrdData);
            
            $results[$vm['vm_id']] = [
                'proxmox_data' => $lastPoint,
                'db_record' => null
            ];
            
            // Тестовая запись в БД
            if ($type === 'vm') {
                $stmt = $pdo->prepare("INSERT INTO vm_metrics 
                    (vm_id, timestamp, cpu_usage, mem_usage, mem_total, net_in, net_out, disk_read, disk_write)
                    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
            } else {
                $stmt = $pdo->prepare("INSERT INTO lxc_metrics 
                    (vm_id, timestamp, cpu_usage, mem_usage, mem_total, net_in, net_out, disk_read, disk_write)
                    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
            }
            
            $stmt->execute([
                $vm['vm_id'],
                round($lastPoint['cpu'] * 100, 2),
                round($lastPoint['mem'] / (1024 * 1024 * 1024), 2),
                round($info['maxmem'] / (1024 * 1024 * 1024), 2),
                round(($lastPoint['netin'] * 8) / (1024 * 1024), 2),
                round(($lastPoint['netout'] * 8) / (1024 * 1024), 2),
                round($lastPoint['diskread'] / 1024, 2),
                round($lastPoint['diskwrite'] / 1024, 2)
            ]);
            
            $results[$vm['vm_id']]['db_record'] = $pdo->query("SELECT * FROM ".($type === 'vm' ? 'vm_metrics' : 'lxc_metrics')." WHERE vm_id = {$vm['vm_id']} ORDER BY id DESC LIMIT 1")->fetch();
            
        } catch (Exception $e) {
            $results[$vm['vm_id']]['error'] = $e->getMessage();
        }
    }
    
    return $results;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Metrics Collection</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #fff; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f5f5f5; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .btn { display: inline-block; padding: 8px 15px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
        .btn:hover { background: #2980b9; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
        .warning { color: #f39c12; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-bug"></i> Debug Metrics Collection</h1>
        
        <div class="card">
            <h2>Test Metrics Collection</h2>
            <p>
                <a href="?test_collect=1&type=vm" class="btn"><i class="fas fa-server"></i> Test VM Collection</a>
                <a href="?test_collect=1&type=lxc" class="btn"><i class="fas fa-box"></i> Test LXC Collection</a>
            </p>
            
            <?php if (isset($testResults)): ?>
                <h3>Test Results:</h3>
                <?php foreach ($testResults as $vmId => $result): ?>
                    <div class="card">
                        <h4>VM/CT ID: <?= $vmId ?></h4>
                        
                        <?php if (isset($result['error'])): ?>
                            <p class="error"><i class="fas fa-times-circle"></i> Error: <?= $result['error'] ?></p>
                        <?php else: ?>
                            <div style="display: flex; gap: 20px;">
                                <div style="flex: 1;">
                                    <h5>Data from Proxmox API:</h5>
                                    <pre><?= json_encode($result['proxmox_data'], JSON_PRETTY_PRINT) ?></pre>
                                </div>
                                <div style="flex: 1;">
                                    <h5>Record in Database:</h5>
                                    <pre><?= json_encode($result['db_record'], JSON_PRETTY_PRINT) ?></pre>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>Current Status</h2>
            
            <h3>VM Statistics</h3>
            <table>
                <tr>
                    <th>Total VMs</th>
                    <th>Running VMs</th>
                    <th>Monitored VMs</th>
                </tr>
                <tr>
                    <td><?= $vmStats['total_vms'] ?></td>
                    <td><?= $vmStats['running_vms'] ?></td>
                    <td><?= $vmStats['monitored_vms'] ?></td>
                </tr>
            </table>
            
            <h3>LXC Statistics</h3>
            <table>
                <tr>
                    <th>Total Containers</th>
                    <th>Running Containers</th>
                    <th>Monitored Containers</th>
                </tr>
                <tr>
                    <td><?= $lxcStats['total_containers'] ?></td>
                    <td><?= $lxcStats['running_containers'] ?></td>
                    <td><?= $lxcStats['monitored_containers'] ?></td>
                </tr>
            </table>
            
            <h3>Cron Jobs Status</h3>
            <table>
                <tr>
                    <th>Job</th>
                    <th>Last Run</th>
                    <th>Records Count</th>
                </tr>
                <tr>
                    <td>VM Metrics</td>
                    <td><?= $cronStatus['vm_metrics']['last_run'] ?: 'Never' ?></td>
                    <td><?= $cronStatus['vm_metrics']['count'] ?></td>
                </tr>
                <tr>
                    <td>LXC Metrics</td>
                    <td><?= $cronStatus['lxc_metrics']['last_run'] ?: 'Never' ?></td>
                    <td><?= $cronStatus['lxc_metrics']['count'] ?></td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2>Recent Metrics</h2>
            
            <h3>Last VM Metrics</h3>
            <table>
                <tr>
                    <th>VM ID</th>
                    <th>Timestamp</th>
                    <th>CPU %</th>
                    <th>Mem Usage</th>
                    <th>Net In</th>
                    <th>Net Out</th>
                </tr>
                <?php foreach ($lastVMMetrics as $metric): ?>
                <tr>
                    <td><?= $metric['vm_id'] ?></td>
                    <td><?= $metric['timestamp'] ?></td>
                    <td><?= $metric['cpu_usage'] ?>%</td>
                    <td><?= round($metric['mem_usage'], 2) ?>/<?= round($metric['mem_total'], 2) ?> GB</td>
                    <td><?= round($metric['net_in'], 2) ?> Mbit/s</td>
                    <td><?= round($metric['net_out'], 2) ?> Mbit/s</td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <h3>Last LXC Metrics</h3>
            <table>
                <tr>
                    <th>CT ID</th>
                    <th>Timestamp</th>
                    <th>CPU %</th>
                    <th>Mem Usage</th>
                    <th>Net In</th>
                    <th>Net Out</th>
                </tr>
                <?php foreach ($lastLXCMetrics as $metric): ?>
                <tr>
                    <td><?= $metric['vm_id'] ?></td>
                    <td><?= $metric['timestamp'] ?></td>
                    <td><?= $metric['cpu_usage'] ?>%</td>
                    <td><?= round($metric['mem_usage'], 2) ?>/<?= round($metric['mem_total'], 2) ?> GB</td>
                    <td><?= round($metric['net_in'], 2) ?> Mbit/s</td>
                    <td><?= round($metric['net_out'], 2) ?> Mbit/s</td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="card">
            <h2>Recent Logs</h2>
            <table>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>Message</th>
                </tr>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= $log['created_at'] ?></td>
                    <td><?= $log['type'] ?></td>
                    <td><?= $log['message'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</body>
</html>