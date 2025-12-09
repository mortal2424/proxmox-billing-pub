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
$stmt = $pdo->prepare("SELECT COUNT(ip_address) as ip_count FROM vms WHERE user_id = ? AND ip_address IS NOT NULL");
$stmt->execute([$user_id]);
$ip_count = $stmt->fetch()['ip_count'];

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
                            <i class="fas fa-server"></i> Мои виртуальные машины
                        </h1>
                        <div>
                            <a href="order_vm.php" class="btn btn-primary-vm">
                                <i class="fas fa-plus"></i> Создать ВМ
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
                            <i class="fas fa-list"></i> Список виртуальных машин
                        </h2>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" placeholder="Поиск ВМ..." id="vm-search">
                        </div>
                    </div>
                    
                    <div class="vm-list" id="vm-list">
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
                <h3 class="metrics-modal-title" id="metricsModalTitle">Метрики виртуальной машины</h3>
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
    let currentVmId = null;
    let currentVmName = null;
    let cpuChart = null;
    let memoryChart = null;
    let networkChart = null;
    let diskChart = null;
    let vmMetrics = {};
    
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
            const response = await fetch('/api/get_vm_stats.php?user_id=<?= $user_id ?>');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('stats-grid').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <h3>Запущенные</h3>
                        <p class="stat-value">${data.running_count}</p>
                        <p class="stat-details">из ${data.total_count} всего</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <h3>Всего vCPU</h3>
                        <p class="stat-value">${data.total_cpu}</p>
                        <p class="stat-details">ядер</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <h3>Всего RAM</h3>
                        <p class="stat-value">${data.total_ram / 1024}</p>
                        <p class="stat-details">GB</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-hdd"></i>
                        </div>
                        <h3>Всего дисков</h3>
                        <p class="stat-value">${data.total_disk}</p>
                        <p class="stat-details">GB</p>
                    </div>
                    
                    <div class="stat-card ip-card">
                        <div class="stat-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <h3>IP-адреса</h3>
                        <p class="stat-value"><?= $ip_count ?></p>
                        <p class="stat-details">назначено</p>
                    </div>
                `;
                updateProgress(30);
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }
    
    // Загрузка метрик для VM
    async function loadVmResourceUsage(vmId) {
        try {
            const response = await fetch(`/api/get_vm_metrics.php?vm_id=${vmId}&timeframe=hour`);
            const data = await response.json();
            
            if (data.success) {
                // Сохраняем метрики для использования в интерфейсе
                vmMetrics[vmId] = {
                    cpu: data.cpuData[data.cpuData.length - 1] || 0, // Последнее значение CPU
                    ram: data.memData[data.memData.length - 1] || 0, // Последнее значение RAM
                    ramTotal: data.memTotalData[0] || 1, // Общий объем RAM
                    disk: (data.diskReadData[data.diskReadData.length - 1] || 0) + 
                          (data.diskWriteData[data.diskWriteData.length - 1] || 0) // Сумма операций диска
                };
                
                return true;
            }
        } catch (error) {
            console.error(`Error loading metrics for VM ${vmId}:`, error);
        }
        return false;
    }
    
    // Загрузка списка виртуальных машин
    async function loadVms() {
        try {
            const response = await fetch('/api/get_user_vms.php?user_id=<?= $user_id ?>');
            const data = await response.json();
            
            if (data.success && data.vms.length > 0) {
                let html = '';
                
                // Сначала загружаем метрики для всех VM
                const metricsPromises = data.vms.map(vm => loadVmResourceUsage(vm.vm_id));
                await Promise.all(metricsPromises);
                
                data.vms.forEach((vm, index) => {
                    const percent = 30 + Math.floor((index / data.vms.length) * 70);
                    updateProgress(percent);
                    
                    // Получаем сохраненные метрики или используем нулевые значения
                    const metrics = vmMetrics[vm.vm_id] || {
                        cpu: 0,
                        ram: 0,
                        ramTotal: vm.ram / 1024,
                        disk: 0
                    };
                    
                    // Рассчитываем использование RAM в процентах
                    const ramUsagePercent = Math.round((metrics.ram / metrics.ramTotal) * 100);
                    
                    html += `
                        <div class="vm-card" data-status="${vm.status}" data-vmid="${vm.vm_id}" data-nodeid="${vm.node_id}" data-id="${vm.id}">
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
                                            <span>${Math.round(metrics.cpu)}%</span>
                                        </div>
                                        <div class="mini-progress-bar">
                                            <div class="mini-progress-fill cpu-progress" style="width: ${metrics.cpu}%"></div>
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
                                            <span>${ramUsagePercent}% (${metrics.ram.toFixed(1)}/${metrics.ramTotal.toFixed(1)} GB)</span>
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
                                            <span>${metrics.disk.toFixed(2)} MB/s</span>
                                        </div>
                                        <div class="mini-progress-bar">
                                            <div class="mini-progress-fill disk-progress" style="width: ${Math.min(100, metrics.disk * 10)}%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="vm-spec">
                                    <i class="fas fa-network-wired"></i>
                                    <span>IP: ${vm.ip_address || 'Не назначен'}</span>
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
                
                document.getElementById('vm-list').innerHTML = html;
                updateProgress(100);
                allVmsLoaded = true;
                addVmActionHandlers();
                
                // Запускаем периодическое обновление метрик
                setInterval(updateVmMetrics, 30000); // Обновляем каждые 30 секунд
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
                updateProgress(100);
            }
        } catch (error) {
            console.error('Error loading VMs:', error);
            updateProgress(100);
        }
    }
    
    // Обновление метрик VM
    async function updateVmMetrics() {
        if (!allVmsLoaded) return;
        
        const vmCards = document.querySelectorAll('.vm-card:not(.loading)');
        
        for (const card of vmCards) {
            const vmId = card.dataset.vmid;
            
            try {
                const response = await fetch(`/api/get_vm_metrics.php?vm_id=${vmId}&timeframe=hour`);
                const data = await response.json();
                
                if (data.success) {
                    const cpu = data.cpuData[data.cpuData.length - 1] || 0;
                    const ram = data.memData[data.memData.length - 1] || 0;
                    const ramTotal = data.memTotalData[0] || 1;
                    const disk = (data.diskReadData[data.diskReadData.length - 1] || 0) + 
                                 (data.diskWriteData[data.diskWriteData.length - 1] || 0);
                    
                    const ramUsagePercent = Math.round((ram / ramTotal) * 100);
                    
                    // Обновляем прогресс-бары
                    const cpuFill = card.querySelector('.cpu-progress');
                    const ramFill = card.querySelector('.ram-progress');
                    const diskFill = card.querySelector('.disk-progress');
                    
                    if (cpuFill) {
                        cpuFill.style.width = `${cpu}%`;
                        cpuFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child').textContent = `${Math.round(cpu)}%`;
                    }
                    
                    if (ramFill) {
                        ramFill.style.width = `${ramUsagePercent}%`;
                        const label = ramFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child');
                        label.textContent = `${ramUsagePercent}% (${ram.toFixed(1)}/${ramTotal.toFixed(1)} GB)`;
                    }
                    
                    if (diskFill) {
                        const diskPercent = Math.min(100, disk * 10);
                        diskFill.style.width = `${diskPercent}%`;
                        diskFill.closest('.mini-progress-container').querySelector('.progress-label span:last-child').textContent = `${disk.toFixed(2)} MB/s`;
                    }
                }
            } catch (error) {
                console.error(`Error updating metrics for VM ${vmId}:`, error);
            }
        }
    }
    
    // [Остальной код остается без изменений...]
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
    
    function handleVmAction(action, nodeId, vm_id, card) {
        if (vmActionInProgress) return;
        vmActionInProgress = true;
        
        const actionText = {
            'start': 'запуск',
            'stop': 'остановка',
            'reboot': 'перезагрузка'
        }[action];
        
        Swal.fire({
            title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} VM...`,
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        fetch('vm_action.php', {
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
                    text: `Виртуальная машина #${vm_id} успешно ${
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

    // Функция для загрузки метрик VM
    async function loadVmMetrics(vm_id, timeframe) {
        try {
            updateMetricsProgress(10);
            
            const response = await fetch(`/api/get_vm_metrics.php?vm_id=${vm_id}&timeframe=${timeframe}`);
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
    function openMetricsModal(vm_id, vm_name) {
        currentVmId = vm_id;
        currentVmName = vm_name;
        
        // Устанавливаем заголовок
        document.getElementById('metricsModalTitle').textContent = `Метрики VM #${vm_id} (${vm_name})`;
        
        // Сбрасываем прогресс-бар
        document.querySelector('#metricsModal .progress-container').style.display = 'block';
        document.querySelector('#metricsModal .progress-container').style.opacity = '1';
        updateMetricsProgress(0);
        
        // Показываем модальное окно
        const modal = document.getElementById('metricsModal');
        modal.style.display = 'block';
        
        // Загружаем метрики для текущего диапазона времени
        const timeframe = document.getElementById('timeframe').value;
        loadVmMetrics(vm_id, timeframe);
    }
    
    function addVmActionHandlers() {
        document.querySelectorAll('.vm-start').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('start', card.dataset.nodeid, card.dataset.vmid, card);
            });
        });

        document.querySelectorAll('.vm-stop').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('stop', card.dataset.nodeid, card.dataset.vmid, card);
            });
        });

        document.querySelectorAll('.vm-reboot').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                handleVmAction('reboot', card.dataset.nodeid, card.dataset.vmid, card);
            });
        });

        document.querySelectorAll('.vm-console').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const nodeId = card.dataset.nodeid;
                openVncConsole(nodeId, vm_id);
            });
        });

        document.querySelectorAll('.vm-metrics').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.vm-card');
                const vm_id = card.dataset.vmid;
                const vm_name = card.querySelector('.vm-name').textContent;
                openMetricsModal(vm_id, vm_name);
            });
        });
    }

    function openVncConsole(nodeId, vm_id) {
        if (vmActionInProgress) return;
        vmActionInProgress = true;
        
        Swal.fire({
            title: 'Подготовка консоли...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся...</div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                fetch(`vnc_console.php?node_id=${nodeId}&vm_id=${vm_id}`, {
                    credentials: 'include'
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.error || 'Ошибка сервера');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    vmActionInProgress = false;
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Не удалось подключиться');
                    }

                    const iframe = document.createElement('iframe');
                    iframe.src = 'about:blank';
                    iframe.style.display = 'none';
                    document.body.appendChild(iframe);

                    iframe.contentDocument.cookie = `${data.data.cookie.name}=${encodeURIComponent(data.data.cookie.value)}; ` +
                        `domain=${data.data.cookie.domain}; ` +
                        `path=${data.data.cookie.path}; ` +
                        `secure=${data.data.cookie.secure}; ` +
                        `httponly=${data.data.cookie.httponly}`;

                    setTimeout(() => {
                        const vncWindow = window.open(
                            data.data.url,
                            `vnc_${nodeId}_${vm_id}`,
                            'width=1024,height=768,scrollbars=yes,resizable=yes'
                        );

                        if (!vncWindow || vncWindow.closed) {
                            throw new Error('Не удалось открыть окно VNC. Разрешите всплывающие окна.');
                        }

                        setTimeout(() => document.body.removeChild(iframe), 3000);
                        
                        Swal.close();
                    }, 500);
                })
                .catch(error => {
                    vmActionInProgress = false;
                    Swal.fire({
                        title: 'Ошибка подключения',
                        text: error.message,
                        icon: 'error'
                    });
                    console.error('VNC Error:', error);
                });
            }
        });
    }
    
    // Инициализация
    document.addEventListener('DOMContentLoaded', function() {
        checkScreenSize();
        
        // Загружаем данные последовательно
        loadStats().then(() => {
            return loadVms();
        }).then(() => {
            // Через 1 секунду скрываем прогресс-бар
            setTimeout(() => {
                document.querySelector('.progress-container').style.opacity = '0';
                setTimeout(() => {
                    document.querySelector('.progress-container').style.display = 'none';
                }, 500);
            }, 1000);
        });
        
        // Инициализация поиска
        document.getElementById('vm-search').addEventListener('input', function(e) {
            if (!allVmsLoaded) return;
            
            const searchTerm = e.target.value.toLowerCase();
            const vmCards = document.querySelectorAll('.vm-card');
            
            vmCards.forEach(card => {
                const vmName = card.querySelector('.vm-name').textContent.toLowerCase();
                const vmId = card.querySelector('.vm-id').textContent.toLowerCase();
                
                if (vmName.includes(searchTerm) || vmId.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
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
                const timeframe = this.value;
                loadVmMetrics(currentVmId, timeframe);
            }
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