<?php
// Установим корректное окружение для cron
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

// Логирование
$logFile = '/var/www/homevlad_ru_usr/data/www/homevlad.ru/logs/proxmox_metrics.log';
file_put_contents($logFile, "\n[".date('Y-m-d H:i:s')."] CRON JOB STARTED\n", FILE_APPEND);

function log_message($message) {
    global $logFile;
    file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL, FILE_APPEND);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    // Получаем ВМ с сортировкой по ID
    $stmt = $pdo->query("SELECT vm_id, node_id FROM vms WHERE vm_type = 'qemu' AND status = 'running' ORDER BY vm_id");
    $vms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vms as $index => $vm) {
        try {
            log_message("Processing VM {$vm['vm_id']}");
            
            $nodeStmt = $pdo->prepare("SELECT * FROM proxmox_nodes WHERE id = ?");
            $nodeStmt->execute([$vm['node_id']]);
            $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$node) {
                log_message("Node not found for VM {$vm['vm_id']}");
                continue;
            }

            $proxmoxApi = new ProxmoxAPI(
                $node['hostname'],
                $node['username'],
                $node['password'],
                22,
                $node['node_name'],
                $node['id'],
                $pdo
            );

            // Добавляем задержку для первой ВМ в cron
            if ($index === 0 && php_sapi_name() === 'cli' && isset($_SERVER['TERM']) === false) {
                sleep(5);
                log_message("Added initial delay for first VM");
            }

            $vmInfo = $proxmoxApi->getVMStatus($vm['vm_id']);
            $rrdData = $proxmoxApi->getRRDData($vm['vm_id'], 'hour');
            
            log_message("VM {$vm['vm_id']} raw data: ".json_encode(end($rrdData)));

            if (empty($rrdData)) {
                log_message("No RRD data for VM {$vm['vm_id']}");
                continue;
            }

            $lastPoint = end($rrdData);
            
            // Проверка данных перед записью
            if ($lastPoint['cpu'] == 0 && $lastPoint['mem'] == 0) {
                log_message("Suspicious zero values for VM {$vm['vm_id']}, retrying...");
                sleep(2);
                $rrdData = $proxmoxApi->getRRDData($vm['vm_id'], 'hour');
                $lastPoint = end($rrdData);
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO vm_metrics 
                (vm_id, timestamp, cpu_usage, mem_usage, mem_total, net_in, net_out, disk_read, disk_write)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                cpu_usage = VALUES(cpu_usage),
                mem_usage = VALUES(mem_usage),
                net_in = VALUES(net_in),
                net_out = VALUES(net_out),
                disk_read = VALUES(disk_read),
                disk_write = VALUES(disk_write)
            ");
            
            $insertStmt->execute([
                $vm['vm_id'],
                round($lastPoint['cpu'] * 100, 2),
                round($lastPoint['mem'] / (1024 * 1024 * 1024), 2),
                round($vmInfo['maxmem'] / (1024 * 1024 * 1024), 2),
                round(($lastPoint['netin'] * 8) / (1024 * 1024), 2),
                round(($lastPoint['netout'] * 8) / (1024 * 1024), 2),
                round($lastPoint['diskread'] / 1024, 2),
                round($lastPoint['diskwrite'] / 1024, 2)
            ]);

            log_message("Successfully updated VM {$vm['vm_id']} metrics");

        } catch (Exception $e) {
            log_message("ERROR VM {$vm['vm_id']}: ".$e->getMessage());
        }
    }

} catch (Exception $e) {
    log_message("FATAL ERROR: ".$e->getMessage());
    exit(1);
}