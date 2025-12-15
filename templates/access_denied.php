<?php
session_start();

// Если пользователь авторизован, получаем его данные
$user = isset($_SESSION['user']) ? $_SESSION['user'] : null;

$title = "Доступ запрещен | HomeVlad Cloud";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="/img/cloud.png" type="image/png">
    <style>
        :root {
            --primary: #00bcd4;
            --primary-dark: #0097a7;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --success: #10b981;
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --gray-dark: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--darker) 0%, var(--dark) 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Фоновые эффекты */
        .bg-effects {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-particle {
            position: absolute;
            background: rgba(0, 188, 212, 0.1);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }

        .bg-particle:nth-child(1) {
            width: 300px;
            height: 300px;
            top: -100px;
            left: -100px;
            animation-delay: 0s;
        }

        .bg-particle:nth-child(2) {
            width: 200px;
            height: 200px;
            top: 50%;
            right: -50px;
            background: rgba(239, 68, 68, 0.1);
            animation-delay: 5s;
        }

        .bg-particle:nth-child(3) {
            width: 150px;
            height: 150px;
            bottom: -50px;
            left: 30%;
            background: rgba(245, 158, 11, 0.1);
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Основной контейнер */
        .access-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .access-card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 60px 50px;
            max-width: 800px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transform: translateY(0);
            animation: cardAppear 0.6s ease-out;
            position: relative;
            overflow: hidden;
        }

        .access-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--danger), var(--warning));
        }

        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Иконка */
        .access-icon {
            font-size: 120px;
            margin-bottom: 30px;
            display: inline-block;
            position: relative;
        }

        .access-icon i {
            color: var(--danger);
            filter: drop-shadow(0 10px 20px rgba(239, 68, 68, 0.3));
        }

        .access-icon::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.2) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: -1;
        }

        /* Заголовок */
        .access-title {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--danger), var(--warning));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1.2;
        }

        .access-subtitle {
            font-size: 20px;
            color: var(--gray);
            margin-bottom: 40px;
            line-height: 1.6;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Коды ошибок */
        .error-codes {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .error-code {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 12px 24px;
            font-size: 14px;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .error-code:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.2);
        }

        .error-code i {
            font-size: 16px;
        }

        /* Сообщение */
        .access-message {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 40px;
            text-align: left;
        }

        .access-message p {
            margin-bottom: 15px;
            color: #cbd5e1;
            line-height: 1.6;
        }

        .access-message ul {
            margin: 15px 0 15px 20px;
            color: #94a3b8;
        }

        .access-message li {
            margin-bottom: 8px;
        }

        /* Кнопки действий */
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: none;
            min-width: 180px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.1);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(239, 68, 68, 0.4);
        }

        /* Информация о пользователе */
        .user-info {
            background: rgba(30, 41, 59, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            border-left: 4px solid var(--primary);
        }

        .user-info h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info p {
            color: #94a3b8;
            margin-bottom: 5px;
        }

        /* Футер */
        .access-footer {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--gray);
            font-size: 14px;
        }

        .access-footer a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .access-footer a:hover {
            color: white;
            text-decoration: underline;
        }

        /* Анимации */
        @keyframes pulse {
            0% { opacity: 0.7; }
            50% { opacity: 1; }
            100% { opacity: 0.7; }
        }

        .pulse {
            animation: pulse 2s infinite ease-in-out;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .access-card {
                padding: 40px 25px;
                margin: 20px;
            }

            .access-title {
                font-size: 36px;
            }

            .access-icon {
                font-size: 90px;
            }

            .access-subtitle {
                font-size: 18px;
                padding: 0 10px;
            }

            .btn {
                min-width: 160px;
                padding: 14px 24px;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .error-codes {
                flex-direction: column;
                align-items: center;
            }
        }

        @media (max-width: 480px) {
            .access-title {
                font-size: 28px;
            }

            .access-icon {
                font-size: 70px;
            }

            .access-card {
                padding: 30px 20px;
            }
        }

        /* Дополнительные эффекты */
        .glow {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background: radial-gradient(circle at 50% 0%, rgba(239, 68, 68, 0.1), transparent 50%);
            pointer-events: none;
        }
    </style>
</head>
<body>
    <!-- Фоновые эффекты -->
    <div class="bg-effects">
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="glow"></div>
    </div>

    <!-- Основной контент -->
    <div class="access-container">
        <div class="access-card">
            <!-- Иконка -->
            <div class="access-icon pulse">
                <i class="fas fa-ban"></i>
            </div>

            <!-- Заголовок -->
            <h1 class="access-title">Доступ запрещен</h1>
            <p class="access-subtitle">
                У вас недостаточно прав для доступа к запрошенной странице или выполнению этого действия.
            </p>

            <!-- Коды ошибок -->
            <div class="error-codes">
                <div class="error-code">
                    <i class="fas fa-shield-alt"></i>
                    <span>Ошибка 403</span>
                </div>
                <div class="error-code">
                    <i class="fas fa-lock"></i>
                    <span>Недостаточно прав</span>
                </div>
                <div class="error-code">
                    <i class="fas fa-user-times"></i>
                    <span>Доступ отклонен</span>
                </div>
            </div>

            <!-- Сообщение -->
            <div class="access-message">
                <p><strong>Возможные причины:</strong></p>
                <ul>
                    <li>У вашей учетной записи недостаточно прав для доступа к этому разделу</li>
                    <li>Вы пытаетесь получить доступ к административной панели без прав администратора</li>
                    <li>Сессия истекла или вы не авторизованы</li>
                    <li>Доступ к ресурсу ограничен по другим причинам</li>
                </ul>
                <p><strong>Что можно сделать:</strong></p>
                <ul>
                    <li>Обратитесь к администратору системы для получения необходимых прав</li>
                    <li>Убедитесь, что вы авторизованы под правильной учетной записью</li>
                    <li>Проверьте, не истекла ли ваша сессия</li>
                </ul>
            </div>

            <!-- Информация о пользователе (если авторизован) -->
            <?php if ($user): ?>
                <div class="user-info">
                    <h3><i class="fas fa-user-circle"></i> Информация о текущей сессии</h3>
                    <p><strong>ID пользователя:</strong> <?= htmlspecialchars($user['id'] ?? 'N/A') ?></p>
                    <p><strong>Имя пользователя:</strong> <?= htmlspecialchars($user['username'] ?? 'N/A') ?></p>
                    <p><strong>Роль:</strong> <?= htmlspecialchars($user['role'] ?? 'Пользователь') ?></p>
                    <?php if (isset($user['email'])): ?>
                        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Кнопки действий -->
            <div class="action-buttons">
                <a href="/index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> На главную
                </a>
                
                <?php if ($user): ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a href="/admin/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-tachometer-alt"></i> Админ панель
                        </a>
                    <?php else: ?>
                        <a href="/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-tachometer-alt"></i> Личный кабинет
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="/login.php" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt"></i> Войти в систему
                    </a>
                <?php endif; ?>
                
                <a href="javascript:history.back()" class="btn">
                    <i class="fas fa-arrow-left"></i> Вернуться назад
                </a>
            </div>

            <!-- Футер -->
            <div class="access-footer">
                <p>Если вы считаете, что получили это сообщение по ошибке, пожалуйста, <a href="/support.php">свяжитесь с поддержкой</a>.</p>
                <p style="margin-top: 10px;">© <?= date('Y') ?> HomeVlad Cloud. Все права защищены.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Добавляем эффект наведения на кнопки
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Добавляем случайные частицы при клике
            document.addEventListener('click', function(e) {
                const particle = document.createElement('div');
                particle.style.position = 'fixed';
                particle.style.width = '20px';
                particle.style.height = '20px';
                particle.style.background = 'radial-gradient(circle, rgba(239, 68, 68, 0.3) 0%, transparent 70%)';
                particle.style.borderRadius = '50%';
                particle.style.pointerEvents = 'none';
                particle.style.left = e.clientX + 'px';
                particle.style.top = e.clientY + 'px';
                particle.style.zIndex = '9999';
                particle.style.animation = 'fadeOut 1s forwards';
                
                document.body.appendChild(particle);
                
                setTimeout(() => {
                    if (particle.parentNode) {
                        particle.parentNode.removeChild(particle);
                    }
                }, 1000);
            });

            // Добавляем стиль для анимации fadeOut
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeOut {
                    0% { opacity: 1; transform: scale(1); }
                    100% { opacity: 0; transform: scale(2); }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>