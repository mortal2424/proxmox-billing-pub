<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/proxmox_functions.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем список административных ВМ
try {
    $sql = "SELECT id, hostname, status, vm_id, node_id";
    if (columnExists($pdo, 'vms_admin', 'created_at')) {
        $sql .= ", created_at";
    }
    $sql .= " FROM vms_admin ORDER BY id DESC";

    $stmt = safeQuery($pdo, $sql);
    $adminVms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log("Database error in vms.php (admin): " . $e->getMessage());
    $adminVms = [];
}

// Обработка действий с ВМ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['delete_vm'])) {
            $vmId = (int)$_POST['vm_id'];
            safeQuery($pdo, "DELETE FROM vms_admin WHERE id = ?", [$vmId]);
            $_SESSION['success'] = "Административная ВМ успешно удалена";
            header("Location: vms.php");
            exit;
        }

        // Обработка управления ВМ (старт, стоп, перезагрузка)
        if (isset($_POST['vm_action']) && isset($_POST['vm_id']) && isset($_POST['node_id'])) {
            $vmId = (int)$_POST['vm_id'];
            $nodeId = (int)$_POST['node_id'];
            $action = $_POST['vm_action'];

            $nodeInfo = safeQuery($pdo, "SELECT * FROM proxmox_nodes WHERE id = ?", [$nodeId])->fetch();

            if ($nodeInfo) {
                $proxmox = new ProxmoxAPI(
                    $nodeInfo['hostname'],
                    $nodeInfo['username'],
                    $nodeInfo['password'],
                    $nodeInfo['ssh_port'] ?? 22,
                    $nodeInfo['node_name'],
                    $nodeId,
                    $pdo
                );

                switch ($action) {
                    case 'start':
                        $proxmox->startVM($vmId);
                        break;
                    case 'stop':
                        $proxmox->stopVM($vmId);
                        break;
                    case 'reboot':
                        $proxmox->rebootVM($vmId);
                        break;
                }

                // Обновляем статус в базе данных
                $newStatus = $action === 'stop' ? 'stopped' : 'running';
                safeQuery($pdo, "UPDATE vms_admin SET status = ? WHERE vm_id = ? AND node_id = ?",
                         [$newStatus, $vmId, $nodeId]);

                $_SESSION['success'] = "Команда '$action' выполнена для VM $vmId";
                header("Location: vms.php");
                exit;
            }
        }
    } catch (Exception $e) {
        error_log("VM action error: " . $e->getMessage());
        $_SESSION['error'] = "Ошибка: " . $e->getMessage();
    }
}

