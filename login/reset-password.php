<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$db = new Database();
$pdo = $db->getConnection();

$errors = [];
$success = false;
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$email_sent = isset($_GET['sent']);
$token_valid = true;

// Обработка первого шага (ввод email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Некорректный email адрес!";
    } else {
        // Проверяем существует ли пользователь с таким email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            // Генерируем токен для сброса пароля
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Сохраняем токен в базе
            $stmt = $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    token = VALUES(token),
                    expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$email, $token, $expires]);

            // Отправляем email с ссылкой для сброса через PHPMailer
            $reset_link = "https://" . $_SERVER['HTTP_HOST'] . "/login/reset-password.php?step=2&token=$token&email=" . urlencode($email);

            $mail_subject = "Сброс пароля на HomeVlad Cloud";
            $mail_body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: 'Inter', sans-serif; color: #1e293b; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 30px; text-align: center; border-radius: 15px 15px 0 0; }
                        .logo { color: white; font-size: 24px; font-weight: 800; }
                        .content { background: #f8fafc; padding: 30px; }
                        .reset-btn { display: inline-block; background: linear-gradient(135deg, #00bcd4, #0097a7); color: white; padding: 16px 32px; text-decoration: none; border-radius: 10px; font-weight: 600; margin: 20px 0; }
                        .warning { background: #fff7ed; border: 1px solid #fed7aa; padding: 15px; border-radius: 8px; margin: 20px 0; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <div class='logo'>HomeVlad Cloud</div>
                        </div>
                        <div class='content'>
                            <h2>Сброс пароля</h2>
                            <p>Здравствуйте!</p>
                            <p>Мы получили запрос на сброс пароля для вашего аккаунта HomeVlad Cloud.</p>
                            <p>Для сброса пароля нажмите на кнопку ниже:</p>
                            <p style='text-align: center;'>
                                <a href='$reset_link' class='reset-btn'>Сбросить пароль</a>
                            </p>
                            <p>Или скопируйте и вставьте эту ссылку в браузер:</p>
                            <p style='word-break: break-all; background: #e2e8f0; padding: 10px; border-radius: 5px;'>$reset_link</p>
                            <div class='warning'>
                                <strong>Внимание:</strong> Ссылка действительна в течение 1 часа. Если вы не запрашивали сброс пароля, проигнорируйте это письмо.
                            </div>
                            <p>С уважением,<br>Команда HomeVlad Cloud</p>
                        </div>
                    </div>
                </body>
                </html>
            ";

            if (sendEmail($email, $mail_subject, $mail_body)) {
                $_SESSION['reset_email'] = $email;
                header('Location: reset-password.php?step=1&sent=1');
                exit;
            } else {
                $errors[] = "Не удалось отправить email. Попробуйте позже.";
            }
        } else {
            // Для безопасности не сообщаем, что email не существует
            $email_sent = true; // Показываем такое же сообщение, как при успешной отправке
        }
    }
}

// Обработка второго шага (ввод нового пароля)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $token = $_POST['token'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Валидация пароля
    if (strlen($password) < 8) {
        $errors[] = "Пароль должен быть не менее 8 символов!";
    } elseif (!preg_match("/[A-Z]/", $password)) {
        $errors[] = "Пароль должен содержать хотя бы одну заглавную букву!";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Пароли не совпадают!";
    } else {
        // Проверяем токен
        $stmt = $pdo->prepare("
            SELECT email FROM password_resets
            WHERE token = ? AND email = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token, $email]);

        if ($row = $stmt->fetch()) {
            // Обновляем пароль
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $stmt->execute([$password_hash, $email]);

            // Удаляем использованный токен
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt->execute([$email]);

            $success = true;
        } else {
            $errors[] = "Недействительная или просроченная ссылка для сброса пароля.";
            $token_valid = false;
        }
    }
}

