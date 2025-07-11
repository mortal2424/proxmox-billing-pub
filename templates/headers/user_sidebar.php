<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

checkAuth();
$user_id = $_SESSION['user']['id'];
$user = $db->getConnection()->query("SELECT * FROM users WHERE id = $user_id")->fetch();
?>
<!-- Боковая панель пользователя -->
<!-- Сайдбар -->
            <aside class="admin-sidebar">
                <ul class="admin-menu">
                    <li class="admin-menu-item">
                        <a href="/templates/dashboard.php" class="admin-menu-link">
                            <i class="fas fa-tachometer-alt"></i> Дашборд
                        </a>
                    </li>
                    <li class="admin-menu-item">
                        <a href="/templates/order_vm.php" class="admin-menu-link">
                            <i class="fas fa-plus-circle"></i> Заказать ВМ
                        </a>
                    </li>
                    <li class="admin-menu-item">
                        <a href="/templates/my_vms.php" class="admin-menu-link">
                            <i class="fas fa-server"></i> Мои ВМ
                        </a>
                    </li>
                    <li class="admin-menu-item">
                        <a href="/templates/billing.php" class="admin-menu-link link">
                            <i class="fas fa-credit-card"></i> Биллинг
                        </a>
                    </li>
                    <li class="admin-menu-item">
                        <a href="/templates/settings.php" class="admin-menu-link">
                            <i class="fas fa-cog"></i> Настройки
                        </a>
                    </li>
                    <!--<li class="menu-item <?= $activePage == 'telegram' ? 'active' : '' ?>">
                        <a href="/templates/telegram.php">
                            <i class="fab fa-telegram"></i> Telegram Bot
                        </a>
                    </li>-->
                    <li class="admin-menu-item">
                        <a href="/templates/support.php" class="admin-menu-link">
                            <i class="fas fa-question-circle"></i> Поддержка
                        </a>
                    </li>
                </ul>
            </aside>