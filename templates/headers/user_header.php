<!-- Шапка -->
<header class="admin-header">
    <div class="container">
        <a href="/" class="logo">
            <span class="logo-text">HomeVlad Cloud Panel</span>
        </a>
        <div class="admin-nav">
            <div class="user-info">
                <?php if (!empty($user['avatar'])): ?>
                    <div class="user-avatar">
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар пользователя" class="avatar-image">
                    </div>
                <?php else: ?>
                    <?php
                    // Массив иконок Font Awesome для дефолтных аватаров
                    $defaultIcons = [
                        'fa-user', 'fa-user-tie', 'fa-user-graduate', 'fa-user-astronaut',
                        'fa-user-ninja', 'fa-user-secret', 'fa-user-md', 'fa-user-edit',
                        'fa-user-cog', 'fa-user-shield', 'fa-user-check', 'fa-user-plus'
                    ];
                    $randomIcon = $defaultIcons[array_rand($defaultIcons)];
                    ?>
                    <div class="user-avatar default-avatar">
                        <i class="fas <?= $randomIcon ?>"></i>
                    </div>
                <?php endif; ?>
                <span class="user-name"><?= htmlspecialchars($user['full_name']) ?>
                    <span class="account-type-badge <?= $user['user_type'] ?>-badge">
                        <?= $user['user_type'] === 'individual' ? 'Физ. лицо' : 
                           ($user['user_type'] === 'entrepreneur' ? 'ИП' : 'Юр. лицо') ?>
                    </span>
                </span>
                <?php if ($user['balance'] > 0): ?>
                    <span class="bonus-balance">
                        <i class="fas fa-credit-card"></i> <?= number_format($user['balance'], 2) ?> ₽
                    </span>
                <?php endif; ?>
                <?php if ($user['bonus_balance'] > 0): ?>
                    <span class="bonus-badge">
                        <i class="fas fa-coins"></i> <?= number_format($user['bonus_balance'], 2) ?> ₽
                    </span>
                <?php endif; ?>
            </div>
            <div class="theme-switcher">
                <button id="themeToggle" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-moon"></i> Тёмная тема
                </button>
            </div>
            <a href="/login/logout.php" class="admin-nav-btn admin-nav-btn-danger">
                <i class="fas fa-sign-out-alt"></i> Выйти
            </a>
        </div>
    </div>
</header>

<style>
.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    position: relative;
    overflow: hidden;
    font-size: 18px;
}

.user-avatar.default-avatar {
    background-color: var(--primary-color);
    color: white;
}

.user-avatar .avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-avatar .fas {
    font-size: 1.2em;
}
</style>