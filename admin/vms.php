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

// Получаем статистику для карточек
$totalVms = count($adminVms);
$runningVms = 0;
$stoppedVms = 0;
$totalNodes = 0;

foreach ($adminVms as $vm) {
    if ($vm['status'] === 'running') {
        $runningVms++;
    } else {
        $stoppedVms++;
    }
}

// Получаем количество нод
try {
    $nodeCount = safeQuery($pdo, "SELECT COUNT(*) as count FROM proxmox_nodes")->fetch();
    $totalNodes = $nodeCount['count'] ?? 0;
} catch (Exception $e) {
    error_log("Node count error: " . $e->getMessage());
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

$title = "Управление административными ВМ | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ОСНОВНЫЕ ПЕРЕМЕННЫЕ (СИНХРОНИЗИРОВАНЫ С ШАПКОЙ И САЙДБАРОМ) */
:root {
    --admin-bg: #f8fafc;
    --admin-card-bg: #ffffff;
    --admin-text: #1e293b;
    --admin-text-secondary: #475569;
    --admin-border: #cbd5e1;
    --admin-accent: #0ea5e9;
    --admin-accent-hover: #0284c7;
    --admin-accent-light: rgba(14, 165, 233, 0.15);
    --admin-danger: #ef4444;
    --admin-success: #10b981;
    --admin-warning: #f59e0b;
    --admin-info: #3b82f6;
    --admin-purple: #8b5cf6;
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

[data-theme="dark"] {
    --admin-bg: #1e293b;
    --admin-card-bg: #1e293b;
    --admin-text: #f1f5f9;
    --admin-text-secondary: #cbd5e1;
    --admin-border: #334155;
    --admin-accent: #38bdf8;
    --admin-accent-hover: #0ea5e9;
    --admin-accent-light: rgba(56, 189, 248, 0.15);
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

/* ========== ОСНОВНОЙ МАКЕТ ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--admin-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

/* ========== ШАПКА СТРАНИЦЫ ========== */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    box-shadow: var(--admin-shadow);
}

.header-left h1 {
    color: var(--admin-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--admin-accent);
}

.header-left p {
    color: var(--admin-text-secondary);
    font-size: 14px;
    margin: 0;
}

.dashboard-quick-actions {
    display: flex;
    gap: 12px;
}

.dashboard-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.dashboard-action-btn-primary {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
}

.dashboard-action-btn-secondary {
    background: var(--admin-card-bg);
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
    border: 1px solid transparent;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
    color: #047857;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.alert i {
    font-size: 18px;
}

.alert-success i {
    color: #10b981;
}

.alert-danger i {
    color: #ef4444;
}

/* ========== СТАТИСТИКА ВМ ========== */
.vms-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.vm-stat-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s ease;
    box-shadow: var(--admin-shadow);
}

.vm-stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--admin-accent);
    box-shadow: var(--admin-hover-shadow);
}

