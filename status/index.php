<?php
require_once __DIR__ . '/../includes/db.php';

header('Cache-Control: no-store');
$embedded = ($_GET['embed'] ?? '') === '1';
if ($embedded) {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

$databaseAvailable = false;
$monitoringAvailable = false;
$nodes = [];
try {
    $pdo = (new Database())->getConnection();
    $pdo->query('SELECT 1')->fetchColumn();
    $databaseAvailable = true;
    $nodes = $pdo->query('SELECT n.id, s.created_at,
        TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS age_seconds
        FROM proxmox_nodes n JOIN proxmox_clusters z ON z.id = n.cluster_id
        LEFT JOIN node_stats s ON s.id = (
            SELECT recent.id FROM node_stats recent
            WHERE recent.node_id = n.id ORDER BY recent.created_at DESC, recent.id DESC LIMIT 1
        )
        WHERE n.is_active = 1 AND n.available_for_users = 1 AND z.allowed_user_ids IS NULL ORDER BY n.id')->fetchAll();
    $monitoringAvailable = true;
} catch (Throwable $exception) {
    error_log('Platform status: database or metrics query failed');
}

$console = @stream_socket_client('unix:///run/homevlad-console/issuer.sock', $socketError, $socketMessage, 1);
$consoleAvailable = $console !== false;
if ($consoleAvailable) {
    fclose($console);
}

function recentMeasurement(array $node): bool
{
    return $node['age_seconds'] !== null && (int) $node['age_seconds'] >= 0 && (int) $node['age_seconds'] <= 900;
}

$freshNodes = count(array_filter($nodes, 'recentMeasurement'));
$services = [
    ['База данных', $databaseAvailable ? 'Доступна' : 'Недоступна', $databaseAvailable, 'Ответ на проверочный запрос.'],
    ['Прокси консоли', $consoleAvailable ? 'Принимает соединения' : 'Нет ответа', $consoleAvailable, 'Проверка локального сокета прокси.'],
    ['Мониторинг узлов', $freshNodes > 0 ? 'Есть свежие замеры' : 'Нет свежих замеров', $freshNodes > 0,
        $monitoringAvailable && $nodes ? "Узлов со свежими замерами: {$freshNodes} из " . count($nodes) . '.' : 'Нет данных о публичных узлах.'],
];
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require __DIR__ . '/../includes/app_head.php'; ?>
    <title>Статус системы — HomeVlad Cloud</title>
    <link rel="stylesheet" href="/css/landing.css">
    <link rel="stylesheet" href="/css/service-overview.css?v=<?= (int)filemtime(__DIR__ . '/../css/service-overview.css') ?>">
</head>
<body class="hv-public-status">
<?php if (!$embedded): ?>
<header class="site-header">
    <div class="wrap header-row">
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">HV</span> HomeVlad Cloud</a>
        <a class="button button-outline" href="/templates/dashboard.php">Личный кабинет</a>
    </div>
</header>
<?php endif ?>
<main class="hv-status-main hv-overview">
    <?php $allChecksOk = $databaseAvailable && $consoleAvailable && count($nodes) > 0 && $freshNodes === count($nodes); ?>
    <div class="hv-status-head"><h1>Статус системы</h1><a href="/status/<?= $embedded ? '?embed=1' : '' ?>">Обновить</a></div>
    <div class="hv-state-banner <?= $allChecksOk ? 'hv-ok' : 'hv-warn' ?>">
        <strong class="hv-state"><?= $allChecksOk ? 'Все проверки пройдены' : 'Часть проверок требует внимания' ?></strong>
        <div>Обновлено в <?= $escape((new DateTimeImmutable('now', new DateTimeZone('Asia/Vladivostok')))->format('H:i')) ?> по Владивостоку.</div>
    </div>
    <h2>Сервисы платформы</h2>
    <label class="hv-status-search">Поиск
    <input type="search" data-status-search placeholder="Название сервиса или узла">
</label>
<p data-status-empty hidden>Совпадений нет.</p>

    <details class="hv-status-group" data-status-group open>
        <summary><span>Проверки доступности</span><span class="hv-state <?= $allChecksOk ? 'hv-ok' : 'hv-warn' ?>"><?= $allChecksOk ? 'В норме' : 'Есть отклонения' ?></span></summary>
        <?php foreach ($services as [$label, $state, $ok, $description]): ?>
            <div class="hv-status-row" data-status-row>
                <div><strong><?= $escape($label) ?></strong><small><?= $escape($description) ?></small></div>
                <span class="hv-state <?= $ok ? 'hv-ok' : 'hv-warn' ?>"><?= $escape($state) ?></span>
            </div>
        <?php endforeach ?>
    </details>
    <details class="hv-status-group" data-status-group>
        <summary><span>Гипервизоры</span><span class="hv-state <?= $nodes && $freshNodes === count($nodes) ? 'hv-ok' : 'hv-warn' ?>"><?= $nodes ? "$freshNodes / " . count($nodes) . " со свежими замерами" : "Нет данных" ?></span></summary>
        <?php foreach ($nodes as $node): ?>
            <?php $fresh = recentMeasurement($node); ?>
            <div class="hv-status-row" data-status-row>
                <strong>Узел <?= (int) $node['id'] ?></strong>
                <span class="hv-state <?= $fresh ? 'hv-ok' : 'hv-warn' ?>"><?= $fresh ? 'Свежий замер' : 'Состояние неизвестно' ?></span>
            </div>
        <?php endforeach ?>
        <?php if (!$nodes): ?><div class="hv-status-row" data-status-row>Нет данных о публичных гипервизорах.</div><?php endif ?>
    </details>
    <section class="hv-observations">
        <h2>Отклонения по текущим проверкам</h2>
        <?php if ($allChecksOk): ?><p>Отклонений по последним проверкам нет.</p><?php endif ?>
        <?php foreach ($services as [$label, $state, $ok, $description]): ?>
            <?php if (!$ok): ?><article class="hv-observation"><strong class="hv-warn"><?= $escape($label) ?> · <?= $escape($state) ?></strong><div><?= $escape($description) ?></div></article><?php endif ?>
        <?php endforeach ?>
        <?php if ($nodes && $freshNodes !== count($nodes)): ?>
            <article class="hv-observation"><strong class="hv-warn">Гипервизоры · нет свежих замеров</strong><div>Текущее состояние <?= count($nodes) - $freshNodes ?> узл. неизвестно.</div></article>
        <?php endif ?>
    </section>
</main>
<?php if (!$embedded): ?>
<footer class="site-footer">
    <div class="wrap footer-row"><span>HomeVlad Cloud</span><a href="/docs/docs.php">Документация</a></div>
</footer>
<?php endif ?>
<script src="/js/service-overview.js?v=<?= (int)filemtime(__DIR__ . '/../js/service-overview.js') ?>" defer></script>
</body>
</html>