// Проверка токена при переходе по ссылке
if ($step === 2 && isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = urldecode($_GET['email']);

    $stmt = $pdo->prepare("
        SELECT email, expires_at FROM password_resets
        WHERE token = ? AND email = ? AND expires_at > NOW()
    ");
    $stmt->execute([$token, $email]);

    if (!$stmt->fetch()) {
        $token_valid = false;
        $errors[] = "Недействительная или просроченная ссылка для сброса пароля.";
        $step = 1;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля | HomeVlad Cloud</title>
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
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
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
            padding: 140px 20px 60px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
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

        /* Карточка восстановления */
        .auth-container {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.8s ease forwards;
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
            background: var(--warning-gradient);
            border-radius: 24px 24px 0 0;
        }

        /* Шаги восстановления */
        .reset-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }

        .reset-steps::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 25%;
            right: 25%;
            height: 2px;
            background: rgba(148, 163, 184, 0.2);
            z-index: 1;
        }

        .step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .step-number {
            width: 48px;
            height: 48px;
            background: white;
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-light);
            margin: 0 auto 12px;
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: var(--warning-gradient);
            border-color: transparent;
            color: white;
            transform: scale(1.1);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }

        .step.completed .step-number {
            background: var(--success-gradient);
            border-color: transparent;
            color: white;
        }

        .step.completed .step-number::after {
            content: '✓';
            margin-left: 2px;
        }

        .step-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }

        .step.active .step-title {
            color: var(--text-primary);
            font-weight: 700;
        }

        .step.completed .step-title {
            color: #10b981;
        }

        /* Заголовок */
        .auth-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .auth-icon {
            width: 80px;
            height: 80px;
            background: var(--warning-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.3);
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
            color: #f59e0b;
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
            border-color: #f59e0b;
            background: white;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-light);
        }

        /* Кнопки */
        .btn-auth {
            width: 100%;
            padding: 18px;
            background: var(--warning-gradient);
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
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }

        .btn-auth:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(245, 158, 11, 0.4);
        }

        .btn-auth:active {
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-gradient);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            box-shadow: 0 12px 30px rgba(16, 185, 129, 0.4);
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

        .notification.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: #d97706;
        }

        .notification.info {
            background: linear-gradient(135deg, rgba(0, 188, 212, 0.1), rgba(0, 151, 167, 0.1));
            border: 1px solid rgba(0, 188, 212, 0.2);
            color: #0097a7;
        }

        .notification i {
            font-size: 18px;
        }

        /* Сообщение об отправке email */
        .email-sent-message {
            text-align: center;
            padding: 32px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(5, 150, 105, 0.05));
            border-radius: 20px;
            border: 2px solid rgba(16, 185, 129, 0.2);
            margin-bottom: 32px;
            animation: pulse 2s ease infinite;
        }

        .email-sent-icon {
            width: 80px;
            height: 80px;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .email-sent-message h3 {
            font-size: 24px;
            font-weight: 700;
            color: #059669;
            margin-bottom: 16px;
        }

        .email-sent-message p {
            color: var(--text-secondary);
            margin-bottom: 12px;
            line-height: 1.7;
        }

        .email-highlight {
            font-weight: 700;
            color: #00bcd4;
            background: rgba(0, 188, 212, 0.1);
            padding: 4px 12px;
            border-radius: 8px;
            display: inline-block;
        }

        /* Информация о таймере */
        .timer-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
            padding: 16px;
            background: rgba(245, 158, 11, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(245, 158, 11, 0.1);
        }

        .timer-info i {
            color: #f59e0b;
            font-size: 20px;
        }

        .timer-text {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
        }

        .timer-value {
            color: #f59e0b;
            font-weight: 700;
            font-size: 16px;
        }

        /* Ссылки */
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

        .auth-footer {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(148, 163, 184, 0.2);
            color: var(--text-secondary);
            font-size: 14px;
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
                transform: scale(1);
                box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
            }
            50% {
                transform: scale(1.02);
                box-shadow: 0 15px 30px rgba(16, 185, 129, 0.4);
            }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
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

            .reset-steps::before {
                left: 20%;
                right: 20%;
            }

            .main-content {
                padding: 120px 15px 40px;
            }

            .email-sent-message {
                padding: 24px;
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

            .btn-auth {
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

            .step-number {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .reset-steps::before {
                left: 15%;
                right: 15%;
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
                border-color: #f59e0b;
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

            .step-number {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
                color: #94a3b8;
            }

            .reset-steps::before {
                background: rgba(255, 255, 255, 0.1);
            }

            .email-sent-message {
                background: rgba(16, 185, 129, 0.1);
                border-color: rgba(16, 185, 129, 0.3);
            }

            .timer-info {
                background: rgba(245, 158, 11, 0.1);
                border-color: rgba(245, 158, 11, 0.2);
            }

            .email-highlight {
                background: rgba(0, 188, 212, 0.2);
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
                    <a href="/login/login.php" class="nav-btn nav-btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Войти
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <div class="auth-container">
            <div class="auth-card">
                <!-- Шаги восстановления -->
                <div class="reset-steps">
                    <div class="step <?= $step == 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>">
                        <div class="step-number">1</div>
                        <div class="step-title">Запрос сброса</div>
                    </div>
                    <div class="step <?= $step == 2 ? 'active' : '' ?>">
                        <div class="step-number">2</div>
                        <div class="step-title">Новый пароль</div>
                    </div>
                </div>

                <!-- Заголовок -->
                <div class="auth-header">
                    <?php if ($step == 1): ?>
                        <div class="auth-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <h1 class="auth-title">Восстановление пароля</h1>
                        <p class="auth-subtitle">
                            Введите email вашего аккаунта<br>
                            Мы отправим ссылку для сброса пароля
                        </p>
                    <?php elseif ($step == 2): ?>
                        <div class="auth-icon" style="background: var(--success-gradient);">
                            <i class="fas fa-unlock-alt"></i>
                        </div>
                        <h1 class="auth-title">Новый пароль</h1>
                        <p class="auth-subtitle">
                            Придумайте новый пароль для вашего аккаунта<br>
                            Используйте надежную комбинацию символов
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Уведомления об ошибках -->
                <?php if (!empty($errors)): ?>
                    <div class="notification error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <?php foreach ($errors as $error): ?>
                                <p><?= htmlspecialchars($error) ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Успешное восстановление -->
                <?php if ($success): ?>
                    <div class="notification success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <p><strong>Пароль успешно изменен!</strong></p>
                            <p>Теперь вы можете войти в систему с новым паролем.</p>
                        </div>
                    </div>

                    <div class="text-center">
                        <a href="/login/login.php" class="btn-auth btn-success">
                            <i class="fas fa-sign-in-alt"></i> Войти в аккаунт
                        </a>

                        <div class="auth-links">
                            <a href="/" class="auth-link">
                                <i class="fas fa-home"></i> На главную
                            </a>
                        </div>
                    </div>

                <!-- Email отправлен -->
                <?php elseif ($email_sent && $step == 1): ?>
                    <div class="email-sent-message">
                        <div class="email-sent-icon">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <h3>Ссылка отправлена!</h3>
                        <p>Мы отправили ссылку для сброса пароля на email:</p>
                        <div class="email-highlight">
                            <?= htmlspecialchars($_SESSION['reset_email'] ?? 'ваш email') ?>
                        </div>
                        <p>Пожалуйста, проверьте вашу почту и следуйте инструкциям в письме.</p>

                        <div class="timer-info">
                            <i class="fas fa-clock"></i>
                            <div class="timer-text">Ссылка будет действительна:</div>
                            <div class="timer-value">1 час</div>
                        </div>
                    </div>

                    <div class="auth-links">
                        <a href="reset-password.php" class="auth-link">
                            <i class="fas fa-redo"></i> Отправить ссылку еще раз
                        </a>
                        <br>
                        <a href="/login/login.php" class="auth-link" style="margin-top: 12px;">
                            <i class="fas fa-arrow-left"></i> Вернуться к входу
                        </a>
                    </div>

                <!-- Шаг 1: Ввод email -->
                <?php elseif ($step == 1): ?>
                    <form method="POST" action="reset-password.php" id="resetForm">
                        <input type="hidden" name="step" value="1">

                        <div class="form-group">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope"></i> Email
                            </label>
                            <input type="email" id="email" name="email" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   placeholder="your@email.com"
                                   autocomplete="email">
                        </div>

                        <button type="submit" class="btn-auth pulse">
                            <i class="fas fa-paper-plane"></i> Отправить ссылку
                        </button>

                        <div class="auth-footer">
                            Вспомнили пароль? <a href="/login/login.php" class="auth-link">Войти в аккаунт</a>
                        </div>
                    </form>

                <!-- Шаг 2: Новый пароль -->
                <?php elseif ($step == 2 && $token_valid): ?>
                    <form method="POST" action="reset-password.php?step=2" id="newPasswordForm">
                        <input type="hidden" name="step" value="2">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? '') ?>">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($_GET['email'] ?? $_POST['email'] ?? '') ?>">

                        <div class="form-group">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock"></i> Новый пароль
                            </label>
                            <input type="password" id="password" name="password" class="form-control" required
                                   placeholder="Не менее 8 символов, с заглавной буквой"
                                   autocomplete="new-password">
                            <div style="margin-top: 8px; font-size: 12px; color: var(--text-light);">
                                <i class="fas fa-info-circle"></i> Минимум 8 символов, 1 заглавная буква
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                <i class="fas fa-lock"></i> Подтвердите пароль
                            </label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required
                                   placeholder="Повторите пароль"
                                   autocomplete="new-password">
                        </div>

                        <div class="timer-info">
                            <i class="fas fa-shield-alt"></i>
                            <div class="timer-text">Ссылка действительна:</div>
                            <div class="timer-value">до <?= date('H:i') ?></div>
                        </div>

                        <button type="submit" class="btn-auth btn-success">
                            <i class="fas fa-save"></i> Сохранить новый пароль
                        </button>
                    </form>
                <?php endif; ?>
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
            // Автофокус на поле email на первом шаге
            const emailField = document.getElementById('email');
            if (emailField) {
                emailField.focus();
            }

            // Анимация при загрузке
            const authCard = document.querySelector('.auth-card');
            authCard.style.opacity = '0';
            authCard.style.transform = 'translateY(20px)';

            setTimeout(() => {
                authCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                authCard.style.opacity = '1';
                authCard.style.transform = 'translateY(0)';
            }, 100);

            // Валидация пароля в реальном времени (шаг 2)
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');

            if (passwordField && confirmPasswordField) {
                function validatePassword() {
                    const password = passwordField.value;
                    const confirmPassword = confirmPasswordField.value;

                    if (password.length < 8) {
                        passwordField.style.borderColor = '#ef4444';
                        showPasswordHint('Минимум 8 символов', 'error');
                    } else if (!/[A-Z]/.test(password)) {
                        passwordField.style.borderColor = '#f59e0b';
                        showPasswordHint('Нужна хотя бы одна заглавная буква', 'warning');
                    } else {
                        passwordField.style.borderColor = '#10b981';
                        showPasswordHint('Пароль надежен!', 'success');
                    }

                    if (confirmPassword && password !== confirmPassword) {
                        confirmPasswordField.style.borderColor = '#ef4444';
                        showConfirmHint('Пароли не совпадают', 'error');
                    } else if (confirmPassword) {
                        confirmPasswordField.style.borderColor = '#10b981';
                        showConfirmHint('Пароли совпадают', 'success');
                    }
                }

                function showPasswordHint(message, type) {
                    let hint = document.getElementById('password-hint');
                    if (!hint) {
                        hint = document.createElement('div');
                        hint.id = 'password-hint';
                        hint.style.marginTop = '8px';
                        hint.style.fontSize = '12px';
                        hint.style.display = 'flex';
                        hint.style.alignItems = 'center';
                        hint.style.gap = '6px';
                        passwordField.parentNode.appendChild(hint);
                    }

                    hint.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'}"
                                        style="color: ${type === 'success' ? '#10b981' : type === 'warning' ? '#f59e0b' : '#ef4444'}"></i> ${message}`;
                }

                function showConfirmHint(message, type) {
                    let hint = document.getElementById('confirm-hint');
                    if (!hint) {
                        hint = document.createElement('div');
                        hint.id = 'confirm-hint';
                        hint.style.marginTop = '8px';
                        hint.style.fontSize = '12px';
                        hint.style.display = 'flex';
                        hint.style.alignItems = 'center';
                        hint.style.gap = '6px';
                        confirmPasswordField.parentNode.appendChild(hint);
                    }

                    hint.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"
                                        style="color: ${type === 'success' ? '#10b981' : '#ef4444'}"></i> ${message}`;
                }

                passwordField.addEventListener('input', validatePassword);
                confirmPasswordField.addEventListener('input', validatePassword);
            }

            // Показать/скрыть пароль
            function addPasswordToggle(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;

                const wrapper = document.createElement('div');
                wrapper.style.position = 'relative';

                const toggleBtn = document.createElement('span');
                toggleBtn.innerHTML = '<i class="fas fa-eye" style="cursor: pointer; color: #94a3b8;"></i>';
                toggleBtn.style.position = 'absolute';
                toggleBtn.style.right = '15px';
                toggleBtn.style.top = '50%';
                toggleBtn.style.transform = 'translateY(-50%)';
                toggleBtn.style.cursor = 'pointer';
                toggleBtn.style.zIndex = '2';

                toggleBtn.addEventListener('click', function() {
                    if (input.type === 'password') {
                        input.type = 'text';
                        this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    } else {
                        input.type = 'password';
                        this.innerHTML = '<i class="fas fa-eye"></i>';
                    }
                });

                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                wrapper.appendChild(toggleBtn);
            }

            addPasswordToggle('password');
            addPasswordToggle('confirm_password');

            // Анимация ошибок
            <?php if (!empty($errors)): ?>
                const notification = document.querySelector('.notification.error');
                if (notification) {
                    notification.style.animation = 'shake 0.5s ease';
                    setTimeout(() => {
                        notification.style.animation = '';
                    }, 500);
                }
            <?php endif; ?>

            // Обработка формы восстановления
            const resetForm = document.getElementById('resetForm');
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    const emailField = this.querySelector('input[type="email"]');
                    if (emailField && !emailField.value.trim()) {
                        e.preventDefault();
                        emailField.style.borderColor = '#ef4444';
                        emailField.style.animation = 'shake 0.5s ease';
                        setTimeout(() => {
                            emailField.style.animation = '';
                        }, 500);
                    }
                });
            }

            // Таймер для срока действия ссылки
            function updateTimer() {
                const timerElement = document.querySelector('.timer-value');
                if (!timerElement) return;

                const now = new Date();
                const expiryTime = new Date(now.getTime() + 60 * 60 * 1000); // 1 час от текущего времени

                function formatTime(date) {
                    return date.toLocaleTimeString('ru-RU', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }

                timerElement.textContent = 'до ' + formatTime(expiryTime);
            }

            // Обновляем таймер каждую минуту
            if (document.querySelector('.timer-value')) {
                updateTimer();
                setInterval(updateTimer, 60000);
            }
        });
    </script>
</body>
</html>