.vm-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.vm-stat-icon.total { background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover)); }
.vm-stat-icon.running { background: linear-gradient(135deg, var(--admin-success), #059669); }
.vm-stat-icon.stopped { background: linear-gradient(135deg, var(--admin-warning), #d97706); }
.vm-stat-icon.nodes { background: linear-gradient(135deg, var(--admin-purple), #7c3aed); }

.vm-stat-content h4 {
    color: var(--admin-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 4px 0;
}

.vm-stat-value {
    color: var(--admin-text);
    font-size: 24px;
    font-weight: 700;
    margin: 0;
}

.vm-stat-subtext {
    color: var(--admin-text-secondary);
    font-size: 12px;
    margin: 4px 0 0 0;
}

/* ========== ТАБЛИЦА ВМ ========== */
.vms-table-container {
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    overflow: hidden;
    box-shadow: var(--admin-shadow);
}

.vms-table-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header-left h3 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header-left h3 i {
    color: var(--admin-accent);
}

.table-search {
    position: relative;
}

.table-search input {
    padding: 10px 40px 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--admin-border);
    background: var(--admin-card-bg);
    color: var(--admin-text);
    font-size: 14px;
    width: 200px;
    transition: all 0.3s ease;
}

.table-search input:focus {
    outline: none;
    border-color: var(--admin-accent);
    box-shadow: 0 0 0 3px var(--admin-accent-light);
}

.table-search i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--admin-text-secondary);
}

/* ========== СТИЛИ ТАБЛИЦЫ ========== */
.vms-table {
    width: 100%;
    border-collapse: collapse;
}

.vms-table thead th {
    color: var(--admin-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--admin-border);
    background: rgba(14, 165, 233, 0.05);
}

.vms-table tbody tr {
    border-bottom: 1px solid var(--admin-border);
    transition: all 0.3s ease;
}

.vms-table tbody tr:hover {
    background: var(--admin-accent-light);
}

.vms-table tbody td {
    color: var(--admin-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

/* Стили для ячеек таблицы */
.vm-id {
    font-weight: 600;
    color: var(--admin-accent);
    font-family: 'Monaco', 'Consolas', monospace;
}

.vm-name {
    color: var(--admin-text);
    font-weight: 500;
}

.vm-date {
    color: var(--admin-text-secondary);
    font-size: 13px;
}

/* ========== БЕЙДЖИ СТАТУСА ========== */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-running {
    background: linear-gradient(135deg, var(--admin-success), #059669);
    color: white;
}

.status-stopped {
    background: rgba(239, 68, 68, 0.15);
    color: var(--admin-danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* ========== ДЕЙСТВИЯ С ВМ ========== */
.vm-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.vm-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s ease;
    background: transparent;
    color: var(--admin-text-secondary);
}

.vm-action-btn:hover {
    transform: translateY(-2px);
}

.vm-action-start {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--admin-success);
}

.vm-action-start:hover {
    background: rgba(16, 185, 129, 0.25);
    border-color: var(--admin-success);
}

.vm-action-stop {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--admin-danger);
}

.vm-action-stop:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: var(--admin-danger);
}

.vm-action-reboot {
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: var(--admin-warning);
}

.vm-action-reboot:hover {
    background: rgba(245, 158, 11, 0.25);
    border-color: var(--admin-warning);
}

.vm-action-console {
    background: rgba(139, 92, 246, 0.15);
    border: 1px solid rgba(139, 92, 246, 0.3);
    color: var(--admin-purple);
}

.vm-action-console:hover {
    background: rgba(139, 92, 246, 0.25);
    border-color: var(--admin-purple);
}

.vm-action-edit {
    background: rgba(14, 165, 233, 0.15);
    border: 1px solid rgba(14, 165, 233, 0.3);
    color: var(--admin-accent);
}

.vm-action-edit:hover {
    background: rgba(14, 165, 233, 0.25);
    border-color: var(--admin-accent);
}

.vm-action-delete {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--admin-danger);
}

.vm-action-delete:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: var(--admin-danger);
}

.vm-action-stats {
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--admin-info);
}

.vm-action-stats:hover {
    background: rgba(59, 130, 246, 0.25);
    border-color: var(--admin-info);
}

.vm-action-form {
    display: inline;
    margin: 0;
    padding: 0;
}

/* ========== ПУСТАЯ ТАБЛИЦА ========== */
.table-empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--admin-text-secondary);
}

.table-empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.table-empty-state h4 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0 0 8px 0;
}

.table-empty-state p {
    margin: 0;
    font-size: 14px;
}

/* ========== МОДАЛЬНОЕ ОКНО ГРАФИКОВ ========== */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background-color: var(--admin-card-bg);
    margin: 40px auto;
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    width: 90%;
    max-width: 1200px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: var(--admin-hover-shadow);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-header h3 i {
    color: var(--admin-accent);
}

.close {
    color: var(--admin-text-secondary);
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.3s ease;
}

.close:hover {
    color: var(--admin-danger);
}

.modal-body {
    padding: 24px;
}

