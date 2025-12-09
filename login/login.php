<?php
session_start();
error_log("Login page accessed. Session: " . print_r($_SESSION, true));
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Генерация новой капчи при каждой загрузке страницы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $num1 = rand(1, 10);
    $num2 = rand(1, 10);
    $_SESSION['captcha'] = $num1 + $num2;
    $_SESSION['captcha_question'] = "$num1 + $num2";
}

// Обработка входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $captcha_answer = isset($_POST['captcha']) ? (int)$_POST['captcha'] : null;

    // Валидация данных
    $error = null;

    if (empty($email)) {
        $error = "Введите email";
    } elseif (empty($password)) {
        $error = "Введите пароль";
    } elseif (empty($captcha_answer)) {
        $error = "Введите ответ капчи";
    } elseif ($captcha_answer !== $_SESSION['captcha']) {
        $error = "Неправильный ответ капчи!";
    } else {
        $db = new Database();
        $stmt = $db->getConnection()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = $user;

            // Удаляем использованную капчу
            unset($_SESSION['captcha']);
            unset($_SESSION['captcha_question']);

            header('Location: /templates/dashboard.php');
            exit;
        } else {
            $error = "Неверный email или пароль!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
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

        .nav-btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: rgba(255, 255, 255, 0.9);
        }

        .nav-btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
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
            padding: 120px 20px 60px;
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
        }

        /* Карточка авторизации */
        .auth-container {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 2;
        }

        .auth-card {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(148, 163, 184, 0.1);
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeInUp 0.8s ease forwards;
        }

        .auth-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.12);
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 24px 24px 0 0;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .auth-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(0, 188, 212, 0.3);
        }

        .auth-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .auth-subtitle {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.7;
        }

        /* Форма */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label i {
            color: #00bcd4;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 16px 20px;
            background: rgba(248, 250, 252, 0.8);
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #00bcd4;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 188, 212, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-light);
        }

        /* Капча */
        .captcha-box {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .captcha-box:hover {
            border-color: #00bcd4;
            background: white;
        }

        .captcha-box .form-control {
            margin-top: 12px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
        }

        /* Кнопка входа */
        .btn-auth {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-auth:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        .btn-auth:active {
            transform: translateY(-1px);
        }

        /* Вспомогательные ссылки */
        .auth-links {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .auth-link {
            color: #00bcd4;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .auth-link:hover {
            color: #0097a7;
            transform: translateX(3px);
        }

        .auth-link i {
            font-size: 12px;
        }

        /* Разделитель */
        .auth-divider {
            display: flex;
            align-items: center;
            margin: 32px 0;
            color: var(--text-light);
            font-size: 14px;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(148, 163, 184, 0.2);
        }

        .auth-divider span {
            padding: 0 15px;
        }

        /* Кнопка регистрации */
        .btn-register {
            width: 100%;
            padding: 18px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(0, 188, 212, 0.3);
            border-radius: 12px;
            color: #00bcd4;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-register:hover {
            background: rgba(0, 188, 212, 0.1);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.2);
        }

        /* Уведомления */
        .notification {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #059669;
        }

        .notification.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #dc2626;
        }

        .notification i {
            font-size: 18px;
        }

        /* Дополнительная информация */
        .auth-info {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        .auth-info p {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        /* Футер */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 30px 0;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
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
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(0, 188, 212, 0.7);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(0, 188, 212, 0);
            }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .auth-card {
                padding: 40px 30px;
            }

            .auth-title {
                font-size: 28px;
            }

            .auth-icon {
                width: 70px;
                height: 70px;
                font-size: 30px;
            }

            .main-content {
                padding: 100px 15px 40px;
            }

            .auth-links {
                flex-direction: column;
                gap: 12px;
                align-items: center;
            }
        }

        @media (max-width: 576px) {
            .auth-card {
                padding: 32px 24px;
                border-radius: 20px;
            }

            .auth-title {
                font-size: 24px;
            }

            .auth-icon {
                width: 60px;
                height: 60px;
                font-size: 24px;
            }

            .form-control {
                padding: 14px 16px;
            }

            .btn-auth,
            .btn-register {
                padding: 16px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        /* Тёмная тема */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            }

            .auth-card {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .form-control {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
                color: #cbd5e1;
            }

            .form-control:focus {
                background: rgba(15, 23, 42, 1);
                border-color: #00bcd4;
            }

            .captcha-box {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
                color: #cbd5e1;
            }

            .auth-title {
                background: linear-gradient(135deg, #ffffff, #e2e8f0);
                -webkit-background-clip: text;
                background-clip: text;
            }

            .form-label {
                color: #cbd5e1;
            }

            .auth-subtitle {
                color: #94a3b8;
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
                    <a href="/" class="nav-btn nav-btn-secondary">
                        <i class="fas fa-home"></i> На главную
                    </a>
                    <a href="/login/register.php" class="nav-btn nav-btn-primary">
                        <i class="fas fa-user-plus"></i> Регистрация
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <div class="auth-container">
            <div class="auth-card">
                <!-- Заголовок -->
                <div class="auth-header">
                    <div class="auth-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <h1 class="auth-title">Вход в систему</h1>
                    <p class="auth-subtitle">
                        Войдите в свой аккаунт HomeVlad Cloud<br>
                        для управления виртуальными серверами
                    </p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="notification error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['message'])): ?>
                    <div class="notification success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($_SESSION['message']) ?>
                        <?php unset($_SESSION['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <!-- Email -->
                    <div class="form-group">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope"></i> Email
                        </label>
                        <input type="email" id="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               placeholder="your@email.com"
                               autocomplete="email">
                    </div>

                    <!-- Пароль -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock"></i> Пароль
                        </label>
                        <input type="password" id="password" name="password" class="form-control" required
                               placeholder="Введите ваш пароль"
                               autocomplete="current-password">
                    </div>

                    <!-- Капча -->
                    <div class="captcha-box">
                        <div>Сколько будет?</div>
                        <div style="font-size: 24px; font-weight: 700; margin: 8px 0; color: #00bcd4;">
                            <?= $_SESSION['captcha_question'] ?> = ?
                        </div>
                        <input type="number" name="captcha" class="form-control" required
                               placeholder="Введите ответ"
                               min="1" max="20">
                    </div>

                    <!-- Вспомогательные ссылки -->
                    <div class="auth-links">
                        <a href="/login/reset-password.php" class="auth-link">
                            <i class="fas fa-key"></i> Забыли пароль?
                        </a>
                        <a href="/login/register.php" class="auth-link">
                            <i class="fas fa-user-plus"></i> Нет аккаунта?
                        </a>
                    </div>

                    <!-- Кнопка входа -->
                    <button type="submit" class="btn-auth pulse">
                        <i class="fas fa-unlock-alt"></i> Войти в систему
                    </button>
                </form>

                <!-- Разделитель -->
                <div class="auth-divider">
                    <span>или</span>
                </div>

                <!-- Регистрация -->
                <a href="/login/register.php" class="btn-register">
                    <i class="fas fa-rocket"></i> Создать новый аккаунт
                </a>

                <!-- Дополнительная информация -->
                <div class="auth-info">
                    <p><i class="fas fa-shield-alt"></i> Ваши данные защищены шифрованием</p>
                    <p><i class="fas fa-clock"></i> Поддержка 24/7</p>
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
            // Автофокус на поле email
            document.getElementById('email').focus();

            // Обработка формы
            const form = document.getElementById('loginForm');
            const submitBtn = form.querySelector('button[type="submit"]');

            form.addEventListener('submit', function(e) {
                // Можно добавить дополнительную валидацию здесь
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Вход...';
                submitBtn.disabled = true;
            });

            // Показ/скрытие пароля (можно добавить иконку глаза)
            const passwordField = document.getElementById('password');
            const addEyeIcon = document.createElement('span');
            addEyeIcon.innerHTML = '<i class="fas fa-eye" style="cursor: pointer; margin-left: 10px;"></i>';
            addEyeIcon.style.color = '#94a3b8';
            addEyeIcon.addEventListener('click', function() {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    passwordField.type = 'password';
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });

            // Вставляем иконку глаза после поля пароля
            passwordField.parentNode.appendChild(addEyeIcon);

            // Анимация при загрузке
            const authCard = document.querySelector('.auth-card');
            authCard.style.opacity = '0';
            authCard.style.transform = 'translateY(20px)';

            setTimeout(() => {
                authCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                authCard.style.opacity = '1';
                authCard.style.transform = 'translateY(0)';
            }, 100);

            // Обработка Enter для перехода между полями
            document.querySelectorAll('.form-control').forEach((input, index, inputs) => {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const nextIndex = index + 1;
                        if (nextIndex < inputs.length) {
                            inputs[nextIndex].focus();
                        } else {
                            form.submit();
                        }
                    }
                });
            });

            // Добавляем эффект при наведении на капчу
            const captchaBox = document.querySelector('.captcha-box');
            captchaBox.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });

            captchaBox.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Показ уведомления при загрузке (если есть в session)
        <?php if (isset($_SESSION['login_message'])): ?>
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.className = 'notification success';
                notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; animation: slideIn 0.3s ease;';
                notification.innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    <span><?= addslashes($_SESSION['login_message']) ?></span>
                    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                document.body.appendChild(notification);

                setTimeout(() => {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }, 5000);
            }, 500);
            <?php unset($_SESSION['login_message']); ?>
        <?php endif; ?>
    </script>
</body>
</html>
