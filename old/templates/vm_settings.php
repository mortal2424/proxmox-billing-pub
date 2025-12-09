<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем ID VM из параметров
$vm_db_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$vm_db_id) {
    header("Location: my_vms.php");
    exit;
}

// Получаем базовые данные о VM из базы
$stmt = $pdo->prepare("
    SELECT 
        v.*,
        n.hostname,
        n.username,
        n.password,
        n.node_name,
        t.name as tariff_name,
        t.cpu as tariff_cpu,
        t.ram as tariff_ram,
        t.disk as tariff_disk
    FROM vms v
    JOIN proxmox_nodes n ON v.node_id = n.id
    LEFT JOIN tariffs t ON v.tariff_id = t.id
    WHERE v.id = ? AND v.user_id = ?
");
$stmt->execute([$vm_db_id, $user_id]);
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    header("Location: my_vms.php");
    exit;
}

// Получаем список тарифов
$plans = $pdo->query("SELECT id, name, cpu, ram, disk FROM tariffs WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Получаем список тарифов для соответствующего типа VM
$plans = $pdo->prepare("
    SELECT id, name, cpu, ram, disk 
    FROM tariffs 
    WHERE is_active = 1 AND vm_type = ?
");
$plans->execute([$vm['vm_type']]);
$plans = $plans->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Инициализация Proxmox API для POST-запросов
        $proxmox = new ProxmoxAPI(
            $vm['hostname'],
            $vm['username'],
            $vm['password'],
            $vm['ssh_port'] ?? 22,
            $vm['node_name'],
            $vm['node_id'],
            $pdo
        );

        // Обработка изменения тарифа/ресурсов
        if (isset($_POST['change_resources'])) {
            $new_plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : null;
            $custom_cpu = isset($_POST['custom_cpu']) ? intval($_POST['custom_cpu']) : null;
            $custom_ram = isset($_POST['custom_ram']) ? intval($_POST['custom_ram']) : null;
            $custom_disk = isset($_POST['custom_disk']) ? intval($_POST['custom_disk']) : null;
            
            $result = $proxmox->changeVmResources(
                $vm['vm_id'], 
                $vm['vm_type'], 
                $new_plan_id, 
                $custom_cpu, 
                $custom_ram, 
                $custom_disk
            );
            
            if ($result['status'] === 'success') {
                $success_message = $result['message'];
                // Обновляем данные VM после изменения
                $stmt = $pdo->prepare("SELECT * FROM vms WHERE id = ?");
                $stmt->execute([$vm_db_id]);
                $vm = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                throw new Exception($result['message']);
            }
        }
        
        // Обработка изменения/добавления диска
        if (isset($_POST['change_disk'])) {
            $disk_id = $_POST['disk_id'];
            $new_size = $_POST['disk_size'];
            $new_storage = $_POST['disk_storage'];
            
            if ($vm['vm_type'] === 'qemu') {
                $result = $proxmox->changeVmDisk(
                    $vm['vm_id'], 
                    $vm['vm_type'], 
                    $disk_id, 
                    $new_size, 
                    $new_storage
                );
            } else {
                // Для LXC меняем только размер rootfs
                $result = $proxmox->changeVmResources(
                    $vm['vm_id'],
                    $vm['vm_type'],
                    null,
                    null,
                    null,
                    $new_size
                );
            }
            
            if ($result['status'] === 'success') {
                $success_message = $result['message'];
            } else {
                throw new Exception($result['message']);
            }
        }
        
        // Обработка добавления сети
        if (isset($_POST['add_network'])) {
            $network_id = $_POST['network_id'];
            $bridge = $_POST['bridge'];
            
            $result = $proxmox->addVmNetwork(
                $vm['vm_id'], 
                $vm['vm_type'], 
                $network_id, 
                $bridge
            );
            
            if ($result['status'] === 'success') {
                $success_message = $result['message'];
            } else {
                throw new Exception($result['message']);
            }
        }
        
        // Обработка удаления VM
        if (isset($_POST['delete_vm'])) {
            $result = $proxmox->deleteVm($vm['vm_id'], $vm['vm_type']);
            
            if ($result['status'] === 'success') {
                header("Location: my_vms.php");
                exit();
            } else {
                throw new Exception($result['message']);
            }
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнера' ?> | <?= htmlspecialchars($_ENV['APP_NAME']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/settings_styles.css'; ?>
        <?php include '../css/vm_settings_styles.css'; ?>
        <?php include '../css/header_styles.css'; ?>
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <main class="admin-main">
                <h1 class="admin-title">
                    <i class="fas fa-cog"></i> Настройки <?= $vm['vm_type'] === 'qemu' ? 'Виртуальной машины' : 'Контейнера' ?> #<?= $vm['vm_id'] ?>
                    <small><?= htmlspecialchars($vm['name'] ?? '') ?></small>
                </h1>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <div class="vm-settings-container">
                    <!-- Блок изменения ресурсов -->
                    <div class="vm-config-card">
                        <div class="card-header">
                            <h5><i class="fas fa-sliders-h"></i> Изменение ресурсов</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label>Текущий тариф</label>
                                    <select class="tariff-select" name="plan_id">
                                        <option value="0" <?= empty($vm['tariff_id']) ? 'selected' : '' ?>>Кастомный</option>
                                        <?php foreach ($plans as $plan): ?>
                                        <option value="<?= $plan['id'] ?>" <?= ($plan['id'] == $vm['tariff_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($plan['name']) ?> 
                                            (CPU: <?= $plan['cpu'] ?>, RAM: <?= $plan['ram'] ?>MB, 
                                            Disk: <?= $plan['disk'] ?>GB)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="custom_resources" style="<?= empty($vm['tariff_id']) ? '' : 'display:none;' ?>">
                                    <div class="form-group">
                                        <label>CPU (ядра)</label>
                                        <input type="number" class="form-control" name="custom_cpu" 
                                               value="<?= $vm['cpu_cores'] ?? 1 ?>" min="1" max="<?= $vm['vm_type'] === 'qemu' ? '32' : '16' ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>RAM (MB)</label>
                                        <input type="number" class="form-control" name="custom_ram" 
                                               value="<?= $vm['ram_mb'] ?? 1024 ?>" min="128" max="<?= $vm['vm_type'] === 'qemu' ? '131072' : '65536' ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Диск (GB)</label>
                                        <input type="number" class="form-control" name="custom_disk" 
                                               value="<?= $vm['disk_gb'] ?? 10 ?>" min="1" max="2048" <?= $vm['vm_type'] === 'lxc' ? 'required' : '' ?>>
                                    </div>
                                </div>
                                
                                <button type="submit" name="change_resources" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Изменить ресурсы
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Блок управления дисками -->
                    <div class="vm-config-card" id="disks-section">
                        <div class="card-header">
                            <h5><i class="fas fa-hdd"></i> Управление дисками</h5>
                        </div>
                        <div class="card-body">
                            <div class="loading-placeholder">
                                <div class="loading-spinner"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Блок управления сетями -->
                    <div class="vm-config-card" id="networks-section">
                        <div class="card-header">
                            <h5><i class="fas fa-network-wired"></i> Управление сетями</h5>
                        </div>
                        <div class="card-body">
                            <div class="loading-placeholder">
                                <div class="loading-spinner"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Блок удаления VM -->
                    <div class="vm-config-card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5><i class="fas fa-trash-alt"></i> Удаление <?= $vm['vm_type'] === 'qemu' ? 'Виртуальной машины' : 'Контейнера' ?></h5>
                        </div>
                        <div class="card-body">
                            <p>Это действие невозможно отменить. <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнер' ?> будет полностью удален с гипервизора.</p>
                            <form method="POST" onsubmit="return confirm('Вы уверены что хотите удалить эту <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'контейнер' ?>?');">
                                <button type="submit" name="delete_vm" class="btn btn-danger">
                                    <i class="fas fa-trash-alt"></i> Удалить <?= $vm['vm_type'] === 'qemu' ? 'ВМ' : 'Контейнер' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
    // Показываем/скрываем кастомные ресурсы при изменении тарифа
    document.querySelector('[name="plan_id"]').addEventListener('change', function() {
        document.getElementById('custom_resources').style.display = this.value == '0' ? 'block' : 'none';
    });
    
    // Инициализация состояния при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        const planSelect = document.querySelector('[name="plan_id"]');
        if (planSelect.value == '0') {
            document.getElementById('custom_resources').style.display = 'block';
        }
        
        // Асинхронная загрузка данных
        loadVmData();
    });
    
    // Функция для асинхронной загрузки данных
    function loadVmData() {
        const vmId = <?= $vm['id'] ?>;
        const vmType = '<?= $vm['vm_type'] ?>';
        
        // Загрузка данных о дисках
        fetch(`/api/vm_settings.php?action=get_disks&id=${vmId}`)
            .then(response => response.json())
            .then(data => {
                const disksSection = document.getElementById('disks-section');
                disksSection.querySelector('.card-body').innerHTML = '';
                
                if (vmType === 'qemu') {
                    // Для KVM отображаем все диски
                    if (data.disks && data.disks.length > 0) {
                        data.disks.forEach(disk => {
                            const diskForm = createDiskForm(disk);
                            disksSection.querySelector('.card-body').appendChild(diskForm);
                        });
                    } else {
                        disksSection.querySelector('.card-body').innerHTML = '<p>Диски не найдены</p>';
                    }
                } else {
                    // Для LXC отображаем только rootfs
                    const currentSize = data.disk_size || <?= $vm['disk_gb'] ?? 10 ?>;
                    const diskForm = createLxcDiskForm(currentSize);
                    disksSection.querySelector('.card-body').appendChild(diskForm);
                }
            })
            .catch(error => {
                console.error('Error loading disks:', error);
                document.getElementById('disks-section').querySelector('.card-body').innerHTML = 
                    '<div class="alert alert-danger">Ошибка загрузки данных о дисках</div>';
            });
        
        // Загрузка данных о сетях
        fetch(`/api/vm_settings.php?action=get_networks&id=${vmId}`)
            .then(response => response.json())
            .then(data => {
                const networksSection = document.getElementById('networks-section');
                networksSection.querySelector('.card-body').innerHTML = '';
                
                if (data.networks && data.networks.length > 0) {
                    data.networks.forEach(net => {
                        const netElement = createNetworkElement(net, vmType);
                        networksSection.querySelector('.card-body').appendChild(netElement);
                    });
                }
                
                // Добавляем форму для добавления новой сети
                const addNetworkForm = createAddNetworkForm(data.availableNetworks || [], 
                    data.networks ? data.networks.length : 0, vmType);
                networksSection.querySelector('.card-body').appendChild(addNetworkForm);
            })
            .catch(error => {
                console.error('Error loading networks:', error);
                document.getElementById('networks-section').querySelector('.card-body').innerHTML = 
                    '<div class="alert alert-danger">Ошибка загрузки данных о сетях</div>';
            });
    }
    
    // Создание формы для диска KVM
    function createDiskForm(disk) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'disk-form';
        
        form.innerHTML = `
            <input type="hidden" name="disk_id" value="${disk.id}">
            <div class="disk-info">
                <div class="disk-info-item">
                    <strong>Диск ${disk.id}</strong>
                    <div>${disk.size} GB</div>
                </div>
                <div class="disk-info-item">
                    <strong>Хранилище</strong>
                    <div>${disk.storage}</div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Новый размер (GB)</label>
                    <input type="number" class="form-control" name="disk_size" 
                           value="${parseInt(disk.size) + 1}" min="${parseInt(disk.size) + 1}" max="2048" required>
                </div>
                <div class="form-group">
                    <label>Хранилище</label>
                    <select class="form-control" name="disk_storage" required>
                        ${disk.storages.map(storage => `
                            <option value="${storage.name}" ${storage.selected ? 'selected' : ''}>
                                ${storage.name} (${storage.available}GB free)
                            </option>
                        `).join('')}
                    </select>
                </div>
            </div>
            <button type="submit" name="change_disk" class="btn btn-primary">
                <i class="fas fa-hdd"></i> Изменить диск
            </button>
        `;
        
        return form;
    }
    
    // Создание формы для диска LXC (только изменение размера)
    function createLxcDiskForm(currentSize) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'disk-form';
        
        // Для LXC минимальный размер = текущий + 1GB
        const minSize = parseInt(currentSize) + 1;
        
        form.innerHTML = `
            <div class="disk-info">
                <div class="disk-info-item">
                    <strong>RootFS</strong>
                    <div>${currentSize} GB</div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Новый размер (GB)</label>
                    <input type="number" class="form-control" name="disk_size" 
                           value="${minSize}" min="${minSize}" max="2048" required>
                    <small class="text-muted">Минимальный размер: ${minSize}GB</small>
                </div>
            </div>
            <input type="hidden" name="disk_id" value="0">
            <input type="hidden" name="disk_storage" value="local">
            <button type="submit" name="change_disk" class="btn btn-primary">
                <i class="fas fa-hdd"></i> Изменить размер
            </button>
        `;
        
        return form;
    }
    
    // Создание элемента сети
    function createNetworkElement(net, vmType) {
        const div = document.createElement('div');
        div.className = 'network-form';
        
        let networkContent = `
            <h6><i class="fas fa-ethernet"></i> Интерфейс ${net.id}</h6>
            <div class="network-info">
                <div class="network-info-item">
                    <strong>${vmType === 'qemu' ? 'MAC' : 'Имя'}</strong>
                    <div>${net.mac || net.name || 'N/A'}</div>
                </div>
                <div class="network-info-item">
                    <strong>Bridge</strong>
                    <div>${net.bridge}</div>
                </div>
        `;
        
        if (net.alias) {
            networkContent += `
                <div class="network-info-item">
                    <strong>Описание</strong>
                    <div>${net.alias}</div>
                </div>
            `;
        }
        
        networkContent += `</div>`;
        
        if (net.alias) {
            networkContent += `<div class="sdn-alias">${net.alias}</div>`;
        }
        
        div.innerHTML = networkContent;
        return div;
    }
    
    // Создание формы для добавления сети
    function createAddNetworkForm(availableNetworks, currentNetworksCount, vmType) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.className = 'network-form';
        
        let networkIdField = '';
        if (vmType === 'lxc') {
            // Для LXC имя обязательно (net0, net1 и т.д.)
            const nextId = currentNetworksCount;
            networkIdField = `
                <div class="form-group">
                    <label>ID интерфейса</label>
                    <input type="text" class="form-control" name="network_id" 
                           value="net${nextId}" pattern="net[0-9]" maxlength="4" required>
                    <small class="text-muted">Формат: net0, net1, ..., net9</small>
                </div>
            `;
        } else {
            // Для KVM имя не обязательно
            networkIdField = `
                <input type="hidden" name="network_id" value="net${currentNetworksCount}">
            `;
        }
        
        let networkOptions = '';
        availableNetworks.forEach(net => {
            if (typeof net === 'object') {
                // SDN сеть с alias
                networkOptions += `
                    <option value="${net.name}">
                        ${net.name}${net.alias ? ' (' + net.alias + ')' : ''}
                    </option>
                `;
            } else {
                // Обычная сеть
                networkOptions += `<option value="${net}">${net}</option>`;
            }
        });
        
        form.innerHTML = `
            <h6><i class="fas fa-plus-circle"></i> Добавить интерфейс</h6>
            <div class="form-row">
                ${networkIdField}
                <div class="form-group">
                    <label>Bridge</label>
                    <select class="form-control" name="bridge" required>
                        ${networkOptions}
                    </select>
                </div>
            </div>
            <button type="submit" name="add_network" class="btn btn-primary">
                <i class="fas fa-network-wired"></i> Добавить интерфейс
            </button>
        `;
        
        return form;
    }
</script>
</body>
</html>