.timeframe-filter {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.timeframe-filter label {
    color: var(--admin-text);
    font-weight: 500;
    font-size: 14px;
}

.timeframe-filter select {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid var(--admin-border);
    background: var(--admin-card-bg);
    color: var(--admin-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.timeframe-filter select:focus {
    outline: none;
    border-color: var(--admin-accent);
    box-shadow: 0 0 0 3px var(--admin-accent-light);
}

.progress-container {
    margin-bottom: 20px;
    height: 4px;
    background: var(--admin-border);
    border-radius: 2px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    border-radius: 2px;
    transition: width 0.3s ease;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 20px;
}

.metric-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    padding: 16px;
    transition: all 0.3s ease;
}

.metric-card:hover {
    border-color: var(--admin-accent);
    box-shadow: var(--admin-shadow);
}

.metric-chart {
    position: relative;
    height: 200px;
    width: 100%;
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .vms-table-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }

    .table-search input {
        width: 100%;
    }

    .vms-table {
        display: block;
        overflow-x: auto;
    }

    .vms-table thead th,
    .vms-table tbody td {
        white-space: nowrap;
    }

    .vm-actions {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding: 5px 0;
    }

    .metrics-grid {
        grid-template-columns: 1fr;
    }

    .modal-content {
        width: 95%;
        margin: 20px auto;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }

    .header-left h1 {
        font-size: 20px;
    }

    .vms-table-header {
        padding: 15px;
    }

    .vms-table tbody td {
        padding: 12px;
    }

    .vm-actions {
        flex-direction: column;
        gap: 4px;
    }

    .vm-action-btn {
        width: 32px;
        height: 32px;
    }

    .vms-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .modal-content {
        width: 98%;
        margin: 10px auto;
    }

    .modal-body {
        padding: 16px;
    }
}

@media (max-width: 576px) {
    .vms-stats {
        grid-template-columns: 1fr;
    }
}

/* ========== АНИМАЦИИ ДЛЯ ТАБЛИЦЫ ========== */
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.vm-action-processing {
    animation: pulse 1s infinite;
    pointer-events: none;
}

/* ========== ИНФОРМАЦИОННЫЕ КАРТОЧКИ ========== */
.info-card {
    background: var(--admin-accent-light);
    border: 1px solid var(--admin-accent);
    border-radius: 8px;
    padding: 16px;
    margin: 20px 0;
}

.info-card h4 {
    color: var(--admin-accent);
    font-size: 14px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-card p {
    color: var(--admin-text);
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

/* ========== СПИННЕР ЗАГРУЗКИ ========== */
.loading-spinner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 20px;
}

.loading-spinner i {
    font-size: 24px;
    color: var(--admin-accent);
}

/* ========== СТИЛИ SWEETALERT2 ========== */
.swal2-popup {
    background: var(--admin-card-bg) !important;
    color: var(--admin-text) !important;
    border-radius: 12px !important;
    border: 1px solid var(--admin-border) !important;
}

.swal2-title {
    color: var(--admin-text) !important;
}

.swal2-content {
    color: var(--admin-text-secondary) !important;
}

.swal2-confirm {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover)) !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 10px 24px !important;
}

