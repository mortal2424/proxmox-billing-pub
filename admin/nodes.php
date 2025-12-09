<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем список кластеров с количеством нод
$clusters = $pdo->query("
    SELECT c.*, COUNT(n.id) as nodes_count 
    FROM proxmox_clusters c
    LEFT JOIN proxmox_nodes n ON n.cluster_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Получаем список нод с именами кластеров
$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name 
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = "Управление нодами и кластерами | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="section-actions" style="display: flex; justify-content: flex-end; gap: 20px; margin-bottom: 30px;">
                <a href="add_cluster.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Создать кластер
                </a>
                <a href="add_node.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Создать ноду
                </a>
            </div>

            <!-- Список кластеров -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-network-wired"></i> Список кластеров
                </h2>
                
                <?php if (!empty($clusters)): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Имя</th>
                                <th>Описание</th>
                                <th>Нод</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clusters as $cluster): ?>
                            <tr>
                                <td><?= htmlspecialchars($cluster['name']) ?></td>
                                <td><?= htmlspecialchars($cluster['description']) ?></td>
                                <td><?= $cluster['nodes_count'] ?></td>
                                <td>
                                    <span class="status-badge <?= $cluster['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $cluster['is_active'] ? 'Активен' : 'Неактивен' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="edit_cluster.php?id=<?= $cluster['id'] ?>" class="btn btn-icon btn-edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="delete_cluster.php" class="inline-form">
                                        <input type="hidden" name="id" value="<?= $cluster['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-delete" 
                                                onclick="return confirm('Удалить этот кластер? Все связанные ноды также будут удалены.')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Нет созданных кластеров
                    </div>
                <?php endif; ?>
            </section>

            <!-- Список нод -->
            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-server"></i> Список нод
                </h2>
                
                <?php if (!empty($nodes)): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Кластер</th>
                                <th>Нода</th>
                                <th>Адрес</th>
                                <th>Порт</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nodes as $node): ?>
                            <tr>
                                <td><?= htmlspecialchars($node['cluster_name']) ?></td>
                                <td><?= htmlspecialchars($node['node_name']) ?></td>
                                <td><?= htmlspecialchars($node['hostname']) ?></td>
                                <td><?= $node['api_port'] ?></td>
                                <td>
                                    <span class="status-badge <?= $node['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $node['is_active'] ? 'Активна' : 'Неактивна' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <button onclick="showNodeInfo(<?= $node['id'] ?>)" class="btn btn-icon btn-info">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                    <button onclick="showNodeCharts(<?= $node['id'] ?>, '<?= $node['cluster_name'] ?>', '<?= $node['node_name'] ?>')" class="btn btn-icon btn-chart">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                    <a href="edit_node.php?id=<?= $node['id'] ?>" class="btn btn-icon btn-edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="delete_node.php" class="inline-form">
                                        <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-delete" 
                                                onclick="return confirm('Удалить эту ноду?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Нет добавленных нод
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.8"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>

<style>
    <?php include '../admin/css/nodes_styles.css'; ?>
</style>

<script>
// Функция для отображения информации о ноде
function showNodeInfo(nodeId) {
    Swal.fire({
        title: 'Получение данных...',
        allowOutsideClick: false,
        background: 'var(--card-bg)',
        color: 'var(--text-color)',
        didOpen: () => Swal.showLoading()
    });

    fetch('get_node_info.php?id=' + nodeId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                Swal.fire({
                    title: 'Ошибка',
                    text: data.error,
                    icon: 'error',
                    background: 'var(--card-bg)',
                    color: 'var(--text-color)'
                });
                return;
            }

            let html = `
                <div class="node-info">
                    <div class="info-section">
                        <h3><i class="fas fa-microchip"></i> Процессор</h3>
                        <p><strong>Модель:</strong> ${data.cpu_model}</p>
                        <p><strong>Ядра/Потоки:</strong> ${data.cpu_physical} / ${data.cpu_threads}</p>
                        <p><strong>Сокетов:</strong> ${data.cpu_sockets}</p>
                    </div>

                    <div class="info-section">
                        <h3><i class="fas fa-memory"></i> Память</h3>
                        <p><strong>Всего:</strong> ${data.ram_total}</p>
                        <p><strong>Использовано:</strong> ${data.ram_used} (${data.ram_percent})</p>
                    </div>

                    <div class="info-section">
                        <h3><i class="fas fa-network-wired"></i> Сеть</h3>
                        <p><strong>IP-адрес:</strong> ${data.ip}</p>
                        <p><strong>MAC-адрес:</strong> ${data.mac}</p>
                    </div>
            `;

            if (data.disks && data.disks.length > 0) {
                html += `<div class="info-section">
                    <h3><i class="fas fa-hdd"></i> Диски</h3>`;

                data.disks.forEach(disk => {
                    html += `
                        <div class="disk-info">
                            <p><strong>${disk.name}:</strong> ${disk.size} (${disk.percent} использовано)</p>
                            <p><small>Монтирован в: ${disk.mount || 'не смонтирован'}</small></p>
                        </div>
                    `;
                });

                html += `</div>`;
            }

            html += `</div>`;

            Swal.fire({
                title: 'Информация о ноде',
                html: html,
                width: '700px',
                background: 'var(--card-bg)',
                color: 'var(--text-color)',
                confirmButtonColor: 'var(--primary-color)',
                confirmButtonText: 'Закрыть'
            });
        })
        .catch(error => {
            Swal.fire({
                title: 'Ошибка',
                text: 'Не удалось получить данные: ' + error.message,
                icon: 'error',
                background: 'var(--card-bg)',
                color: 'var(--text-color)'
            });
        });
}

