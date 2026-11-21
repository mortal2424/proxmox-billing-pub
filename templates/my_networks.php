<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

checkAuth();
header('Cache-Control: private, no-store');
$pdo = (new Database())->getConnection();
$query = $pdo->prepare('SELECT id, is_admin FROM users WHERE id=? AND is_active=1');
$query->execute([(int)$_SESSION['user']['id']]);
$user = $query->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(403);
    exit('Доступ запрещён');
}

$query = $pdo->prepare("SELECT id,hostname,vm_type,ip_address FROM vms
    WHERE user_id=? AND status<>'deleted' AND ip_address IS NOT NULL AND ip_address<>''
    ORDER BY hostname,id");
$query->execute([$user['id']]);
$guests = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои сети | HomeVlad Cloud</title>
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <link rel="stylesheet" href="/css/user-palette.css">
    <link rel="stylesheet" href="/css/workspace.css?v=6">
    <link rel="stylesheet" href="/css/cloud-overview.css?v=3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="workspace cloud-overview">
    <?php require __DIR__ . '/headers/workspace_nav.php'; ?>
    <div class="workspace-layout">
        <?php require __DIR__ . '/headers/workspace_sidebar.php'; ?>
        <main class="cloud-main">
            <section class="cloud-panel" aria-labelledby="network-title">
                <div class="cloud-section-head">
                    <div>
                        <h1 id="network-title">Мои сети</h1>
                        <p>Обнаруженные IP-адреса ВМ и контейнеров. Интерфейсы управляются из карточки гостя.</p>
                    </div>
                </div>
                <?php if (!$guests): ?>
                    <div class="cloud-section-head"><p>У ваших ВМ и контейнеров пока нет обнаруженных IP-адресов.</p></div>
                <?php else: ?>
                    <ul class="cloud-guests">
                        <?php foreach ($guests as $guest): ?>
                            <li class="cloud-guest">
                                <span class="cloud-guest-icon" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                                <div class="cloud-guest-name">
                                    <a href="/templates/vm_settings.php?id=<?= (int)$guest['id'] ?>#networks-section"><?= htmlspecialchars($guest['hostname']) ?></a>
                                    <small><?= $guest['vm_type'] === 'lxc' ? 'Контейнер LXC' : 'Виртуальная машина' ?></small>
                                </div>
                                <code><?= htmlspecialchars($guest['ip_address']) ?></code>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
