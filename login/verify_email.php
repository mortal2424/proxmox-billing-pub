<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = new Database();
$pdo = $db->getConnection();

$errors = [];
$success = false;
$email = $_GET['email'] ?? '';

// Если email передан в GET параметре
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Проверяем, существует ли пользователь с таким email
    $stmt = $pdo->prepare("SELECT id, email_verified, verification_code FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $errors[] = "Пользователь с таким email не найден";
    } elseif ($user['email_verified'] == 1) {
        $errors[] = "Email уже подтвержден";
    } elseif (empty($user['verification_code'])) {
        $errors[] = "Код подтверждения не был сгенерирован";
    }
}

// Обработка формы подтверждения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    
    // Валидация
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Некорректный email адрес";
    }
    
    if (empty($code) || strlen($code) != 6 || !is_numeric($code)) {
        $errors[] = "Код должен состоять из 6 цифр";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id, verification_code, verification_sent_at, email_verified FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $errors[] = "Пользователь с таким email не найден";
            } elseif ($user['email_verified'] == 1) {
                $errors[] = "Email уже подтвержден";
                $success = true;
            } elseif (empty($user['verification_code'])) {
                $errors[] = "Код подтверждения не был сгенерирован";
            } elseif ($user['verification_code'] != $code) {
                $errors[] = "Неверный код подтверждения";
            } else {
                // Проверяем, не истек ли срок действия кода (1 час)
                $stmt = $pdo->prepare("SELECT verification_sent_at FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $verificationData = $stmt->fetch();
                
                $sentTime = strtotime($verificationData['verification_sent_at']);
                $currentTime = time();
                
                if (($currentTime - $sentTime) > 3600) {
                    $errors[] = "Срок действия кода истек. Запросите новый код.";
                } else {
                    // Подтверждаем email
                    $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, verification_code = NULL WHERE email = ?");
                    $stmt->execute([$email]);
                    
                    $success = true;
                    $_SESSION['verification_success'] = "Email успешно подтвержден!";
                    
                    // Перенаправляем на страницу входа через 3 секунды
                    header("Refresh: 3; URL=/login/login.php");
                }
            }
        } catch (Exception $e) {
            $errors[] = "Ошибка при подтверждении email: " . $e->getMessage();
        }
    }
}

