<!-- Современная шапка с центрированными элементами -->
<header class="admin-header modern-header centered-header">
    <div class="container">
        <div class="header-content">
            <!-- Логотип и брендинг -->
            <a href="/" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-cloud"></i>
                </div>
                <div class="logo-text">
                    <span class="logo-title">HomeVlad</span>
                    <span class="logo-subtitle">Cloud Panel</span>
                </div>
            </a>

            <!-- Центральная часть с навигацией -->
            <div class="header-center">
                <!-- Быстрые действия -->
                <div class="quick-actions">
                    <a href="/templates/dashboard.php" class="quick-action" data-tooltip="Дашборд">
                        <i class="fas fa-chart-line"></i>
                    </a>
                    <a href="/templates/billing.php" class="quick-action" data-tooltip="Финансы">
                        <i class="fas fa-credit-card"></i>
                    </a>
                    <a href="/templates/support.php" class="quick-action" data-tooltip="Поддержка">
                        <i class="fas fa-headset"></i>
                    </a>
                </div>

                <!-- Уведомления -->
                <div class="notifications-dropdown">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <?php if (isset($user['unread_notifications']) && $user['unread_notifications'] > 0): ?>
                            <span class="notification-badge"><?= $user['unread_notifications'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notifications-menu" id="notificationsMenu">
                        <div class="notifications-header">
                            <h4>Уведомления</h4>
                            <a href="/docs/docs.php" class="view-all">Все</a>
                        </div>
                        <div class="notifications-list">
                            <div class="notification-empty">
                                <i class="fas fa-check-circle"></i>
                                <p>Нет новых уведомлений</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Переключатель темы -->
                <div class="theme-switcher">
                    <input type="checkbox" id="themeToggle" class="theme-checkbox" hidden>
                    <label for="themeToggle" class="theme-label">
                        <i class="fas fa-sun"></i>
                        <i class="fas fa-moon"></i>
                        <span class="theme-ball"></span>
                    </label>
                </div>
            </div>

            <!-- Профиль пользователя -->
            <div class="user-profile-dropdown">
                <button class="profile-btn" id="profileBtn">
                    <?php if (!empty($user['avatar'])): ?>
                        <div class="user-avatar">
                            <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар" class="avatar-image">
                        </div>
                    <?php else: ?>
                        <?php
                        $defaultIcons = ['fa-user', 'fa-user-tie', 'fa-user-astronaut', 'fa-user-shield'];
                        $randomIcon = $defaultIcons[array_rand($defaultIcons)];
                        ?>
                        <div class="user-avatar default-avatar">
                            <i class="fas <?= $randomIcon ?>"></i>
                        </div>
                    <?php endif; ?>

                    <div class="user-info-short">
                        <span class="user-name"><?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></span>
                        <span class="user-balance"><?= number_format($user['balance'] + $user['bonus_balance'], 0) ?> ₽</span>
                    </div>

                    <i class="fas fa-chevron-down dropdown-arrow"></i>
                </button>

                <div class="profile-menu" id="profileMenu">
                    <div class="profile-header">
                        <div class="profile-avatar-large">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар">
                            <?php else: ?>
                                <i class="fas <?= $randomIcon ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="profile-info">
                            <h4><?= htmlspecialchars($user['full_name']) ?></h4>
                            <span class="user-email"><?= htmlspecialchars($user['email']) ?></span>
                            <div class="account-badges">
                                <span class="account-type-badge <?= $user['user_type'] ?>-badge">
                                    <?= $user['user_type'] === 'individual' ? 'Физ. лицо' :
                                       ($user['user_type'] === 'entrepreneur' ? 'ИП' : 'Юр. лицо') ?>
                                </span>
                                <?php if (isset($user['verified']) && $user['verified']): ?>
                                    <span class="verified-badge">
                                        <i class="fas fa-check-circle"></i> Проверен
                                    </span>
                                <?php endif; ?>
                        </div>
                    </div>

                    <div class="balance-info">
                        <div class="balance-item">
                            <span class="balance-label">Основной баланс</span>
                            <span class="balance-amount primary"><?= number_format($user['balance'], 2) ?> ₽</span>
                        </div>
                        <?php if ($user['bonus_balance'] > 0): ?>
                            <div class="balance-item">
                                <span class="balance-label">Бонусный баланс</span>
                                <span class="balance-amount bonus"><?= number_format($user['bonus_balance'], 2) ?> ₽</span>
                            </div>
                        <?php endif; ?>
                        <div class="balance-actions">
                            <a href="/templates/billing.php?action=deposit" class="btn-deposit">
                                <i class="fas fa-plus-circle"></i> Пополнить
                            </a>
                            <a href="/templates/billing.php" class="btn-billing">
                                <i class="fas fa-history"></i> История
                            </a>
                        </div>
                    </div>

                    <div class="menu-divider"></div>

                    <div class="profile-links">
                        <a href="/templates/settings.php" class="menu-link">
                            <i class="fas fa-user-cog"></i> Настройки профиля
                        </a>
                        <a href="/templates/security.php" class="menu-link">
                            <i class="fas fa-shield-alt"></i> Безопасность
                        </a>
                        <a href="/templates/support.php" class="menu-link">
                            <i class="fas fa-headset"></i> Поддержка
                        </a>
                        <a href="/templates/notifications.php" class="menu-link">
                            <i class="fas fa-bell"></i> Уведомления
                            <?php if (isset($user['unread_notifications']) && $user['unread_notifications'] > 0): ?>
                                <span class="menu-badge"><?= $user['unread_notifications'] ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <div class="menu-divider"></div>

                    <div class="profile-footer">
                        <a href="/login/logout.php" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Выйти из системы
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
/* ========== СБРОС СТИЛЕЙ ДЛЯ КНОПОК В ЯНДЕКС БРАУЗЕРЕ ========== */
button,
input[type="button"],
input[type="submit"],
input[type="reset"] {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    border: none;
    background: none;
    padding: 0;
    margin: 0;
    font-family: inherit;
    font-size: inherit;
    color: inherit;
    cursor: pointer;
    outline: none;
}

/* Убираем стандартные стили Яндекс.Браузера */
::-webkit-search-decoration,
::-webkit-search-cancel-button,
::-webkit-search-results-button,
::-webkit-search-results-decoration {
    display: none;
}

/* ========== ОБЩИЕ ИСПРАВЛЕНИЯ ДЛЯ ВЫРАВНИВАНИЯ ========== */
* {
    box-sizing: border-box;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

html {
    text-size-adjust: 100%;
}

/* ========== СТИЛЬ ШАПКИ С ЦВЕТОВОЙ СХЕМОЙ КАК У ФУТЕРА ========== */
.admin-header.centered-header.modern-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    position: sticky;
    top: 0;
    z-index: 1000;
    height: 70px;
    width: 100%;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.admin-header.centered-header .container {
    width: 100%;
    max-width: 100%;
    padding: 0 24px;
    height: 100%;
}

.admin-header.centered-header .header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 100%;
    width: 100%;
    position: relative;
}

/* ========== ЛОГОТИП ========== */
.admin-header.centered-header .logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    flex-shrink: 0;
    position: absolute;
    left: 24px;
    top: 50%;
    transform: translateY(-50%);
}

