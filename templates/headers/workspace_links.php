<nav class="workspace-nav" aria-label="Разделы кабинета">
    <?php foreach ($workspaceLinks as $page => [$url, $label, $icon]): ?>
        <a href="<?= $url ?>"<?= $page === $workspaceCurrent ? ' aria-current="page"' : '' ?>>
            <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
</nav>
