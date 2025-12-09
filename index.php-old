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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeVlad | Cloud VPS на Proxmox</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="img/cloud.png" type="image/png">
    <style>
        <?php include 'css/index_styles.css'; ?>
    </style>
</head>
<body>
    <!-- Шапка -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
            <img src="img/logo.png" alt="HomeVlad" width="120">
                <span class="logo-text">HomeVlad Cloud</span>
            </a>
            
            <div class="nav-links">
                <a href="#tariffs" class="nav-btn nav-btn-secondary">Тарифы</a>
                <a href="#features" class="nav-btn nav-btn-secondary">Возможности</a>
                <a href="#promo" class="nav-btn nav-btn-secondary">Акции</a>
                <?php if (isset($_SESSION['user'])): ?>
                    <a href="/templates/dashboard.php" class="nav-btn nav-btn-primary">
                        <i class="fas fa-user-circle"></i> Кабинет
                    </a>
                <?php else: ?>
                    <a href="/login/register.php" class="nav-btn nav-btn-primary">
                        <i class="fas fa-rocket"></i> Регистрация
                    </a>
                    <a href="/login/login.php" class="nav-btn nav-btn-primary">
                        <i class="fa-solid fa-gear"></i> Вход
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Герой-баннер -->
    <section class="hero">
        <div class="container">
            <h1>Мощные VPS на Proxmox</h1>
            <p>Надежные виртуальные серверы с автоматической оплатой и мгновенным развертыванием</p>
            <a href="#tariffs" class="btn-main">Выбрать тариф</a>
        </div>
    </section>

    <!-- Тарифы -->
    <section id="tariffs" class="tariffs">
        <div class="container">
            <h2 class="section-title">Наши тарифы</h2>
            <div class="tariff-grid">
                <?php if (empty($tariffs)): ?>
                    <div class="no-tariffs" style="grid-column: 1/-1; text-align: center; padding: 40px;">
                        <i class="fas fa-info-circle" style="font-size: 3rem; color: #ccc; margin-bottom: 20px;"></i>
                        <p style="font-size: 1.2rem; color: #777;">В данный момент нет доступных тарифов</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tariffs as $tariff): ?>
                        <div class="tariff-card <?= $tariff['is_popular'] ? 'popular' : '' ?>">
                            <?php if ($tariff['is_popular']): ?>
                                <div class="popular-badge">Популярный</div>
                            <?php endif; ?>
                            <h3 class="tariff-name"><?= htmlspecialchars($tariff['name']) ?></h3>
                            <div class="tariff-price">~<?= number_format($tariff['price'], 0, '', ' ') ?> руб.<span>/месяц</span></div>
                            <ul class="tariff-features">
                                <li><i class="fas fa-microchip"></i> <?= $tariff['cpu'] ?> vCPU</li>
                                <li><i class="fas fa-memory"></i> <?= $tariff['ram'] ?> MB RAM</li>
                                <li><i class="fas fa-hdd"></i> <?= $tariff['disk'] ?> GB SSD</li>
                                <?php if (!empty($tariff['traffic'])): ?>
                                    <li><i class="fas fa-network-wired"></i> <?= $tariff['traffic'] ?> Трафика</li>
                                <?php endif; ?>
                                <?php if (!empty($tariff['backups'])): ?>
                                    <li><i class="fa fa-cloud"></i> <?= $tariff['backups'] ?></li>
                                <?php endif; ?>
                                <?php if (!empty($tariff['support'])): ?>
                                    <li><i class="fas fa-headset"></i> <?= $tariff['support'] ?></li>
                                <?php endif; ?>
                            </ul>
                            <a href="/login/register.php" class="btn-tariff">Заказать</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Возможности -->
    <section id="features" class="features-section" style="padding: 80px 0; background: #f9f9f9;">
        <div class="container">
            <h2 class="section-title">Наши возможности</h2>
            <div class="features-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
                <?php if (empty($features)): ?>
                    <div style="grid-column: 1/-1; text-align: center;">
                        <p style="color: #777;">Возможности временно недоступны</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($features as $feature): ?>
                        <div class="feature-card" style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05);">
                            <div style="font-size: 2.5rem; color: var(--primary); margin-bottom: 15px;">
                                <i class="<?= htmlspecialchars($feature['icon']) ?>"></i>
                            </div>
                            <h3 style="margin: 0 0 15px; font-size: 1.3rem;"><?= htmlspecialchars($feature['title']) ?></h3>
                            <p style="color: #555; margin: 0;"><?= htmlspecialchars($feature['description']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Акции -->
    <section id="promo" class="promotions-section" style="padding: 80px 0;">
        <div class="container">
            <h2 class="section-title">Акции и спецпредложения</h2>
            <div class="promotions-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px;">
                <?php if (empty($promotions)): ?>
                    <div style="grid-column: 1/-1; text-align: center;">
                        <p style="color: #777;">Сейчас нет активных акций</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($promotions as $promo): ?>
                        <div class="promo-card" style="background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                            <?php if ($promo['image']): ?>
                                <div style="height: 200px; background: url('<?= htmlspecialchars($promo['image']) ?>') center/cover;"></div>
                            <?php endif; ?>
                            <div style="padding: 20px;">
                                <h3 style="margin: 0 0 10px; font-size: 1.3rem; color: var(--primary);"><?= htmlspecialchars($promo['title']) ?></h3>
                                <p style="color: #555; margin: 0 0 15px;"><?= htmlspecialchars($promo['description']) ?></p>
                                <div style="color: #777; font-size: 0.9rem;">
                                    Акция действует до <?= date('d.m.Y', strtotime($promo['end_date'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Футер -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> HomeVlad. Все права защищены.</p>
        </div>
    </footer>
</body>
</html>