$title = "Управление административными ВМ | ITSP Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>

        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-server"></i> Административные виртуальные машины
            </h1>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <section class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i> Список административных ВМ
                    </h2>
                    <a href="vm_add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Добавить ВМ
                    </a>
                </div>

                <?php if (!empty($adminVms)): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Имя</th>
                                    <th>VMID</th>
                                    <th>Нода</th>
                                    <th>Статус</th>
                                    <?php if (columnExists($pdo, 'vms_admin', 'created_at')): ?>
                                    <th>Дата создания</th>
                                    <?php endif; ?>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminVms as $vm):
                                    $nodeName = safeQuery($pdo, "SELECT node_name FROM proxmox_nodes WHERE id = ?", [$vm['node_id']])->fetchColumn();
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($vm['id']) ?></td>
                                    <td><?= htmlspecialchars($vm['hostname']) ?></td>
                                    <td><?= htmlspecialchars($vm['vm_id']) ?></td>
                                    <td><?= htmlspecialchars($nodeName) ?></td>
                                    <td>
                                        <span class="status-badge <?= $vm['status'] === 'running' ? 'status-active' : 'status-inactive' ?>">
                                            <?= $vm['status'] === 'running' ? 'Запущена' : 'Остановлена' ?>
                                        </span>
                                    </td>
                                    <?php if (columnExists($pdo, 'vms_admin', 'created_at')): ?>
                                    <td><?= htmlspecialchars(date('d.m.Y', strtotime($vm['created_at'] ?? ''))) ?></td>
                                    <?php endif; ?>
                                    <td class="actions">
                                        <div class="action-buttons">
                                            <?php if ($vm['status'] === 'running'): ?>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="vm_id" value="<?= (int)$vm['vm_id'] ?>">
                                                    <input type="hidden" name="node_id" value="<?= (int)$vm['node_id'] ?>">
                                                    <input type="hidden" name="vm_action" value="stop">
                                                    <button type="submit" class="action-btn action-btn-stop" title="Остановить">
                                                        <i class="fas fa-stop"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="vm_id" value="<?= (int)$vm['vm_id'] ?>">
                                                    <input type="hidden" name="node_id" value="<?= (int)$vm['node_id'] ?>">
                                                    <input type="hidden" name="vm_action" value="reboot">
                                                    <button type="submit" class="action-btn action-btn-reboot" title="Перезагрузить">
                                                        <i class="fas fa-redo"></i>
                                                    </button>
                                                </form>

                                                <!-- ЕДИНАЯ корректная кнопка VNC (JS-обработчик), БЕЗ прямого перехода на vnc_console.php -->
                                                <a href="#"
                                                   class="action-btn action-btn-console btn-vnc"
                                                   data-node="<?= (int)$vm['node_id'] ?>"
                                                   data-vmid="<?= (int)$vm['vm_id'] ?>"
                                                   onclick="openVNC(<?= (int)$vm['node_id'] ?>, <?= (int)$vm['vm_id'] ?>); return false;"
                                                   title="Консоль VNC">
                                                    <i class="fas fa-desktop"></i>
                                                </a>
                                            <?php else: ?>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="vm_id" value="<?= (int)$vm['vm_id'] ?>">
                                                    <input type="hidden" name="node_id" value="<?= (int)$vm['node_id'] ?>">
                                                    <input type="hidden" name="vm_action" value="start">
                                                    <button type="submit" class="action-btn action-btn-start" title="Запустить">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <a href="vm_admin_edit.php?id=<?= (int)$vm['id'] ?>"
                                               class="action-btn action-btn-edit" title="Редактировать">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <form method="POST" onsubmit="return confirm('Вы уверены?')" class="action-form">
                                                <input type="hidden" name="vm_id" value="<?= (int)$vm['id'] ?>">
                                                <button type="submit" name="delete_vm" class="action-btn action-btn-delete" title="Удалить">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>

                                            <button class="action-btn action-btn-stats"
                                                    title="Графики"
                                                    onclick="showVmStats(<?= (int)$vm['vm_id'] ?>, <?= (int)$vm['node_id'] ?>, '<?= htmlspecialchars($vm['hostname'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-chart-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i> Нет административных виртуальных машин
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<!-- Модальное окно для графиков -->
<div id="statsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Статистика ВМ</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="timeframe-filter">
                <label for="timeframe">Период:</label>
                <select id="timeframe" onchange="updateStats()">
                    <option value="hour">1 час</option>
                    <option value="day">1 день</option>
                    <option value="week">1 неделя</option>
                    <option value="month">1 месяц</option>
                    <option value="year">1 год</option>
                </select>
            </div>

            <div class="progress-container">
                <div class="progress-bar" id="loading-progress"></div>
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
</div>

<style>
    <?php include '../admin/css/vms_styles.css'; ?>
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Текущие данные для графиков
let currentCharts = { cpu: null, memory: null, network: null, disk: null };

// Текущие параметры ВМ
let currentVm = { id: null, nodeId: null, name: null };

// Показать модальное окно с графиками
function showVmStats(vmId, nodeId, vmName) {
    currentVm.id = vmId;
    currentVm.nodeId = nodeId;
    currentVm.name = vmName;

    document.getElementById('modalTitle').textContent = `Статистика ВМ ${vmId} (${vmName})`;
    document.getElementById('statsModal').style.display = 'block';

    // Загружаем данные
    updateStats();
}

// Закрыть модальное окно
function closeModal() {
    document.getElementById('statsModal').style.display = 'none';
    Object.values(currentCharts).forEach(chart => { if (chart) chart.destroy(); });
    currentCharts = { cpu: null, memory: null, network: null, disk: null };
}

