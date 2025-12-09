<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['registration_success'])) {
    header('Location: register.php');
    exit;
}

// Получаем данные из сессии
$welcome_bonus = $_SESSION['welcome_bonus'] ?? 3000;
$user_id = $_SESSION['user_id'] ?? null;
$telegram_registration = $_SESSION['telegram_registration'] ?? false;

// Очищаем сессию после использования
unset($_SESSION['registration_success']);
unset($_SESSION['welcome_bonus']);
unset($_SESSION['user_id']);
unset($_SESSION['telegram_registration']);

$title = "Регистрация завершена! | HomeVlad Cloud";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
            --gold-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-light: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            animation: fadeIn 0.8s ease;
        }

        /* Хедер */
        .modern-header {
            background: var(--primary-gradient);
            padding: 18px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .logo-image {
            height: 45px;
            width: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 188, 212, 0.3));
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.5px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-btn {
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-btn-primary {
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: 1px solid rgba(0, 188, 212, 0.3);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .nav-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
        }

        /* Основное содержимое */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 140px 20px 60px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
            overflow: hidden;
        }

        .main-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 10% 20%, rgba(0, 188, 212, 0.1) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.05) 0%, transparent 40%);
            animation: gradientShift 15s ease infinite alternate;
        }

        /* Конфетти эффект */
        .confetti {
            position: absolute;
            width: 10px;
            height: 20px;
            background: var(--secondary-gradient);
            top: -20px;
            animation: confettiFall 3s linear forwards;
            border-radius: 2px;
        }

        .confetti:nth-child(odd) {
            background: var(--success-gradient);
        }

        .confetti:nth-child(even) {
            background: var(--gold-gradient);
        }

        /* Карточка успеха */
        .success-container {
            width: 100%;
            max-width: 520px;
            position: relative;
            z-index: 2;
            animation: slideUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .success-card {
            background: var(--card-bg);
            border-radius: 28px;
            padding: 56px 48px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(148, 163, 184, 0.1);
            position: relative;
            overflow: hidden;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .success-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--success-gradient);
            border-radius: 28px 28px 0 0;
        }

        .success-card::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        /* Иконка успеха */
        .success-icon-container {
            width: 120px;
            height: 120px;
            margin: 0 auto 32px;
            position: relative;
        }

        .success-icon-background {
            position: absolute;
            width: 100%;
            height: 100%;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 40px rgba(16, 185, 129, 0.3);
            animation: iconPulse 2s ease infinite;
        }

        .success-icon {
            font-size: 48px;
            color: white;
            position: relative;
            z-index: 2;
            animation: iconFloat 3s ease-in-out infinite;
        }

        .success-icon-container::before {
            content: '';
            position: absolute;
            width: 140%;
            height: 140%;
            top: -20%;
            left: -20%;
            background: var(--success-gradient);
            border-radius: 50%;
            opacity: 0.2;
            filter: blur(20px);
            animation: glow 3s ease-in-out infinite alternate;
        }

        /* Заголовок */
        .success-title {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #10b981, #059669);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            position: relative;
            display: inline-block;
        }

        .success-title::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: var(--success-gradient);
            border-radius: 2px;
        }

        .success-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 40px;
            line-height: 1.7;
        }

        <?php if ($telegram_registration): ?>
        .telegram-success {
            color: #8b5cf6;
            font-weight: 600;
        }
        <?php endif; ?>

        /* Бонусная карточка */
        .bonus-card {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            border: 2px solid rgba(245, 158, 11, 0.2);
            border-radius: 20px;
            padding: 32px;
            margin: 32px 0;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            transform-style: preserve-3d;
            perspective: 1000px;
        }

        .bonus-card::before {
            content: '🎁';
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 40px;
            opacity: 0.3;
            transform: rotate(15deg);
        }

        .bonus-amount {
            font-size: 64px;
            font-weight: 800;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin: 16px 0;
            text-shadow: 0 4px 20px rgba(245, 158, 11, 0.3);
            animation: amountFloat 3s ease-in-out infinite;
        }

        .bonus-text {
            font-size: 16px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .bonus-note {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 12px;
            font-style: italic;
        }

        /* Функции аккаунта */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 40px 0;
        }

        .feature-card {
            background: rgba(248, 250, 252, 0.8);
            border: 2px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            border-color: rgba(0, 188, 212, 0.3);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
        }

        .feature-icon {
            width: 56px;
            height: 56px;
            background: var(--secondary-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
            color: white;
            box-shadow: 0 8px 20px rgba(0, 188, 212, 0.2);
        }

        .feature-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .feature-desc {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* Кнопки действий */
        .action-buttons {
            display: flex;
            gap: 16px;
            margin-top: 40px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-action {
            padding: 18px 32px;
            border-radius: 14px;
            text-decoration: none;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-width: 200px;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
            z-index: -1;
        }

        .btn-action:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--secondary-gradient);
            color: white;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 15px 35px rgba(0, 188, 212, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(148, 163, 184, 0.3);
            color: var(--text-primary);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        .btn-secondary:hover {
            background: white;
            border-color: #00bcd4;
            transform: translateY(-4px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }

        .btn-telegram {
            background: var(--purple-gradient);
            color: white;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }

        .btn-telegram:hover {
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 15px 35px rgba(139, 92, 246, 0.4);
        }

        /* Ссылка на инструкцию */
        .tutorial-link {
            margin-top: 32px;
            text-align: center;
        }

        .tutorial-link a {
            color: #00bcd4;
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .tutorial-link a:hover {
            color: #0097a7;
            gap: 12px;
        }

        .tutorial-link i {
            font-size: 12px;
        }

        /* Футер */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 30px 0;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            margin-top: auto;
        }

        .modern-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 188, 212, 0.5), transparent);
        }

        .footer-bottom {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
        }

        .copyright {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        /* Анимации */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes iconFloat {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes amountFloat {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes glow {
            from {
                opacity: 0.2;
                transform: scale(1);
            }
            to {
                opacity: 0.4;
                transform: scale(1.1);
            }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 0.5;
            }
            50% {
                opacity: 1;
            }
        }

        @keyframes gradientShift {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(180deg);
            }
        }

        @keyframes confettiFall {
            0% {
                transform: translateY(-100px) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            100% {
                transform: translateY(100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .success-card {
                padding: 48px 32px;
                border-radius: 24px;
            }

            .success-icon-container {
                width: 100px;
                height: 100px;
            }

            .success-icon {
                font-size: 40px;
            }

            .success-title {
                font-size: 30px;
            }

            .success-subtitle {
                font-size: 16px;
            }

            .bonus-amount {
                font-size: 48px;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn-action {
                min-width: 100%;
            }

            .main-content {
                padding: 120px 15px 40px;
            }
        }

        @media (max-width: 576px) {
            .success-card {
                padding: 40px 24px;
                border-radius: 20px;
            }

            .success-icon-container {
                width: 80px;
                height: 80px;
            }

            .success-icon {
                font-size: 32px;
            }

            .success-title {
                font-size: 26px;
            }

            .bonus-amount {
                font-size: 36px;
            }

            .bonus-card {
                padding: 24px;
            }

            .nav-links {
                flex-direction: column;
                gap: 10px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
            }

            .logo-text {
                font-size: 20px;
            }
        }

        /* Тёмная тема */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            }

            .success-card {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .success-subtitle {
                color: #94a3b8;
            }

            .bonus-card {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.15));
                border-color: rgba(245, 158, 11, 0.3);
            }

            .feature-card {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .feature-title {
                color: #cbd5e1;
            }

            .feature-desc {
                color: #94a3b8;
            }

            .btn-secondary {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.2);
                color: #cbd5e1;
            }

            .btn-secondary:hover {
                background: rgba(30, 41, 59, 1);
                color: white;
            }

            .bonus-text, .bonus-note {
                color: #cbd5e1;
            }
        }
    </style>
</head>
<body>
    <!-- Модернизированный хедер -->
    <header class="modern-header">
        <div class="container">
            <div class="header-content">
                <a href="/" class="logo">
                    <img src="../img/logo.png" alt="HomeVlad" class="logo-image">
                    <span class="logo-text">HomeVlad Cloud</span>
                </a>

                <div class="nav-links">
                    <a href="/" class="nav-btn nav-btn-primary">
                        <i class="fas fa-rocket"></i> Начать использовать
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <!-- Конфетти -->
        <div class="confetti" style="left: 10%; animation-delay: 0s;"></div>
        <div class="confetti" style="left: 20%; animation-delay: 0.5s;"></div>
        <div class="confetti" style="left: 30%; animation-delay: 1s;"></div>
        <div class="confetti" style="left: 40%; animation-delay: 1.5s;"></div>
        <div class="confetti" style="left: 50%; animation-delay: 0.2s;"></div>
        <div class="confetti" style="left: 60%; animation-delay: 0.7s;"></div>
        <div class="confetti" style="left: 70%; animation-delay: 1.2s;"></div>
        <div class="confetti" style="left: 80%; animation-delay: 0.3s;"></div>
        <div class="confetti" style="left: 90%; animation-delay: 0.8s;"></div>
        <div class="confetti" style="left: 95%; animation-delay: 1.3s;"></div>

        <div class="success-container">
            <div class="success-card">
                <!-- Иконка успеха -->
                <div class="success-icon-container">
                    <div class="success-icon-background">
                        <i class="fas fa-check success-icon"></i>
                    </div>
                </div>

                <!-- Заголовок -->
                <h1 class="success-title">Регистрация завершена!</h1>
                <p class="success-subtitle">
                    Добро пожаловать в HomeVlad Cloud!<br>
                    Ваш аккаунт успешно создан и активирован.
                    <?php if ($telegram_registration): ?>
                        <span class="telegram-success"><br>Данные из Telegram сохранены!</span>
                    <?php endif; ?>
                </p>

                <!-- Бонусная карточка -->
                <div class="bonus-card">
                    <div class="bonus-text">Вам начислен приветственный бонус!</div>
                    <div class="bonus-amount">+<?= number_format($welcome_bonus, 0, '', ' ') ?> ₽</div>
                    <div class="bonus-text">Эти средства уже доступны в вашем личном кабинете</div>
                    <div class="bonus-note">*Бонус можно использовать для оплаты любых услуг</div>
                </div>

                <!-- Возможности аккаунта -->
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="feature-title">3 виртуальные машины</div>
                        <div class="feature-desc">Бесплатно на старте</div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="feature-title">Мощные ресурсы</div>
                        <div class="feature-desc">10 ядер, 10 ГБ RAM</div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-hdd"></i>
                        </div>
                        <div class="feature-title">200 ГБ дискового пространства</div>
                        <div class="feature-desc">Быстрые SSD накопители</div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div class="feature-title">Поддержка 24/7</div>
                        <div class="feature-desc">Наши эксперты всегда на связи</div>
                    </div>
                </div>

                <!-- Кнопки действий -->
                <div class="action-buttons">
                    <a href="/login/login.php" class="btn-action btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Войти в аккаунт
                    </a>

                    <a href="/dashboard/dashboard.php" class="btn-action btn-secondary">
                        <i class="fas fa-tachometer-alt"></i> Перейти в панель
                    </a>

                    <?php if ($telegram_registration): ?>
                    <a href="https://t.me/HomeVladCloud_Bot" target="_blank" class="btn-action btn-telegram" style="margin-top: 16px;">
                        <i class="fab fa-telegram"></i> Наш Telegram бот
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Ссылка на инструкцию -->
                <div class="tutorial-link">
                    <a href="/docs/getting-started">
                        <i class="fas fa-book"></i> Как начать работу? Руководство для начинающих
                    </a>
                </div>
            </div>
        </div>
    </main>

    <!-- Футер -->
    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>
    <!--<footer class="modern-footer">
        <div class="container">
            <div class="footer-bottom">
                <div class="copyright">
                    © 2024 HomeVlad Cloud. Все права защищены.
                </div>
                <div class="copyright">
                    Разработано с <i class="fas fa-heart" style="color: #ef4444;"></i> для сообщества
                </div>
            </div>
        </div>
    </footer>-->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Анимация появления карточки
            const successCard = document.querySelector('.success-card');
            successCard.style.opacity = '0';
            successCard.style.transform = 'translateY(30px) scale(0.95)';

            setTimeout(() => {
                successCard.style.transition = 'all 0.8s cubic-bezier(0.34, 1.56, 0.64, 1)';
                successCard.style.opacity = '1';
                successCard.style.transform = 'translateY(0) scale(1)';
            }, 300);

            // Генерация дополнительного конфетти
            function createConfetti() {
                const container = document.querySelector('.main-content');
                for (let i = 0; i < 15; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.width = Math.random() * 10 + 5 + 'px';
                    confetti.style.height = Math.random() * 20 + 10 + 'px';
                    confetti.style.animationDelay = Math.random() * 2 + 's';
                    confetti.style.animationDuration = Math.random() * 2 + 3 + 's';
                    container.appendChild(confetti);

                    // Удаляем конфетти после анимации
                    setTimeout(() => {
                        confetti.remove();
                    }, 5000);
                }
            }

            // Запускаем конфетти несколько раз
            setTimeout(createConfetti, 500);
            setTimeout(createConfetti, 2000);
            setTimeout(createConfetti, 3500);

            // Анимация счетчика бонуса (опционально)
            const bonusAmount = document.querySelector('.bonus-amount');
            if (bonusAmount) {
                const originalText = bonusAmount.textContent;
                const amount = parseInt(originalText.replace(/[^0-9]/g, ''));

                // Эффект счетчика
                let current = 0;
                const increment = amount / 50;
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= amount) {
                        current = amount;
                        clearInterval(timer);
                    }
                    bonusAmount.textContent = '+' + Math.floor(current).toLocaleString('ru-RU') + ' ₽';
                }, 30);
            }

            // Эффект при наведении на кнопки
            const buttons = document.querySelectorAll('.btn-action');
            buttons.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px) scale(1.05)';
                });

                btn.addEventListener('mouseleave', function() {
                    if (!this.classList.contains('btn-primary') && !this.classList.contains('btn-telegram')) {
                        this.style.transform = 'translateY(0) scale(1)';
                    }
                });
            });

            // Плавный скролл к началу
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    </script>
</body>
</html>
