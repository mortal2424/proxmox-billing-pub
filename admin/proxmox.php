<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$nodes = $pdo->query("
    SELECT n.*, c.name as cluster_name 
    FROM proxmox_nodes n
    JOIN proxmox_clusters c ON c.id = n.cluster_id
    WHERE n.is_active = 1
    ORDER BY c.name, n.node_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = "Управление Proxmox | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-network-wired"></i> Управление Proxmox
            </h1>

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

            <section class="section">
                <h2 class="section-title">
                    <i class="fas fa-server"></i> Виртуальные машины на нодах
                </h2>
                
                <?php if (!empty($nodes)): ?>
                    <div class="nodes-accordion">
                        <?php foreach ($nodes as $node): ?>
                        <div class="node-card">
                            <div class="node-header" onclick="loadNodeVms(<?= $node['id'] ?>)">
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
        </main>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.8"></script>

<script>
// Глобальные переменные для управления состоянием
const vmActions = {
    pending: {},
    
    start: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;
        
        Swal.fire({
            title: 'Запуск VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        fetch(`vm_action.php?action=start&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно запущена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        loadNodeVms(nodeId);
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось запустить VM: ' + error.message,
                    icon: 'error'
                });
            });
    },
    
    stop: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;
        
        Swal.fire({
            title: 'Остановка VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        fetch(`vm_action.php?action=stop&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно остановлена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        loadNodeVms(nodeId);
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось остановить VM: ' + error.message,
                    icon: 'error'
                });
            });
    },

    reboot: function(nodeId, vmid) {
        if (this.pending[`${nodeId}-${vmid}`]) return;
        this.pending[`${nodeId}-${vmid}`] = true;
        
        Swal.fire({
            title: 'Перезагрузка VM...',
            html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Пожалуйста, подождите</div>',
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        fetch(`vm_action.php?action=reboot&node_id=${nodeId}&vmid=${vmid}`)
            .then(response => response.json())
            .then(data => {
                delete this.pending[`${nodeId}-${vmid}`];
                if (data.error) {
                    Swal.fire({
                        title: 'Ошибка',
                        text: data.error,
                        icon: 'error'
                    });
                } else {
                    Swal.fire({
                        title: 'Успех',
                        text: 'Виртуальная машина успешно перезагружена',
                        icon: 'success',
                        timer: 2000
                    }).then(() => {
                        loadNodeVms(nodeId);
                    });
                }
            })
            .catch(error => {
                delete this.pending[`${nodeId}-${vmid}`];
                Swal.fire({
                    title: 'Ошибка',
                    text: 'Не удалось перезагрузить VM: ' + error.message,
                    icon: 'error'
                });
            });
    }
};

function loadNodeVms(nodeId) {
    const container = document.getElementById(`node-vms-${nodeId}`);
    const chevron = container.previousElementSibling.querySelector('.fa-chevron-down');
    
    if (container.dataset.loaded === 'true') {
        container.classList.toggle('show');
        chevron.classList.toggle('fa-rotate-180');
        return;
    }
    
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка данных...</div>';
    container.classList.add('show');
    chevron.classList.add('fa-rotate-180');
    
    fetch(`get_node_vms.php?node_id=${nodeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<div class="error-message">${data.error}</div>`;
                return;
            }
            
            let html = `
                <div class="vm-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Имя</th>
                                <th>Статус</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Диск</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.vms.forEach(vm => {
                html += `
                    <tr>
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
                        <td class="actions">
                            <button class="btn btn-icon btn-info" onclick="showVmInfo(${nodeId}, ${vm.vmid})">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            <button class="btn btn-icon btn-console" onclick="openVncConsole(${nodeId}, ${vm.vmid})">
                                <i class="fas fa-terminal"></i>
                            </button>
                            ${vm.status === 'running' ? 
                                `<button class="btn btn-icon btn-danger" onclick="vmActions.stop(${nodeId}, ${vm.vmid})">
                                    <i class="fas fa-stop"></i>
                                </button>` :
                                `<button class="btn btn-icon btn-success" onclick="vmActions.start(${nodeId}, ${vm.vmid})">
                                    <i class="fas fa-play"></i>
                                </button>`
                            }
                            <button class="btn btn-icon btn-warning" onclick="vmActions.reboot(${nodeId}, ${vm.vmid})">
                                <i class="fas fa-redo"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div>`;
            container.innerHTML = html;
            container.dataset.loaded = 'true';
        })
        .catch(error => {
            container.innerHTML = `<div class="error-message">Ошибка загрузки: ${error.message}</div>`;
        });
}

function showVmInfo(nodeId, vmid) {
    Swal.fire({
        title: 'Получение информации о VM...',
        html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Загрузка данных...</div>',
        showConfirmButton: false,
        allowOutsideClick: false,
        didOpen: () => {
            fetch(`get_vm_info.php?node_id=${nodeId}&vmid=${vmid}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        Swal.fire({
                            title: 'Ошибка',
                            text: data.error,
                            icon: 'error'
                        });
                        return;
                    }
                    
                    let html = `
                        <div class="vm-info">
                            <div class="info-row">
                                <span class="info-label">ID:</span>
                                <span class="info-value">${data.vmid}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Имя:</span>
                                <span class="info-value">${escapeHtml(data.name)}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Статус:</span>
                                <span class="status-badge ${data.status === 'running' ? 'status-active' : 'status-inactive'}">
                                    ${data.status === 'running' ? 'Запущена' : 'Остановлена'}
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">CPU:</span>
                                <span class="info-value">${data.cpus} ядер</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Память:</span>
                                <span class="info-value">${data.mem} GB</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Диск:</span>
                                <span class="info-value">${data.disk} GB</span>
                            </div>
                    `;
                    
                    if (data.ip) {
                        html += `
                            <div class="info-row">
                                <span class="info-label">IP-адрес:</span>
                                <span class="info-value">${data.ip}</span>
                            </div>
                        `;
                    }
                    
                    html += `</div>`;
                    
                    Swal.fire({
                        title: `Информация о VM ${data.vmid}`,
                        html: html,
                        confirmButtonText: 'Закрыть'
                    });
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Ошибка',
                        text: 'Не удалось получить информацию: ' + error.message,
                        icon: 'error'
                    });
                });
        }
    });
}