// Глобальные переменные для хранения графиков
const nodeCharts = {};
let autoRefreshInterval = null;
let currentNodeId = null;
let currentClusterName = null;
let currentNodeName = null;
let currentHours = 3;
let currentInterval = 5; // 5 минут по умолчанию

function showNodeCharts(nodeId, clusterName, nodeName) {
    currentNodeId = nodeId;
    currentClusterName = clusterName;
    currentNodeName = nodeName;
    
    // Останавливаем предыдущее автообновление
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    // Создаем модальное окно с дополнительными элементами управления
    const modal = Swal.fire({
        title: `Мониторинг ноды #${nodeId} (Кластер "${clusterName}", Нода "${nodeName}")`,
        html: `
            <div class="charts-controls">
                <div class="time-filters">
                    <button class="btn-time active" data-hours="1">1 час</button>
                    <button class="btn-time" data-hours="3">3 часа</button>
                    <button class="btn-time" data-hours="6">6 часов</button>
                    <button class="btn-time" data-hours="24">24 часа</button>
                    <button class="btn-time" data-hours="72">3 дня</button>
                </div>
                <div class="interval-filters">
                    <select class="interval-select" id="intervalSelect">
                        <option value="5">5 минут</option>
                        <option value="30">30 минут</option>
                        <option value="180">3 часа</option>
                        <option value="360">6 часов</option>
                    </select>
                    <label class="auto-refresh">
                        <input type="checkbox" id="autoRefreshCheck" checked>
                        Автообновление (30 сек)
                    </label>
                </div>
            </div>
            <div class="charts-container">
                <div class="chart-row">
                    <div class="chart-box">
                        <canvas id="cpuChart-${nodeId}" height="200"></canvas>
                    </div>
                    <div class="chart-box">
                        <canvas id="ramChart-${nodeId}" height="200"></canvas>
                    </div>
                </div>
                <div class="chart-row">
                    <div class="chart-box">
                        <canvas id="networkChart-${nodeId}" height="200"></canvas>
                    </div>
                </div>
            </div>
        `,
        width: '90%',
        showConfirmButton: false,
        background: 'var(--card-bg)',
        color: 'var(--text-color)',
        didOpen: () => {
            // Инициализация элементов управления
            initControls(nodeId);
            
            // Загрузка данных
            loadChartData(nodeId, currentHours, currentInterval);
            
            // Запуск автообновления
            setupAutoRefresh(nodeId);
        },
        willClose: () => {
            // Очистка при закрытии
            destroyCharts(nodeId);
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
            currentNodeId = null;
        }
    });
}

// Инициализация элементов управления
function initControls(nodeId) {
    // Обработчики временного диапазона
    document.querySelectorAll('.btn-time').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.btn-time').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentHours = parseInt(this.dataset.hours);
            loadChartData(nodeId, currentHours, currentInterval);
        });
    });
    
    // Обработчик выбора интервала
    const intervalSelect = document.getElementById('intervalSelect');
    intervalSelect.addEventListener('change', function() {
        currentInterval = parseInt(this.value);
        loadChartData(nodeId, currentHours, currentInterval);
    });
    
    // Обработчик автообновления
    const autoRefreshCheck = document.getElementById('autoRefreshCheck');
    autoRefreshCheck.addEventListener('change', function() {
        if (this.checked) {
            setupAutoRefresh(nodeId);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    });
}

// Настройка автообновления
function setupAutoRefresh(nodeId) {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    autoRefreshInterval = setInterval(() => {
        loadChartData(nodeId, currentHours, currentInterval);
    }, 30000); // 30 секунд
}

// Загрузка данных с учетом интервала
function loadChartData(nodeId, hours, interval) {
    Swal.showLoading();
    
    fetch(`get_node_stats.php?id=${nodeId}&hours=${hours}&interval=${interval}`)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (!data || data.length === 0) throw new Error('Нет данных для отображения');
            
            updateOrCreateChart(nodeId, 'cpu', data);
            updateOrCreateChart(nodeId, 'ram', data);
            updateOrCreateChart(nodeId, 'network', data);
            
            // Обновляем время последнего обновления
            const now = new Date();
            document.querySelector('.swal2-title').innerHTML = 
                `Мониторинг ноды: ${currentNodeName} (Кластер: ${currentClusterName}) <br><small>Последнее обновление: ${now.toLocaleTimeString()}</small>`;
            
            Swal.hideLoading();
        })
        .catch(error => {
            console.error('Ошибка загрузки данных:', error);
            Swal.hideLoading();
            
            // Показываем ошибку только если модальное окно еще открыто
            if (currentNodeId === nodeId) {
                Swal.fire({
                    icon: 'error',
                    title: 'Ошибка',
                    text: 'Не удалось загрузить данные для графиков',
                    timer: 2000,
                    showConfirmButton: false,
                    background: 'var(--card-bg)',
                    color: 'var(--text-color)'
                });
            }
        });
}

