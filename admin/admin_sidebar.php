<?php
require_once '../includes/db.php';
$db = new Database();
$pdo = $db->getConnection();

// Проверка существования таблиц
$payments_exists = safeQuery($pdo, "SHOW TABLES LIKE 'payments'")->rowCount() > 0;
$nodes_exists = safeQuery($pdo, "SHOW TABLES LIKE 'proxmox_nodes'")->rowCount() > 0;
$legal_exists = safeQuery($pdo, "SHOW TABLES LIKE 'legal_entity_info'")->rowCount() > 0;
?>
<!-- Боковое меню -->
<aside class="admin-sidebar">
    <ul class="admin-menu">
        <li class="admin-menu-item">
            <a href="/admin/" class="admin-menu-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> Дашборд
            </a>
        </li>
        <li class="admin-menu-item">
            <a href="/admin/users.php" class="admin-menu-link <?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Пользователи
            </a>
        </li>
        <li class="admin-menu-item">
            <a href="/admin/vms.php" class="admin-menu-link <?= basename($_SERVER['PHP_SELF']) === 'vms.php' ? 'active' : '' ?>">
                <i class="fas fa-server"></i> ВМ
            </a>
        </li>
        <?php if ($nodes_exists): ?>
        <li class="admin-menu-item">
            <a href="/admin/nodes.php" class="admin-menu-link <?= 
                basename($_SERVER['PHP_SELF']) === 'nodes.php' || 
                basename($_SERVER['PHP_SELF']) === 'add_nodes.php' || 
                basename($_SERVER['PHP_SELF']) === 'edit_node.php' ? 'active' : '' 
            ?>">
                <i class="fas fa-network-wired"></i> Ноды
            </a>
        </li>
        <li class="admin-menu-item">
            <a href="/admin/proxmox.php" class="admin-menu-link <?= 
                basename($_SERVER['PHP_SELF']) === 'proxmox.php' ? 'active' : '' 
            ?>">
                <i class="fas fa-cubes"></i> Управление
            </a>
        </li>
        <?php endif; ?>
        <?php if ($payments_exists): ?>
        <li class="admin-menu-item">
            <a href="/admin/payments.php" class="admin-menu-link <?= basename($_SERVER['PHP_SELF']) === 'payments.php' ? 'active' : '' ?>">
                <i class="fas fa-credit-card"></i> Платежи
            </a>
        </li>
        <?php endif; ?>
        <li class="admin-menu-item">
            <a href="/admin/settings.php" class="admin-menu-link <?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Настройки
            </a>
        </li>
        <li class="admin-menu-item">
            <a href="/admin/quotas.php" class="admin-menu-link">
                <i class='fa fa-quote-left'></i> Квоты
            </a>
        </li>
        <li class="admin-menu-item">
            <a href="/admin/ticket.php" class="admin-menu-link">
                <i class="fas fa-question-circle"></i> Тикеты
            </a>
        </li>
        <?php if ($legal_exists): ?>
        <li class="admin-menu-item">
            <a href="/admin/legal_info.php" class="admin-menu-link">
                <i class="fas fa-building"></i> Юр. информация
            </a>
        </li>
        <?php endif; ?>
    </ul>
</aside>