.swal2-cancel {
    background: var(--admin-card-bg) !important;
    color: var(--admin-text) !important;
    border: 1px solid var(--admin-border) !important;
    border-radius: 8px !important;
    padding: 10px 24px !important;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-server"></i> Управление административными ВМ</h1>
            <p>Общее количество ВМ: <?= $totalVms ?> (<?= $runningVms ?> запущено, <?= $stoppedVms ?> остановлено)</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="vm_add.php" class="dashboard-action-btn dashboard-action-btn-primary">
                <i class="fas fa-plus"></i> Добавить ВМ
            </a>
            <button class="dashboard-action-btn dashboard-action-btn-secondary" onclick="refreshVMList()">
                <i class="fas fa-sync-alt"></i> Обновить
            </button>
        </div>
    </div>

    <!-- Статистика ВМ -->
    <div class="vms-stats">
        <div class="vm-stat-card">
            <div class="vm-stat-icon total">
                <i class="fas fa-server"></i>
            </div>
            <div class="vm-stat-content">
                <h4>Всего ВМ</h4>
                <div class="vm-stat-value"><?= $totalVms ?></div>
                <p class="vm-stat-subtext">Административные виртуальные машины</p>
            </div>
        </div>

        <div class="vm-stat-card">
            <div class="vm-stat-icon running">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="vm-stat-content">
                <h4>Запущено</h4>
                <div class="vm-stat-value"><?= $runningVms ?></div>
                <p class="vm-stat-subtext"><?= $totalVms > 0 ? round(($runningVms / $totalVms) * 100) : '0' ?>% от общего числа</p>
            </div>
        </div>

        <div class="vm-stat-card">
            <div class="vm-stat-icon stopped">
                <i class="fas fa-stop-circle"></i>
            </div>
            <div class="vm-stat-content">
                <h4>Остановлено</h4>
                <div class="vm-stat-value"><?= $stoppedVms ?></div>
                <p class="vm-stat-subtext"><?= $totalVms > 0 ? round(($stoppedVms / $totalVms) * 100) : '0' ?>% от общего числа</p>
            </div>
        </div>

        <div class="vm-stat-card">
            <div class="vm-stat-icon nodes">
                <i class="fas fa-network-wired"></i>
            </div>
            <div class="vm-stat-content">
                <h4>Нод Proxmox</h4>
                <div class="vm-stat-value"><?= $totalNodes ?></div>
                <p class="vm-stat-subtext">Серверов виртуализации</p>
            </div>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Таблица ВМ -->
    <div class="vms-table-container">
        <!-- Заголовок таблицы -->
        <div class="vms-table-header">
            <div class="table-header-left">
                <h3><i class="fas fa-list"></i> Список административных ВМ</h3>
            </div>
            <div class="table-search">
                <input type="text" id="vmSearch" placeholder="Поиск ВМ..." onkeyup="searchVMs()">
                <i class="fas fa-search"></i>
            </div>
        </div>

        <!-- Таблица -->
        <?php if (!empty($adminVms)): ?>
            <table class="vms-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя ВМ</th>
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
                        <td class="vm-id">#<?= $vm['id'] ?></td>
                        <td class="vm-name"><?= htmlspecialchars($vm['hostname']) ?></td>
                        <td class="vm-id"><?= htmlspecialchars($vm['vm_id']) ?></td>
                        <td><?= htmlspecialchars($nodeName) ?></td>
                        <td>
                            <span class="status-badge status-<?= $vm['status'] ?>">
                                <?= $vm['status'] === 'running' ? 'Запущена' : 'Остановлена' ?>
                            </span>
                        </td>
                        <?php if (columnExists($pdo, 'vms_admin', 'created_at')): ?>
                        <td class="vm-date"><?= htmlspecialchars(date('d.m.Y', strtotime($vm['created_at'] ?? ''))) ?></td>
                        <?php endif; ?>
                        <td>
                            <div class="vm-actions">
                                <?php if ($vm['status'] === 'running'): ?>
                                    <form method="POST" class="vm-action-form" onsubmit="return showActionConfirmation('stop', <?= $vm['vm_id'] ?>)">
                                        <input type="hidden" name="vm_id" value="<?= $vm['vm_id'] ?>">
                                        <input type="hidden" name="node_id" value="<?= $vm['node_id'] ?>">
                                        <input type="hidden" name="vm_action" value="stop">
                                        <button type="submit" class="vm-action-btn vm-action-stop" title="Остановить">
                                            <i class="fas fa-stop"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="vm-action-form" onsubmit="return showActionConfirmation('reboot', <?= $vm['vm_id'] ?>)">
                                        <input type="hidden" name="vm_id" value="<?= $vm['vm_id'] ?>">
                                        <input type="hidden" name="node_id" value="<?= $vm['node_id'] ?>">
                                        <input type="hidden" name="vm_action" value="reboot">
                                        <button type="submit" class="vm-action-btn vm-action-reboot" title="Перезагрузить">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </form>
                                    <button class="vm-action-btn vm-action-console"
                                            title="VNC консоль"
                                            onclick="openAdminVncConsole(<?= $vm['node_id'] ?>, <?= $vm['vm_id'] ?>, '<?= htmlspecialchars(addslashes($vm['hostname'])) ?>')">
                                        <i class="fas fa-terminal"></i>
                                    </button>
                                <?php else: ?>
                                    <form method="POST" class="vm-action-form" onsubmit="return showActionConfirmation('start', <?= $vm['vm_id'] ?>)">
                                        <input type="hidden" name="vm_id" value="<?= $vm['vm_id'] ?>">
                                        <input type="hidden" name="node_id" value="<?= $vm['node_id'] ?>">
                                        <input type="hidden" name="vm_action" value="start">
                                        <button type="submit" class="vm-action-btn vm-action-start" title="Запустить">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <a href="vm_admin_edit.php?id=<?= $vm['id'] ?>"
                                   class="vm-action-btn vm-action-edit" title="Редактировать">
                                    <i class="fas fa-edit"></i>
                                </a>

                                <form method="POST" class="vm-action-form" onsubmit="return confirmDelete(<?= $vm['id'] ?>, '<?= htmlspecialchars($vm['hostname']) ?>')">
                                    <input type="hidden" name="vm_id" value="<?= $vm['id'] ?>">
                                    <button type="submit" name="delete_vm" class="vm-action-btn vm-action-delete" title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>

                                <button class="vm-action-btn vm-action-stats"
                                        title="Статистика"
                                        onclick="showVmStats(<?= $vm['vm_id'] ?>, <?= $vm['node_id'] ?>, '<?= htmlspecialchars($vm['hostname']) ?>')">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="table-empty-state">
                <i class="fas fa-server"></i>
                <h4>Нет административных ВМ</h4>
                <p>Добавьте виртуальные машины для управления</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно для графиков -->
<div id="statsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-chart-line"></i> <span id="modalTitle">Статистика ВМ</span></h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="timeframe-filter">
                <label for="timeframe">Период:</label>
                <select id="timeframe" onchange="updateStats()">
                    <option value="hour">1 час</option>
                    <option value="day" selected>1 день</option>
                    <option value="week">1 неделя</option>
                    <option value="month">1 месяц</option>
                    <option value="year">1 год</option>
                </select>
            </div>

            <div class="progress-container" id="progressContainer" style="display: none;">
                <div class="progress-bar" id="loadingProgress"></div>
            </div>

            <div class="metrics-grid" id="metricsGrid">
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек статистики
    const statCards = document.querySelectorAll('.vm-stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Анимация строк таблицы
    const tableRows = document.querySelectorAll('.vms-table tbody tr');
    tableRows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(10px)';

        setTimeout(() => {
            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, (statCards.length * 100) + (index * 50));
    });

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const dashboard = document.querySelector('.dashboard-wrapper');

    if (sidebar && dashboard) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        dashboard.style.marginLeft = '70px';
                    } else {
                        dashboard.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }
});

