<?php
// Получаем информацию о системе
$system_info = [];
$system_version = '2.5.1'; // Версия по умолчанию

try {
    require_once '../includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();

    // Получаем текущую версию системы из базы данных
    $table_exists = safeQuery($pdo, "SHOW TABLES LIKE 'system_versions'")->rowCount() > 0;

    if ($table_exists) {
        // Получаем последнюю версию из таблицы
        $stmt = $pdo->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1");
        if ($stmt->rowCount() > 0) {
            $system_version = $stmt->fetchColumn();
        }
    }

    // Время работы системы - время выполнения скрипта
    $start_time = $_SERVER['REQUEST_TIME_FLOAT'] ?? time();
    $system_info['uptime'] = formatUptime($start_time);

    // Загрузка системы (load average)
    $load = sys_getloadavg();
    $system_info['load'] = $load ? implode(', ', array_map(function($v) { return round($v, 2); }, $load)) : 'Недоступно';

    // Использование памяти PHP
    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    $system_info['memory_usage'] = formatBytes($memory_usage);
    $system_info['memory_peak'] = formatBytes($memory_peak);

    // Получаем информацию о CPU
    $system_info['cpu_info'] = getCpuInfo();

    // Получаем информацию о памяти сервера
    $system_info['server_memory'] = getServerMemoryInfo();

    // Получаем информацию о дисковом пространстве
    $system_info['disk_usage'] = getDiskUsage();

    // Общее количество пользователей
    $total_users = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['total_users'] = $total_users;

    // Статистика ВМ
    $running_vms = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status = 'running'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $total_vms = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status != 'deleted'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['running_vms'] = $running_vms;
    $system_info['total_vms'] = $total_vms;

    // Статистика тикетов
    $open_tickets = $pdo->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['open_tickets'] = $open_tickets;

} catch (Exception $e) {
    error_log("Error loading system info: " . $e->getMessage());
}

// Функция для форматирования времени работы
function formatUptime($timestamp) {
    $diff = microtime(true) - $timestamp;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $seconds = floor($diff % 60);

    if ($hours > 0) {
        return $hours . ' ч. ' . $minutes . ' мин. ' . $seconds . ' сек.';
    } elseif ($minutes > 0) {
        return $minutes . ' мин. ' . $seconds . ' сек.';
    } else {
        return $seconds . ' сек.';
    }
}

// Функция для форматирования байтов
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Функция для получения информации о CPU
function getCpuInfo() {
    $cpuInfo = [
        'model' => 'Недоступно',
        'cores' => 'Недоступно',
        'usage' => 'Недоступно'
    ];

    // Получаем количество ядер CPU
    if (function_exists('shell_exec')) {
        // Для Linux
        if (PHP_OS_FAMILY === 'Linux') {
            // Модель процессора
            $cpuModel = @shell_exec('grep -m 1 "model name" /proc/cpuinfo | cut -d ":" -f 2');
            if ($cpuModel) {
                $cpuInfo['model'] = trim($cpuModel);
            }

            // Количество ядер
            $cpuCores = @shell_exec('grep -c "^processor" /proc/cpuinfo');
            if ($cpuCores) {
                $cpuInfo['cores'] = (int)trim($cpuCores) . ' ядер';
            }

            // Загрузка CPU (процент) - упрощенный метод
            if ($cpuCores) {
                // Используем load average для оценки
                $load = sys_getloadavg();
                if ($load) {
                    $cpuInfo['usage'] = round(($load[0] / max(1, (int)trim($cpuCores))) * 100, 1) . '%';
                }
            }
        }
        // Для Windows
        elseif (PHP_OS_FAMILY === 'Windows') {
            // Модель процессора
            $cpuModel = @shell_exec('wmic cpu get name /value');
            if ($cpuModel && preg_match('/Name=(.+)/', $cpuModel, $matches)) {
                $cpuInfo['model'] = trim($matches[1]);
            }

            // Количество ядер
            $cpuCores = @shell_exec('wmic cpu get NumberOfCores /value');
            if ($cpuCores && preg_match('/NumberOfCores=(\d+)/', $cpuCores, $matches)) {
                $cpuInfo['cores'] = (int)$matches[1] . ' ядер';
            }
        }
    }

    // Альтернативный способ получения количества ядер
    if ($cpuInfo['cores'] === 'Недоступно') {
        $cores = function_exists('shell_exec') ? @shell_exec('nproc') : 1;
        if ($cores) {
            $cpuInfo['cores'] = (int)trim($cores) . ' ядер';
        } else {
            $cpuInfo['cores'] = '1 ядро';
        }
    }

    return $cpuInfo;
}

