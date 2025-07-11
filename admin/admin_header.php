<?php
if (!isset($title)) {
    $title = 'Админ панель | HomeVlad Cloud';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/admin/css/admin_style.css">
    <link rel="stylesheet" href="/css/themes.css"> <!-- Подключаем общие стили тем -->
</head>
<body>
    <!-- Шапка админки -->
    <header class="admin-header">
        <div class="container">
            <div class="header-left">
                <a href="/admin/" class="logo">
                    <!--<img src="../img/logo.png" alt="HomeVlad" width="100">-->
                    <span class="logo-text">HomeVlad Cloud Admin Panel</span>
                </a>
            </div>
                <div class="theme-switcher">
                    <button id="themeToggle" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-moon"></i> Тёмная тема
                    </button>
                </div>
                <div class="admin-nav">
                    <a href="/" class="admin-nav-btn">
                        <i class="fas fa-home"></i> На сайт
                    </a>
                    <a href="/templates/logout.php" class="admin-nav-btn admin-nav-btn-danger">
                        <i class="fas fa-sign-out-alt"></i> Выйти
                    </a>
                </div>
            </div>
        </div>
    </header>

    <script>
    // Функция переключения темы
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggle = document.getElementById('themeToggle');
        const currentTheme = localStorage.getItem('theme') || 'light';

        // Применяем сохранённую тему
        if (currentTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            updateToggleButton('dark');
        }

        // Обработчик кнопки
        themeToggle.addEventListener('click', function() {
            const theme = document.documentElement.getAttribute('data-theme');
            if (theme === 'dark') {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
                updateToggleButton('light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                updateToggleButton('dark');
            }
        });

        function updateToggleButton(theme) {
            if (theme === 'dark') {
                themeToggle.innerHTML = '<i class="fas fa-sun"></i> Светлая тема';
            } else {
                themeToggle.innerHTML = '<i class="fas fa-moon"></i> Тёмная тема';
            }
        }
    });
    </script>
</body>
</html>