// Текущие данные для графиков
let currentCharts = {
    cpu: null,
    memory: null,
    network: null,
    disk: null
};

// Текущие параметры ВМ
let currentVm = {
    id: null,
    nodeId: null,
    name: null
};

// Текущее состояние для VNC консоли
let vncActionInProgress = false;

// Функция для открытия VNC консоли - исправленная версия
async function openAdminVncConsole(nodeId, vmId, vmName) {
    if (vncActionInProgress) return;
    vncActionInProgress = true;

    try {
        // Показываем загрузку
        const swalInstance = Swal.fire({
            title: 'Подготовка консоли...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся к админской ВМ...</div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Формируем URL для админской ВМ (новый файл)
        const url = new URL('vnc_console_admin.php', window.location.href);
        url.searchParams.append('node_id', nodeId);
        url.searchParams.append('vm_id', vmId);
        url.searchParams.append('type', 'qemu'); // Админские ВМ - всегда qemu

        console.log('Fetching ADMIN VNC console URL:', url.toString());

        const response = await fetch(url);

        // Проверяем ответ
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const text = await response.text();
        console.log('Raw response:', text.substring(0, 500));

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text);
            throw new Error('Неверный формат ответа от сервера');
        }

        console.log('Parsed data:', data);

        if (!data.success) {
            throw new Error(data.error || 'Не удалось подключиться к VNC консоли админской ВМ');
        }

        // Упрощенная установка cookie
        if (data.data.cookie) {
            const cookie = data.data.cookie;
            // Формируем строку cookie
            let cookieStr = `${cookie.name}=${encodeURIComponent(cookie.value)}; `;
            
            // Добавляем domain если он есть
            if (cookie.domain) {
                cookieStr += `domain=${cookie.domain}; `;
            } else {
                cookieStr += `domain=${window.location.hostname}; `;
            }
            
            cookieStr += `path=${cookie.path || '/'}; `;
            cookieStr += `secure=${cookie.secure !== false}; `;
            cookieStr += `samesite=${cookie.samesite || 'Lax'}`;

            console.log('Setting cookie for admin VNC:', cookieStr);
            document.cookie = cookieStr;
        }

        // Проверка URL консоли
        if (!data.data.url) {
            throw new Error('Не получен URL консоли');
        }

        // Закрываем окно загрузки
        swalInstance.close();

        // Открываем VNC консоль
        const vncWindow = window.open(
            data.data.url,
            `vnc_admin_${nodeId}_${vmId}`,
            'width=1024,height=768,scrollbars=yes,resizable=yes,location=yes'
        );

        if (!vncWindow || vncWindow.closed) {
            throw new Error('Не удалось открыть окно VNC. Пожалуйста, разрешите всплывающие окна для этого сайта.');
        }

        // Показываем успешное сообщение
        Swal.fire({
            title: 'Консоль открыта',
            text: `VNC консоль для админской ВМ #${vmId} (${vmName}) открыта в новом окне`,
            icon: 'success',
            timer: 3000,
            showConfirmButton: false
        });

    } catch (error) {
        // Закрываем загрузку если открыто
        if (Swal.isVisible()) {
            Swal.close();
        }

        // Показываем ошибку
        Swal.fire({
            title: 'Ошибка подключения к админской ВМ',
            text: error.message,
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: '#ef4444'
        });

        console.error('Admin VNC Error:', error);
    } finally {
        vncActionInProgress = false;
    }
}

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

    // Уничтожаем все графики
    Object.values(currentCharts).forEach(chart => {
        if (chart) chart.destroy();
    });

    currentCharts = {
        cpu: null,
        memory: null,
        network: null,
        disk: null
    };
}