.admin-header.centered-header .logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}

.admin-header.centered-header .logo-text {
    display: flex;
    flex-direction: column;
}

.admin-header.centered-header .logo-title {
    font-size: 20px;
    font-weight: 700;
    color: white;
    line-height: 1.2;
}

.admin-header.centered-header .logo-subtitle {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 400;
}

/* ========== ЦЕНТРАЛЬНАЯ ЧАСТЬ ========== */
.admin-header.centered-header .header-center {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-radius: 12px;
    padding: 8px 20px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

/* ========== БЫСТРЫЕ ДЕЙСТВИЯ ========== */
.admin-header.centered-header .quick-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.admin-header.centered-header .quick-action {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    position: relative;
    line-height: 1;
    vertical-align: middle;
}

.admin-header.centered-header .quick-action i {
    display: inline-block;
    vertical-align: middle;
    line-height: 1;
}

.admin-header.centered-header .quick-action:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    border-color: rgba(255, 255, 255, 0.25);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.admin-header.centered-header .notifications-dropdown {
    position: relative;
}

.admin-header.centered-header .notification-btn {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: white;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    vertical-align: middle;
}

.admin-header.centered-header .notification-btn i {
    display: inline-block;
    vertical-align: middle;
    line-height: 1;
}

.admin-header.centered-header .notification-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    border-color: rgba(255, 255, 255, 0.25);
}

