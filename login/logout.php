<?php
session_start();

// Сохраняем данные пользователя для отображения
$user_name = $_SESSION['user']['username'] ?? 'Пользователь';
$logout_time = date('H:i');

// Уничтожаем сессию
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
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
            /* УБИРАЕМ overflow: hidden - это главное исправление */
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

        /* Основное содержимое */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 120px 20px 60px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
            /* УБИРАЕМ overflow: hidden */
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
        }

        /* Карточка выхода */
        .logout-container {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 2;
            text-align: center;
            margin: 40px 0; /* Добавляем отступы для скролла */
        }

        .logout-card {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 60px 40px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(148, 163, 184, 0.1);
            position: relative;
            /* УБИРАЕМ overflow: hidden */
            animation: fadeInUp 0.8s ease forwards;
        }

        .logout-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--success-gradient);
            border-radius: 24px 24px 0 0;
        }

        /* Иконка выхода */
        .logout-icon {
            width: 120px;
            height: 120px;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 48px;
            color: white;
            box-shadow: 0 15px 35px rgba(16, 185, 129, 0.3);
            position: relative;
            animation: pulse 2s infinite;
        }

        .logout-icon::after {
            content: '';
            position: absolute;
            width: 140px;
            height: 140px;
            border: 2px solid rgba(16, 185, 129, 0.3);
            border-radius: 50%;
            animation: ripple 2s infinite;
        }

        /* Текст */
        .logout-title {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .logout-text {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 30px;
            line-height: 1.7;
        }

        /* Детали выхода */
        .logout-details {
            background: rgba(248, 250, 252, 0.8);
            border-radius: 16px;
            padding: 20px;
            margin: 30px 0;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            font-size: 15px;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Кнопки */
        .logout-actions {
            display: flex;
            gap: 16px;
            margin-top: 40px;
        }

        .btn-main {
            flex: 1;
            padding: 18px 30px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-main:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        .btn-secondary {
            flex: 1;
            padding: 18px 30px;
            background: rgba(248, 250, 252, 0.8);
            border: 2px solid rgba(0, 188, 212, 0.3);
            border-radius: 12px;
            color: #00bcd4;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-secondary:hover {
            background: rgba(0, 188, 212, 0.1);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.2);
        }

        /* Футер */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 30px 0;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            /* УБИРАЕМ overflow: hidden */
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
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 15px 35px rgba(16, 185, 129, 0.3);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 20px 40px rgba(16, 185, 129, 0.4);
            }
        }

        @keyframes ripple {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(1.3);
                opacity: 0;
            }
        }

        @keyframes countdown {
            0% {
                width: 100%;
            }
            100% {
                width: 0%;
            }
        }

        /* Прогресс бар авто-редиректа */
        .progress-bar {
            height: 4px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 2px;
            margin-top: 30px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: var(--success-gradient);
            border-radius: 2px;
            animation: countdown 8s linear forwards;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .logout-card {
                padding: 50px 30px;
            }

            .logout-title {
                font-size: 30px;
            }

            .logout-icon {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }

            .logout-icon::after {
                width: 120px;
                height: 120px;
            }

            .logout-actions {
                flex-direction: column;
            }

            .main-content {
                padding: 100px 15px 40px;
            }
        }

        @media (max-width: 576px) {
            .logout-card {
                padding: 40px 24px;
                border-radius: 20px;
            }

            .logout-title {
                font-size: 26px;
            }

            .logout-text {
                font-size: 16px;
            }

            .logout-icon {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }

            .logout-icon::after {
                width: 100px;
                height: 100px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Тёмная тема */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            }

            .logout-card {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .logout-details {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .detail-label {
                color: #94a3b8;
            }

            .detail-value {
                color: #cbd5e1;
            }

            .btn-secondary {
                background: rgba(15, 23, 42, 0.8);
                color: #00bcd4;
            }

            .logout-title {
                background: linear-gradient(135deg, #ffffff, #e2e8f0);
                -webkit-background-clip: text;
                background-clip: text;
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
                    <a href="/" class="nav-btn" style="padding: 10px 20px; background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: rgba(255, 255, 255, 0.9); border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; transition: all 0.3s ease;">
                        <i class="fas fa-home"></i> На главную
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <div class="logout-container">
            <div class="logout-card">
                <!-- Иконка выхода -->
                <div class="logout-icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>

                <!-- Заголовок и текст -->
                <h1 class="logout-title">Вы успешно вышли</h1>
                <p class="logout-text">
                    Ваш сеанс работы завершён. Все данные защищены.<br>
                    Будем рады видеть вас снова!
                </p>

                <!-- Детали выхода -->
                <div class="logout-details">
                    <div class="detail-item">
                        <span class="detail-label">Пользователь:</span>
                        <span class="detail-value"><?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Время выхода:</span>
                        <span class="detail-value"><?= $logout_time ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Статус:</span>
                        <span class="detail-value" style="color: #10b981; font-weight: 700;">
                            <i class="fas fa-check-circle"></i> Сессия завершена
                        </span>
                    </div>
                </div>

                <!-- Кнопки действий -->
                <div class="logout-actions">
                    <a href="/login/login.php" class="btn-main">
                        <i class="fas fa-sign-in-alt"></i> Войти снова
                    </a>
                    <a href="/" class="btn-secondary">
                        <i class="fas fa-home"></i> На главную
                    </a>
                </div>

                <!-- Прогресс бар авто-редиректа -->
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <p style="margin-top: 10px; font-size: 14px; color: var(--text-secondary);">
                    Автоматический переход на главную через <span id="countdown">8</span> секунд
                </p>
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
            // Анимация загрузки карточки
            const logoutCard = document.querySelector('.logout-card');
            logoutCard.style.opacity = '0';
            logoutCard.style.transform = 'translateY(40px)';

            setTimeout(() => {
                logoutCard.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
                logoutCard.style.opacity = '1';
                logoutCard.style.transform = 'translateY(0)';
            }, 100);

            // Обратный отсчет для авто-редиректа
            let seconds = 8;
            const countdownElement = document.getElementById('countdown');
            const progressFill = document.querySelector('.progress-fill');

            const countdownInterval = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds;

                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = '/';
                }
            }, 1000);

            // Остановить авто-редирект при взаимодействии
            const stopRedirect = () => {
                clearInterval(countdownInterval);
                progressFill.style.animation = 'none';
                countdownElement.textContent = '0';
                document.querySelector('.progress-bar + p').innerHTML =
                    'Автопереход отменён. Нажмите на кнопку для продолжения.';
            };

            // Останавливаем авто-редирект при любом взаимодействии
            document.querySelectorAll('a, button').forEach(element => {
                element.addEventListener('click', stopRedirect);
            });

            // Эффект при наведении на карточку
            logoutCard.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });

            logoutCard.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });

            // Добавляем звуковой эффект (опционально)
            const playSound = () => {
                try {
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    const oscillator = audioContext.createOscillator();
                    const gainNode = audioContext.createGain();

                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);

                    oscillator.frequency.value = 523.25; // Нота C5
                    oscillator.type = 'sine';

                    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);

                    oscillator.start(audioContext.currentTime);
                    oscillator.stop(audioContext.currentTime + 0.5);
                } catch (e) {
                    console.log('Audio not supported');
                }
            };

            // Проигрываем звук при загрузке
            setTimeout(playSound, 500);

            // Сохраняем время выхода в localStorage для статистики
            try {
                const logoutStats = JSON.parse(localStorage.getItem('logoutStats') || '[]');
                logoutStats.push({
                    time: new Date().toISOString(),
                    user: '<?= htmlspecialchars($user_name) ?>'
                });

                // Храним только последние 10 записей
                if (logoutStats.length > 10) {
                    logoutStats.shift();
                }

                localStorage.setItem('logoutStats', JSON.stringify(logoutStats));
            } catch (e) {
                console.log('LocalStorage not available');
            }
        });
    </script>
</body>
</html>
