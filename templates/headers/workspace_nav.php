<?php
$workspaceLinks = [
    'dashboard.php' => ['/templates/dashboard.php', 'Обзор', 'fa-chart-line'],
    'my_vms.php' => ['/templates/my_vms.php', 'Серверы', 'fa-server'],
    'order_vm.php' => ['/templates/order_vm.php', 'Заказ', 'fa-plus'],
    'billing.php' => ['/templates/billing.php', 'Финансы', 'fa-wallet'],
    'support.php' => ['/templates/support.php', 'Поддержка', 'fa-headset'],
    'telegram.php' => ['/templates/telegram.php', 'Telegram', 'fa-paper-plane'],
    'settings.php' => ['/templates/settings.php', 'Профиль', 'fa-user'],
    'docs.php' => ['/docs/docs.php', 'Вики', 'fa-book'],
];
$workspaceCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($workspaceCurrent === 'vm_settings.php') {
    $workspaceCurrent = 'my_vms.php';
}
?>
<nav class="workspace-nav" aria-label="Быстрые переходы">
    <?php foreach ($workspaceLinks as $page => [$url, $label, $icon]): ?>
        <a href="<?= $url ?>"<?= $page === $workspaceCurrent ? ' aria-current="page"' : '' ?>>
            <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
</nav>
