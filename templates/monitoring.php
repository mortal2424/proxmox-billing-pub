<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/headers/workspace_preference.php';
checkAuth();
$pdo = (new Database())->getConnection();
$query = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$query->execute([$_SESSION['user']['id']]);
$user = $query->fetch(PDO::FETCH_ASSOC);
$embedded = ($_GET['embed'] ?? '') === '1';
if ($embedded) {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="300">
<title>Мониторинг | HomeVlad Cloud</title>
<?php require __DIR__ . '/../includes/app_head.php'; ?>
<link rel="stylesheet" href="/css/user-palette.css">
<link rel="stylesheet" href="/css/themes.css">
<link rel="stylesheet" href="/css/service-overview.css?v=<?= (int)filemtime(__DIR__ . '/../css/service-overview.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($workspaceLite): ?><link rel="stylesheet" href="/css/workspace.css?v=6"><?php endif; ?>
<style>
body { margin: 0; font-family: system-ui, sans-serif; background: #f1f6f8; }

.customer-monitoring { margin-left: 280px; padding: 24px; }
.cabinet-embedded .customer-monitoring { margin: 0; padding: .8rem; }
.workspace-layout .customer-monitoring { margin: 0; padding: 0; min-width: 0; }
@media(max-width:992px) { .customer-monitoring { margin-left: 0; padding: 16px; } }
</style>
</head>
<body<?= $embedded ? ' class="cabinet-embedded"' : ($workspaceLite ? ' class="workspace"' : '') ?>>
<?php if (!$embedded): ?>
<?php if ($workspaceLite): ?>
<?php require __DIR__ . '/headers/workspace_nav.php'; ?>
<div class="workspace-layout">
<?php require __DIR__ . '/headers/workspace_sidebar.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/headers/user_header.php'; ?>
<?php require __DIR__ . '/headers/user_sidebar.php'; ?>
<?php endif; ?>
<?php endif; ?>
<main class="customer-monitoring hv-overview"><div class="hv-status-stream">
<div class="hv-status-head"><h1>Мониторинг</h1><a href="/status/"<?= $embedded ? ' target="_top"' : '' ?>>Статус платформы</a></div>
<?php $infrastructureOverview = true; require __DIR__ . '/../includes/infrastructure_status.php'; ?>
</div>
</main>
<script src="/js/service-overview.js?v=<?= (int)filemtime(__DIR__ . '/../js/service-overview.js') ?>" defer></script>
<?php if ($workspaceLite && !$embedded): ?></div><?php endif; ?>
</body>
</html>