.admin-header.centered-header .notification-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: linear-gradient(135deg, #ff4757, #ff3838);
    color: white;
    font-size: 11px;
    font-weight: 700;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #0f172a;
    box-shadow: 0 2px 8px rgba(255, 71, 87, 0.4);
    line-height: 1;
}

/* ========== ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ ========== */
.admin-header.centered-header .theme-switcher {
    display: flex;
    align-items: center;
}

.admin-header.centered-header .theme-label {
    width: 60px;
    height: 32px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 20px;
    position: relative;
    cursor: pointer;
    display: flex;
    align-items: center;
    padding: 0 6px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    overflow: hidden;
}

.admin-header.centered-header .theme-label:hover {
    background: rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.admin-header.centered-header .theme-label i {
    position: absolute;
    font-size: 14px;
    color: white;
    z-index: 1;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-header.centered-header .theme-label .fa-sun {
    left: 10px;
}

.admin-header.centered-header .theme-label .fa-moon {
    right: 10px;
}

.admin-header.centered-header .theme-ball {
    width: 24px;
    height: 24px;
    background: white;
    border-radius: 50%;
    position: absolute;
    left: 4px;
    transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    z-index: 2;
}

.admin-header.centered-header .theme-checkbox:checked + .theme-label .theme-ball {
    transform: translateX(28px);
}

/* ========== ПРОФИЛЬ ПОЛЬЗОВАТЕЛЯ ========== */
.admin-header.centered-header .user-profile-dropdown {
    position: absolute;
    right: 24px;
    top: 50%;
    transform: translateY(-50%);
}

.admin-header.centered-header .profile-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    padding: 8px 16px 8px 12px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    height: 44px;
    min-width: 180px;
    line-height: 1;
}

.admin-header.centered-header .profile-btn > * {
    vertical-align: middle;
    line-height: 1;
}

.admin-header.centered-header .profile-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    border-color: rgba(255, 255, 255, 0.25);
}

.admin-header.centered-header .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    font-size: 16px;
    flex-shrink: 0;
    line-height: 1;
}

.admin-header.centered-header .user-avatar i {
    display: inline-block;
    vertical-align: middle;
    line-height: 1;
}

.admin-header.centered-header .user-avatar.default-avatar {
    background: linear-gradient(135deg, #8a2be2, #4b0082);
}

.admin-header.centered-header .user-avatar .avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.admin-header.centered-header .user-info-short {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    flex: 1;
    min-width: 0;
    line-height: 1.2;
}

.admin-header.centered-header .user-name {
    color: white;
    font-weight: 600;
    font-size: 14px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100px;
}

.admin-header.centered-header .user-balance {
    color: rgba(255, 255, 255, 0.9);
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    line-height: 1.2;
}

.admin-header.centered-header .dropdown-arrow {
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    flex-shrink: 0;
    line-height: 1;
}

.admin-header.centered-header .user-profile-dropdown:hover .dropdown-arrow {
    transform: rotate(180deg);
}

/* ========== ВЫПАДАЮЩИЕ МЕНЮ ========== */
.admin-header.centered-header .notifications-menu,
.admin-header.centered-header .profile-menu {
    position: absolute;
    top: 100%;
    right: 0;
    width: 320px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    border: 1px solid #e5e7eb;
    margin-top: 15px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1001;
    overflow: hidden;
}

/* Стили для меню уведомлений */
.admin-header.centered-header .notifications-menu {
    width: 280px;
}

.admin-header.centered-header .notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
}

.admin-header.centered-header .notifications-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.admin-header.centered-header .view-all {
    font-size: 12px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}

.admin-header.centered-header .notifications-list {
    padding: 16px;
}

.admin-header.centered-header .notification-empty {
    text-align: center;
    padding: 20px 0;
    color: #9ca3af;
}

