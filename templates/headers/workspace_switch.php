<button type="button" class="<?= $workspaceLite ? 'portal-layout-switch' : 'menu-link portal-layout-switch' ?>"
        role="switch" aria-checked="<?= $workspaceLite ? 'true' : 'false' ?>"
        data-portal-layout="<?= $workspaceLite ? 'full' : 'lite' ?>">
    Лайт-режим <span aria-hidden="true">· <?= $workspaceLite ? 'Вкл' : 'Выкл' ?></span>
</button>
<script>
(() => {
    const button = document.currentScript.previousElementSibling;
    button.addEventListener('click', () => {
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'homevlad_layout=' + button.dataset.portalLayout
            + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
        location.replace(location.pathname + location.search);
    });
})();
</script>
