<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
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

// Создаем таблицу vms_admin, если она не существует
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vms_admin` (
      `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `user_id` int NOT NULL COMMENT 'ID пользователя',
      `vm_id` int NOT NULL COMMENT 'ID виртуальной машины в Proxmox',
      `node_id` int NOT NULL COMMENT 'ID ноды Proxmox',
      `hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Имя хоста',
      `cpu` int NOT NULL COMMENT 'Количество ядер CPU',
      `ram` int NOT NULL COMMENT 'Объем RAM (MB)',
      `disk` int NOT NULL COMMENT 'Размер диска (GB)',
      `network` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vmbr0' COMMENT 'Основной сетевой интерфейс',
      `storage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Хранилище диска',
      `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running' COMMENT 'Статус ВМ',
      `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Описание ВМ',
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
      UNIQUE KEY `vm_node_unique` (`vm_id`,`node_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Административные виртуальные машины Proxmox'
");

$errors = [];
$success = false;

// Получаем список нод
$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    WHERE n.is_active = 1
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Обработка формы добавления ВМ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_vms'])) {
    try {
        $pdo->beginTransaction();

        foreach ($_POST['selected_vms'] as $vm_data) {
            list($node_id, $vm_id) = explode('-', $vm_data);

            // Получаем информацию о ноде
            $node_info = $pdo->query("SELECT * FROM proxmox_nodes WHERE id = $node_id")->fetch();

            if (!$node_info) {
                throw new Exception("Нода не найдена");
            }

            // Подключаемся к Proxmox API
            $proxmox = new ProxmoxAPI(
                $node_info['hostname'],
                $node_info['username'],
                $node_info['password'],
                22,
                $node_info['node_name'],
                $node_info['id'],
                $pdo
            );

            $vm_info = $proxmox->getVMInfo($vm_id);

            if (!$vm_info) {
                throw new Exception("ВМ $vm_id не найдена на ноде $node_id");
            }

            // Добавляем ВМ в таблицу vms_admin
            $stmt = $pdo->prepare("
                INSERT INTO vms_admin (
                    user_id, vm_id, node_id, hostname, cpu, ram, disk,
                    network, storage, status, description
                ) VALUES (
                    0, ?, ?, ?, ?, ?, ?, ?, ?, 'running', ?
                )
            ");

            $stmt->execute([
                $vm_id,
                $node_id,
                $vm_info['name'] ?? "VM $vm_id",
                $vm_info['cpu'] ?? 1,
                $vm_info['memory'] ?? 1024,
                $vm_info['disk'] ?? 20,
                $vm_info['network'] ?? 'vmbr0',
                $vm_info['storage'] ?? 'local',
                "Добавлена администратором"
            ]);
        }

        $pdo->commit();
        $success = true;
        $_SESSION['success'] = "ВМ успешно добавлены в административный список";
        header("Location: vms.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Ошибка при добавлении ВМ: " . $e->getMessage();
    }
}

$title = "Добавить административную ВМ | HomeVlad Cloud";
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

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.alert i {
    font-size: 18px;
}

.alert-danger i {
    color: #ef4444;
}

/* ========== ФОРМА ДОБАВЛЕНИЯ ВМ ========== */
.add-vm-container {
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    overflow: hidden;
    box-shadow: var(--admin-shadow);
    margin-bottom: 30px;
}

.add-vm-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--admin-border);
}

.add-vm-header h3 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.add-vm-header h3 i {
    color: var(--admin-accent);
}

/* ========== АККОРДЕОН НОД ========== */
.nodes-accordion {
    padding: 24px;
}

.node-card {
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.node-card:hover {
    border-color: var(--admin-accent);
    box-shadow: var(--admin-shadow);
}

.node-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: var(--admin-card-bg);
    cursor: pointer;
    user-select: none;
    transition: all 0.3s ease;
}

.node-header:hover {
    background: var(--admin-accent-light);
}

.node-header h3 {
    color: var(--admin-text);
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.node-header h3 i {
    color: var(--admin-accent);
}

.node-header h3 small {
    color: var(--admin-text-secondary);
    font-size: 14px;
    font-weight: 400;
}

.node-status {
    display: flex;
    align-items: center;
    gap: 12px;
}

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

.status-active {
    background: linear-gradient(135deg, var(--admin-success), #059669);
    color: white;
}

.node-header .fa-chevron-down {
    color: var(--admin-text-secondary);
    transition: transform 0.3s ease;
}

.node-header .fa-chevron-down.fa-rotate-180 {
    transform: rotate(180deg);
}

.node-vms {
    display: none;
    padding: 20px;
    background: var(--admin-card-bg);
    border-top: 1px solid var(--admin-border);
}

.node-vms.show {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* ========== ТАБЛИЦА ВМ ========== */
.vm-table-container {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    overflow: hidden;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
}

.admin-table thead th {
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

.admin-table tbody tr {
    border-bottom: 1px solid var(--admin-border);
    transition: all 0.3s ease;
}

.admin-table tbody tr:hover {
    background: var(--admin-accent-light);
}

.admin-table tbody td {
    color: var(--admin-text);
    font-size: 14px;
    padding: 16px;
    vertical-align: middle;
}

.admin-table tbody td:first-child {
    width: 50px;
    text-align: center;
}

/* ========== ЧЕКБОКСЫ ========== */
.select-all-checkbox,
.admin-table input[type="checkbox"] {
    width: 18px;
    height: 18px;
    border: 2px solid var(--admin-border);
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
    appearance: none;
    position: relative;
    background: var(--admin-card-bg);
}

.select-all-checkbox:checked,
.admin-table input[type="checkbox"]:checked {
    background: var(--admin-accent);
    border-color: var(--admin-accent);
}

.select-all-checkbox:checked::after,
.admin-table input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.select-all-checkbox:hover,
.admin-table input[type="checkbox"]:hover {
    border-color: var(--admin-accent);
}

/* ========== ЗАГРУЗКА И ОШИБКИ ========== */
.loading-spinner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 40px;
    color: var(--admin-text-secondary);
}

.loading-spinner i {
    color: var(--admin-accent);
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.no-data {
    text-align: center;
    padding: 40px 20px;
    color: var(--admin-text-secondary);
}

.no-data i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.error-message {
    padding: 20px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 8px;
    color: #b91c1c;
    text-align: center;
}

/* ========== КНОПКИ ФОРМЫ ========== */
.form-actions {
    padding: 24px;
    background: var(--admin-card-bg);
    border-top: 1px solid var(--admin-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.btn-primary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* ========== ПУСТОЕ СОСТОЯНИЕ ========== */
.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--admin-text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h4 {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0 0 8px 0;
}

.empty-state p {
    margin: 0;
    font-size: 14px;
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

    .node-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .node-status {
        width: 100%;
        justify-content: space-between;
    }

    .admin-table {
        display: block;
        overflow-x: auto;
    }

    .admin-table thead th,
    .admin-table tbody td {
        white-space: nowrap;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn-primary {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }

    .header-left h1 {
        font-size: 20px;
    }

    .nodes-accordion {
        padding: 16px;
    }

    .node-header {
        padding: 12px 16px;
    }

    .admin-table tbody td {
        padding: 12px;
    }

    .no-data {
        padding: 30px 15px;
    }

    .empty-state {
        padding: 40px 15px;
    }
}

@media (max-width: 576px) {
    .dashboard-header {
        padding: 16px;
    }

    .add-vm-header {
        padding: 16px;
    }

    .node-vms {
        padding: 16px;
    }

    .form-actions {
        padding: 20px;
    }
}

/* ========== СКЕЛЕТОН ДЛЯ ЗАГРУЗКИ ========== */
.skeleton {
    background: linear-gradient(90deg, var(--admin-bg) 25%, var(--admin-border) 50%, var(--admin-bg) 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
    border-radius: 6px;
}

.skeleton-text {
    height: 12px;
    margin-bottom: 8px;
}

.skeleton-text:last-child {
    margin-bottom: 0;
    width: 70%;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ========== КАРТОЧКА ИНФОРМАЦИИ ========== */
.info-card {
    background: var(--admin-accent-light);
    border: 1px solid var(--admin-accent);
    border-radius: 8px;
    padding: 16px;
    margin: 20px 24px;
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
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-plus-circle"></i> Добавить административную ВМ</h1>
            <p>Выберите виртуальные машины с нод Proxmox для добавления в административный список</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="vms.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад к списку
            </a>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Форма добавления ВМ -->
    <form method="POST" id="add-vms-form">
        <div class="add-vm-container">
            <!-- Заголовок формы -->
            <div class="add-vm-header">
                <h3><i class="fas fa-server"></i> Доступные виртуальные машины</h3>
            </div>

            <!-- Информационная карточка -->
            <div class="info-card">
                <h4><i class="fas fa-info-circle"></i> Информация</h4>
                <p>Выберите виртуальные машины из списка ниже. После добавления они будут отображаться в списке административных ВМ. Убедитесь, что ВМ существует на выбранной ноде Proxmox.</p>
            </div>

            <!-- Список нод -->
            <div class="nodes-accordion">
                <?php if (!empty($nodes)): ?>
                    <?php foreach ($nodes as $node): ?>
                    <div class="node-card">
                        <div class="node-header" onclick="loadNodeVms(<?= $node['id'] ?>, this)">
                            <h3>
                                <i class="fas fa-server"></i>
                                <?= htmlspecialchars($node['node_name']) ?>
                                <small>(<?= htmlspecialchars($node['cluster_name']) ?>)</small>
                            </h3>
                            <div class="node-status">
                                <span class="status-badge status-active">Активна</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                        <div class="node-vms" id="node-vms-<?= $node['id'] ?>">
                            <div class="loading-spinner">
                                <i class="fas fa-spinner fa-spin"></i> Загрузка данных...
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <h4>Нет активных нод Proxmox</h4>
                        <p>Для добавления ВМ необходимо настроить хотя бы одну активную ноду Proxmox</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Кнопки формы -->
            <div class="form-actions">
                <button type="submit" class="btn-primary" id="submit-btn" disabled>
                    <i class="fas fa-plus-circle"></i> Добавить выбранные ВМ
                </button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек нод
    const nodeCards = document.querySelectorAll('.node-card');
    nodeCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
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

// Функция загрузки ВМ ноды
async function loadNodeVms(nodeId, headerElement) {
    const container = document.getElementById(`node-vms-${nodeId}`);
    const chevron = headerElement.querySelector('.fa-chevron-down');

    // Если уже открыто - закрываем
    if (container.classList.contains('show')) {
        container.classList.remove('show');
        chevron.classList.remove('fa-rotate-180');
        return;
    }

    // Показываем спиннер
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка данных...</div>';
    container.classList.add('show');
    chevron.classList.add('fa-rotate-180');

    try {
        // Загружаем данные с сервера
        const response = await fetch(`get_admin_vms.php?node_id=${nodeId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.error) {
            container.innerHTML = `<div class="error-message">${data.error}</div>`;
            return;
        }

        if (!data.vms || data.vms.length === 0) {
            container.innerHTML = `
                <div class="no-data">
                    <i class="fas fa-box-open"></i>
                    <p>Нет доступных виртуальных машин для добавления</p>
                </div>
            `;
            return;
        }

        // Создаем таблицу с ВМ
        let html = `
            <div class="vm-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" class="select-all-checkbox"
                                    onclick="toggleSelectAll(${nodeId}, this)">
                            </th>
                            <th>VMID</th>
                            <th>Имя ВМ</th>
                            <th>Статус</th>
                            <th>CPU</th>
                            <th>RAM</th>
                            <th>Диск</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        data.vms.forEach(vm => {
            const statusClass = vm.status === 'running' ? 'status-active' : 'status-inactive';
            const statusText = vm.status === 'running' ? 'Запущена' : 'Остановлена';
            const ramGB = Math.round(vm.mem / 1024);
            const diskGB = Math.round(vm.disk / 1024);

            html += `
                <tr>
                    <td>
                        <input type="checkbox" 
                               name="selected_vms[]" 
                               value="${nodeId}-${vm.vmid}"
                               onchange="updateSubmitButton()">
                    </td>
                    <td class="vm-id">${vm.vmid}</td>
                    <td class="vm-name">${escapeHtml(vm.name)}</td>
                    <td>
                        <span class="status-badge ${statusClass}">
                            ${statusText}
                        </span>
                    </td>
                    <td>${vm.cpus} ядер</td>
                    <td>${ramGB} GB</td>
                    <td>${diskGB} GB</td>
                </tr>
            `;
        });

        html += `</tbody></table></div>`;
        container.innerHTML = html;

        // Анимация строк таблицы
        const tableRows = container.querySelectorAll('tbody tr');
        tableRows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(10px)';

            setTimeout(() => {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, index * 50);
        });

    } catch (error) {
        console.error('Error loading VMs:', error);
        container.innerHTML = `
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                Ошибка загрузки: ${error.message}
            </div>
        `;
    }
}

// Функция выделения всех ВМ в ноде
function toggleSelectAll(nodeId, checkbox) {
    const container = document.getElementById(`node-vms-${nodeId}`);
    const checkboxes = container.querySelectorAll('input[name="selected_vms[]"]');

    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });

    updateSubmitButton();
}

// Функция обновления состояния кнопки отправки
function updateSubmitButton() {
    const selectedVms = document.querySelectorAll('input[name="selected_vms[]"]:checked');
    const submitBtn = document.getElementById('submit-btn');

    if (selectedVms.length > 0) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<i class="fas fa-plus-circle"></i> Добавить выбранные ВМ (${selectedVms.length})`;
    } else {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<i class="fas fa-plus-circle"></i> Добавить выбранные ВМ`;
    }
}

// Функция экранирования HTML
function escapeHtml(unsafe) {
    return unsafe?.replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;") || '';
}

// Обработка отправки формы
document.getElementById('add-vms-form').addEventListener('submit', function(e) {
    const selectedVms = document.querySelectorAll('input[name="selected_vms[]"]:checked');

    if (selectedVms.length === 0) {
        e.preventDefault();
        Swal.fire({
            title: 'Ошибка',
            text: 'Пожалуйста, выберите хотя бы одну виртуальную машину',
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: '#ef4444'
        });
        return;
    }

    // Показываем подтверждение
    e.preventDefault();
    
    Swal.fire({
        title: 'Добавление ВМ',
        html: `Вы уверены, что хотите добавить <b>${selectedVms.length}</b> виртуальных машин в административный список?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Да, добавить',
        cancelButtonText: 'Отмена',
        confirmButtonColor: '#0ea5e9',
        cancelButtonColor: '#ef4444'
    }).then((result) => {
        if (result.isConfirmed) {
            // Показываем загрузку
            Swal.fire({
                title: 'Добавление ВМ',
                html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Добавляем выбранные ВМ...</div>',
                showConfirmButton: false,
                allowOutsideClick: false
            });

            // Отправляем форму
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text().then(text => {
                        Swal.fire({
                            title: 'Ошибка',
                            text: 'Произошла ошибка при добавлении ВМ',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    title: 'Ошибка',
                    text: error.message,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            });
        }
    });
});
</script>

<?php require 'admin_footer.php'; ?>