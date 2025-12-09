<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем количество IP-адресов
$stmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN vm_type = 'qemu' AND ip_address IS NOT NULL THEN 1 ELSE 0 END) as vm_ip_count,
    SUM(CASE WHEN vm_type = 'lxc' AND ip_address IS NOT NULL THEN 1 ELSE 0 END) as lxc_ip_count
    FROM vms WHERE user_id = ?");
$stmt->execute([$user_id]);
$ip_counts = $stmt->fetch();
$ip_count = $ip_counts['vm_ip_count'];
$container_ip_count = $ip_counts['lxc_ip_count'];

// Максимальное количество IP-адресов (можно настроить)
//$max_ip = 10; // Примерное значение, можно изменить

// Процент использованных IP
//$ip_percent = round(($ip_count + $container_ip_count) / $max_ip * 100);

$title = "Мои виртуальные машины | HomeVlad Cloud";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/my_vms_style.css'; ?>
        <?php include '../css/header_styles.css'; ?>
        <?php include '../css/metrics_style.css'; ?>       
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <main class="admin-main">
                <div class="admin-header-container">
                    <div class="admin-header-content">
                        <h1 class="admin-title">
                            <i class="fas fa-server"></i> Мои виртуальные машины и контейнеры
                        </h1>
                        <div>
                            <a href="order_vm.php" class="btn btn-primary-vm">
                                <i class="fas fa-plus"></i> Заказать ресурсы
                            </a>
                        </div>
                    </div>
                </div>

                <div class="stats-grid" id="stats-grid">
                    <!-- Заполнится через AJAX -->
                    <div class="loading-placeholder" style="height: 120px;"></div>
                    <div class="loading-placeholder" style="height: 120px;"></div>
                    <div class="loading-placeholder" style="height: 120px;"></div>
                    <div class="loading-placeholder" style="height: 120px;"></div>
                    <div class="loading-placeholder" style="height: 120px;"></div>
                </div>

                <div class="progress-container">
                    <div class="progress-bar" id="loading-progress"></div>
                </div>

                <section class="section">
                    <div class="vm-list-header">
                        <h2 class="section-title">
                            <i class="fas fa-list"></i> Список виртуальных машин (QEMU)
                        </h2>
                        
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" data-view="grid" title="Плиточный вид">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button class="view-toggle-btn" data-view="compact" title="Компактный вид">
                                <i class="fas fa-list"></i>
                            </button>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Поиск ВМ..." id="vm-search">
                        </div>
                    </div>
                </div>
                    
                    <div class="vm-list grid-view" id="vm-list">
                        <!-- Временные карточки для отображения во время загрузки -->
                        <?php for($i = 0; $i < 3; $i++): ?>
                            <div class="vm-card loading">
                                <div class="vm-card-header">
                                    <h3 class="vm-name loading-placeholder" style="width: 60%; height: 24px;"></h3>
                                    <span class="vm-id loading-placeholder" style="width: 30%; height: 18px;"></span>
                                    <span class="status-badge loading-placeholder" style="width: 25%; height: 24px;"></span>
                                </div>
                                
                                <div class="vm-specs">
                                    <?php for($j = 0; $j < 7; $j++): ?>
                                        <div class="vm-spec">
                                            <i class="fas fa-microchip loading-placeholder"></i>
                                            <span class="loading-placeholder" style="width: 70%; height: 18px;"></span>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                
                                <div class="vm-actions">
                                    <?php for($k = 0; $k < 5; $k++): ?>
                                        <button class="btn btn-sm btn-secondary loading-placeholder" style="width: 36px; height: 36px;"></button>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </section>

                <section class="section">
                    <div class="vm-list-header">
                        <h2 class="section-title">
                            <i class="fas fa-list"></i> Список виртуальных контейнеров (LXC)
                        </h2>
                        
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" data-view="grid" title="Плиточный вид">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button class="view-toggle-btn" data-view="compact" title="Компактный вид">
                                <i class="fas fa-list"></i>
                            </button>
                            <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Поиск контейнеров..." id="container-search">
                        </div>
                        </div>
                    </div>
                    
                    <div class="vm-list grid-view" id="container-list">
                        <!-- Временные карточки для отображения во время загрузки -->
                        <?php for($i = 0; $i < 3; $i++): ?>
                            <div class="vm-card loading">
                                <div class="vm-card-header">
                                    <h3 class="vm-name loading-placeholder" style="width: 60%; height: 24px;"></h3>
                                    <span class="vm-id loading-placeholder" style="width: 30%; height: 18px;"></span>
                                    <span class="status-badge loading-placeholder" style="width: 25%; height: 24px;"></span>
                                </div>
                                
                                <div class="vm-specs">
                                    <?php for($j = 0; $j < 7; $j++): ?>
                                        <div class="vm-spec">
                                            <i class="fas fa-microchip loading-placeholder"></i>
                                            <span class="loading-placeholder" style="width: 70%; height: 18px;"></span>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                
                                <div class="vm-actions">
                                    <?php for($k = 0; $k < 5; $k++): ?>
                                        <button class="btn btn-sm btn-secondary loading-placeholder" style="width: 36px; height: 36px;"></button>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <!-- Модальное окно для метрик -->
    <div id="metricsModal" class="metrics-modal">
        <div class="metrics-modal-content">
            <div class="metrics-modal-header">
                <h3 class="metrics-modal-title" id="metricsModalTitle">Метрики</h3>
                <button class="metrics-modal-close" id="metricsModalClose">&times;</button>
            </div>
            
            <div class="timeframe-filter">
                <label for="timeframe">Период:</label>
                <select id="timeframe">
                    <option value="hour">1 час</option>
                    <option value="day">1 день</option>
                    <option value="week">1 неделя</option>
                    <option value="month">1 месяц</option>
                    <option value="year">1 год</option>
                </select>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar" id="metrics-loading-progress"></div>
            </div>
            
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="cpuChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="memoryChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="networkChart"></canvas>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-chart">
                        <canvas id="diskChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.8"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
    // Глобальные переменные
    let vmActionInProgress = false;
    let allVmsLoaded = false;
    let allContainersLoaded = false;
    let currentVmId = null;
    let currentVmName = null;
    let currentVmType = null;
    let cpuChart = null;
    let memoryChart = null;
    let networkChart = null;
    let diskChart = null;
    
    // Функция для обновления прогресс-бара
    function updateProgress(percent) {
        document.getElementById('loading-progress').style.width = percent + '%';
    }
    
    function updateMetricsProgress(percent) {
        document.getElementById('metrics-loading-progress').style.width = percent + '%';
    }
    
    // Загрузка статистики
    async function loadStats() {
        try {
            updateProgress(10);
            
            const response = await fetch('/api/get_user_stats.php?user_id=<?= $user_id ?>');
            const stats = await response.json();
            
            if (stats.success) {
                document.getElementById('stats-grid').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <h3>Запущенные ВМ</h3>
                        <p class="stat-value">${stats.vm_running}</p>
                        <p class="stat-details">из ${stats.vm_total} всего</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <h3>Запущенные контейнеры</h3>
                        <p class="stat-value">${stats.lxc_running}</p>
                        <p class="stat-details">из ${stats.lxc_total} всего</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <h3>Всего vCPU</h3>
                        <p class="stat-value">${stats.total_cpu}</p>
                        <p class="stat-details">ядер</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <h3>Всего RAM</h3>
                        <p class="stat-value">${stats.total_ram}</p>
                        <p class="stat-details">GB</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <h3>IP-адреса</h3>
                        <p class="stat-value"><?= $ip_count + $container_ip_count ?></p>
                        <p class="stat-details">шт</p>
                        <!--<p class="stat-details">из <?= $max_ip ?> доступно</p>
                        <div class="mini-progress-container">
                            <div class="progress-label">
                                <span>Использовано</span>
                                <span><?= $ip_percent ?>%</span>
                            </div>
                            <div class="mini-progress-bar">
                                <div class="mini-progress-fill ip-progress" style="width: <?= $ip_percent ?>%"></div>
                            </div>-->
                        </div>
                    </div>
                `;
                updateProgress(30);
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }
    
    // Загрузка списка виртуальных машин и контейнеров
    async function loadVms() {
    try {
        // Параллельная загрузка VM и контейнеров
        const [vmsResponse, containersResponse] = await Promise.all([
            fetch('/api/get_user_vms.php?user_id=<?= $user_id ?>'),
            fetch('/api/get_user_containers.php?user_id=<?= $user_id ?>')
        ]);
        
        const [vmsData, containersData] = await Promise.all([
            vmsResponse.json(),
            containersResponse.json()
        ]);
        
        // Обработка VM (qemu)
        if (vmsData.success && vmsData.vms.length > 0) {
            let gridHtml = '';
            let compactHtml = '';
            
            // Загружаем метрики для всех VM
            const metricsPromises = vmsData.vms.map(vm => 
                fetch(`/api/get_latest_metrics.php?vm_id=${vm.vm_id}&type=qemu`)
                    .then(res => res.json())
            );
            const metricsResults = await Promise.all(metricsPromises);
            
            vmsData.vms.forEach((vm, index) => {
                const percent = 30 + Math.floor((index / vmsData.vms.length) * 35);
                updateProgress(percent);
                
                const metrics = metricsResults[index].success ? metricsResults[index].data : {
                    cpu_usage: 0,
                    mem_usage: 0,
                    mem_total: vm.ram / 1024,
                    net_in: 0,
                    net_out: 0,
                    disk_read: 0,
                    disk_write: 0
                };
                
                const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
                const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
                const netIn = metrics.net_in || 0;
                const netOut = metrics.net_out || 0;
                const netTotal = (netIn + netOut).toFixed(2);
                
                // Стандартный вид (плитки)
                gridHtml += `
                    <div class="vm-card" data-status="${vm.status}" data-vmid="${vm.vm_id}" data-nodeid="${vm.node_id}" data-id="${vm.id}" data-vmtype="qemu">
                        <div class="vm-card-header">
                            <span class="status-badge ${vm.status === 'running' ? 'status-active' : 'status-inactive'}">
                                ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                            </span>
                            <span class="vm-id">VMID: ${vm.vm_id}</span>
                        </div>
                        <h3 class="vm-name">Имя: ${escapeHtml(vm.hostname)}</h3>
                        <div class="vm-specs">
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-microchip"></i>
                                    <span>${vm.cpu} vCPU</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>CPU</span>
                                        <span>${Math.round(metrics.cpu_usage)}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-memory"></i>
                                    <span>${vm.ram / 1024} GB RAM</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>RAM</span>
                                        <span>${ramUsagePercent}% (${metrics.mem_usage.toFixed(1)}/${metrics.mem_total.toFixed(1)} GB)</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-hdd"></i>
                                    <span>${vm.disk} GB SSD</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>Disk IO</span>
                                        <span>${diskTotal} MB/s</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-network-wired"></i>
                                <span>${vm.ip_address || 'Не назначен'}</span>
                                <div class="network-progress-container">
                                    <div class="network-progress-label">
                                        <span>Сеть</span>
                                        <span>${netTotal} Mbit/s</span>
                                    </div>
                                    <div class="network-progress-bars network-progress">
                                        <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                        <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                    </div>
                                    <div class="network-speed">▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s</div>
                                </div>
                            </div>
                        </div>
                        <div class="vm-specs-tf">
                            <div class="vm-spec-tf">
                                <i class="fas fa-tag"></i>
                                <span>Тариф: ${escapeHtml(vm.tariff_name)}</span>
                            </div>
                            <div class="vm-spec-tf">
                                <i class="fas fa-server"></i>
                                <span>Сервер: ${escapeHtml(vm.node_name)}</span>
                            </div>
                            <div class="vm-spec-tf">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Дата: ${formatDate(vm.created_at)}</span>
                            </div>
                        </div>
                    
                        
                        <div class="vm-actions">
                            ${vm.status !== 'running' ? `
                                <button class="btn btn-sm btn-primary vm-start" title="Запустить">
                                    <i class="fas fa-play"></i>
                                </button>` : `
                                <button class="btn btn-sm btn-warning vm-reboot" title="Перезагрузить">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-danger vm-stop" title="Остановить">
                                    <i class="fas fa-stop"></i>
                                </button>`}
                            <button class="btn btn-sm btn-info vm-console" title="Консоль">
                                <i class="fas fa-terminal"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary vm-metrics" title="Метрики">
                                <i class="fas fa-chart-line"></i>
                            </button>
                            <a href="vm_settings.php?id=${vm.id}" class="btn btn-sm btn-secondary" title="Настройки">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                    </div>
                
                `;
                
                // Компактный вид (список)
                compactHtml += `
                    <div class="vm-card" data-status="${vm.status}" data-vmid="${vm.vm_id}" data-nodeid="${vm.node_id}" data-id="${vm.id}" data-vmtype="qemu">
                        <div class="vm-card-header">
                            <span class="status-badge ${vm.status === 'running' ? 'status-active' : 'status-inactive'}">
                                ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                            </span>
                            <span class="vm-id">VMID: ${vm.vm_id}</span>
                            <h3 class="vm-name">${escapeHtml(vm.hostname)}</h3>
                        </div>
                        <div class="vm-specs">
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-microchip"></i>
                                    <span>${vm.cpu} vCPU</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>CPU</span>
                                        <span>${Math.round(metrics.cpu_usage)}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-memory"></i>
                                    <span>${vm.ram / 1024} GB RAM</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>RAM</span>
                                        <span>${ramUsagePercent}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-hdd"></i>
                                    <span>${vm.disk} GB SSD</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>Disk IO</span>
                                        <span>${diskTotal} MB/s</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-network-wired"></i>
                                <span>${vm.ip_address || 'Не назначен'}</span>
                                <div class="network-progress-container">
                                    <div class="network-progress-label">
                                        <span>Сеть</span>
                                        <span>${netTotal} Mbit/s</span>
                                    </div>
                                    <div class="network-progress-bars network-progress">
                                        <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                        <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-tag"></i>
                                <span>Тариф: ${escapeHtml(vm.tariff_name)}</span>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-server"></i>
                                <span>Сервер: ${escapeHtml(vm.node_name)}</span>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Дата: ${formatDate(vm.created_at)}</span>
                            </div>
                        </div>
                        <div class="vm-actions">
                            ${vm.status !== 'running' ? `
                                <button class="btn btn-sm btn-primary vm-start" title="Запустить">
                                    <i class="fas fa-play"></i>
                                </button>` : `
                                <button class="btn btn-sm btn-warning vm-reboot" title="Перезагрузить">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-danger vm-stop" title="Остановить">
                                    <i class="fas fa-stop"></i>
                                </button>`}
                            <button class="btn btn-sm btn-info vm-console" title="Консоль">
                                <i class="fas fa-terminal"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary vm-metrics" title="Метрики">
                                <i class="fas fa-chart-line"></i>
                            </button>
                            <a href="vm_settings.php?id=${vm.id}" class="btn btn-sm btn-secondary" title="Настройки">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('vm-list').innerHTML = gridHtml;
            document.getElementById('vm-list').dataset.gridHtml = gridHtml;
            document.getElementById('vm-list').dataset.compactHtml = compactHtml;
            allVmsLoaded = true;
            addVmActionHandlers();
        } else {
            document.getElementById('vm-list').innerHTML = `
                <div class="no-data">
                    <i class="fas fa-cloud"></i>
                    <p>У вас пока нет виртуальных машин</p>
                    <a href="order_vm.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Создать первую ВМ
                    </a>
                </div>
            `;
        }
        
        // Обработка контейнеров (lxc)
        if (containersData.success && containersData.containers.length > 0) {
            let gridHtml = '';
            let compactHtml = '';
            
            // Загружаем метрики для всех контейнеров
            const metricsPromises = containersData.containers.map(ct => 
                fetch(`/api/get_latest_metrics.php?vm_id=${ct.vm_id}&type=lxc`)
                    .then(res => res.json())
            );
            const metricsResults = await Promise.all(metricsPromises);
            
            containersData.containers.forEach((ct, index) => {
                const percent = 65 + Math.floor((index / containersData.containers.length) * 35);
                updateProgress(percent);
                
                const metrics = metricsResults[index].success ? metricsResults[index].data : {
                    cpu_usage: 0,
                    mem_usage: 0,
                    mem_total: ct.ram / 1024,
                    net_in: 0,
                    net_out: 0,
                    disk_read: 0,
                    disk_write: 0
                };
                
                const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
                const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
                const netIn = metrics.net_in || 0;
                const netOut = metrics.net_out || 0;
                const netTotal = (netIn + netOut).toFixed(2);
                
                // Стандартный вид (плитки)
                gridHtml += `
                    <div class="vm-card" data-status="${ct.status}" data-vmid="${ct.vm_id}" data-nodeid="${ct.node_id}" data-id="${ct.id}" data-vmtype="lxc">
                        <div class="vm-card-header">
                            <span class="status-badge ${ct.status === 'running' ? 'status-active' : 'status-inactive'}">
                                ${ct.status === 'running' ? 'Запущен' : 'Остановлен'}
                            </span>
                            <span class="vm-id">CTID: ${ct.vm_id}</span>
                        </div>
                        <h3 class="vm-name">Имя: ${escapeHtml(ct.hostname)}</h3>
                        <div class="vm-specs">
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-microchip"></i>
                                    <span>${ct.cpu} vCPU</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>CPU</span>
                                        <span>${Math.round(metrics.cpu_usage)}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-memory"></i>
                                    <span>${ct.ram / 1024} GB RAM</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>RAM</span>
                                        <span>${ramUsagePercent}% (${metrics.mem_usage.toFixed(1)}/${metrics.mem_total.toFixed(1)} GB)</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-hdd"></i>
                                    <span>${ct.disk} GB SSD</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>Disk IO</span>
                                        <span>${diskTotal} MB/s</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-network-wired"></i>
                                <span>${ct.ip_address || 'Не назначен'}</span>
                                <div class="network-progress-container">
                                    <div class="network-progress-label">
                                        <span>Сеть</span>
                                        <span>${netTotal} Mbit/s</span>
                                    </div>
                                    <div class="network-progress-bars network-progress">
                                        <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                        <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                    </div>
                                    <div class="network-speed">▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s</div>
                                </div>
                            </div>
                            <div class="vm-spec-tf">
                                <i class="fas fa-tag"></i>
                                <span>Тариф: ${escapeHtml(ct.tariff_name)}</span>
                            </div>
                            <div class="vm-spec-tf">
                                <i class="fas fa-server"></i>
                                <span>Сервер: ${escapeHtml(ct.node_name)}</span>
                            </div>
                            <div class="vm-spec-tf">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Дата: ${formatDate(ct.created_at)}</span>
                            </div>
                        </div>
                        
                        <div class="vm-actions">
                            ${ct.status !== 'running' ? `
                                <button class="btn btn-sm btn-primary container-start" title="Запустить">
                                    <i class="fas fa-play"></i>
                                </button>` : `
                                <button class="btn btn-sm btn-warning container-reboot" title="Перезагрузить">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-danger container-stop" title="Остановить">
                                    <i class="fas fa-stop"></i>
                                </button>`}
                            <button class="btn btn-sm btn-info container-console" title="Консоль">
                                <i class="fas fa-terminal"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary container-metrics" title="Метрики">
                                <i class="fas fa-chart-line"></i>
                            </button>
                            <a href="vm_settings.php?id=${ct.id}" class="btn btn-sm btn-secondary" title="Настройки">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                    </div>
                `;
                
                // Компактный вид (список)
                compactHtml += `
                    <div class="vm-card" data-status="${ct.status}" data-vmid="${ct.vm_id}" data-nodeid="${ct.node_id}" data-id="${ct.id}" data-vmtype="lxc">
                        <div class="vm-card-header">
                            <span class="status-badge ${ct.status === 'running' ? 'status-active' : 'status-inactive'}">
                                ${ct.status === 'running' ? 'Запущен' : 'Остановлен'}
                            </span>
                            <span class="vm-id">CTID: ${ct.vm_id}</span>
                            <h3 class="vm-name">${escapeHtml(ct.hostname)}</h3>
                        </div>
                        <div class="vm-specs">
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-microchip"></i>
                                    <span>${ct.cpu} vCPU</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>CPU</span>
                                        <span>${Math.round(metrics.cpu_usage)}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill cpu-progress" style="width: ${metrics.cpu_usage}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-memory"></i>
                                    <span>${ct.ram / 1024} GB RAM</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>RAM</span>
                                        <span>${ramUsagePercent}%</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill ram-progress" style="width: ${ramUsagePercent}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <div>
                                    <i class="fas fa-hdd"></i>
                                    <span>${ct.disk} GB SSD</span>
                                </div>
                                <div class="mini-progress-container">
                                    <div class="progress-label">
                                        <span>Disk IO</span>
                                        <span>${diskTotal} MB/s</span>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill disk-progress" style="width: ${Math.min(100, diskTotal * 10)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-network-wired"></i>
                                <span>${ct.ip_address || 'Не назначен'}</span>
                                <div class="network-progress-container">
                                    <div class="network-progress-label">
                                        <span>Сеть</span>
                                        <span>${netTotal} Mbit/s</span>
                                    </div>
                                    <div class="network-progress-bars network-progress">
                                        <div class="network-progress-in" style="width: ${Math.min(100, netIn * 2)}%"></div>
                                        <div class="network-progress-out" style="width: ${Math.min(100, netOut * 2)}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-tag"></i>
                                <span>Тариф: ${escapeHtml(ct.tariff_name)}</span>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-server"></i>
                                <span>Сервер: ${escapeHtml(ct.node_name)}</span>
                            </div>
                            <div class="vm-spec">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Дата: ${formatDate(ct.created_at)}</span>
                            </div>
                        </div>
                        <div class="vm-actions">
                            ${ct.status !== 'running' ? `
                                <button class="btn btn-sm btn-primary container-start" title="Запустить">
                                    <i class="fas fa-play"></i>
                                </button>` : `
                                <button class="btn btn-sm btn-warning container-reboot" title="Перезагрузить">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-danger container-stop" title="Остановить">
                                    <i class="fas fa-stop"></i>
                                </button>`}
                            <button class="btn btn-sm btn-info container-console" title="Консоль">
                                <i class="fas fa-terminal"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary container-metrics" title="Метрики">
                                <i class="fas fa-chart-line"></i>
                            </button>
                            <a href="vm_settings.php?id=${ct.id}" class="btn btn-sm btn-secondary" title="Настройки">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('container-list').innerHTML = gridHtml;
            document.getElementById('container-list').dataset.gridHtml = gridHtml;
            document.getElementById('container-list').dataset.compactHtml = compactHtml;
            allContainersLoaded = true;
            addContainerActionHandlers();
        } else {
            document.getElementById('container-list').innerHTML = `
                <div class="no-data">
                    <i class="fas fa-box"></i>
                    <p>У вас пока нет контейнеров</p>
                    <a href="order_container.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Создать первый контейнер
                    </a>
                </div>
            `;
        }
        
        updateProgress(100);
        
        // Запускаем периодическое обновление метрик
        setInterval(updateAllMetrics, 30000);
    } catch (error) {
        console.error('Error loading VMs and containers:', error);
        updateProgress(100);
    }
}

    // Обновление метрик всех VM и контейнеров
    async function updateAllMetrics() {
        if (!allVmsLoaded && !allContainersLoaded) return;
        
        // Обновляем метрики VM
        if (allVmsLoaded) {
            const vmCards = document.querySelectorAll('#vm-list .vm-card:not(.loading)');
            
            for (const card of vmCards) {
                const vmId = card.dataset.vmid;
                
                try {
                    const response = await fetch(`/api/get_latest_metrics.php?vm_id=${vmId}&type=qemu`);
                    const data = await response.json();
                    
                    if (data.success) {
                        updateVmCardMetrics(card, data.data, 'qemu');
                    }
                } catch (error) {
                    console.error(`Error updating metrics for VM ${vmId}:`, error);
                }
            }
        }
        
        // Обновляем метрики контейнеров
        if (allContainersLoaded) {
            const containerCards = document.querySelectorAll('#container-list .vm-card:not(.loading)');
            
            for (const card of containerCards) {
                const vmId = card.dataset.vmid;
                
                try {
                    const response = await fetch(`/api/get_latest_metrics.php?vm_id=${vmId}&type=lxc`);
                    const data = await response.json();
                    
                    if (data.success) {
                        updateVmCardMetrics(card, data.data, 'lxc');
                    }
                } catch (error) {
                    console.error(`Error updating metrics for container ${vmId}:`, error);
                }
            }
        }
    }
    
    // Обновление метрик на карточке
    function updateVmCardMetrics(card, metrics, vmType) {
    const ramUsagePercent = Math.round((metrics.mem_usage / metrics.mem_total) * 100);
    const diskTotal = (metrics.disk_read + metrics.disk_write).toFixed(2);
    const netIn = metrics.net_in || 0;
    const netOut = metrics.net_out || 0;
    const netTotal = (netIn + netOut).toFixed(2);
    
    // Обновляем прогресс-бары
    const cpuFill = card.querySelector('.cpu-progress');
    const ramFill = card.querySelector('.ram-progress');
    const diskFill = card.querySelector('.disk-progress');
    
    if (cpuFill) {
        cpuFill.style.width = `${metrics.cpu_usage}%`;
        const cpuLabel = cpuFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child');
        if (cpuLabel) cpuLabel.textContent = `${Math.round(metrics.cpu_usage)}%`;
    }
    
    if (ramFill) {
        ramFill.style.width = `${ramUsagePercent}%`;
        const ramLabel = ramFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child');
        if (ramLabel) ramLabel.textContent = `${ramUsagePercent}%`;
    }
    
    if (diskFill) {
        const diskPercent = Math.min(100, diskTotal * 10);
        diskFill.style.width = `${diskPercent}%`;
        const diskLabel = diskFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child');
        if (diskLabel) diskLabel.textContent = `${diskTotal} MB/s`;
    }
    
    // Обновляем прогресс-бары сети
    const networkProgress = card.querySelector('.network-progress');
    if (networkProgress) {
        const netInPercent = Math.min(100, netIn * 2);
        const netOutPercent = Math.min(100, netOut * 2);
        
        networkProgress.querySelector('.network-progress-in').style.width = `${netInPercent}%`;
        networkProgress.querySelector('.network-progress-out').style.width = `${netOutPercent}%`;
        const networkSpeed = networkProgress.querySelector('.network-speed');
        if (networkSpeed) networkSpeed.textContent = `▲${netOut.toFixed(2)} ▼${netIn.toFixed(2)} Mbit/s`;
    }
}

    // Функция для загрузки метрик
    async function loadVmMetrics(vm_id, timeframe, vm_type) {
        try {
            updateMetricsProgress(10);
            
            const response = await fetch(`/api/${vm_type === 'lxc' ? 'get_lxc_metrics' : 'get_vm_metrics'}.php?vm_id=${vm_id}&timeframe=${timeframe}`);
            if (!response.ok) throw new Error('Ошибка сервера');
            
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');
            
            updateMetricsProgress(30);
            
            // Уничтожаем старые графики, если они есть
            if (cpuChart) cpuChart.destroy();
            if (memoryChart) memoryChart.destroy();
            if (networkChart) networkChart.destroy();
            if (diskChart) diskChart.destroy();
            
            // Создаем графики
            createCharts(data);
            
            updateMetricsProgress(100);
            
            // Через 1 секунду скрываем прогресс-бар
            setTimeout(() => {
                document.querySelector('#metricsModal .progress-container').style.opacity = '0';
                setTimeout(() => {
                    document.querySelector('#metricsModal .progress-container').style.display = 'none';
                }, 500);
            }, 1000);
            
        } catch (error) {
            console.error('Ошибка загрузки метрик:', error);
            updateMetricsProgress(100);
            
            const metricsGrid = document.querySelector('#metricsModal .metrics-grid');
            metricsGrid.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    ${error.message}
                </div>
            `;
            
            setTimeout(() => {
                document.querySelector('#metricsModal .progress-container').style.opacity = '0';
                setTimeout(() => {
                    document.querySelector('#metricsModal .progress-container').style.display = 'none';
                }, 500);
            }, 1000);
        }
    }
    
    // Функция для создания графиков
    function createCharts(data) {
        // График CPU Usage
        cpuChart = new Chart(
            document.getElementById('cpuChart'),
            {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Использование CPU',
                        data: data.cpuData,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' %';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Использование CPU (%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(0) + ' %';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            }
        );
        
        updateMetricsProgress(50);
        
        // График Memory Usage
        memoryChart = new Chart(
            document.getElementById('memoryChart'),
            {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Используемая память',
                            data: data.memData,
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: 'Всего памяти',
                            data: data.memTotalData,
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 0,
                            backgroundColor: 'rgba(0, 0, 0, 0)',
                            pointRadius: 0,
                            pointHoverRadius: 0,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                filter: function(item) {
                                    return item.text !== 'Всего памяти';
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' ГБ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Память (ГБ)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1) + ' ГБ';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            }
        );
        
        updateMetricsProgress(70);
        
        // График Network Traffic
        networkChart = new Chart(
            document.getElementById('networkChart'),
            {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Входящий трафик',
                            data: data.netInData,
                            borderColor: 'rgba(153, 102, 255, 1)',
                            backgroundColor: 'rgba(153, 102, 255, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: 'Исходящий трафик',
                            data: data.netOutData,
                            borderColor: 'rgba(255, 159, 64, 1)',
                            backgroundColor: 'rgba(255, 159, 64, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' Mbit/s';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Скорость передачи (Mbit/s)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' Mbit/s';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            }
        );
        
        updateMetricsProgress(90);
        
        // График Disk IO
        diskChart = new Chart(
            document.getElementById('diskChart'),
            {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Чтение с диска',
                            data: data.diskReadData,
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        },
                        {
                            label: 'Запись на диск',
                            data: data.diskWriteData,
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' МБ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Дисковые операции (МБ)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' МБ';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Время'
                            }
                        }
                    }
                }
            }
        );
    }
    
    // Функция для открытия модального окна с метриками
    function openMetricsModal(vm_id, vm_name, vm_type) {
        currentVmId = vm_id;
        currentVmName = vm_name;
        currentVmType = vm_type;
        
        // Устанавливаем заголовок
        document.getElementById('metricsModalTitle').textContent = 
            `Метрики ${vm_type === 'lxc' ? 'контейнера' : 'VM'} #${vm_id} (${vm_name})`;
        
        // Сбрасываем прогресс-бар
        document.querySelector('#metricsModal .progress-container').style.display = 'block';
        document.querySelector('#metricsModal .progress-container').style.opacity = '1';
        updateMetricsProgress(0);
        
        // Показываем модальное окно
        const modal = document.getElementById('metricsModal');
        modal.style.display = 'block';
        
        // Загружаем метрики для текущего диапазона времени
        const timeframe = document.getElementById('timeframe').value;
        loadVmMetrics(vm_id, timeframe, vm_type);
    }
    
    function addVmActionHandlers() {
        document.querySelectorAll('.vm-start').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('start', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
            });
        });

        document.querySelectorAll('.vm-stop').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('stop', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
            });
        });

        document.querySelectorAll('.vm-reboot').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('reboot', card.dataset.nodeid, card.dataset.vmid, card, 'qemu');
            });
        });

        document.querySelectorAll('.vm-console').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const nodeId = card.dataset.nodeid;
                openVncConsole(nodeId, vm_id, 'qemu');
            });
        });

        document.querySelectorAll('.vm-metrics').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const vm_name = card.querySelector('.vm-name').textContent.replace('Имя: ', '');
                openMetricsModal(vm_id, vm_name, 'qemu');
            });
        });
    }
    
    function addContainerActionHandlers() {
        document.querySelectorAll('.container-start').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('start', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
            });
        });

        document.querySelectorAll('.container-stop').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('stop', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
            });
        });

        document.querySelectorAll('.container-reboot').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('reboot', card.dataset.nodeid, card.dataset.vmid, card, 'lxc');
            });
        });

        document.querySelectorAll('.container-console').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const nodeId = card.dataset.nodeid;
                openVncConsole(nodeId, vm_id, 'lxc');
            });
        });

        document.querySelectorAll('.container-metrics').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const vm_name = card.querySelector('.vm-name').textContent.replace('Имя: ', '');
                openMetricsModal(vm_id, vm_name, 'lxc');
            });
        });
    }

    function handleVmAction(action, nodeId, vm_id, card, vmType) {
        if (vmActionInProgress) return;
        vmActionInProgress = true;
        
        const actionText = {
            'start': vmType === 'lxc' ? 'запуск контейнера' : 'запуск VM',
            'stop': vmType === 'lxc' ? 'остановка контейнера' : 'остановка VM',
            'reboot': vmType === 'lxc' ? 'перезагрузка контейнера' : 'перезагрузка VM'
        }[action];
        
        Swal.fire({
            title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)}...`,
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        fetch(vmType === 'lxc' ? 'container_action.php' : 'vm_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: action,
                node_id: nodeId,
                vm_id: vm_id
            })
        })
        .then(response => response.json())
        .then(data => {
            vmActionInProgress = false;
            
            if (data.success) {
                Swal.fire({
                    title: 'Успех',
                    text: `${vmType === 'lxc' ? 'Контейнер' : 'Виртуальная машина'} #${vm_id} успешно ${
                        action === 'start' ? 'запущена' : 
                        action === 'stop' ? 'остановлена' : 'перезагружена'
                    }`,
                    icon: 'success',
                    timer: 2000,
                    didClose: () => {
                        window.location.reload();
                    }
                });
            } else {
                Swal.fire({
                    title: 'Ошибка',
                    text: data.error || 'Произошла ошибка',
                    icon: 'error'
                });
            }
        })
        .catch(error => {
            vmActionInProgress = false;
            Swal.fire({
                title: 'Ошибка',
                text: error.message,
                icon: 'error'
            });
        });
    }

    async function openVncConsole(nodeId, vmId, vmType) {
    if (vmActionInProgress) return;
    vmActionInProgress = true;
    
    try {
        const swalInstance = Swal.fire({
            title: 'Подготовка консоли...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся...</div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Формируем URL с учетом типа ВМ
        const url = new URL('vnc_console.php', window.location.href);
        url.searchParams.append('node_id', nodeId);
        url.searchParams.append('vm_id', vmId);
        
        if (vmType === 'lxc' || vmType === 'qemu') {
            url.searchParams.append('type', vmType);
        }

        const response = await fetch(url);
        
        // Проверяем, что ответ содержит данные
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text);
            throw new Error('Неверный формат ответа от сервера');
        }
        
        if (!data.success) {
            throw new Error(data.error || 'Не удалось подключиться');
        }

        // Проверка типа ВМ
        if (vmType && data.data.vm_type && vmType !== data.data.vm_type) {
            console.warn(`Тип ВМ не совпадает: ожидался ${vmType}, получен ${data.data.vm_type}`);
        }

        // Упрощенная установка cookie (без iframe)
        if (data.data.cookie) {
            const cookie = data.data.cookie;
            const cookieStr = `${cookie.name}=${encodeURIComponent(cookie.value)}; ` +
                `domain=${cookie.domain || window.location.hostname}; ` +
                `path=${cookie.path || '/'}; ` +
                `secure=${cookie.secure !== false}; ` +
                `samesite=${cookie.samesite || 'Lax'}`;
            
            document.cookie = cookieStr;
            console.log('Cookie set:', cookieStr);
        }

        // Проверка URL консоли
        if (!data.data.url) {
            throw new Error('Не получен URL консоли');
        }

        const consoleUrl = new URL(data.data.url);
        if (!consoleUrl.searchParams.get('console') || 
            !['lxc', 'kvm'].includes(consoleUrl.searchParams.get('console'))) {
            throw new Error('Некорректный URL консоли');
        }

        // Открываем VNC консоль
        const vncWindow = window.open(
            data.data.url,
            `vnc_${nodeId}_${vmId}`,
            'width=1024,height=768,scrollbars=yes,resizable=yes,location=yes'
        );

        if (!vncWindow || vncWindow.closed) {
            throw new Error('Не удалось открыть окно VNC. Пожалуйста, разрешите всплывающие окна для этого сайта.');
        }

        swalInstance.close();

    } catch (error) {
        Swal.fire({
            title: 'Ошибка подключения',
            text: error.message,
            icon: 'error',
            confirmButtonText: 'OK'
        });
        console.error('VNC Error:', error);
    } finally {
        vmActionInProgress = false;
    }
}
    
    // Вспомогательные функции
    function escapeHtml(unsafe) {
        return unsafe?.replace(/&/g, "&amp;")
                     .replace(/</g, "&lt;")
                     .replace(/>/g, "&gt;")
                     .replace(/"/g, "&quot;")
                     .replace(/'/g, "&#039;") || '';
    }
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear();
        return `${day}.${month}.${year}`;
    }
    
    // Переключение вида карточек
    function toggleView(viewType) {
        const vmList = document.getElementById('vm-list');
        const containerList = document.getElementById('container-list');
        
        // Обновляем активные кнопки
        document.querySelectorAll('.view-toggle-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === viewType);
        });
        
        if (viewType === 'compact') {
            vmList.classList.remove('grid-view');
            vmList.classList.add('compact-view');
            containerList.classList.remove('grid-view');
            containerList.classList.add('compact-view');
            
            if (vmList.dataset.compactHtml) {
                vmList.innerHTML = vmList.dataset.compactHtml;
            }
            if (containerList.dataset.compactHtml) {
                containerList.innerHTML = containerList.dataset.compactHtml;
            }
        } else {
            vmList.classList.remove('compact-view');
            vmList.classList.add('grid-view');
            containerList.classList.remove('compact-view');
            containerList.classList.add('grid-view');
            
            if (vmList.dataset.gridHtml) {
                vmList.innerHTML = vmList.dataset.gridHtml;
            }
            if (containerList.dataset.gridHtml) {
                containerList.innerHTML = containerList.dataset.gridHtml;
            }
        }
        
        // Обновляем обработчики событий
        addVmActionHandlers();
        addContainerActionHandlers();
        
        // Сохраняем выбор пользователя в localStorage
        localStorage.setItem('vmViewType', viewType);
    }
    
    // Инициализация
    document.addEventListener('DOMContentLoaded', function() {
        checkScreenSize();
        
        // Загружаем данные
        loadStats().then(loadVms).then(() => {
            setTimeout(() => {
                document.querySelector('.progress-container').style.opacity = '0';
                setTimeout(() => {
                    document.querySelector('.progress-container').style.display = 'none';
                }, 500);
            }, 1000);
            
            // Восстанавливаем сохраненный вид после загрузки данных
            const savedViewType = localStorage.getItem('vmViewType') || 'grid';
            toggleView(savedViewType);
        });
        
        // Инициализация поиска VM
document.getElementById('vm-search').addEventListener('input', function(e) {
    if (!allVmsLoaded) return;
    
    const searchTerm = e.target.value.toLowerCase();
    const vmList = document.getElementById('vm-list');
    const vmCards = vmList.querySelectorAll('.vm-card');
    
    vmCards.forEach(card => {
        const vmName = card.querySelector('.vm-name').textContent.toLowerCase();
        const vmId = card.querySelector('.vm-id')?.textContent.toLowerCase() || '';
        
        if (vmName.includes(searchTerm) || vmId.includes(searchTerm)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
    
    // Проверяем, есть ли видимые карточки
    const visibleCards = Array.from(vmCards).filter(card => card.style.display !== 'none');
    if (visibleCards.length === 0) {
        vmList.innerHTML = `
            <div class="no-results">
                <i class="fas fa-search"></i>
                <p>Ничего не найдено</p>
            </div>
        `;
    } else if (vmList.querySelector('.no-results')) {
        // Восстанавливаем оригинальный HTML, если был показан "ничего не найдено"
        vmList.innerHTML = vmList.dataset.gridHtml;
        // Повторно применяем фильтр
        document.getElementById('vm-search').dispatchEvent(new Event('input'));
    }
});

// Инициализация поиска контейнеров
document.getElementById('container-search').addEventListener('input', function(e) {
    if (!allContainersLoaded) return;
    
    const searchTerm = e.target.value.toLowerCase();
    const containerList = document.getElementById('container-list');
    const containerCards = containerList.querySelectorAll('.vm-card');
    
    containerCards.forEach(card => {
        const ctName = card.querySelector('.vm-name').textContent.toLowerCase();
        const ctId = card.querySelector('.vm-id')?.textContent.toLowerCase() || '';
        
        if (ctName.includes(searchTerm) || ctId.includes(searchTerm)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
    
    // Проверяем, есть ли видимые карточки
    const visibleCards = Array.from(containerCards).filter(card => card.style.display !== 'none');
    if (visibleCards.length === 0) {
        containerList.innerHTML = `
            <div class="no-results">
                <i class="fas fa-search"></i>
                <p>Ничего не найдено</p>
            </div>
        `;
    } else if (containerList.querySelector('.no-results')) {
        // Восстанавливаем оригинальный HTML, если был показан "ничего не найдено"
        containerList.innerHTML = containerList.dataset.gridHtml;
        // Повторно применяем фильтр
        document.getElementById('container-search').dispatchEvent(new Event('input'));
    }
});
        
        // Обработчики для модального окна метрик
        document.getElementById('metricsModalClose').addEventListener('click', function() {
            document.getElementById('metricsModal').style.display = 'none';
        });
        
        // Закрытие модального окна при клике вне его
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('metricsModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        
        // Обработчик изменения временного диапазона
        document.getElementById('timeframe').addEventListener('change', function() {
            if (currentVmId) {
                loadVmMetrics(currentVmId, this.value, currentVmType);
            }
        });
        
        // Обработчики переключения вида
        document.querySelectorAll('.view-toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const viewType = this.dataset.view;
                toggleView(viewType);
            });
        });
    });
    
    // Адаптивное меню для мобильных устройств
    const menuToggle = document.createElement('button');
    menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    menuToggle.className = 'btn btn-icon';
    menuToggle.style.position = 'fixed';
    menuToggle.style.top = '15px';
    menuToggle.style.left = '15px';
    menuToggle.style.zIndex = '1000';
    document.body.appendChild(menuToggle);
    
    const sidebar = document.querySelector('.admin-sidebar');
    
    function checkScreenSize() {
        if (window.innerWidth <= 992) {
            sidebar.style.display = 'none';
            menuToggle.style.display = 'block';
        } else {
            sidebar.style.display = 'block';
            menuToggle.style.display = 'none';
        }
    }
    
    menuToggle.addEventListener('click', function() {
        sidebar.style.display = sidebar.style.display === 'none' ? 'block' : 'none';
    });
    
    window.addEventListener('resize', checkScreenSize);
    </script>
</body>
</html>