.admin-header.centered-header .notification-empty i {
    font-size: 32px;
    margin-bottom: 10px;
    color: #d1d5db;
}

.admin-header.centered-header .notification-empty p {
    margin: 0;
    font-size: 14px;
}

/* Стили для меню профиля */
.admin-header.centered-header .profile-menu {
    width: 320px;
}

.admin-header.centered-header .profile-header {
    padding: 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e5e7eb;
}

.admin-header.centered-header .profile-avatar-large {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    margin-bottom: 12px;
    overflow: hidden;
}

.admin-header.centered-header .profile-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.admin-header.centered-header .profile-info h4 {
    margin: 0 0 4px 0;
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
}

.admin-header.centered-header .user-email {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 10px;
    display: block;
}

.admin-header.centered-header .account-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.admin-header.centered-header .account-type-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    text-transform: uppercase;
}

.admin-header.centered-header .individual-badge {
    background: #dbeafe;
    color: #1d4ed8;
}

.admin-header.centered-header .entrepreneur-badge {
    background: #f0f9ff;
    color: #0369a1;
}

.admin-header.centered-header .legal-badge {
    background: #fef3c7;
    color: #d97706;
}

.admin-header.centered-header .verified-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #dcfce7;
    color: #15803d;
    display: flex;
    align-items: center;
    gap: 4px;
}

.admin-header.centered-header .verified-badge i {
    font-size: 10px;
}

.admin-header.centered-header .balance-info {
    padding: 20px;
    background: white;
}

.admin-header.centered-header .balance-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.admin-header.centered-header .balance-item:last-child {
    margin-bottom: 16px;
}

.admin-header.centered-header .balance-label {
    font-size: 13px;
    color: #6b7280;
}

.admin-header.centered-header .balance-amount {
    font-weight: 700;
    font-size: 16px;
}

.admin-header.centered-header .balance-amount.primary {
    color: #10b981;
}

.admin-header.centered-header .balance-amount.bonus {
    color: #8b5cf6;
}

.admin-header.centered-header .balance-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.admin-header.centered-header .btn-deposit,
.admin-header.centered-header .btn-billing {
    flex: 1;
    padding: 10px 0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: all 0.2s ease;
}

.admin-header.centered-header .btn-deposit {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
}

.admin-header.centered-header .btn-billing {
    background: #f3f4f6;
    color: #4b5563;
    border: 1px solid #e5e7eb;
}

.admin-header.centered-header .btn-deposit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35);
}

.admin-header.centered-header .btn-billing:hover {
    background: #e5e7eb;
}

.admin-header.centered-header .menu-divider {
    height: 1px;
    background: #e5e7eb;
    margin: 0;
}

.admin-header.centered-header .profile-links {
    padding: 16px 20px;
}

.admin-header.centered-header .menu-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    text-decoration: none;
    color: #4b5563;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s ease;
    border-bottom: 1px solid #f9fafb;
}

.admin-header.centered-header .menu-link:last-child {
    border-bottom: none;
}

.admin-header.centered-header .menu-link:hover {
    color: #3b82f6;
}

.admin-header.centered-header .menu-link i {
    width: 20px;
    text-align: center;
    font-size: 16px;
    color: #9ca3af;
}

.admin-header.centered-header .menu-badge {
    margin-left: auto;
    background: #ef4444;
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}

.admin-header.centered-header .profile-footer {
    padding: 20px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
}

.admin-header.centered-header .logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    color: #ef4444;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.admin-header.centered-header .logout-btn:hover {
    background: #fef2f2;
    border-color: #fecaca;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1);
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .admin-header.centered-header .header-center {
        gap: 16px;
        padding: 8px 16px;
    }

    .admin-header.centered-header .quick-actions {
        gap: 10px;
    }

    .admin-header.centered-header .quick-action,
    .admin-header.centered-header .notification-btn {
        width: 38px;
        height: 38px;
    }

    .admin-header.centered-header .profile-btn {
        min-width: 160px;
        padding: 7px 14px 7px 10px;
    }

    .admin-header.centered-header .user-name {
        max-width: 90px;
    }
}