function openVncConsole(nodeId, vmid) {
    Swal.fire({
        title: 'Подготовка консоли...',
        html: '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Подключаемся...</div>',
        showConfirmButton: false,
        allowOutsideClick: false,
        didOpen: () => {
            fetch(`/templates/vnc_console.php?node_id=${nodeId}&vmid=${vmid}`, {
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
                if (!data.success) {
                    throw new Error(data.error || 'Не удалось подключиться');
                }

                // Создаем iframe для установки cookie
                const iframe = document.createElement('iframe');
                iframe.src = 'about:blank';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);

                // Устанавливаем cookie для домена ноды
                iframe.contentDocument.cookie = `${data.data.cookie.name}=${encodeURIComponent(data.data.cookie.value)}; ` +
                    `domain=${data.data.cookie.domain}; ` +
                    `path=${data.data.cookie.path}; ` +
                    `secure=${data.data.cookie.secure}; ` +
                    `httponly=${data.data.cookie.httponly}`;

                // Открываем VNC консоль после установки cookie
                setTimeout(() => {
                    const vncWindow = window.open(
                        data.data.url,
                        `vnc_${nodeId}_${vmid}`,
                        'width=1024,height=768,scrollbars=yes,resizable=yes'
                    );

                    if (!vncWindow || vncWindow.closed) {
                        throw new Error('Не удалось открыть окно VNC. Разрешите всплывающие окна.');
                    }

                    // Удаляем iframe после использования
                    setTimeout(() => document.body.removeChild(iframe), 3000);
                    
                    Swal.close();
                }, 500);
            })
            .catch(error => {
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

function escapeHtml(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
</script>

<style>
    <?php include '../admin/css/proxmox_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>