// Функция обновления или создания графика
function updateOrCreateChart(nodeId, type, data) {
    const ctx = document.getElementById(`${type}Chart-${nodeId}`).getContext('2d');
    const chartId = `${type}-${nodeId}`;
    
    // Если график уже существует - обновляем данные
    if (nodeCharts[chartId]) {
        updateChartData(nodeCharts[chartId], type, data);
        return;
    }
    
    // Создаем новый график
    nodeCharts[chartId] = new Chart(ctx, getChartConfig(type, data));
}

// Функция обновления данных графика
function updateChartData(chart, type, data) {
    const { labels, datasets } = prepareChartData(type, data);
    
    chart.data.labels = labels;
    chart.data.datasets.forEach((dataset, i) => {
        dataset.data = datasets[i].data;
        if (datasets[i].label) {
            dataset.label = datasets[i].label;
        }
    });
    chart.update();
}

// Подготовка данных для графиков
function prepareChartData(type, data) {
    const labels = data.map(item => item.timestamp);
    
    switch(type) {
        case 'cpu':
            return {
                labels: labels,
                datasets: [{
                    data: data.map(item => item.cpu),
                    label: 'Использование CPU (%)'
                }]
            };
        
        case 'ram':
            const totalRamGB = (data[data.length-1].memory_total / 1024).toFixed(1);
            return {
                labels: labels,
                datasets: [{
                    data: data.map(item => item.memory),
                    label: `Использование RAM (${totalRamGB} GB всего) (%)`
                }]
            };
        
        case 'network':
            return {
                labels: labels,
                datasets: [
                    {
                        data: data.map(item => item.network_rx /100), // в Mbit/s
                        label: 'Входящий трафик'
                    },
                    {
                        data: data.map(item => item.network_tx /100), // в Mbit/s
                        label: 'Исходящий трафик'
                    }
                ]
            };
    }
}

// Конфигурация графиков
function getChartConfig(type, data) {
    const { labels, datasets } = prepareChartData(type, data);
    
    const gridColor = 'rgba(255, 255, 255, 0.1)';
    const textColor = 'var(--text-color)';
    
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    color: textColor
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(209, 203, 203, 0.9)',
                titleColor: '#333333',
                bodyColor: '#333333',
                borderColor: 'rgba(0, 0, 0, 0.1)',
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        label += context.parsed.y.toFixed(2);
                        if (type === 'cpu' || type === 'ram') {
                            label += '%';
                        } else {
                            label += ' Mbit/s';
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            x: {
                type: 'time',
                time: {
                    tooltipFormat: 'DD.MM HH:mm',
                    displayFormats: {
                        hour: 'HH:mm'
                    }
                },
                grid: {
                    display: false,
                    color: gridColor
                },
                ticks: {
                    color: textColor
                }
            },
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: type === 'network' ? 'Mbit/s' : '%',
                    color: textColor
                },
                grid: {
                    color: gridColor
                },
                ticks: {
                    color: textColor
                }
            }
        },
        animation: {
            duration: 0
        }
    };
    
    return {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets.map(dataset => ({
                ...dataset,
                borderWidth: 1,
                tension: 0.1,
                fill: true,
                backgroundColor: getBackgroundColor(type, datasets.indexOf(dataset)),
                borderColor: getBorderColor(type, datasets.indexOf(dataset))
            }))
        },
        options: commonOptions
    };
}

// Цвета для графиков
function getBorderColor(type, index) {
    const colors = {
        cpu: 'rgba(100, 149, 237, 1)', // CornflowerBlue
        ram: 'rgba(220, 53, 69, 1)', // Crimson
        network: ['rgba(32, 201, 151, 1)', 'rgba(108, 92, 231, 1)'] // Green & Purple
    };
    return type === 'network' ? colors.network[index] : colors[type];
}

function getBackgroundColor(type, index) {
    const color = getBorderColor(type, index);
    return color.replace('1)', '0.2)');
}

// Уничтожение графиков
function destroyCharts(nodeId) {
    [`cpu-${nodeId}`, `ram-${nodeId}`, `network-${nodeId}`].forEach(chartId => {
        if (nodeCharts[chartId]) {
            nodeCharts[chartId].destroy();
            delete nodeCharts[chartId];
        }
    });
}
</script>

<?php require 'admin_footer.php'; ?>