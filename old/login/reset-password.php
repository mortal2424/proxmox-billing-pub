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
            
            $mail_subject = "Сброс пароля на HomeVlad";
            $mail_body = "
                <h2>Сброс пароля</h2>
                <p>Для сброса пароля перейдите по ссылке:</p>
                <p><a href='$reset_link'>$reset_link</a></p>
                <p>Ссылка действительна 1 час.</p>
                <p>Если вы не запрашивали сброс пароля, проигнорируйте это письмо.</p>
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
        }
    }
}

// Проверка токена при переходе по ссылке
if ($step === 2 && isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = urldecode($_GET['email']);
    
    $stmt = $pdo->prepare("
        SELECT email FROM password_resets 
        WHERE token = ? AND email = ? AND expires_at > NOW()
    ");
    $stmt->execute([$token, $email]);
    
    if (!$stmt->fetch()) {
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
    <title>Восстановление пароля | HomeVlad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        <?php include '../login/css/register_styles.css'; ?>
        
        .reset-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e0e0e0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
        }
        
        .step.active .step-number {
            background-color: #4e54c8;
            color: white;
        }
        
        .step.completed .step-number {
            background-color: #4CAF50;
            color: white;
        }
        
        .step-title {
            font-size: 12px;
            color: #666;
        }
        
        .step.active .step-title {
            color: #4e54c8;
            font-weight: bold;
        }
        
        .step.completed .step-title {
            color: #4CAF50;
        }
        
        .email-sent-message {
            text-align: center;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
                <span class="logo-text">HomeVlad</span>
            </a>
            <div class="nav-links">
                <a href="/" class="nav-btn nav-btn-secondary">
                    <i class="fas fa-home"></i> На главную
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="auth-card">
            <div class="reset-steps">
                <div class="step <?= $step == 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>">
                    <div class="step-number">1</div>
                    <div class="step-title">Ввод email</div>
                </div>
                <div class="step <?= $step == 2 ? 'active' : '' ?>">
                    <div class="step-number">2</div>
                    <div class="step-title">Новый пароль</div>
                </div>
            </div>
            
            <h2 class="auth-title">
                <i class="fas fa-key"></i> Восстановление пароля
            </h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <p>Пароль успешно изменен!</p>
                    <p>Теперь вы можете <a href="login.php" class="auth-link">войти</a> с новым паролем.</p>
                </div>
            <?php elseif ($email_sent): ?>
                <div class="email-sent-message">
                    <h3><i class="fas fa-check-circle text-success"></i> Ссылка отправлена</h3>
                    <p>Мы отправили ссылку для сброса пароля на указанный email.</p>
                    <p>Пожалуйста, проверьте вашу почту и следуйте инструкциям в письме.</p>
                    <p>Ссылка будет действительна в течение 1 часа.</p>
                </div>
                
                <div class="text-center">
                    <a href="reset-password.php" class="auth-link">
                        <i class="fas fa-arrow-left"></i> Вернуться назад
                    </a>
                </div>
            <?php elseif ($step == 1): ?>
                <form method="POST" action="reset-password.php">
                    <input type="hidden" name="step" value="1">
                    
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control" required 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <i class="fas fa-paper-plane"></i> Отправить ссылку
                    </button>
                    
                    <div class="auth-footer">
                        Вспомнили пароль? <a href="login.php" class="auth-link">Войти</a>
                    </div>
                </form>
            <?php elseif ($step == 2): ?>
                <form method="POST" action="reset-password.php?step=2">
                    <input type="hidden" name="step" value="2">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? '') ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($_GET['email'] ?? $_POST['email'] ?? '') ?>">
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Новый пароль</label>
                        <input type="password" id="password" name="password" class="form-control" required
                               placeholder="Не менее 8 символов, с заглавной буквой">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Подтвердите новый пароль</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <i class="fas fa-save"></i> Сохранить пароль
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> HomeVlad. Все права защищены.</p>
        </div>
    </footer>
</body>
</html>