@media (max-width: 992px) {
    .admin-header.centered-header .header-center {
        gap: 14px;
        padding: 6px 14px;
    }

    .admin-header.centered-header .quick-action,
    .admin-header.centered-header .notification-btn {
        width: 36px;
        height: 36px;
        font-size: 15px;
    }

    .admin-header.centered-header .theme-label {
        width: 56px;
        height: 30px;
    }

    .admin-header.centered-header .theme-ball {
        width: 22px;
        height: 22px;
    }

    .admin-header.centered-header .theme-checkbox:checked + .theme-label .theme-ball {
        transform: translateX(26px);
    }
}

@media (max-width: 768px) {
    .admin-header.centered-header.modern-header {
        height: 65px;
    }

    .admin-header.centered-header .container {
        padding: 0 20px;
    }

    .admin-header.centered-header .logo {
        left: 20px;
    }

    .admin-header.centered-header .logo-icon {
        width: 38px;
        height: 38px;
        font-size: 19px;
    }

    .admin-header.centered-header .logo-title {
        font-size: 19px;
    }

    .admin-header.centered-header .logo-subtitle {
        font-size: 11px;
    }

    .admin-header.centered-header .header-center {
        position: static;
        transform: none;
        background: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        border: none;
        box-shadow: none;
        padding: 0;
        margin-left: auto;
        margin-right: auto;
        order: 2;
        gap: 12px;
    }

    .admin-header.centered-header .header-content {
        justify-content: center;
        gap: 20px;
    }

    .admin-header.centered-header .logo {
        position: static;
        transform: none;
        order: 1;
    }

    .admin-header.centered-header .user-profile-dropdown {
        position: static;
        transform: none;
        order: 3;
    }

    .admin-header.centered-header .quick-actions {
        display: none;
    }

    .admin-header.centered-header .profile-btn {
        min-width: auto;
        padding: 6px 12px;
        gap: 10px;
        height: 40px;
    }

    .admin-header.centered-header .user-info-short {
        display: none;
    }

    .admin-header.centered-header .user-avatar {
        width: 34px;
        height: 34px;
        font-size: 17px;
    }

    .admin-header.centered-header .dropdown-arrow {
        font-size: 11px;
    }
}

@media (max-width: 576px) {
    .admin-header.centered-header .container {
        padding: 0 16px;
    }

    .admin-header.centered-header .logo-text {
        display: none;
    }

    .admin-header.centered-header .theme-switcher {
        display: none;
    }

    .admin-header.centered-header .header-content {
        gap: 15px;
    }

    .admin-header.centered-header .profile-btn {
        height: 38px;
        padding: 5px 10px;
        gap: 8px;
    }

    .admin-header.centered-header .user-avatar {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }

    .admin-header.centered-header .notification-btn {
        width: 34px;
        height: 34px;
        font-size: 14px;
    }
}

/* ========== ДОПОЛНИТЕЛЬНЫЕ ФИКСЫ ========== */
.admin-header.centered-header .quick-action:active,
.admin-header.centered-header .notification-btn:active,
.admin-header.centered-header .profile-btn:active,
.admin-header.centered-header .theme-label:active {
    -webkit-tap-highlight-color: transparent;
}

.admin-header.centered-header .quick-action:focus,
.admin-header.centered-header .notification-btn:focus,
.admin-header.centered-header .profile-btn:focus,
.admin-header.centered-header .theme-label:focus {
    outline: none;
}

