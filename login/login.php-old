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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        <?php include '../login/css/login_styles.css'; ?>
    </style>
</head>
<body>
    <!-- Шапка -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
                <!--<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgNTAiPjx0ZXh0IHg9IjEwIiB5PSIzNSIgZm9udC1mYW1pbHk9IlBvcHBpbnMiIGZvbnQtc2l6ZT0iMzAiIGZpbGw9InVybCgjbG9nby1ncmFkaWVudCkiPkhvbWVWbGFkPC90ZXh0PjxsaW5lYXJHcmFkaWVudCBpZD0ibG9nby1ncmFkaWVudCI+PHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iIzZjNWNlNyIvPjxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzAwY2VjOSIvPjwvbGluZWFyR3JhZGllbnQ+PC9zdmc+" 
                     alt="HomeVlad" class="logo-img">-->
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
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <div class="auth-card">
            <h2 class="auth-title">
                <i class="fas fa-sign-in-alt"></i> Вход в систему
            </h2>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Пароль</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                    <div class="forgot-password">
                        <a href="/login/reset-password.php" class="auth-link">Забыли пароль?</a>
                    </div>
                </div>
                
                <div class="captcha-box">
                    Введите результат: <?= $_SESSION['captcha_question'] ?> = ?
                    <input type="number" name="captcha" class="form-control" required style="margin-top: 10px;">
                </div>
                
                <button type="submit" class="btn-auth">
                    <i class="fas fa-unlock-alt"></i> Войти
                </button>
                
                <div class="auth-footer">
                    Нет аккаунта? <a href="/login/register.php" class="auth-link">Создайте его</a>
                </div>
            </form>
        </div>
    </main>

    <!-- Футер -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 HomeVlad. Все права защищены.</p>
        </div>
    </footer>
</body>
</html>