// Функция для получения информации о памяти сервера
function getServerMemoryInfo() {
    $memoryInfo = [
        'total' => 'Недоступно',
        'used' => 'Недоступно',
        'free' => 'Недоступно',
        'percent' => 'Недоступно'
    ];

    if (PHP_OS_FAMILY === 'Linux') {
        // Для Linux читаем /proc/meminfo
        if (is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');

            // Получаем общую память
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $matches)) {
                $memoryInfo['total'] = formatBytes($matches[1] * 1024);
            }

            // Получаем свободную память
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $matches)) {
                $memoryInfo['free'] = formatBytes($matches[1] * 1024);

                // Если есть общая и свободная, вычисляем использованную
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $totalMatches)) {
                    $total = $totalMatches[1] * 1024;
                    $free = $matches[1] * 1024;
                    $used = $total - $free;
                    $memoryInfo['used'] = formatBytes($used);
                    $memoryInfo['percent'] = round(($used / $total) * 100, 1) . '%';
                }
            }
        }
    } elseif (PHP_OS_FAMILY === 'Windows') {
        // Для Windows используем wmic
        if (function_exists('shell_exec')) {
            $memory = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value');
            if ($memory && preg_match('/TotalVisibleMemorySize=(\d+)/', $memory, $totalMatches) &&
                preg_match('/FreePhysicalMemory=(\d+)/', $memory, $freeMatches)) {
                $total = $totalMatches[1] * 1024; // Кибибайты в байты
                $free = $freeMatches[1] * 1024;
                $used = $total - $free;

                $memoryInfo['total'] = formatBytes($total);
                $memoryInfo['free'] = formatBytes($free);
                $memoryInfo['used'] = formatBytes($used);
                $memoryInfo['percent'] = round(($used / $total) * 100, 1) . '%';
            }
        }
    }

    // Если данные недоступны, пробуем через memory_limit из php.ini
    if ($memoryInfo['total'] === 'Недоступно') {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit) {
            $memoryInfo['total'] = $memoryLimit . ' (limit)';
        }
    }

    return $memoryInfo;
}

// Функция для получения информации о дисковом пространстве
function getDiskUsage() {
    $diskInfo = [
        'total' => 'Недоступно',
        'used' => 'Недоступно',
        'free' => 'Недоступно',
        'percent' => 'Недоступно'
    ];

    // Получаем информацию о корневом разделе
    if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');

        if ($total !== false && $free !== false) {
            $used = $total - $free;

            $diskInfo['total'] = formatBytes($total);
            $diskInfo['free'] = formatBytes($free);
            $diskInfo['used'] = formatBytes($used);
            $diskInfo['percent'] = round(($used / $total) * 100, 1) . '%';
        }
    }

    // Альтернативный способ для Windows
    if ($diskInfo['total'] === 'Недоступно' && PHP_OS_FAMILY === 'Windows') {
        if (function_exists('shell_exec')) {
            $disk = @shell_exec('wmic logicaldisk where "DeviceID=\'C:\'" get Size,FreeSpace /value');
            if ($disk && preg_match('/Size=(\d+)/', $disk, $totalMatches) &&
                preg_match('/FreeSpace=(\d+)/', $disk, $freeMatches)) {
                $total = $totalMatches[1];
                $free = $freeMatches[1];
                $used = $total - $free;

                $diskInfo['total'] = formatBytes($total);
                $diskInfo['free'] = formatBytes($free);
                $diskInfo['used'] = formatBytes($used);
                $diskInfo['percent'] = round(($used / $total) * 100, 1) . '%';
            }
        }
    }

    return $diskInfo;
}
?>

<style>
/* ========== ФУТЕР ========== */
.admin-footer {
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding: 20px 0;
    margin-top: 40px;
    position: relative;
    overflow: hidden;
}

.admin-footer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #00bcd4, #0097a7);
    opacity: 0.5;
}

.footer-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Информационная панель */
.footer-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    transition: all 0.3s ease;
}

