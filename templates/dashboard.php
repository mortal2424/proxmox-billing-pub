<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

checkAuth();

$pdo = (new Database())->getConnection();
$userId = (int) $_SESSION['user']['id'];
$query = $pdo->prepare('SELECT balance, bonus_balance, is_admin FROM users WHERE id = ?');
$query->execute([$userId]);
$user = $query->fetch();

$query = $pdo->prepare('SELECT amount, created_at FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$query->execute([$userId]);
$lastPayment = $query->fetch();

$guestScope = "FROM vms WHERE user_id = ? AND (status IS NULL OR status <> 'deleted')";
$query = $pdo->prepare("SELECT COUNT(*) AS guests, SUM(status = 'running') AS running,
    SUM(cpu) AS cpu, SUM(ram) AS ram, SUM(disk) AS disk $guestScope");
$query->execute([$userId]);
$usage = $query->fetch();

$query = $pdo->prepare("SELECT hostname, vm_id, vm_type, status, cpu, ram, disk, ip_address
    $guestScope ORDER BY created_at DESC, id DESC LIMIT 6");
$query->execute([$userId]);
$guests = $query->fetchAll();

$query = $pdo->prepare('SELECT max_vms, max_cpu, max_ram, max_disk FROM user_quotas WHERE user_id = ?');
$query->execute([$userId]);
$quota = $query->fetch();
if (!$quota) {
    $initializeQuota = $pdo->prepare('INSERT INTO user_quotas (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id');
    $initializeQuota->execute([$userId]);
    $query->execute([$userId]);
    $quota = $query->fetch();
}

$query = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND (status IS NULL OR status <> 'closed')");
$query->execute([$userId]);
$openTickets = (int) $query->fetchColumn();

$resources = [
    ['Серверы', 'guests', 'max_vms', '', 1],
    ['Процессор', 'cpu', 'max_cpu', ' vCPU', 1],
    ['Память', 'ram', 'max_ram', ' ГБ', 1024],
    ['Хранилище', 'disk', 'max_disk', ' ГБ', 1],
];
$money = static fn ($value) => number_format((float) $value, 2, ',', ' ') . ' ₽';
$running = (int) $usage['running'];
$total = (int) $usage['guests'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <title>Инфраструктура | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/workspace.css?v=3">
    <link rel="stylesheet" href="/css/cloud-overview.css?v=1">
</head>
<body class="workspace cloud-overview">
    <?php require __DIR__ . '/headers/workspace_nav.php'; ?>
    <main class="cloud-main">
        <section class="cloud-hero" aria-labelledby="cloud-title">
            <div>
                <p class="cloud-kicker">HOMEVLAD CLOUD / ЛИЧНЫЙ КАБИНЕТ</p>
                <h1 id="cloud-title">Инфраструктура</h1>
                <p>Виртуальные машины, контейнеры и ресурсы аккаунта.</p>
                <a class="cloud-button" href="/templates/order_vm.php"><i class="fas fa-plus" aria-hidden="true"></i> Заказать сервер</a>
            </div>
            <dl class="cloud-fleet">
                <div><dt>Всего серверов</dt><dd><?= $total ?></dd></div>
                <div><dt><span class="cloud-dot is-running"></span> Запущено</dt><dd><?= $running ?></dd></div>
                <div><dt>Не запущено</dt><dd><?= $total - $running ?></dd></div>
            </dl>
        </section>

        <div class="cloud-columns">
            <section class="cloud-panel cloud-inventory" aria-labelledby="cloud-servers">
                <header class="cloud-section-head">
                    <div>
                        <h2 id="cloud-servers">Последние серверы</h2>
                        <p>ВМ и LXC в порядке создания</p>
                    </div>
                    <a href="/templates/my_vms.php">Все серверы →</a>
                </header>
                <?php if ($guests): ?>
                    <ul class="cloud-guests">
                        <?php foreach ($guests as $guest):
                            $type = $guest['vm_type'] === 'lxc' ? 'lxc' : 'qemu';
                            $status = match ($guest['status']) {
                                'running' => 'Работает',
                                'stopped' => 'Остановлен',
                                'suspended' => 'Приостановлен',
                                'creating' => 'Создаётся',
                                default => 'Неизвестно',
                            };
                        ?>
                            <li class="cloud-guest">
                                <span class="cloud-guest-icon" aria-hidden="true"><i class="fas <?= $type === 'lxc' ? 'fa-cube' : 'fa-server' ?>"></i></span>
                                <div class="cloud-guest-name">
                                    <a href="/templates/my_vms.php?guest=<?= (int) $guest['vm_id'] ?>&amp;type=<?= $type ?>"><?= htmlspecialchars($guest['hostname'] ?: 'Сервер') ?></a>
                                    <small><?= strtoupper($type) ?> · №<?= (int) $guest['vm_id'] ?><?php if ($guest['ip_address']): ?> · <?= htmlspecialchars($guest['ip_address']) ?><?php endif; ?></small>
                                </div>
                                <span class="cloud-status"><span class="cloud-dot <?= $guest['status'] === 'running' ? 'is-running' : '' ?>"></span><?= $status ?></span>
                                <small class="cloud-spec"><?= (int) $guest['cpu'] ?> vCPU · <?= (int) $guest['ram'] / 1024 ?> ГБ RAM · <?= (int) $guest['disk'] ?> ГБ диска</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="cloud-empty">
                        <i class="fas fa-server" aria-hidden="true"></i>
                        <p>На аккаунте пока нет серверов.</p>
                        <a href="/templates/order_vm.php">Выбрать конфигурацию →</a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="cloud-panel cloud-limits" aria-labelledby="cloud-resources">
                <header class="cloud-section-head">
                    <div><h2 id="cloud-resources">Квоты</h2><p>Выделено серверам / лимит аккаунта</p></div>
                </header>
                <?php foreach ($resources as [$label, $usedKey, $limitKey, $unit, $divisor]):
                    $used = (int) $usage[$usedKey];
                    $limit = isset($quota[$limitKey]) ? (int) $quota[$limitKey] : null;
                    $percent = $limit > 0 ? round(100 * $used / $limit) : null;
                ?>
                    <div class="cloud-limit">
                        <div><strong><?= $label ?></strong><span><?= $used / $divisor ?> / <?= $limit === null ? '—' : $limit / $divisor ?><?= $unit ?></span></div>
                        <?php if ($percent !== null): ?>
                            <progress class="<?= $percent >= 85 ? 'near-limit' : '' ?>" max="100" value="<?= min(100, $percent) ?>" aria-label="<?= $label ?>, выделено <?= $percent ?>%"></progress>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="cloud-panel cloud-finances" aria-labelledby="cloud-billing">
                <header class="cloud-section-head">
                    <div><h2 id="cloud-billing">Финансы</h2><p>Баланс и история пополнений</p></div>
                    <a href="/templates/billing.php#payment-form">Пополнить →</a>
                </header>
                <dl class="cloud-money">
                    <div><dt>Основной баланс</dt><dd><?= $money($user['balance']) ?></dd></div>
                    <div><dt>Бонусы</dt><dd><?= $money($user['bonus_balance']) ?></dd></div>
                    <div><dt>Последний платёж<?= $lastPayment ? ' · ' . date('d.m.Y', strtotime($lastPayment['created_at'])) : '' ?></dt><dd><?= $lastPayment ? $money($lastPayment['amount']) : '—' ?></dd></div>
                </dl>
            </section>

            <section class="cloud-panel cloud-links" aria-labelledby="cloud-help">
                <header class="cloud-section-head"><h2 id="cloud-help">Помощь</h2></header>
                <a href="/templates/support.php"><i class="fas fa-headset" aria-hidden="true"></i> Обращения <span><?= $openTickets ?> открыто →</span></a>
                <a href="/docs/docs.php"><i class="fas fa-book" aria-hidden="true"></i> Документация <span>→</span></a>
                <a href="/status/"><i class="fas fa-wave-square" aria-hidden="true"></i> Статус платформы <span>→</span></a>
            </section>
        </div>
    </main>
    <?php
    $footer = __DIR__ . '/headers/user_footer.php';
    if (is_file($footer)) {
        require $footer;
    }
    ?>
</body>
</html>
