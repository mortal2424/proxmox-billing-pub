<?php
require_once __DIR__ . '/../includes/db.php';

header('Cache-Control: no-store');

$databaseAvailable = false;
$monitoringAvailable = false;
$nodes = [];
try {
    $pdo = (new Database())->getConnection();
    $pdo->query('SELECT 1')->fetchColumn();
    $databaseAvailable = true;
    $nodes = $pdo->query('SELECT n.id, s.created_at,
        TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS age_seconds
        FROM proxmox_nodes n LEFT JOIN node_stats s ON s.id = (
            SELECT recent.id FROM node_stats recent
            WHERE recent.node_id = n.id ORDER BY recent.created_at DESC, recent.id DESC LIMIT 1
        )
        WHERE n.is_active = 1 AND n.available_for_users = 1 ORDER BY n.id')->fetchAll();
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
        $monitoringAvailable ? "Узлов со свежими замерами: {$freshNodes} из " . count($nodes) . '.' : 'Данные мониторинга недоступны.'],
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
</head>
<body>
<header class="site-header">
    <div class="wrap header-row">
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">HV</span> HomeVlad Cloud</a>
        <a class="button button-outline" href="/templates/dashboard.php">Личный кабинет</a>
    </div>
</header>
<main class="wrap">
    <section class="section" aria-labelledby="status-title">
        <p class="eyebrow">HomeVlad Cloud</p>
        <h1 id="status-title">Статус системы</h1>
        <p class="section-intro">Проверка выполнена в <?= gmdate('H:i') ?> UTC.</p>
        <a class="button button-outline" href="/status/">Обновить</a>
        <div class="cards">
            <?php foreach ($services as [$label, $state, $ok, $description]): ?>
                <article class="panel">
                    <h3><?= $escape($label) ?></h3>
                    <p><?= $ok ? '🟢' : '🟡' ?> <?= $escape($state) ?></p>
                    <p><?= $escape($description) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="section" aria-labelledby="nodes-title">
        <h2 id="nodes-title">Гипервизоры</h2>
        <p class="section-intro">Замер считается свежим в течение 15 минут. Отсутствие свежего замера означает, что текущее состояние неизвестно.</p>
        <?php if (!$nodes): ?>
            <p class="panel">Нет данных о доступных пользователям гипервизорах.</p>
        <?php else: ?>
            <div class="cards">
                <?php foreach ($nodes as $node): ?>
                    <article class="panel">
                        <h3>Узел <?= (int) $node['id'] ?></h3>
                        <p><?= recentMeasurement($node) ? '🟢 Свежий замер' : '🟡 Состояние неизвестно' ?></p>
                        <p><?= $node['created_at'] ? 'Последний замер ' . max(0, (int) floor($node['age_seconds'] / 60)) . ' мин. назад' : 'Замеров пока нет' ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<footer class="site-footer">
    <div class="wrap footer-row"><span>HomeVlad Cloud</span><a href="/docs/docs.php">Документация</a></div>
</footer>
</body>
</html>