// Обновить данные статистики
async function updateStats() {
    const timeframe = document.getElementById('timeframe').value;
    const loadingProgress = document.getElementById('loading-progress');
    const progressContainer = document.querySelector('.progress-container');

    progressContainer.style.display = 'block';
    loadingProgress.style.width = '0%';

    try {
        updateProgress(loadingProgress, 10);
        const response = await fetch(`get_vm_stats.php?vm_id=${currentVm.id}&node_id=${currentVm.nodeId}&timeframe=${timeframe}`);
        if (!response.ok) throw new Error('Ошибка сервера');

        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');

        updateProgress(loadingProgress, 30);
        updateCharts(data);
        updateProgress(loadingProgress, 100);

        setTimeout(() => {
            progressContainer.style.opacity = '0';
            setTimeout(() => { progressContainer.style.display = 'none'; }, 500);
        }, 1000);

    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
        updateProgress(loadingProgress, 100);
        const metricsGrid = document.querySelector('.metrics-grid');
        metricsGrid.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                ${error.message}
            </div>`;
        setTimeout(() => {
            progressContainer.style.opacity = '0';
            setTimeout(() => { progressContainer.style.display = 'none'; }, 500);
        }, 1000);
    }
}

function updateProgress(element, percent) { element.style.width = percent + '%'; }

function updateCharts(data) {
    Object.values(currentCharts).forEach(chart => { if (chart) chart.destroy(); });
    currentCharts.cpu    = createCpuChart(data);
    currentCharts.memory = createMemoryChart(data);
    currentCharts.network= createNetworkChart(data);
    currentCharts.disk   = createDiskChart(data);
}

function createCpuChart(data) {
    return new Chart(document.getElementById('cpuChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Использование CPU',
                data: data.cpuData,
                borderColor: 'rgba(255, 99, 132, 1)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                borderWidth: 2, fill: true, tension: 0.1
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' },
                       tooltip: { mode: 'index', intersect: false,
                         callbacks:{ label: (c)=> c.dataset.label+': '+c.parsed.y.toFixed(1)+' %' } } },
            scales: {
                y: { beginAtZero: true, max: 100,
                     title: { display: true, text: 'Использование CPU (%)' },
                     ticks: { callback: v => v.toFixed(0)+' %' } },
                x: { title: { display: true, text: 'Время' } }
            }
        }
    });
}

function createMemoryChart(data) {
    return new Chart(document.getElementById('memoryChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                { label:'Используемая память', data:data.memData,
                  borderColor:'rgba(54, 162, 235, 1)', backgroundColor:'rgba(54, 162, 235, 0.2)',
                  borderWidth:2, fill:true, tension:0.1 },
                { label:'Всего памяти', data:data.memTotalData,
                  borderColor:'rgba(75, 192, 192, 1)', borderWidth:0, backgroundColor:'rgba(0,0,0,0)',
                  pointRadius:0, pointHoverRadius:0, fill:false }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'top', labels:{ filter: i => i.text!=='Всего памяти' } },
                      tooltip:{ mode:'index', intersect:false,
                        callbacks:{ label:(c)=> c.dataset.label+': '+c.parsed.y.toFixed(1)+' ГБ' } } },
            scales:{
                y:{ beginAtZero:true, title:{ display:true, text:'Память (ГБ)' },
                    ticks:{ callback:v=> v.toFixed(1)+' ГБ' } },
                x:{ title:{ display:true, text:'Время' } }
            }
        }
    });
}

function createNetworkChart(data) {
    return new Chart(document.getElementById('networkChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                { label:'Входящий трафик', data:data.netInData,
                  borderColor:'rgba(153, 102, 255, 1)', backgroundColor:'rgba(153,102,255,0.2)',
                  borderWidth:2, fill:true, tension:0.1 },
                { label:'Исходящий трафик', data:data.netOutData,
                  borderColor:'rgba(255, 159, 64, 1)', backgroundColor:'rgba(255,159,64,0.2)',
                  borderWidth:2, fill:true, tension:0.1 }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'top' },
                      tooltip:{ mode:'index', intersect:false,
                        callbacks:{ label:(c)=> c.dataset.label+': '+c.parsed.y.toFixed(2)+' Mbit/s' } } },
            scales:{
                y:{ beginAtZero:true, title:{ display:true, text:'Скорость передачи (Mbit/s)' },
                    ticks:{ callback:v=> v.toFixed(2)+' Mbit/s' } },
                x:{ title:{ display:true, text:'Время' } }
            }
        }
    });
}

function createDiskChart(data) {
    return new Chart(document.getElementById('diskChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                { label:'Чтение с диска', data:data.diskReadData,
                  borderColor:'rgba(255, 99, 132, 1)', backgroundColor:'rgba(255,99,132,0.2)',
                  borderWidth:2, fill:true, tension:0.1 },
                { label:'Запись на диск', data:data.diskWriteData,
                  borderColor:'rgba(54, 162, 235, 1)', backgroundColor:'rgba(54,162,235,0.2)',
                  borderWidth:2, fill:true, tension:0.1 }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'top' },
                      tooltip:{ mode:'index', intersect:false,
                        callbacks:{ label:(c)=> c.dataset.label+': '+c.parsed.y.toFixed(2)+' МБ' } } },
            scales:{
                y:{ beginAtZero:true, title:{ display:true, text:'Дисковые операции (МБ)' },
                    ticks:{ callback:v=> v.toFixed(2)+' МБ' } },
                x:{ title:{ display:true, text:'Время' } }
            }
        }
    });
}

// Закрыть модальное окно при клике вне его
window.onclick = function(event) {
    const modal = document.getElementById('statsModal');
    if (event.target == modal) closeModal();
}
</script>

<!-- Гарантируем подключение обработчика VNC -->
<script src="/admin/js/proxmox.js?v=20251026"></script>

<?php require 'admin_footer.php'; ?>