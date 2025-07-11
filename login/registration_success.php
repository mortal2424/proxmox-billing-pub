<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['registration_success'])) {
    header('Location: register.php');
    exit;
}

$welcome_bonus = 3000; // Размер приветственного бонуса
$title = "Регистрация завершена | HomeVlad Cloud";

// Получаем ID пользователя из сессии (если нужно)
$user_id = $_SESSION['user_id'] ?? null;

unset($_SESSION['registration_success']);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        <?php include '../login/css/registration_success_styles.css'; ?>
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
                <span class="logo-text">HomeVlad</span>
            </a>
            <div class="nav-links">
                <a href="/" class="btn-auth">
                    <i class="fas fa-home"></i> На главную
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="auth-card">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 class="auth-title">Регистрация завершена!</h2>
            
            <p>Ваш аккаунт успешно создан. Теперь вы можете войти в систему, используя указанные email и пароль.</p>
            
            <div class="bonus-card">
                <div class="bonus-text">Вам начислен приветственный бонус:</div>
                <div class="bonus-amount">+<?= number_format($welcome_bonus, 0, '', ' ') ?> ₽</div>
                <div class="bonus-text">Эти средства уже доступны в вашем личном кабинете</div>
            </div>
            
            <div class="btn-container">
                <a href="/login/login.php" class="btn-auth">
                    <i class="fas fa-sign-in-alt"></i> Войти в аккаунт
                </a>
                <a href="/templates/dashboard.php" class="btn-auth btn-secondary">
                    <i class="fas fa-wallet"></i> Перейти к балансу
                </a>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> HomeVlad. Все права защищены.</p>
        </div>
    </footer>
</body>
</html>