.stat-item:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(0, 188, 212, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 188, 212, 0.1);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-icon.uptime { background: rgba(0, 188, 212, 0.1); color: #00bcd4; }
.stat-icon.load { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.stat-icon.memory { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
.stat-icon.users { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
.stat-icon.server { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.stat-icon.vms { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
.stat-icon.cpu { background: rgba(255, 107, 107, 0.1); color: #ff6b6b; }
.stat-icon.ram { background: rgba(72, 187, 120, 0.1); color: #48bb78; }
.stat-icon.disk { background: rgba(128, 90, 213, 0.1); color: #805ad5; }

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-label {
    font-size: 12px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 14px;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Нижняя часть футера */
.footer-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.footer-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.footer-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: white;
}

.footer-logo-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.footer-logo-text {
    font-size: 16px;
    font-weight: 700;
}

.copyright {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.copyright a {
    color: #00bcd4;
    text-decoration: none;
    transition: color 0.3s ease;
}

.copyright a:hover {
    color: #0097a7;
    text-decoration: underline;
}

.version-info {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
    color: #00bcd4;
}

.footer-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.footer-links {
    display: flex;
    gap: 15px;
}

.footer-link {
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-size: 12px;
    transition: all 0.3s ease;
    padding: 6px 12px;
    border-radius: 6px;
}

.footer-link:hover {
    color: white;
    background: rgba(255, 255, 255, 0.05);
}

.system-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 6px;
}

.status-indicator {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-text {
    font-size: 12px;
    font-weight: 600;
    color: #10b981;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
}

/* Быстрые действия в футере */
.footer-quick-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 11px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.quick-action-btn:hover {
    background: rgba(0, 188, 212, 0.1);
    border-color: rgba(0, 188, 212, 0.3);
    color: #00bcd4;
    transform: translateY(-1px);
}

/* Адаптивность */
@media (max-width: 768px) {
    .footer-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .footer-bottom {
        flex-direction: column;
        text-align: center;
    }

    .footer-left, .footer-right {
        flex-direction: column;
        width: 100%;
    }

    .footer-links {
        justify-content: center;
        flex-wrap: wrap;
    }

    .footer-quick-actions {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .footer-stats {
        grid-template-columns: 1fr;
    }

    .stat-item {
        padding: 12px;
    }
}

/* Progress bar для показателей */
.progress-bar {
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
    margin-top: 5px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease;
}

.progress-fill.low { background: #10b981; }
.progress-fill.medium { background: #f59e0b; }
.progress-fill.high { background: #ef4444; }
</style>

<!-- Футер -->
<footer class="admin-footer">
    <div class="footer-content">
        <!-- Статистика системы -->
        <div class="footer-stats">
            <div class="stat-item">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Пользователи</div>
                    <div class="stat-value">
                        <?= $system_info['total_users'] ?> всего
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon vms">
                    <i class="fas fa-server"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Вирт. машины</div>
                    <div class="stat-value">
                        <?= $system_info['running_vms'] ?> запущ. / <?= $system_info['total_vms'] ?> всего
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon load">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Загрузка системы</div>
                    <div class="stat-value"><?= htmlspecialchars($system_info['load']) ?></div>
                    <div class="progress-bar">
                        <?php
                        $loadPercent = 0;
                        if ($system_info['load'] !== 'Недоступно') {
                            $loads = explode(', ', $system_info['load']);
                            if (isset($loads[0])) {
                                $load1 = floatval($loads[0]);
                                $cpuCores = 1;
                                if (preg_match('/(\d+)\s+ядер/', $system_info['cpu_info']['cores'], $matches)) {
                                    $cpuCores = (int)$matches[1];
                                }
                                $loadPercent = min(100, ($load1 / max(1, $cpuCores)) * 100);
                            }
                        }
                        $progressClass = $loadPercent < 50 ? 'low' : ($loadPercent < 80 ? 'medium' : 'high');
                        ?>
                        <div class="progress-fill <?= $progressClass ?>" style="width: <?= $loadPercent ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon memory">
                    <i class="fas fa-memory"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Память PHP</div>
                    <div class="stat-value"><?= $system_info['memory_usage'] ?> / <?= $system_info['memory_peak'] ?></div>
                    <div class="progress-bar">
                        <?php
                        $phpMemPercent = 0;
                        if (preg_match('/([\d\.]+)\s+(\w+)/', $system_info['memory_usage'], $matchesUsage) &&
                            preg_match('/([\d\.]+)\s+(\w+)/', $system_info['memory_peak'], $matchesPeak)) {
                            $units = ['B' => 1, 'KB' => 1024, 'MB' => 1024*1024, 'GB' => 1024*1024*1024];
                            $usage = $matchesUsage[1] * ($units[$matchesUsage[2]] ?? 1);
                            $peak = $matchesPeak[1] * ($units[$matchesPeak[2]] ?? 1);
                            $phpMemPercent = $peak > 0 ? ($usage / $peak) * 100 : 0;
                        }
                        $progressClass = $phpMemPercent < 50 ? 'low' : ($phpMemPercent < 80 ? 'medium' : 'high');
                        ?>
                        <div class="progress-fill <?= $progressClass ?>" style="width: <?= $phpMemPercent ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon cpu">
                    <i class="fas fa-microchip"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Процессор</div>
                    <div class="stat-value">
                        <?= htmlspecialchars($system_info['cpu_info']['usage']) ?>
                        (<?= htmlspecialchars($system_info['cpu_info']['cores']) ?>)
                    </div>
                    <div class="progress-bar">
                        <?php
                        $cpuPercent = 0;
                        if (preg_match('/([\d\.]+)%/', $system_info['cpu_info']['usage'], $matches)) {
                            $cpuPercent = (float)$matches[1];
                        }
                        $progressClass = $cpuPercent < 50 ? 'low' : ($cpuPercent < 80 ? 'medium' : 'high');
                        ?>
                        <div class="progress-fill <?= $progressClass ?>" style="width: <?= $cpuPercent ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon ram">
                    <i class="fas fa-memory"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Память сервера</div>
                    <div class="stat-value">
                        <?= $system_info['server_memory']['percent'] !== 'Недоступно' ?
                            $system_info['server_memory']['percent'] . ' (' . $system_info['server_memory']['used'] . ')' :
                            'Недоступно' ?>
                    </div>
                    <div class="progress-bar">
                        <?php
                        $ramPercent = 0;
                        if (preg_match('/([\d\.]+)%/', $system_info['server_memory']['percent'], $matches)) {
                            $ramPercent = (float)$matches[1];
                            $progressClass = $ramPercent < 50 ? 'low' : ($ramPercent < 80 ? 'medium' : 'high');
                            ?>
                            <div class="progress-fill <?= $progressClass ?>" style="width: <?= $ramPercent ?>%"></div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon disk">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Диск сервера</div>
                    <div class="stat-value">
                        <?= $system_info['disk_usage']['percent'] !== 'Недоступно' ?
                            $system_info['disk_usage']['percent'] . ' (' . $system_info['disk_usage']['used'] . ')' :
                            'Недоступно' ?>
                    </div>
                    <div class="progress-bar">
                        <?php
                        $diskPercent = 0;
                        if (preg_match('/([\d\.]+)%/', $system_info['disk_usage']['percent'], $matches)) {
                            $diskPercent = (float)$matches[1];
                            $progressClass = $diskPercent < 50 ? 'low' : ($diskPercent < 80 ? 'medium' : 'high');
                            ?>
                            <div class="progress-fill <?= $progressClass ?>" style="width: <?= $diskPercent ?>%"></div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon server">
                    <i class="fas fa-code-branch"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Версия системы</div>
                    <div class="stat-value">v<?= htmlspecialchars($system_version) ?></div>
                </div>
            </div>
        </div>

        <!-- Быстрые действия -->
        <div class="footer-quick-actions">
            <a href="/admin/logs.php" class="quick-action-btn">
                <i class="fas fa-clipboard-list"></i>
                <span>Системные логи</span>
            </a>
            <a href="/admin/backup.php" class="quick-action-btn">
                <i class="fas fa-database"></i>
                <span>Резервное копирование</span>
            </a>
            <a href="/admin/monitoring.php" class="quick-action-btn">
                <i class="fas fa-chart-bar"></i>
                <span>Мониторинг</span>
            </a>
            <a href="/admin/settings.php" class="quick-action-btn">
                <i class="fas fa-cog"></i>
                <span>Настройки системы</span>
            </a>
            <a href="/admin/help.php" class="quick-action-btn">
                <i class="fas fa-question-circle"></i>
                <span>Помощь</span>
            </a>
            <?php if ($system_info['open_tickets'] > 0): ?>
            <a href="/admin/ticket.php" class="quick-action-btn" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); color: #f59e0b;">
                <i class="fas fa-ticket-alt"></i>
                <span>Тикеты (<?= $system_info['open_tickets'] ?>)</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Нижняя часть -->
        <div class="footer-bottom">
            <div class="footer-left">
                <a href="/admin/" class="footer-logo">
                    <div class="footer-logo-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <span class="footer-logo-text">HomeVlad Cloud Admin</span>
                </a>

                <div class="copyright">
                    &copy; <?= date('Y') ?> <a href="/">HomeVlad Cloud</a>.
                    <span id="currentYear"><?= date('Y') ?></span>.
                    Админ панель <span class="version-info">v<?= htmlspecialchars($system_version) ?></span>
                    <br>
                    <small style="color: rgba(255, 255, 255, 0.5);">
                        Время генерации: <span id="pageGenTime"><?= round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) ?> сек.</span>
                        | PHP <?= PHP_VERSION ?> | ОС: <?= PHP_OS ?>
                    </small>
                </div>
            </div>

            <div class="footer-right">
                <div class="footer-links">
                    <a href="/admin/privacy.php" class="footer-link">Конфиденциальность</a>
                    <a href="/admin/terms.php" class="footer-link">Условия</a>
                    <a href="/admin/status.php" class="footer-link">Статус</a>
                    <a href="/admin/contact.php" class="footer-link">Контакты</a>
                </div>

                <div class="system-status">
                    <span class="status-indicator"></span>
                    <span class="status-text">Система онлайн</span>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Основные скрипты -->
<script src="/admin/js/admin_scripts.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обновление статистики в реальном времени
    function updateSystemStats() {
        fetch('/admin/ajax/system_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем загрузку системы
                    updateElement('loadStat', data.load || 'Недоступно');

                    // Обновляем использование CPU
                    if (data.cpu) {
                        const cpuElement = document.querySelector('.stat-item:nth-child(5) .stat-value');
                        if (cpuElement) {
                            cpuElement.textContent = data.cpu.usage + ' (' + data.cpu.cores + ')';
                        }
                        if (data.cpu.percent !== undefined) {
                            updateProgressBar('.stat-item:nth-child(5)', data.cpu.percent);
                        }
                    }

                    // Обновляем использование памяти сервера
                    if (data.server_memory) {
                        const ramElement = document.querySelector('.stat-item:nth-child(6) .stat-value');
                        if (ramElement) {
                            ramElement.textContent = data.server_memory.percent + ' (' + data.server_memory.used + ')';
                        }
                        if (data.server_memory.percent_value !== undefined) {
                            updateProgressBar('.stat-item:nth-child(6)', data.server_memory.percent_value);
                        }
                    }

                    // Обновляем использование диска
                    if (data.disk) {
                        const diskElement = document.querySelector('.stat-item:nth-child(7) .stat-value');
                        if (diskElement) {
                            diskElement.textContent = data.disk.percent + ' (' + data.disk.used + ')';
                        }
                        if (data.disk.percent_value !== undefined) {
                            updateProgressBar('.stat-item:nth-child(7)', data.disk.percent_value);
                        }
                    }

                    // Обновляем версию системы
                    if (data.version) {
                        const versionElements = document.querySelectorAll('.stat-item:nth-child(8) .stat-value, .version-info');
                        versionElements.forEach(el => {
                            el.textContent = 'v' + data.version;
                        });
                    }

                    // Обновляем время сессии
                    const sessionTime = calculateSessionTime(data.session_start);
                    updateElement('uptimeStat', sessionTime);

                    // Обновляем статус системы
                    updateSystemStatus(data.system_status);

                    // Обновляем время генерации страницы если есть
                    if (data.page_gen_time) {
                        updateElement('pageGenTime', data.page_gen_time.toFixed(3) + ' сек.');
                    }
                }
            })
            .catch(error => console.error('Ошибка загрузки статистики:', error));
    }

    function calculateSessionTime(sessionStart) {
        if (!sessionStart) return 'Недоступно';

        const start = new Date(sessionStart).getTime();
        const now = new Date().getTime();
        const diff = now - start;

        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        if (hours > 0) {
            return hours + ' ч. ' + minutes + ' мин. ' + seconds + ' сек.';
        } else if (minutes > 0) {
            return minutes + ' мин. ' + seconds + ' сек.';
        } else {
            return seconds + ' сек.';
        }
    }

    function updateElement(elementId, value) {
        const element = document.getElementById(elementId);
        if (element && element.textContent !== value) {
            // Анимация изменения значения
            element.style.transform = 'scale(1.1)';
            element.style.color = '#00bcd4';
            element.textContent = value;

            setTimeout(() => {
                element.style.transform = 'scale(1)';
                element.style.color = '';
            }, 300);
        }
    }

    function updateProgressBar(selector, percent) {
        const statItem = document.querySelector(selector);
        if (!statItem) return;

        const progressFill = statItem.querySelector('.progress-fill');
        if (progressFill) {
            let progressClass = 'low';
            if (percent >= 80) progressClass = 'high';
            else if (percent >= 50) progressClass = 'medium';

            progressFill.className = `progress-fill ${progressClass}`;
            progressFill.style.width = Math.min(100, percent) + '%';
        }
    }

    function updateSystemStatus(status) {
        const statusIndicator = document.querySelector('.status-indicator');
        const statusText = document.querySelector('.status-text');
        const systemStatus = document.querySelector('.system-status');

        if (status === 'online') {
            statusIndicator.style.background = '#10b981';
            statusIndicator.style.animation = 'pulse 2s infinite';
            statusText.textContent = 'Система онлайн';
            statusText.style.color = '#10b981';
            systemStatus.style.background = 'rgba(16, 185, 129, 0.1)';
            systemStatus.style.borderColor = 'rgba(16, 185, 129, 0.2)';
        } else if (status === 'warning') {
            statusIndicator.style.background = '#f59e0b';
            statusIndicator.style.animation = 'pulse 1s infinite';
            statusText.textContent = 'Частично доступен';
            statusText.style.color = '#f59e0b';
            systemStatus.style.background = 'rgba(245, 158, 11, 0.1)';
            systemStatus.style.borderColor = 'rgba(245, 158, 11, 0.2)';
        } else {
            statusIndicator.style.background = '#ef4444';
            statusIndicator.style.animation = 'none';
            statusText.textContent = 'Проблемы с системой';
            statusText.style.color = '#ef4444';
            systemStatus.style.background = 'rgba(239, 68, 68, 0.1)';
            systemStatus.style.borderColor = 'rgba(239, 68, 68, 0.2)';
        }
    }

    // Анимация появления футера
    function animateFooter() {
        const footer = document.querySelector('.admin-footer');
        if (footer) {
            footer.style.opacity = '0';
            footer.style.transform = 'translateY(20px)';
            footer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';

            setTimeout(() => {
                footer.style.opacity = '1';
                footer.style.transform = 'translateY(0)';
            }, 100);
        }
    }

    // Анимация карточек статистики
    function animateStats() {
        const stats = document.querySelectorAll('.stat-item');
        stats.forEach((stat, index) => {
            stat.style.opacity = '0';
            stat.style.transform = 'translateY(10px)';

            setTimeout(() => {
                stat.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                stat.style.opacity = '1';
                stat.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }

    // Подсветка текущего года в копирайте
    function highlightCurrentYear() {
        const yearElement = document.getElementById('currentYear');
        if (yearElement) {
            setInterval(() => {
                yearElement.style.color = yearElement.style.color === 'rgb(0, 188, 212)' ? '' : '#00bcd4';
            }, 1000);
        }
    }

    // Инициализация
    animateFooter();
    animateStats();
    highlightCurrentYear();

    // Загружаем статистику при загрузке страницы
    setTimeout(updateSystemStats, 2000);

    // Обновляем статистику каждые 30 секунд
    setInterval(updateSystemStats, 30000);

    // Анимация при наведении на быстрые действия
    document.querySelectorAll('.quick-action-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.05)';
        });

        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Динамическое обновление времени в футере
    function updateTime() {
        const now = new Date();
        const timeElements = document.querySelectorAll('.current-time');
        if (timeElements.length === 0) {
            // Создаем элемент для отображения времени
            const timeContainer = document.createElement('div');
            timeContainer.className = 'current-time';
            timeContainer.style.cssText = 'font-size: 11px; color: rgba(255, 255, 255, 0.5); margin-top: 5px;';
            timeContainer.textContent = now.toLocaleTimeString('ru-RU');
            const copyright = document.querySelector('.copyright');
            if (copyright) {
                copyright.appendChild(timeContainer);
            }
        } else {
            timeElements.forEach(element => {
                element.textContent = now.toLocaleTimeString('ru-RU');
            });
        }
    }

    setInterval(updateTime, 1000);
    updateTime();
});
</script>
</body>
</html>
