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
<header class="portal-header">
    <div class="portal-top">
        <a class="portal-brand" href="/templates/dashboard.php">
            <span class="portal-mark" aria-hidden="true">H</span>
            <span>HomeVlad <small>Cloud</small></span>
        </a>
        <div class="portal-account">
            <button id="portal-theme" type="button" aria-pressed="false" aria-label="Тёмная тема">◐</button>
            <details>
                <summary>Аккаунт</summary>
                <div class="portal-account-menu">
                    <a href="/templates/settings.php">Настройки</a>
                    <a href="/status/">Статус платформы</a>
                    <?php if (!empty($user['is_admin'])): ?><a href="/admin/">Администрирование</a><?php endif; ?>
                    <a href="/login/logout.php">Выйти</a>
                </div>
            </details>
        </div>
    </div>
<nav class="workspace-nav" aria-label="Разделы кабинета">
    <?php foreach ($workspaceLinks as $page => [$url, $label, $icon]): ?>
        <a href="<?= $url ?>"<?= $page === $workspaceCurrent ? ' aria-current="page"' : '' ?>>
            <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
</nav>
</header>
<script>
(() => {
    const button = document.getElementById('portal-theme');
    const key = 'homevlad-portal-theme';
    const apply = dark => {
        document.body.classList.toggle('dark-theme', dark);
        button.setAttribute('aria-pressed', String(dark));
    };
    let dark = localStorage.getItem(key) === 'dark';
    apply(dark);
    button.addEventListener('click', () => {
        dark = !dark;
        localStorage.setItem(key, dark ? 'dark' : 'light');
        apply(dark);
    });
})();
</script>
