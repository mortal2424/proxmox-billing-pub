<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

checkAuth();

$db = new Database();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$user = $db->getConnection()->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Получаем статистику по ВМ
$all_vms = $db->getConnection()->query("SELECT COUNT(*) as total, 
                                      SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running 
                                      FROM vms WHERE user_id = $user_id")->fetch();

// Получаем последние платежи
$last_payment = $db->getConnection()->query("SELECT amount, created_at FROM payments WHERE user_id = $user_id ORDER BY id DESC LIMIT 1")->fetch();

// Получаем ВМ пользователя
$vms = $db->getConnection()->query("SELECT * FROM vms WHERE user_id = $user_id")->fetchAll();

// Получаем квоты пользователя
$quota = $db->getConnection()->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
if (!$quota) {
    $db->getConnection()->exec("INSERT INTO user_quotas (user_id) VALUES ($user_id)");
    $quota = $db->getConnection()->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
}

// Получаем текущее использование ресурсов
$usage = $db->getConnection()->query("
    SELECT 
        COUNT(*) as vm_count,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram,
        SUM(disk) as total_disk
    FROM vms 
    WHERE user_id = $user_id AND status != 'deleted'
")->fetch();

// Рассчитываем процент использования
$cpu_percent = $quota['max_cpu'] > 0 ? round(($usage['total_cpu'] / $quota['max_cpu']) * 100) : 0;
$ram_percent = $quota['max_ram'] > 0 ? round(($usage['total_ram'] / $quota['max_ram']) * 100) : 0;
$disk_percent = $quota['max_disk'] > 0 ? round(($usage['total_disk'] / $quota['max_disk']) * 100) : 0;
$vms_percent = $quota['max_vms'] > 0 ? round(($usage['vm_count'] / $quota['max_vms']) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/dashboard_styles.css'; ?>
        <?php include '../css/header_styles.css'; ?>
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <!-- Основное содержимое -->
    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <!-- Основная область -->
            <main class="admin-main">
                <h1 class="admin-title">
                    <i class="fas fa-tachometer-alt"></i> Личный кабинет
                </h1>
                
                <!-- Статистика -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <h3>Запущенные ВМ</h3>
                        <p class="stat-value"><?= $all_vms['running'] ?? 0 ?></p>
                        <p class="stat-details">из <?= $all_vms['total'] ?? 0 ?> всего</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <h3>Всего ВМ</h3>
                        <p class="stat-value"><?= $all_vms['total'] ?? 0 ?></p>
                        <p class="stat-details">на вашем аккаунте</p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h3>Баланс</h3>
                        <p class="stat-value"><?= number_format($user['balance'], 2) ?> ₽</p>
                        <p class="stat-details"><?= $user['balance'] >= 0 ? 'Доступно' : 'Задолженность' ?></p>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-ruble-sign"></i>
                        </div>
                        <h3>Последний платёж</h3>
                        <p class="stat-value"><?= $last_payment ? number_format($last_payment['amount'], 2) : '0.00' ?> ₽</p>
                        <p class="stat-details"><?= $last_payment ? date('d.m.Y', strtotime($last_payment['created_at'])) : 'нет данных' ?></p>
                    </div>
                    
                    <!-- Плитка квот CPU -->
                    <div class="stat-card quota">
                        <div class="stat-icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <h3>CPU квота</h3>
                        <p class="quota-value <?= $cpu_percent > 80 ? 'quota-high' : ($cpu_percent > 50 ? 'quota-medium' : 'quota-low') ?>">
                            <?= $usage['total_cpu'] ?? 0 ?> / <?= $quota['max_cpu'] ?>
                        </p>
                        <p class="quota-used">Использовано <?= $cpu_percent ?>%</p>
                        <div class="quota-progress">
                            <div class="quota-progress-bar <?= $cpu_percent > 90 ? 'animated' : '' ?>" style="width: <?= $cpu_percent ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Плитка квот RAM -->
                    <div class="stat-card quota">
                        <div class="stat-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <h3>RAM квота</h3>
                        <p class="quota-value <?= $ram_percent > 80 ? 'quota-high' : ($ram_percent > 50 ? 'quota-medium' : 'quota-low') ?>">
                            <?= ($usage['total_ram'] /1024 ?? 0) ?>GB / <?= ($quota['max_ram'] / 1024)?>GB
                        </p>
                        <p class="quota-used">Использовано <?= $ram_percent ?>%</p>
                        <div class="quota-progress">
                            <div class="quota-progress-bar <?= $ram_percent > 90 ? 'animated' : '' ?>" style="width: <?= $ram_percent ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Плитка квот диска -->
                    <div class="stat-card quota">
                        <div class="stat-icon">
                            <i class="fas fa-hdd"></i>
                        </div>
                        <h3>Диск квота</h3>
                        <p class="quota-value <?= $disk_percent > 80 ? 'quota-high' : ($disk_percent > 50 ? 'quota-medium' : 'quota-low') ?>">
                            <?= $usage['total_disk'] ?? 0 ?>GB / <?= $quota['max_disk'] ?>GB
                        </p>
                        <p class="quota-used">Использовано <?= $disk_percent ?>%</p>
                        <div class="quota-progress">
                            <div class="quota-progress-bar <?= $disk_percent > 90 ? 'animated' : '' ?>" style="width: <?= $disk_percent ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Плитка квот ВМ -->
                    <div class="stat-card quota">
                        <div class="stat-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <h3>Квота ВМ</h3>
                        <p class="quota-value <?= $vms_percent > 80 ? 'quota-high' : ($vms_percent > 50 ? 'quota-medium' : 'quota-low') ?>">
                            <?= $usage['vm_count'] ?? 0 ?> / <?= $quota['max_vms'] ?>
                        </p>
                        <p class="quota-used">Использовано <?= $vms_percent ?>%</p>
                        <div class="quota-progress">
                            <div class="quota-progress-bar <?= $vms_percent > 90 ? 'animated' : '' ?>" style="width: <?= $vms_percent ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Новая плитка бонусного баланса -->
                    <div class="stat-card bonus">
                        <div class="stat-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                        <h3>Бонусный баланс</h3>
                        <p class="stat-value"><?= number_format($user['bonus_balance'], 2) ?> ₽</p>
                        <p class="stat-details"><?= $user['bonus_balance'] > 0 ? 'Доступно' : 'Нет бонусов' ?></p>
                    </div>

                    <!-- Плитка Админ Панели (только для админов) -->
                    <?php if ($user['is_admin']): ?>
                    <div class="stat-card admin">
                        <div class="stat-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3>Админ Панель</h3>
                        <p class="stat-value">Доступно</p>
                        <p class="stat-details">Управление системой</p>
                        <a href="/admin/" class="btn btn-small" style="margin-top: 10px; display: inline-block;">
                            <i class="fas fa-cog"></i> Перейти
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Быстрые действия -->
                <div class="quick-actions">
                    <a href="/templates/order_vm.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Создать ВМ
                    </a>
                    <a href="/templates/billing.php" class="btn btn-secondary">
                        <i class="fas fa-credit-card"></i> Пополнить баланс
                    </a>
                    <?php if ($user['bonus_balance'] > 0): ?>
                        <a href="/templates/billing.php#bonuses" class="btn btn-warning">
                            <i class="fas fa-coins"></i> Использовать бонусы
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Секция виртуальных машин -->
                <section class="section">
                    <h2 class="section-title">
                        <i class="fas fa-server"></i> Ваши виртуальные машины
                    </h2>
                    
                    <?php if (count($vms) > 0): ?>
                        <div class="vm-list">
                            <?php foreach ($vms as $vm): ?>
                                <div class="vm-card">
                                    <h3 class="vm-name"><?= htmlspecialchars($vm['hostname']) ?></h3>
                                    <div class="vm-specs-grid">
                                        <div class="vm-spec">
                                            <i class="fas fa-microchip"></i>
                                            <span><?= $vm['cpu'] ?> vCPU</span>
                                        </div>
                                        <div class="vm-spec">
                                            <i class="fas fa-memory"></i>
                                            <span><?= ($vm['ram'] / 1024)?> GB RAM</span>
                                        </div>
                                        <div class="vm-spec">
                                            <i class="fas fa-hdd"></i>
                                            <span><?= $vm['disk'] ?> GB SSD</span>
                                        </div>
                                        <div class="vm-spec">
                                            <i class="fas fa-network-wired"></i>
                                            <span><?= $vm['ip_address'] ?? 'Не назначен' ?></span>
                                        </div>
                                    </div>
                                    <span class="status-badge <?= $vm['status'] === 'running' ? 'status-active' : 'status-inactive' ?>">
                                        <?= $vm['status'] === 'running' ? 'Запущена' : 'Остановлена' ?>
                                    </span>
                                    <div class="vm-actions">
                                        <?php if ($vm['status'] !== 'running'): ?>
                                            <button class="btn btn-primary btn-icon" title="Запустить">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-warning btn-icon" title="Перезагрузить">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <button class="btn btn-danger btn-icon" title="Остановить">
                                                <i class="fas fa-stop"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-info btn-icon" title="Подробнее">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-cloud fa-3x" style="color: #b0bec5; margin-bottom: 15px;"></i>
                            <p>У вас пока нет виртуальных машин</p>
                            <a href="/templates/order_vm.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Заказать ВМ
                            </a>
                        </div>
                    <?php endif; ?>
                </section>
                
                <!-- Последние действия -->
                <section class="section">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i> Последние действия
                    </h2>
                    <div class="recent-activity">
                        <p>Здесь будет отображаться история ваших действий...</p>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
        // Обработка кнопок управления ВМ
        document.querySelectorAll('.vm-actions button').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.title.toLowerCase();
                alert(`Функция "${action}" будет реализована в следующих версиях`);
            });
        });
        
        // Адаптивное меню для мобильных устройств
        const menuToggle = document.createElement('button');
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        menuToggle.className = 'btn btn-icon';
        menuToggle.style.position = 'fixed';
        menuToggle.style.top = '15px';
        menuToggle.style.left = '15px';
        menuToggle.style.zIndex = '1000';
        document.body.appendChild(menuToggle);
        
        const sidebar = document.querySelector('.admin-sidebar');
        
        function checkScreenSize() {
            if (window.innerWidth <= 992) {
                sidebar.style.display = 'none';
                menuToggle.style.display = 'block';
            } else {
                sidebar.style.display = 'block';
                menuToggle.style.display = 'none';
            }
        }
        
        menuToggle.addEventListener('click', function() {
            sidebar.style.display = sidebar.style.display === 'none' ? 'block' : 'none';
        });
        
        window.addEventListener('resize', checkScreenSize);
        checkScreenSize();
    </script>
</body>
</html>