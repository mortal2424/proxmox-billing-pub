<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Проверяем, есть ли уже администраторы
$db = new Database();
$pdo = $db->getConnection();

$adminCount = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1");
    $result = $stmt->fetch();
    $adminCount = $result['count'] ?? 0;
} catch (Exception $e) {
    // Если таблица не существует, продолжаем
}

// Если администраторы уже есть, перенаправляем на страницу входа
if ($adminCount > 0) {
    header('Location: /login/login.php');
    exit;
}

// Проверяем настройки email
$emailConfigured = function_exists('sendVerificationEmail');
$emailConfigWarning = '';

if (!$emailConfigured) {
    $emailConfigWarning = '
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Внимание:</strong> Функция отправки email не найдена.
            <p style="margin-top: 8px; font-size: 14px;">
                • Письма для верификации отправляться не будут<br>
                • Рекомендуется отметить "Email подтвержден"<br>
                • Проверьте файл includes/functions.php
            </p>
        </div>
    </div>';
}

$errors = [];
$success = false;
$formData = [];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Собираем данные формы
    $formData = [
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'password_confirm' => $_POST['password_confirm'] ?? '',
        'phone' => trim($_POST['phone'] ?? ''),
        'full_name' => trim($_POST['full_name'] ?? ''),
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'company_name' => trim($_POST['company_name'] ?? ''),
        'inn' => trim($_POST['inn'] ?? ''),
        'kpp' => trim($_POST['kpp'] ?? ''),
        'telegram_id' => trim($_POST['telegram_id'] ?? ''),
        'avatar' => trim($_POST['avatar'] ?? ''),
        'email_verified' => isset($_POST['email_verified']) ? 1 : 0,
        'bonus_balance' => floatval($_POST['bonus_balance'] ?? 0),
        'is_admin' => 1, // Первый пользователь всегда администратор
        'is_active' => 1  // <--- ИСПРАВЛЕНИЕ: создаём активным по умолчанию
    ];

    // Валидация email
    if (empty($formData['email'])) {
        $errors['email'] = 'Email обязателен для заполнения';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Некорректный формат email';
    } else {
        // Проверка уникальности email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$formData['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Пользователь с таким email уже существует';
        }
    }

    // Валидация пароля
    if (empty($formData['password'])) {
        $errors['password'] = 'Пароль обязателен для заполнения';
    } elseif (strlen($formData['password']) < 8) {
        $errors['password'] = 'Пароль должен содержать минимум 8 символов';
    } elseif ($formData['password'] !== $formData['password_confirm']) {
        $errors['password_confirm'] = 'Пароли не совпадают';
    }

    // Валидация телефона (если указан)
    if (!empty($formData['phone']) && !validatePhone($formData['phone'])) {
        $errors['phone'] = 'Некорректный формат телефона. Используйте формат: +79123456789';
    }

    // Валидация ИНН (если указан)
    if (!empty($formData['inn'])) {
        if (!preg_match('/^[0-9]{10,12}$/', $formData['inn'])) {
            $errors['inn'] = 'ИНН должен содержать 10 или 12 цифр';
        }
    }

    // Валидация КПП (если указан)
    if (!empty($formData['kpp'])) {
        if (!preg_match('/^[0-9]{9}$/', $formData['kpp'])) {
            $errors['kpp'] = 'КПП должен содержать 9 цифр';
        }
    }

    // Валидация бонусного баланса
    if ($formData['bonus_balance'] < 0) {
        $errors['bonus_balance'] = 'Бонусный баланс не может быть отрицательным';
    }

    // Если нет ошибок - сохраняем пользователя
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Хэшируем пароль
            $password_hash = password_hash($formData['password'], PASSWORD_BCRYPT);

            // Генерируем код подтверждения, если email не подтвержден сразу
            $verification_code = null;
            $verification_sent_at = null;

            if (!$formData['email_verified']) {
                $verification_code = generateVerificationCode();
                $verification_sent_at = date('Y-m-d H:i:s');
            }

            // Подготавливаем SQL запрос с добавленным полем is_active
            $sql = "INSERT INTO users (
                email, password_hash, is_admin, is_active, phone, full_name,
                first_name, last_name, company_name, inn, kpp,
                email_verified, bonus_balance, telegram_id, avatar, created_at,
                verification_code, verification_sent_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $formData['email'],
                $password_hash,
                $formData['is_admin'],
                $formData['is_active'],        // <--- ИСПРАВЛЕНИЕ: передаём значение 1
                $formData['phone'] ?: null,
                $formData['full_name'] ?: null,
                $formData['first_name'] ?: null,
                $formData['last_name'] ?: null,
                $formData['company_name'] ?: null,
                $formData['inn'] ?: null,
                $formData['kpp'] ?: null,
                $formData['email_verified'],
                $formData['bonus_balance'],
                $formData['telegram_id'] ?: null,
                $formData['avatar'] ?: null,
                $verification_code,
                $verification_sent_at
            ]);

            $user_id = $pdo->lastInsertId();

            // Отправляем email для верификации, если не подтвержден сразу
            $emailSent = false;
            if (!$formData['email_verified'] && $verification_code) {
                if (function_exists('sendVerificationEmail')) {
                    if (sendVerificationEmail($formData['email'], $verification_code)) {
                        $emailSent = true;
                        $_SESSION['email_sent'] = true;
                        $_SESSION['email_recipient'] = $formData['email'];
                        // Генерируем ссылку для подтверждения
                        $verification_link = "/login/verify_email.php?email=" . urlencode($formData['email']);
                        $_SESSION['verification_link'] = $verification_link;
                    } else {
                        $_SESSION['email_warning'] = "Администратор создан, но не удалось отправить письмо для верификации email. Вы можете отправить его позже.";
                    }
                } else {
                    $_SESSION['email_warning'] = "Администратор создан, но функция отправки email не доступна. Email не подтвержден.";
                }
            }

            $pdo->commit();
            $success = true;

            // Очищаем форму после успешного сохранения
            $formData = [];

            // Сохраняем сообщение в сессию
            $_SESSION['success'] = "✅ Первый администратор успешно создан!";

            // Если email был отправлен, добавляем информацию
            if ($emailSent) {
                $_SESSION['success'] .= "<br><br>📧 Письмо для верификации отправлено на email: <strong>" . htmlspecialchars($_SESSION['email_recipient']) . "</strong>";
                $_SESSION['success'] .= "<br><br>🔗 Для подтверждения email перейдите по ссылке:";
                $_SESSION['success'] .= "<br><a href='" . $verification_link . "' style='color: #0ea5e9; text-decoration: underline; word-break: break-all;'>" . $verification_link . "</a>";
                $_SESSION['success'] .= "<br><br>Или введите код из письма на странице подтверждения.";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Ошибка при сохранении: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создание первого администратора | HomeVlad Cloud</title>
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
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
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
            --warning: #f59e0b;
            --info: #3b82f6;
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
        .install-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
        }

        .install-container::before {
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

        /* ШАПКА УСТАНОВКИ */
        .install-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 2;
        }

        .install-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .install-logo i {
            font-size: 48px;
            color: var(--accent);
            background: var(--accent-light);
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(14, 165, 233, 0.3);
        }

        .install-logo h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .install-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .install-instruction {
            background: var(--accent-light);
            border: 1px solid rgba(14, 165, 233, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .install-instruction h3 {
            color: var(--accent);
            font-size: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .install-instruction p {
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.6;
        }

        /* ОСНОВНАЯ КАРТОЧКА */
        .main-card {
            width: 100%;
            max-width: 800px;
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

        .main-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.12);
        }

        .main-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--secondary-gradient);
            border-radius: 24px 24px 0 0;
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

        .alert-warning {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.3);
            color: #b45309;
        }

        .alert-info {
            background: rgba(14, 165, 233, 0.15);
            border-color: rgba(14, 165, 233, 0.3);
            color: #0369a1;
        }

        .alert i {
            font-size: 18px;
        }

        .alert-success i {
            color: #10b981;
        }

        .alert-danger i {
            color: #ef4444;
        }

        .alert-warning i {
            color: #f59e0b;
        }

        .alert-info i {
            color: #0ea5e9;
        }

        /* ЗАГОЛОВОК ФОРМЫ */
        .form-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-header h2 i {
            color: var(--accent);
        }

        .form-header p {
            color: var(--text-secondary);
            font-size: 15px;
            margin-top: 8px;
        }

        /* СЕТКА ФОРМЫ */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ГРУППЫ ФОРМЫ */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: white;
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-light);
        }

        .form-control.is-invalid {
            border-color: var(--danger);
        }

        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }

        .invalid-feedback {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: var(--danger);
        }

        /* ЧЕКБОКСЫ */
        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            border: 2px solid var(--border-color);
            border-radius: 4px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
            position: relative;
        }

        .form-check-input:checked {
            background: var(--accent);
            border-color: var(--accent);
        }

        .form-check-input:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .form-check-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px var(--accent-light);
        }

        .form-check-label {
            font-size: 14px;
            color: var(--text-primary);
            cursor: pointer;
        }

        /* ГЕНЕРАТОР ПАРОЛЕЙ */
        .password-generator {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .btn-generate {
            padding: 8px 12px;
            background: var(--accent-light);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--accent);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-generate:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-strength.weak .password-strength-bar {
            background: var(--danger);
            width: 33%;
        }

        .password-strength.medium .password-strength-bar {
            background: var(--warning);
            width: 66%;
        }

        .password-strength.strong .password-strength-bar {
            background: var(--success);
            width: 100%;
        }

        .password-strength-text {
            font-size: 11px;
            margin-top: 2px;
            color: var(--text-secondary);
        }

        /* ПРЕДПРОСМОТР АВАТАРА */
        .avatar-preview {
            margin-top: 10px;
            text-align: center;
        }

        .avatar-preview-img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
            background: white;
            display: none;
        }

        .avatar-preview-img.show {
            display: inline-block;
        }

        .avatar-preview-default {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 24px;
            border: 2px solid var(--border-color);
        }

        /* ИНФО КАРТОЧКА */
        .info-card {
            background: var(--accent-light);
            border: 1px solid var(--accent);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            grid-column: 1 / -1;
        }

        .info-card h4 {
            color: var(--accent);
            font-size: 14px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card p {
            color: var(--text-primary);
            font-size: 13px;
            margin: 0;
            line-height: 1.5;
        }

        /* РАЗДЕЛЫ ФОРМЫ */
        .form-section {
            margin-bottom: 32px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--accent);
        }

        /* КНОПКИ */
        .form-actions {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-primary {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(14, 165, 233, 0.3);
        }

        /* ФУТЕР УСТАНОВКИ */
        .install-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* АДАПТИВНОСТЬ */
        @media (max-width: 768px) {
            .main-card {
                padding: 30px 20px;
                border-radius: 20px;
            }

            .install-logo h1 {
                font-size: 28px;
            }

            .install-logo i {
                font-size: 36px;
                width: 60px;
                height: 60px;
            }

            .install-subtitle {
                font-size: 16px;
            }

            .form-header h2 {
                font-size: 20px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-primary {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .main-card {
                padding: 25px 15px;
            }

            .install-logo {
                flex-direction: column;
                gap: 10px;
            }

            .install-logo h1 {
                font-size: 24px;
            }

            .install-subtitle {
                font-size: 14px;
            }
        }

        /* ТЕМНАЯ ТЕМА */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                --card-bg: rgba(30, 41, 59, 0.9);
                --text-primary: #f1f5f9;
                --text-secondary: #cbd5e1;
                --text-light: #94a3b8;
                --border-color: #334155;
            }

            .form-control {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
                color: #cbd5e1;
            }

            .form-control:focus {
                background: rgba(15, 23, 42, 1);
                border-color: var(--accent);
            }

            .install-logo h1 {
                background: linear-gradient(135deg, #ffffff, #e2e8f0);
                -webkit-background-clip: text;
                background-clip: text;
            }
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="main-card">
            <!-- Шапка установки -->
            <div class="install-header">
                <div class="install-logo">
                    <i class="fas fa-cloud"></i>
                    <h1>HomeVlad Cloud</h1>
                </div>
                <p class="install-subtitle">
                    Настройка системы: создание первого администратора
                </p>

                <div class="install-instruction">
                    <h3><i class="fas fa-info-circle"></i> Важная информация</h3>
                    <p>
                        Создайте учетную запись первого администратора системы.
                        Этот пользователь будет иметь полные права доступа к системе управления.
                        После создания учетной записи вы будете перенаправлены на страницу входа.
                    </p>
                    <p style="margin-top: 10px;">
                        <strong>Настройка email:</strong> Если email подтвержден, администратор сможет войти сразу.
                        Если нет - будет отправлено письмо с кодом подтверждения.
                    </p>
                </div>
            </div>

            <!-- Предупреждение о настройке email -->
            <?= $emailConfigWarning ?>

            <!-- Уведомления -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <?= isset($_SESSION['success']) ? $_SESSION['success'] : 'Первый администратор успешно создан!' ?>
                        <p style="margin-top: 15px; font-size: 14px;">
                            <a href="/login/login.php" style="color: #047857; text-decoration: underline; font-weight: 600;">
                                <i class="fas fa-sign-in-alt"></i> Перейти к странице входа
                            </a>
                        </p>
                    </div>
                </div>
                <?php
                unset($_SESSION['success']);
                unset($_SESSION['email_sent']);
                unset($_SESSION['email_recipient']);
                unset($_SESSION['email_warning']);
                unset($_SESSION['verification_link']);
                ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['email_warning'])): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <?= htmlspecialchars($_SESSION['email_warning']) ?>
                        <p style="margin-top: 8px; font-size: 14px;">
                            Вы можете войти в систему и подтвердить email позже.
                        </p>
                    </div>
                </div>
                <?php unset($_SESSION['email_warning']); ?>
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

            <!-- Форма создания администратора -->
            <form method="POST" id="create-admin-form">
                <!-- Заголовок формы -->
                <div class="form-header">
                    <h2><i class="fas fa-user-shield"></i> Учетная запись администратора</h2>
                    <p>Заполните обязательные поля для создания первого администратора системы</p>
                </div>

                <!-- Основная информация -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fas fa-key"></i> Основная информация</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email"
                                   name="email"
                                   class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                   value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                                   required
                                   placeholder="admin@example.com">
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Телефон</label>
                            <input type="tel"
                                   name="phone"
                                   class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                                   value="<?= htmlspecialchars($formData['phone'] ?? '') ?>"
                                   placeholder="+79123456789">
                            <?php if (isset($errors['phone'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Пароль -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fas fa-lock"></i> Пароль</h4>

                    <div class="form-group">
                        <label>Пароль <span class="required">*</span></label>
                        <div class="password-generator">
                            <input type="password"
                                   name="password"
                                   id="password"
                                   class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                   required
                                   placeholder="Минимум 8 символов">
                            <button type="button" class="btn-generate" onclick="generatePassword()">
                                <i class="fas fa-random"></i> Сгенерировать
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
                        <?php endif; ?>

                        <div class="password-strength" id="password-strength">
                            <div class="password-strength-bar"></div>
                        </div>
                        <div class="password-strength-text" id="password-strength-text"></div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Подтверждение пароля <span class="required">*</span></label>
                            <input type="password"
                                   name="password_confirm"
                                   class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                                   required
                                   placeholder="Повторите пароль">
                            <?php if (isset($errors['password_confirm'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Показать пароль</label>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="show-password">
                                <label class="form-check-label" for="show-password">Показать</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Личная информация -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fas fa-user"></i> Личная информация</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Полное имя</label>
                            <input type="text"
                                   name="full_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>"
                                   placeholder="Иванов Иван Иванович">
                        </div>

                        <div class="form-group">
                            <label>Имя</label>
                            <input type="text"
                                   name="first_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['first_name'] ?? '') ?>"
                                   placeholder="Иван">
                        </div>

                        <div class="form-group">
                            <label>Фамилия</label>
                            <input type="text"
                                   name="last_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['last_name'] ?? '') ?>"
                                   placeholder="Иванов">
                        </div>

                        <div class="form-group">
                            <label>Telegram ID</label>
                            <input type="text"
                                   name="telegram_id"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['telegram_id'] ?? '') ?>"
                                   placeholder="@username или 123456789">
                        </div>
                    </div>
                </div>

                <!-- Компания -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fas fa-building"></i> Информация о компании</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Название компании</label>
                            <input type="text"
                                   name="company_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['company_name'] ?? '') ?>"
                                   placeholder="ООО 'Ромашка'">
                        </div>

                        <div class="form-group">
                            <label>ИНН</label>
                            <input type="text"
                                   name="inn"
                                   class="form-control <?= isset($errors['inn']) ? 'is-invalid' : '' ?>"
                                   value="<?= htmlspecialchars($formData['inn'] ?? '') ?>"
                                   placeholder="1234567890">
                            <?php if (isset($errors['inn'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['inn']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>КПП</label>
                            <input type="text"
                                   name="kpp"
                                   class="form-control <?= isset($errors['kpp']) ? 'is-invalid' : '' ?>"
                                   value="<?= htmlspecialchars($formData['kpp'] ?? '') ?>"
                                   placeholder="123456789">
                            <?php if (isset($errors['kpp'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['kpp']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Бонусный баланс</label>
                            <input type="number"
                                   name="bonus_balance"
                                   class="form-control <?= isset($errors['bonus_balance']) ? 'is-invalid' : '' ?>"
                                   value="<?= htmlspecialchars($formData['bonus_balance'] ?? '0') ?>"
                                   step="0.01"
                                   min="0">
                            <?php if (isset($errors['bonus_balance'])): ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bonus_balance']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Настройки -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fas fa-cog"></i> Настройки</h4>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>URL аватара</label>
                            <input type="url"
                                   name="avatar"
                                   id="avatar-url"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formData['avatar'] ?? '') ?>"
                                   placeholder="https://example.com/avatar.jpg"
                                   oninput="updateAvatarPreview()">

                            <div class="avatar-preview">
                                <div class="avatar-preview-default" id="default-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <img src="" alt="Preview" class="avatar-preview-img" id="avatar-preview">
                            </div>
                        </div>

                        <div>
                            <div class="form-group">
                                <div class="form-check">
                                    <input type="checkbox"
                                           name="email_verified"
                                           class="form-check-input"
                                           id="email_verified"
                                           <?= ($formData['email_verified'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="email_verified">
                                        Email подтвержден
                                    </label>
                                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                        Если не отмечено, будет отправлено письмо с кодом подтверждения на указанный email
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Кнопки формы -->
                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-user-plus"></i> Создать первого администратора
                    </button>
                </div>

                <!-- Футер -->
                <div class="install-footer">
                    <p>HomeVlad Cloud &copy; <?= date('Y') ?> | Система управления виртуальными серверами</p>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Обработчик показа пароля
            const showPasswordCheckbox = document.getElementById('show-password');
            if (showPasswordCheckbox) {
                showPasswordCheckbox.addEventListener('change', function() {
                    const passwordField = document.getElementById('password');
                    const confirmField = document.querySelector('input[name="password_confirm"]');
                    const type = this.checked ? 'text' : 'password';

                    if (passwordField) passwordField.type = type;
                    if (confirmField) confirmField.type = type;
                });
            }

            // Обработчик проверки силы пароля
            const passwordField = document.getElementById('password');
            if (passwordField) {
                passwordField.addEventListener('input', checkPasswordStrength);
            }

            // Инициализация предпросмотра аватара
            updateAvatarPreview();
        });

        // Генерация пароля
        function generatePassword() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";

            // Гарантируем наличие хотя бы одного символа каждого типа
            password += getRandomChar("abcdefghijklmnopqrstuvwxyz");
            password += getRandomChar("ABCDEFGHIJKLMNOPQRSTUVWXYZ");
            password += getRandomChar("0123456789");
            password += getRandomChar("!@#$%^&*");

            // Заполняем оставшуюся часть
            for (let i = 4; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }

            // Перемешиваем пароль
            password = password.split('').sort(() => Math.random() - 0.5).join('');

            // Устанавливаем пароль в поле
            const passwordField = document.getElementById('password');
            passwordField.value = password;
            passwordField.type = 'text';

            // Устанавливаем подтверждение
            const confirmField = document.querySelector('input[name="password_confirm"]');
            confirmField.value = password;
            confirmField.type = 'text';

            // Обновляем чекбокс показа пароля
            document.getElementById('show-password').checked = true;

            // Проверяем силу пароля
            checkPasswordStrength();

            // Показываем уведомление
            Swal.fire({
                title: 'Пароль сгенерирован',
                text: 'Скопируйте его в безопасное место',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function getRandomChar(charset) {
            return charset.charAt(Math.floor(Math.random() * charset.length));
        }

        // Проверка силы пароля
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.querySelector('.password-strength-bar');
            const strengthContainer = document.getElementById('password-strength');
            const strengthText = document.getElementById('password-strength-text');

            if (!strengthBar || !strengthContainer || !strengthText) return;

            let strength = 0;
            let text = '';
            let className = '';

            if (password.length === 0) {
                strength = 0;
                text = 'Введите пароль';
                className = '';
            } else if (password.length < 8) {
                strength = 33;
                text = 'Слабый: слишком короткий';
                className = 'weak';
            } else {
                // Проверяем сложность
                const hasLower = /[a-z]/.test(password);
                const hasUpper = /[A-Z]/.test(password);
                const hasNumbers = /\d/.test(password);
                const hasSpecial = /[!@#$%^&*]/.test(password);

                const criteria = [hasLower, hasUpper, hasNumbers, hasSpecial];
                const metCriteria = criteria.filter(Boolean).length;

                if (metCriteria === 1) {
                    strength = 33;
                    text = 'Слабый';
                    className = 'weak';
                } else if (metCriteria === 2 || metCriteria === 3) {
                    strength = 66;
                    text = 'Средний';
                    className = 'medium';
                } else if (metCriteria === 4) {
                    strength = 100;
                    text = 'Надежный';
                    className = 'strong';
                }
            }

            strengthContainer.className = `password-strength ${className}`;
            strengthBar.style.width = `${strength}%`;
            strengthText.textContent = text;
        }

        // Обновление предпросмотра аватара
        function updateAvatarPreview() {
            const avatarUrl = document.getElementById('avatar-url');
            const previewImg = document.getElementById('avatar-preview');
            const defaultAvatar = document.getElementById('default-avatar');

            if (!avatarUrl || !previewImg || !defaultAvatar) return;

            if (avatarUrl.value && isValidUrl(avatarUrl.value)) {
                previewImg.src = avatarUrl.value;
                previewImg.classList.add('show');
                defaultAvatar.style.display = 'none';
            } else {
                previewImg.classList.remove('show');
                defaultAvatar.style.display = 'flex';
            }
        }

        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        // Обработка отправки формы
        const form = document.getElementById('create-admin-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const email = document.querySelector('input[name="email"]').value;
                const password = document.getElementById('password').value;
                const emailVerified = document.getElementById('email_verified').checked;

                if (!email || !password) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Ошибка',
                        text: 'Заполните обязательные поля',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    return;
                }

                // Показываем подтверждение
                e.preventDefault();

                let message = 'Вы уверены, что хотите создать первого администратора системы?';

                if (!emailVerified) {
                    message += '\n\nНа email будет отправлено письмо с кодом подтверждения.';
                    message += '\nПосле получения письма перейдите по ссылке в письме для подтверждения email.';
                }

                Swal.fire({
                    title: 'Создание администратора',
                    html: message.replace(/\n/g, '<br>'),
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Да, создать',
                    cancelButtonText: 'Отмена',
                    confirmButtonColor: '#0ea5e9',
                    cancelButtonColor: '#ef4444'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Показываем загрузку
                        Swal.fire({
                            title: 'Создание...',
                            html: '<div style="text-align: center;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom: 20px;"></i><p>Создаем учетную запись администратора...</p></div>',
                            showConfirmButton: false,
                            allowOutsideClick: false
                        });

                        // Отправляем форму
                        this.submit();
                    }
                });
            });
        }

        // Автозаполнение полей на основе email
        const emailField = document.querySelector('input[name="email"]');
        if (emailField) {
            emailField.addEventListener('blur', function() {
                const email = this.value;
                const nameField = document.querySelector('input[name="first_name"]');
                const lastNameField = document.querySelector('input[name="last_name"]');

                // Если поля пустые и email содержит имя
                if (email && nameField && lastNameField && (!nameField.value || !lastNameField.value)) {
                    const nameFromEmail = email.split('@')[0];
                    if (nameFromEmail && nameFromEmail.includes('.')) {
                        const parts = nameFromEmail.split('.');
                        if (!nameField.value && parts[0]) {
                            nameField.value = capitalizeFirstLetter(parts[0]);
                        }
                        if (!lastNameField.value && parts[1]) {
                            lastNameField.value = capitalizeFirstLetter(parts[1]);
                        }
                    }
                }
            });
        }

        function capitalizeFirstLetter(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }
    </script>
</body>
</html>