// Обновить данные статистики
async function updateStats() {
    const timeframe = document.getElementById('timeframe').value;
    const loadingProgress = document.getElementById('loadingProgress');
    const progressContainer = document.getElementById('progressContainer');
    const metricsGrid = document.getElementById('metricsGrid');

    // Скрываем графики и показываем прогресс
    metricsGrid.style.opacity = '0.5';
    progressContainer.style.display = 'block';
    loadingProgress.style.width = '0%';

    try {
        // Показываем прогресс
        updateProgress(loadingProgress, 10);

        // Загружаем данные
        const response = await fetch(`get_vm_stats.php?vm_id=${currentVm.id}&node_id=${currentVm.nodeId}&timeframe=${timeframe}`);
        if (!response.ok) throw new Error('Ошибка сервера');

        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Ошибка загрузки данных');

        updateProgress(loadingProgress, 30);

        // Обновляем графики
        updateCharts(data);

        updateProgress(loadingProgress, 100);

        // Восстанавливаем видимость графиков
        metricsGrid.style.opacity = '1';

        // Скрываем прогресс-бар через 1 секунду
        setTimeout(() => {
            progressContainer.style.opacity = '0';
            setTimeout(() => {
                progressContainer.style.display = 'none';
                progressContainer.style.opacity = '1';
            }, 500);
        }, 1000);

    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
        updateProgress(loadingProgress, 100);

        // Показываем ошибку
        metricsGrid.innerHTML = `
            <div class="alert alert-danger" style="grid-column: 1 / -1;">
                <i class="fas fa-exclamation-triangle"></i>
                ${error.message}
            </div>
        `;

        setTimeout(() => {
            progressContainer.style.opacity = '0';
            setTimeout(() => {
                progressContainer.style.display = 'none';
                progressContainer.style.opacity = '1';
            }, 500);
        }, 1000);
    }
}

// Обновить прогресс-бар
function updateProgress(element, percent) {
    element.style.width = percent + '%';
}

// Обновить графики
function updateCharts(data) {
    // Уничтожаем старые графики, если они есть
    Object.values(currentCharts).forEach(chart => {
        if (chart) chart.destroy();
    });

    // Создаем новые графики
    currentCharts.cpu = createCpuChart(data);
    currentCharts.memory = createMemoryChart(data);
    currentCharts.network = createNetworkChart(data);
    currentCharts.disk = createDiskChart(data);
}

// Создать график CPU
function createCpuChart(data) {
    return new Chart(
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
}

// Создать график памяти
function createMemoryChart(data) {
    return new Chart(
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
}

// Создать график сети
function createNetworkChart(data) {
    return new Chart(
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
}

// Создать график диска
function createDiskChart(data) {
    return new Chart(
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

// Закрыть модальное окно при клике вне его
window.onclick = function(event) {
    const modal = document.getElementById('statsModal');
    if (event.target == modal) {
        closeModal();
    }
}

// Поиск ВМ
function searchVMs() {
    const input = document.getElementById('vmSearch');
    const filter = input.value.toLowerCase();
    const table = document.querySelector('.vms-table tbody');
    const rows = table.getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.getElementsByTagName('td');
        let show = false;

        for (let j = 0; j < cells.length; j++) {
            const cell = cells[j];
            if (cell) {
                const textValue = cell.textContent || cell.innerText;
                if (textValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
        }

        if (show) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}

// Подтверждение действия с ВМ
function showActionConfirmation(action, vmId) {
    const actions = {
        'start': 'запустить',
        'stop': 'остановить',
        'reboot': 'перезагрузить'
    };

    return confirm(`Вы уверены, что хотите ${actions[action]} ВМ #${vmId}?`);
}

// Подтверждение удаления ВМ
function confirmDelete(vmId, vmName) {
    return confirm(`Вы уверены, что хотите удалить административную ВМ #${vmId} (${vmName})?`);
}

// Обновить список ВМ
function refreshVMList() {
    const refreshBtn = document.querySelector('.dashboard-action-btn-secondary');
    const originalHtml = refreshBtn.innerHTML;

    // Анимация вращения
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обновление...';
    refreshBtn.disabled = true;

    // Имитация загрузки
    setTimeout(() => {
        location.reload();
    }, 1000);
}
</script>

<?php require 'admin_footer.php'; ?>
