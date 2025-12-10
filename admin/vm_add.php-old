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
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Ошибка при добавлении ВМ: " . $e->getMessage();
    }
}

$title = "Добавить административную ВМ | HomeVlad Cloud";
require 'admin_header.php';
?>

    <div class="container">
        <div class="admin-content">
            <?php include 'admin_sidebar.php'; ?>

            <main class="admin-main">
                <div class="admin-header-container">
                    <h1 class="admin-title">
                        <i class="fas fa-server"></i> Добавить административную ВМ
                    </h1>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <p>Виртуальные машины успешно добавлены в административный список</p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="add-vms-form">
                    <section class="section">
                        <h2 class="section-title">
                            <i class="fas fa-server"></i> Доступные виртуальные машины
                        </h2>
                        
                        <?php if (!empty($nodes)): ?>
                            <div class="nodes-accordion">
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
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-info-circle"></i> Нет активных нод Proxmox
                            </div>
                        <?php endif; ?>
                    </section>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-plus-circle"></i> Добавить выбранные ВМ
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../admin/css/vm_add_styles.css'; ?>
    </style>

    <script>
    function loadNodeVms(nodeId, headerElement) {
        const container = document.getElementById(`node-vms-${nodeId}`);
        const chevron = headerElement.querySelector('.fa-chevron-down');
        
        if (container.classList.contains('show')) {
            container.classList.remove('show');
            chevron.classList.remove('fa-rotate-180');
            return;
        }
        
        container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка данных...</div>';
        container.classList.add('show');
        chevron.classList.add('fa-rotate-180');
        
        fetch(`get_admin_vms.php?node_id=${nodeId}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    container.innerHTML = `<div class="error-message">${data.error}</div>`;
                    return;
                }
                
                if (data.vms.length === 0) {
                    container.innerHTML = `<div class="no-data">Нет доступных виртуальных машин для добавления</div>`;
                    return;
                }
                
                let html = `
                    <div class="vm-table-container">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" class="select-all-checkbox" 
                                            onclick="toggleSelectAll(${nodeId}, this)">
                                    </th>
                                    <th>ID</th>
                                    <th>Имя</th>
                                    <th>Статус</th>
                                    <th>CPU</th>
                                    <th>RAM</th>
                                    <th>Диск</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.vms.forEach(vm => {
                    html += `
                        <tr>
                            <td><input type="checkbox" name="selected_vms[]" value="${nodeId}-${vm.vmid}"></td>
                            <td>${vm.vmid}</td>
                            <td>${escapeHtml(vm.name)}</td>
                            <td>
                                <span class="status-badge ${vm.status === 'running' ? 'status-active' : 'status-inactive'}">
                                    ${vm.status === 'running' ? 'Запущена' : 'Остановлена'}
                                </span>
                            </td>
                            <td>${vm.cpus} ядер</td>
                            <td>${vm.mem} GB</td>
                            <td>${vm.disk} GB</td>
                        </tr>
                    `;
                });
                
                html += `</tbody></table></div>`;
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<div class="error-message">Ошибка загрузки: ${error.message}</div>`;
            });
    }
    
    function toggleSelectAll(nodeId, checkbox) {
        const container = document.getElementById(`node-vms-${nodeId}`);
        const checkboxes = container.querySelectorAll('input[name="selected_vms[]"]');
        
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
        });
    }
    
    function escapeHtml(unsafe) {
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
    
    // Обработка отправки формы
    document.getElementById('add-vms-form').addEventListener('submit', function(e) {
        const selectedVms = document.querySelectorAll('input[name="selected_vms[]"]:checked');
        
        if (selectedVms.length === 0) {
            e.preventDefault();
            alert('Пожалуйста, выберите хотя бы одну виртуальную машину');
        }
    });
    </script>

    <?php include 'admin_footer.php'; ?>
</body>
</html>