<?php
session_start();

// Уничтожаем сессию
session_unset();
session_destroy();

// Перенаправляем на главную
header('Location: /');
exit;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        <?php include '../login/css/logout_styles.css'; ?>
    </style>
</head>
<body>
    <!-- Шапка (как на главной) -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
                <!--<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgNTAiPjx0ZXh0IHg9IjEwIiB5PSIzNSIgZm9udC1mYW1pbHk9IlBvcHBpbnMiIGZvbnQtc2l6ZT0iMzAiIGZpbGw9InVybCgjbG9nby1ncmFkaWVudCkiPkhvbWVWbGFkPC90ZXh0PjxsaW5lYXJHcmFkaWVudCBpZD0ibG9nby1ncmFkaWVudCI+PHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iIzZjNWNlNyIvPjxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzAwY2VjOSIvPjwvbGluZWFyR3JhZGllbnQ+PC9zdmc+" 
                     alt="HomeVlad" class="logo-img">-->
                <span class="logo-text">HomeVlad Cloud</span>
            </a>
        </div>
    </header>

    <!-- Основное содержимое -->
    <main class="main-content">
        <div class="logout-message">
            <div class="logout-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h2 class="logout-title">Вы вышли из системы</h2>
            <p class="logout-text">Спасибо, что воспользовались нашим сервисом. Хотите войти снова?</p>
            <a href="/login/login.php" class="btn-main">
                <i class="fas fa-sign-in-alt"></i> Войти
            </a>
        </div>
    </main>

    <!-- Футер (как на главной) -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 HomeVlad. Все права защищены.</p>
        </div>
    </footer>
</body>
</html>