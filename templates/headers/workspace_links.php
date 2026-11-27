<?php
require_once __DIR__ . '/../../includes/license_guard.php';
$workspaceOrderBlock = isset($pdo) && $pdo instanceof PDO
    ? homevladLicenseBlockReason($pdo, ['vms_created'=>1,'vcpu'=>1,'ram_mib'=>1]) : null;
?>
<nav class="workspace-nav" aria-label="Разделы кабинета">
    <?php foreach ($workspaceLinks as $page => [$url, $label, $icon]): ?>
        <?php if ($page === 'order_vm.php' && $workspaceOrderBlock): ?>
            <?= homevladLicenseAction($url, 'workspace-link', '<i class="fas fa-plus" aria-hidden="true"></i><span>Заказ</span>', $workspaceOrderBlock) ?>
            <?php continue; ?>
        <?php endif; ?>
        <a href="<?= $url ?>"<?= match ($page) {
            'platform-usage' => ' data-cabinet-dialog="Потребление ресурсов"',
            'monitoring.php' => ' data-cabinet-dialog="Мониторинг"',
            default => '',
        } ?><?= $page === $workspaceCurrent ? ' aria-current="page"' : '' ?>>
            <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<script src="/js/cabinet-dialogs.js?v=2" defer></script>