/* ========== ЭФФЕКТ ПРИ СКРОЛЛЕ ========== */
.admin-header.centered-header.modern-header.scrolled {
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Элементы управления
    const themeToggle = document.getElementById('themeToggle');
    const header = document.querySelector('.admin-header.centered-header.modern-header');

    // Время для автоматического переключения
    const DARK_START_HOUR = 21; // 21:00
    const DARK_END_HOUR = 8;   // 8:00

    // Ключи для localStorage
    const THEME_KEY = 'theme';
    const MANUAL_THEME_KEY = 'manual_theme';
    const LAST_AUTO_CHANGE_KEY = 'last_auto_theme_date';
    const MANUAL_OVERRIDE_KEY = 'manual_override_active';
    const OVERRIDE_EXPIRES_KEY = 'override_expires_at';

    // Функция для получения текущего часа
    function getCurrentHour() {
        return new Date().getHours();
    }

    // Функция для проверки, нужно ли включать темную тему по времени
    function shouldUseDarkThemeByTime() {
        const currentHour = getCurrentHour();
        return currentHour >= DARK_START_HOUR || currentHour < DARK_END_HOUR;
    }

    // Функция для получения текущей даты в формате YYYY-MM-DD
    function getTodayDate() {
        const now = new Date();
        return now.toISOString().split('T')[0];
    }

    // Функция для установки темы
    function setTheme(isDark) {
        if (isDark) {
            document.body.classList.add('dark-theme');
            if (themeToggle) themeToggle.checked = true;
            localStorage.setItem(THEME_KEY, 'dark');
        } else {
            document.body.classList.remove('dark-theme');
            if (themeToggle) themeToggle.checked = false;
            localStorage.setItem(THEME_KEY, 'light');
        }
    }

    // Функция для установки ручной темы
    function setManualTheme(isDark) {
        setTheme(isDark);
        localStorage.setItem(MANUAL_THEME_KEY, isDark ? 'dark' : 'light');
        localStorage.setItem(LAST_AUTO_CHANGE_KEY, getTodayDate());

        // Если пользователь вручную переключает тему в период 21:00-8:00,
        // устанавливаем флаг, что ручное переопределение активно до следующего дня 21:00
        if (shouldUseDarkThemeByTime() && !isDark) {
            // Пользователь включил светлую тему в темное время
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(DARK_START_HOUR, 0, 0, 0);

            localStorage.setItem(MANUAL_OVERRIDE_KEY, 'true');
            localStorage.setItem(OVERRIDE_EXPIRES_KEY, tomorrow.getTime());
        } else if (!shouldUseDarkThemeByTime() && isDark) {
            // Пользователь включил темную тему в светлое время
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(0, 0, 0, 0);

            localStorage.setItem(MANUAL_OVERRIDE_KEY, 'true');
            localStorage.setItem(OVERRIDE_EXPIRES_KEY, tomorrow.getTime());
        } else {
            // Сбрасываем переопределение, если пользователь выбрал тему по умолчанию для времени суток
            localStorage.removeItem(MANUAL_OVERRIDE_KEY);
            localStorage.removeItem(OVERRIDE_EXPIRES_KEY);
        }
    }

    // Функция для проверки, активно ли ручное переопределение
    function isManualOverrideActive() {
        const overrideActive = localStorage.getItem(MANUAL_OVERRIDE_KEY) === 'true';
        const expiresAt = localStorage.getItem(OVERRIDE_EXPIRES_KEY);

        if (!overrideActive || !expiresAt) {
            return false;
        }

        const now = new Date().getTime();
        const expiresTime = parseInt(expiresAt);

        // Если время истекло, сбрасываем переопределение
        if (now >= expiresTime) {
            localStorage.removeItem(MANUAL_OVERRIDE_KEY);
            localStorage.removeItem(OVERRIDE_EXPIRES_KEY);
            return false;
        }

        return true;
    }

    // Функция для применения автоматической темы по времени
    function applyAutoTheme() {
        const shouldBeDark = shouldUseDarkThemeByTime();
        setTheme(shouldBeDark);
        localStorage.setItem(LAST_AUTO_CHANGE_KEY, getTodayDate());
    }

    // Инициализация темы при загрузке страницы
    function initTheme() {
        const savedTheme = localStorage.getItem(THEME_KEY);
        const manualTheme = localStorage.getItem(MANUAL_THEME_KEY);
        const lastAutoChangeDate = localStorage.getItem(LAST_AUTO_CHANGE_KEY);
        const todayDate = getTodayDate();

        // Проверяем, активно ли ручное переопределение
        const overrideActive = isManualOverrideActive();

        if (overrideActive && manualTheme) {
            // Если активно ручное переопределение, используем сохраненную ручную тему
            setTheme(manualTheme === 'dark');
        } else {
            // Проверяем, меняли ли тему сегодня автоматически
            if (lastAutoChangeDate === todayDate) {
                // Если уже меняли сегодня, используем сохраненную тему
                if (savedTheme) {
                    setTheme(savedTheme === 'dark');
                } else {
                    applyAutoTheme();
                }
            } else {
                // Если не меняли сегодня или нет сохраненной темы, применяем автоматическую
                applyAutoTheme();
            }
        }
    }

    // Инициализируем тему
    initTheme();

    // Обработчик переключения темы
    if (themeToggle) {
        themeToggle.addEventListener('change', function() {
            const isDark = this.checked;
            setManualTheme(isDark);
        });
    }

    // Эффект при скролле
    if (header) {
        if (window.scrollY > 20) {
            header.classList.add('scrolled');
        }

        window.addEventListener('scroll', function() {
            if (window.scrollY > 20) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    }

    // Управление выпадающими меню
    const profileBtn = document.getElementById('profileBtn');
    const profileMenu = document.getElementById('profileMenu');
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationsMenu = document.getElementById('notificationsMenu');

    function closeAllMenus() {
        if (profileMenu) {
            profileMenu.style.opacity = '0';
            profileMenu.style.visibility = 'hidden';
            profileMenu.style.transform = 'translateY(-10px)';
        }

        if (notificationsMenu) {
            notificationsMenu.style.opacity = '0';
            notificationsMenu.style.visibility = 'hidden';
            notificationsMenu.style.transform = 'translateY(-10px)';
        }
    }

    // Меню профиля
    if (profileBtn && profileMenu) {
        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const isVisible = profileMenu.style.opacity === '1';

            if (notificationsMenu && notificationsMenu.style.opacity === '1') {
                notificationsMenu.style.opacity = '0';
                notificationsMenu.style.visibility = 'hidden';
                notificationsMenu.style.transform = 'translateY(-10px)';
            }

            if (!isVisible) {
                profileMenu.style.opacity = '1';
                profileMenu.style.visibility = 'visible';
                profileMenu.style.transform = 'translateY(0)';
            } else {
                profileMenu.style.opacity = '0';
                profileMenu.style.visibility = 'hidden';
                profileMenu.style.transform = 'translateY(-10px)';
            }
        });
    }

    // Меню уведомлений
    if (notificationBtn && notificationsMenu) {
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const isVisible = notificationsMenu.style.opacity === '1';

            if (profileMenu && profileMenu.style.opacity === '1') {
                profileMenu.style.opacity = '0';
                profileMenu.style.visibility = 'hidden';
                profileMenu.style.transform = 'translateY(-10px)';
            }

            if (!isVisible) {
                notificationsMenu.style.opacity = '1';
                notificationsMenu.style.visibility = 'visible';
                notificationsMenu.style.transform = 'translateY(0)';
            } else {
                notificationsMenu.style.opacity = '0';
                notificationsMenu.style.visibility = 'hidden';
                notificationsMenu.style.transform = 'translateY(-10px)';
            }
        });
    }

    // Закрытие меню при клике вне
    document.addEventListener('click', function(e) {
        if (profileMenu && profileBtn && !profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
            profileMenu.style.opacity = '0';
            profileMenu.style.visibility = 'hidden';
            profileMenu.style.transform = 'translateY(-10px)';
        }

        if (notificationsMenu && notificationBtn && !notificationsMenu.contains(e.target) && !notificationBtn.contains(e.target)) {
            notificationsMenu.style.opacity = '0';
            notificationsMenu.style.visibility = 'hidden';
            notificationsMenu.style.transform = 'translateY(-10px)';
        }
    });

    // Предотвращаем закрытие при клике внутри меню
    if (profileMenu) {
        profileMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    if (notificationsMenu) {
        notificationsMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Проверяем изменение времени каждую минуту
    setInterval(function() {
        const overrideActive = isManualOverrideActive();

        if (!overrideActive) {
            // Только если нет активного ручного переопределения
            const currentHour = getCurrentHour();
            const savedTheme = localStorage.getItem(THEME_KEY);
            const shouldBeDark = shouldUseDarkThemeByTime();
            const isCurrentlyDark = savedTheme === 'dark';

            // Если текущая тема не соответствует времени суток, меняем ее
            if (shouldBeDark !== isCurrentlyDark) {
                applyAutoTheme();
            }
        }
    }, 60000); // Проверяем каждую минуту
});
</script>
