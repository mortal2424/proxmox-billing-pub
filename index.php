<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

// Получаем активные готовые тарифы (is_custom = 0)
$tariffs = [];
try {
    $stmt = $pdo->query("SELECT * FROM tariffs WHERE is_active = 1 AND is_custom = 0 AND vm_type = 'qemu' ORDER BY price ASC");
    $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Получаем возможности
$features = [];
try {
    $stmt = $pdo->query("SELECT * FROM features WHERE is_active = 1 ORDER BY id ASC LIMIT 6");
    $features = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Получаем активные акции
$promotions = [];
try {
    $currentDate = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM promotions WHERE is_active = 1 AND start_date <= ? AND end_date >= ? ORDER BY start_date DESC");
    $stmt->execute([$currentDate, $currentDate]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

$loggedIn = isset($_SESSION['user']);
$accountUrl = $loggedIn ? '/templates/dashboard.php' : '/login/login.php';
$startUrl = $loggedIn ? '/templates/order_vm.php' : '/login/register.php';
$accountLabel = $loggedIn ? 'Личный кабинет' : 'Войти';
$startLabel = $loggedIn ? 'Создать сервер' : 'Создать аккаунт';
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Виртуальные серверы HomeVlad Cloud на Proxmox. Сравните тарифы и управляйте ресурсами из личного кабинета.">
    <title>HomeVlad Cloud — виртуальные серверы на Proxmox</title>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
    <link rel="icon" href="/img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/landing.css">
</head>
<body>
<a class="skip-link" href="#main">Перейти к содержанию</a>
<header class="site-header">
    <div class="wrap header-row">
        <a class="brand" href="/" aria-label="HomeVlad Cloud, главная">
            <span class="brand-mark" aria-hidden="true">HV</span> HomeVlad<span class="brand-cloud">Cloud</span>
        </a>
        <nav aria-label="Основная навигация">
            <a href="#tariffs">Тарифы</a>
            <?php if ($features): ?><a href="#features">Возможности</a><?php endif; ?>
            <?php if ($promotions): ?><a href="#promo">Акции</a><?php endif; ?>
        </nav>
        <a class="button button-outline account" href="<?= $accountUrl ?>"><?= $accountLabel ?></a>
    </div>
</header>

<main id="main">
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <p class="eyebrow"><span></span> Виртуализация Proxmox</p>
                <h1>Облачные серверы для ваших проектов</h1>
                <p class="intro">Выберите конфигурацию VPS и управляйте ресурсами, балансом и обращениями в поддержку из одного кабинета.</p>
                <div class="actions">
                    <a class="button button-primary" href="#tariffs">Смотреть тарифы <span aria-hidden="true">↓</span></a>
                    <a class="button button-outline" href="<?= $startUrl ?>"><?= $startLabel ?></a>
                </div>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="rack">
                    <div class="rack-label"><span>HOMEVLAD CLOUD</span><span>QEMU / KVM</span></div>
                    <div class="rack-slot"><span>01</span><i></i><i></i><i></i><b></b></div>
                    <div class="rack-slot"><span>02</span><i></i><i></i><i></i><b></b></div>
                    <div class="rack-slot"><span>03</span><i></i><i></i><i></i><b></b></div>
                    <div class="rack-label"><span>VIRTUAL INFRASTRUCTURE</span><span>HV</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="wrap section" id="tariffs" aria-labelledby="tariff-title">
        <p class="eyebrow">Конфигурации VPS</p>
        <h2 id="tariff-title">Выберите свой тариф</h2>
        <p class="section-intro">Сравните процессор, память и диск для вашей задачи.</p>
        <?php if (!$tariffs): ?>
            <p class="panel">Тарифы временно недоступны. Загляните позже.</p>
        <?php else: ?>
            <div class="cards">
                <?php foreach ($tariffs as $tariff): ?>
                    <?php $planUrl = $loggedIn ? $startUrl : $startUrl . '?tariff_id=' . (int) $tariff['id']; ?>
                    <article class="panel plan<?= $tariff['is_popular'] ? ' featured' : '' ?>">
                        <div class="plan-heading">
                            <h3><?= $escape($tariff['name']) ?></h3>
                            <?php if ($tariff['is_popular']): ?><span class="badge">Популярный</span><?php endif; ?>
                        </div>
                        <p class="price"><?= number_format((float) $tariff['price'], fmod((float) $tariff['price'], 1) ? 2 : 0, ',', ' ') ?> ₽ <small>/ мес.</small></p>
                        <dl class="specs">
                            <div><dt>Процессор</dt><dd><?= (int) $tariff['cpu'] ?> vCPU</dd></div>
                            <div><dt>Память</dt><dd><?= (int) $tariff['ram'] ?> МБ</dd></div>
                            <div><dt>Диск</dt><dd><?= (int) $tariff['disk'] ?> ГБ</dd></div>
                            <?php foreach (['traffic' => 'Трафик', 'backups' => 'Бэкапы', 'support' => 'Поддержка'] as $key => $label): ?>
                                <?php if (!empty($tariff[$key])): ?>
                                    <div><dt><?= $label ?></dt><dd><?= $escape($tariff[$key]) ?></dd></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </dl>
                        <a class="button button-outline" href="<?= $planUrl ?>" aria-label="<?= $escape($startLabel . ' · ' . $tariff['name']) ?>"><?= $startLabel ?> <span aria-hidden="true">→</span></a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($features): ?>
        <section class="wrap section" id="features" aria-labelledby="feature-title">
            <p class="eyebrow">Платформа</p>
            <h2 id="feature-title">Возможности HomeVlad</h2>
            <div class="cards">
                <?php foreach ($features as $index => $feature): ?>
                    <article class="panel">
                        <span class="feature-number" aria-hidden="true"><?= sprintf('%02d', $index + 1) ?></span>
                        <h3><?= $escape($feature['title']) ?></h3>
                        <p><?= $escape($feature['description']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($promotions): ?>
        <section class="wrap section" id="promo" aria-labelledby="promo-title">
            <p class="eyebrow">Предложения</p>
            <h2 id="promo-title">Действующие акции</h2>
            <div class="cards">
                <?php foreach ($promotions as $promo): ?>
                    <article class="panel">
                        <?php if (!empty($promo['image_url'])): ?>
                            <img class="promo-image" src="<?= $escape($promo['image_url']) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <h3><?= $escape($promo['title']) ?></h3>
                        <p><?= $escape($promo['description']) ?></p>
                        <p class="eyebrow">До <?= date('d.m.Y', strtotime($promo['end_date'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <div class="wrap footer-row">
        <span>© <?= date('Y') ?> HomeVlad Cloud</span>
        <a href="<?= $accountUrl ?>"><?= $accountLabel ?></a>
    </div>
</footer>
</body>
</html>
