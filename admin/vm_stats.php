<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$vmId = $_GET['vm_id'] ?? '';
$nodeId = $_GET['node_id'] ?? '';
$timeframe = $_GET['timeframe'] ?? 'hour';

if (empty($vmId) || empty($nodeId)) {
    die("Не указаны параметры VM ID или Node ID.");
}

$db = new Database();
$pdo = $db->getConnection();

function safeQuery($pdo, $query, $tableName = null, $params = []) {
    // Если указано имя таблицы - проверяем её существование
    if ($tableName !== null) {
        $checkQuery = "SHOW TABLES LIKE '" . $pdo->quote($tableName) . "'";
        $checkTable = $pdo->query($checkQuery);
        
        if (!$checkTable->fetch()) {
            throw new Exception("Таблица $tableName не существует");
        }
    }
    
    // Выполняем основной запрос
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    return $stmt;
}


// Получаем информацию о ВМ
$stmt = $pdo->prepare("
    SELECT v.*, n.hostname as node_hostname, n.node_name, n.username, n.password 
    FROM vms_admin v
    JOIN proxmox_nodes n ON n.id = v.node_id
    WHERE v.vm_id = ? AND v.node_id = ?
");
$stmt->execute([$vmId, $nodeId]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    die("Административная виртуальная машина не найдена.");
}

$timeframes = [
    'hour' => '1 час',
    'day' => '1 день',
    'week' => '1 неделя',
    'month' => '1 месяц',
    'year' => '1 год',
];

$title = "Статистика административной ВМ VMID: {$vmId} ({$vm['hostname']}) | HomeVlad Cloud";
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/metrics_style.css'; ?>
    </style>
    <script src="../js/theme.js" defer></script>
</head>
<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include 'admin_sidebar.php'; ?>

            <main class="admin-main">
                <div class="admin-header-container">
                    <div class="admin-header-content">
                        <h1 class="admin-title">
                            <i class="fas fa-chart-line"></i> Статистика административной ВМ VMID: <?= $vmId ?> (<?= htmlspecialchars($vm['hostname']) ?>)
                        </h1>
                        <div class="timeframe-filter">
                            <label for="timeframe">Период:</label>
                            <select id="timeframe" onchange="updateTimeframe()">
                                <?php foreach ($timeframes as $key => $value): ?>
                                    <option value="<?= $key ?>" <?= $timeframe === $key ? 'selected' : '' ?>>
                                        <?= $value ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="progress-container">
                    <div class="progress-bar" id="loading-progress"></div>
                </div>

                <section class="section">
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
                </section>
            </main>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const vmId = '<?= $vmId ?>';
        const nodeId = '<?= $nodeId ?>';
        const timeframe = document.getElementById('timeframe').value;
        const loadingProgress = document.getElementById('loading-progress');
        
        // Функция для обновления прогресс-бара
        function updateProgress(percent) {
            loadingProgress.style.width = percent + '%';
        }
        
        // Загрузка данных статистики
        async function loadStats() {
            updateProgress(10);
            
            try {
                const response = await fetch(`get_vm_stats.php?vm_id=${vmId}&node_id=${nodeId}&timeframe=${timeframe}`);
                if (!response.ok) throw new Error('Ошибка сервера');
                
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');
                
                updateProgress(30);
                
                // Создаем графики
                createCharts(data);
                
                updateProgress(100);
                
                // Через 1 секунду скрываем прогресс-бар
                setTimeout(() => {
                    document.querySelector('.progress-container').style.opacity = '0';
                    setTimeout(() => {
                        document.querySelector('.progress-container').style.display = 'none';
                    }, 500);
                }, 1000);
                
            } catch (error) {
                console.error('Ошибка загрузки статистики:', error);
                updateProgress(100);
                
                const metricsGrid = document.querySelector('.metrics-grid');
                metricsGrid.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${error.message}
                    </div>
                `;
                
                setTimeout(() => {
                    document.querySelector('.progress-container').style.opacity = '0';
                    setTimeout(() => {
                        document.querySelector('.progress-container').style.display = 'none';
                    }, 500);
                }, 1000);
            }
        }
        
        // Функция для создания графиков (аналогична вашей реализации)
        function createCharts(data) {
            // График CPU Usage
            new Chart(
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
            
            updateProgress(50);
            
            // График Memory Usage
            new Chart(
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
            
            updateProgress(70);
            
            // График Network Traffic
            new Chart(
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
            
            updateProgress(90);
            
            // График Disk IO
            new Chart(
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
        
        // Обработчик изменения временного диапазона
        function updateTimeframe() {
            const newTimeframe = document.getElementById('timeframe').value;
            const url = new URL(window.location.href);
            url.searchParams.set('timeframe', newTimeframe);
            window.location.href = url.toString();
        }
        
        // Загружаем данные при загрузке страницы
        loadStats();
    });
    </script>
</body>
</html>