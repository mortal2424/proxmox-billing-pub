<!DOCTYPE html>
<html>
<head>
    <title>Proxmox Billing</title>
    <link rel="stylesheet" href="/static/style.css">
</head>
<body>
    <header>
        <nav>
            <a href="/">Главная</a>
            <?php if (isset($_SESSION['user'])): ?>
                <a href="/dashboard.php">Кабинет</a>
                <a href="/logout.php">Выйти</a>
            <?php else: ?>
                <a href="/login.php">Войти</a>
                <a href="/register.php">Регистрация</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>