// Отправка нового кода
if (isset($_GET['resend']) && $_GET['resend'] == '1' && !empty($email)) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Некорректный email адрес";
    } else {
        $stmt = $pdo->prepare("SELECT id, verification_sent_at FROM users WHERE email = ? AND email_verified = 0");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $errors[] = "Пользователь не найден или email уже подтвержден";
        } else {
            // Проверяем, можно ли отправлять новый код (не чаще чем раз в минуту)
            $sentTime = strtotime($user['verification_sent_at']);
            $currentTime = time();
            
            if (($currentTime - $sentTime) < 60) {
                $errors[] = "Новый код можно запросить не чаще чем раз в минуту. Попробуйте позже.";
            } else {
                // Генерируем новый код
                $new_code = generateVerificationCode();
                
                // Обновляем код в БД
                $stmt = $pdo->prepare("UPDATE users SET verification_code = ?, verification_sent_at = NOW() WHERE email = ?");
                $stmt->execute([$new_code, $email]);
                
                // Отправляем email
                if (sendVerificationEmail($email, $new_code)) {
                    $_SESSION['resend_success'] = "Новый код подтверждения отправлен на ваш email";
                } else {
                    $errors[] = "Не удалось отправить новый код. Попробуйте позже.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение email | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="/img/cloud.png" type="image/png">
    <style>
        /* ОСНОВНЫЕ ПЕРЕМЕННЫЕ */
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-light: #94a3b8;
            --border-color: #cbd5e1;
            --accent: #0ea5e9;
            --accent-hover: #0284c7;
            --accent-light: rgba(14, 165, 233, 0.15);
            --success: #10b981;
            --danger: #ef4444;
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

        /* КОНТЕЙНЕР */
        .container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
        }

        .container::before {
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

        /* КАРТОЧКА */
        .auth-card {
            width: 100%;
            max-width: 500px;
            position: relative;
            z-index: 2;
            background: var(--card-bg);
            border-radius: 24px;
            padding: 40px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(148, 163, 184, 0.1);
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
            background: var(--secondary-gradient);
            border-radius: 24px 24px 0 0;
        }

        /* ЗАГОЛОВОК */
        .auth-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .auth-icon {
            width: 80px;
            height: 80px;
            background: var(--secondary-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(14, 165, 233, 0.3);
        }

        .auth-title {
            font-size: 32px;
            font-weight: 700;
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

        /* УВЕДОМЛЕНИЯ */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border: 1px solid transparent;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

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

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.3);
            color: #047857;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.3);
            color: #b91c1c;
        }

        .alert-info {
            background: rgba(14, 165, 233, 0.15);
            border-color: rgba(14, 165, 233, 0.3);
            color: #0369a1;
        }

        .alert i {
            font-size: 18px;
        }

        /* ФОРМА */
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

        /* КОД ПОДТВЕРЖДЕНИЯ */
        .verification-code-input {
            font-size: 32px;
            letter-spacing: 8px;
            text-align: center;
            font-weight: 700;
            color: var(--text-primary);
            border: 2px solid rgba(0, 188, 212, 0.3);
            border-radius: 12px;
            padding: 16px;
            background: rgba(248, 250, 252, 0.8);
            width: 100%;
            margin: 0 auto 24px;
            display: block;
        }

        .verification-code-input:focus {
            outline: none;
            border-color: #00bcd4;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 188, 212, 0.1);
        }

        /* ИНФОРМАЦИЯ */
        .verification-info {
            background: rgba(0, 188, 212, 0.05);
            border: 2px solid rgba(0, 188, 212, 0.1);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 32px;
            text-align: center;
        }

        .verification-email {
            font-weight: 700;
            color: #00bcd4;
            font-size: 18px;
            margin: 8px 0;
            word-break: break-all;
        }

        /* КНОПКИ */
        .btn-primary {
            width: 100%;
            padding: 18px;
            background: var(--secondary-gradient);
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

        .btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        /* ССЫЛКИ */
        .auth-links {
            text-align: center;
            margin-top: 24px;
        }

        .auth-link {
            color: #00bcd4;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
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

        /* АДАПТИВНОСТЬ */
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

            .verification-code-input {
                font-size: 24px;
                letter-spacing: 6px;
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

            .btn-primary {
                padding: 16px;
            }

            .verification-code-input {
                font-size: 20px;
                letter-spacing: 4px;
            }
        }

        /* ТЁМНАЯ ТЕМА */
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

            .auth-title {
                background: linear-gradient(135deg, #ffffff, #e2e8f0);
                -webkit-background-clip: text;
                background-clip: text;
            }

            .verification-code-input {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(0, 188, 212, 0.3);
                color: #cbd5e1;
            }

            .verification-info {
                background: rgba(0, 188, 212, 0.1);
                border-color: rgba(0, 188, 212, 0.2);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="auth-card">
            <!-- Заголовок -->
            <div class="auth-header">
                <div class="auth-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h1 class="auth-title">Подтверждение email</h1>
                <p class="auth-subtitle">
                    Введите код подтверждения, отправленный на ваш email
                </p>
            </div>

            <!-- Уведомления -->
            <?php if (isset($_SESSION['verification_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <p><?= htmlspecialchars($_SESSION['verification_success']) ?></p>
                        <p style="margin-top: 8px; font-size: 14px;">
                            Перенаправление на страницу входа...
                        </p>
                    </div>
                </div>
                <?php unset($_SESSION['verification_success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['resend_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <p><?= htmlspecialchars($_SESSION['resend_success']) ?></p>
                </div>
                <?php unset($_SESSION['resend_success']); ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <p>Email успешно подтвержден!</p>
                        <p style="margin-top: 8px; font-size: 14px;">
                            Перенаправление на страницу входа...
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Форма подтверждения -->
            <?php if (!$success): ?>
                <form method="POST" id="verification-form">
                    <!-- Информация о email -->
                    <div class="verification-info">
                        <p>Код подтверждения отправлен на email:</p>
                        <div class="verification-email" id="email-display">
                            <?= !empty($email) ? htmlspecialchars($email) : 'укажите ваш email' ?>
                        </div>
                        <p>Введите 6-значный код из письма:</p>
                    </div>

                    <!-- Поле для email (скрыто, если уже передан в GET) -->
                    <?php if (empty($email)): ?>
                        <div class="form-group">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope"></i> Email
                            </label>
                            <input type="email" id="email" name="email" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   placeholder="your@email.com"
                                   oninput="updateEmailDisplay()">
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <?php endif; ?>

                    <!-- Поле для кода -->
                    <div class="form-group">
                        <label for="code" class="form-label">
                            <i class="fas fa-key"></i> Код подтверждения
                        </label>
                        <input type="text" 
                               id="code" 
                               name="code" 
                               class="verification-code-input" 
                               required
                               placeholder="000000"
                               maxlength="6"
                               pattern="\d{6}"
                               autocomplete="off"
                               inputmode="numeric">
                    </div>

                    <!-- Кнопка подтверждения -->
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-check"></i> Подтвердить email
                    </button>
                </form>

                <!-- Ссылки -->
                <div class="auth-links">
                    <a href="verify_email.php?resend=1&email=<?= urlencode($email) ?>" class="auth-link" id="resend-code">
                        <i class="fas fa-redo"></i> Отправить код повторно
                    </a>
                    <br>
                    <a href="/login/login.php" class="auth-link" style="margin-top: 8px;">
                        <i class="fas fa-sign-in-alt"></i> Вернуться к входу
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Автофокус на поле кода
        const codeField = document.getElementById('code');
        if (codeField) {
            codeField.focus();
            
            // Ограничиваем ввод только цифрами
            codeField.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
                
                // Автоматически переходим к отправке формы при вводе 6 цифр
                if (this.value.length === 6) {
                    document.getElementById('verification-form').submit();
                }
            });
        }

        // Обновление отображения email
        updateEmailDisplay();

        // Обработчик повторной отправки кода
        const resendLink = document.getElementById('resend-code');
        if (resendLink) {
            resendLink.addEventListener('click', function(e) {
                e.preventDefault();
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
                this.style.pointerEvents = 'none';

                // Показываем уведомление
                const notification = document.createElement('div');
                notification.className = 'alert alert-info';
                notification.innerHTML = '<i class="fas fa-info-circle"></i> <p>Отправка нового кода...</p>';
                document.querySelector('.auth-header').after(notification);

                // Переходим по ссылке
                setTimeout(() => {
                    window.location.href = this.href;
                }, 1000);
            });
        }
    });

    // Обновление отображения email
    function updateEmailDisplay() {
        const emailField = document.getElementById('email');
        const emailDisplay = document.getElementById('email-display');
        
        if (emailField && emailDisplay) {
            if (emailField.value) {
                emailDisplay.textContent = emailField.value;
            } else {
                emailDisplay.textContent = 'укажите ваш email';
            }
        }
    }

    // Автоматическая отправка формы при вводе 6 цифр
    document.getElementById('code')?.addEventListener('input', function() {
        if (this.value.length === 6) {
            // Добавляем небольшую задержку для лучшего UX
            setTimeout(() => {
                document.getElementById('verification-form').submit();
            }, 500);
        }
    });
    </script>
</body>
</html>