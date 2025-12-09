<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
redirectIfLoggedIn();

// Создаем экземпляр Database
try {
    $db = new Database();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

$current_step = 1;
$errors = [];
$success = false;
$telegram_data = [];

// Обработка данных из Telegram Widget
if (isset($_POST['auth_date'])) {
    $telegram_data = [
        'id' => $_POST['id'],
        'first_name' => $_POST['first_name'] ?? '',
        'last_name' => $_POST['last_name'] ?? '',
        'username' => $_POST['username'] ?? '',
        'photo_url' => $_POST['photo_url'] ?? '',
        'auth_date' => $_POST['auth_date'],
        'hash' => $_POST['hash']
    ];

    // Проверяем подлинность данных Telegram
    if (verifyTelegramAuthorization($telegram_data)) {
        // Проверяем, не зарегистрирован ли уже этот Telegram аккаунт
        $stmt = $db->getConnection()->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_data['id']]);
        
        if ($stmt->fetch()) {
            $errors[] = "Этот Telegram аккаунт уже привязан к другому пользователю!";
        } else {
            // Если проверка прошла, переходим сразу к шагу 2
            $_SESSION['telegram_data'] = $telegram_data;
            $current_step = 2;
        }
    } else {
        $errors[] = "Не удалось проверить подлинность данных Telegram. Попробуйте еще раз.";
    }
}

// Обработка первого шага регистрации (если не через Telegram)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1 && empty($telegram_data)) {
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Валидация
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Некорректный email адрес!";
    }
    
    if (!validatePhone($phone)) {
        $errors[] = "Номер телефона должен быть в формате +7XXXXXXXXXX (11 цифр)";
    }
    
    if (strlen($password) < 8) {
        $errors[] = "Пароль должен быть не менее 8 символов!";
    } elseif (!preg_match("/[A-Z]/", $password)) {
        $errors[] = "Пароль должен содержать хотя бы одну заглавную букву!";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Пароли не совпадают!";
    }

    if (empty($errors)) {
        try {
            // Проверка существования email
            $stmt = $db->getConnection()->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $errors[] = "Email уже зарегистрирован!";
            } else {
                // Генерация кода подтверждения
                $verification_code = generateVerificationCode();
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                
                // Сохраняем данные в сессии
                $_SESSION['register_data'] = [
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => $password_hash,
                    'verification_code' => $verification_code,
                    'verification_sent_at' => time()
                ];
                
                // Отправляем код на email
                if (!sendVerificationEmail($email, $verification_code)) {
                    $errors[] = "Не удалось отправить код подтверждения. Попробуйте позже.";
                } else {
                    $current_step = 2;
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Ошибка при проверке email: " . $e->getMessage();
        }
    }
}

// Обработка повторной отправки кода
if (isset($_GET['resend']) && $_GET['resend'] == '1' && isset($_SESSION['register_data'])) {
    $last_sent = $_SESSION['register_data']['verification_sent_at'] ?? 0;
    if (time() - $last_sent < 60) {
        $errors[] = "Повторный код можно запросить не чаще чем раз в минуту.";
    } else {
        $new_code = generateVerificationCode();
        $_SESSION['register_data']['verification_code'] = $new_code;
        $_SESSION['register_data']['verification_sent_at'] = time();
        
        if (sendVerificationEmail($_SESSION['register_data']['email'], $new_code)) {
            $errors[] = "Новый код подтверждения отправлен на ваш email.";
        } else {
            $errors[] = "Не удалось отправить код. Попробуйте позже.";
        }
    }
}

// Обработка второго шага (подтверждение email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 2) {
    if (isset($_SESSION['telegram_data'])) {
        // Если регистрация через Telegram, пропускаем подтверждение email
        $current_step = 3;
    } elseif (!isset($_SESSION['register_data'])) {
        $errors[] = "Сессия истекла. Пожалуйста, начните регистрацию заново.";
        $current_step = 1;
    } else {
        $user_code = trim($_POST['verification_code']);
        $stored_code = $_SESSION['register_data']['verification_code'] ?? '';
        
        if ($user_code != $stored_code) {
            $errors[] = "Неверный код подтверждения!";
        } elseif (time() - ($_SESSION['register_data']['verification_sent_at'] ?? 0) > 3600) {
            $errors[] = "Код подтверждения истек. Запросите новый.";
        } else {
            $current_step = 3;
        }
    }
}

// Обработка третьего шага (доп. информация)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 3) {
    if (isset($_SESSION['telegram_data'])) {
        // Регистрация через Telegram
        $telegram_data = $_SESSION['telegram_data'];
        $user_type = $_POST['user_type'];
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        // Валидация
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный email адрес!";
        }
        
        if (!validatePhone($phone)) {
            $errors[] = "Номер телефона должен быть в формате +7XXXXXXXXXX (11 цифр)";
        }
        
        if ($user_type != 'individual') {
            $company_name = trim($_POST['company_name'] ?? '');
            $inn = trim($_POST['inn'] ?? '');
            
            if (empty($company_name)) {
                $errors[] = "Название компании обязательно!";
            }
            
            if (empty($inn) || !preg_match('/^\d{10,12}$/', $inn)) {
                $errors[] = "ИНН должен содержать 10 или 12 цифр!";
            }
            
            if ($user_type == 'legal' && !isset($_POST['kpp'])) {
                $errors[] = "КПП обязателен для юридических лиц!";
            }
        }

        if (empty($errors)) {
            try {
                $db->getConnection()->beginTransaction();
                
                // Генерация случайного пароля для Telegram-пользователей
                $password = bin2hex(random_bytes(8));
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                
                // Создаем пользователя
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO users 
                    (email, phone, password_hash, first_name, last_name, user_type, 
                     company_name, inn, kpp, email_verified, bonus_balance, telegram_id, telegram_username) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
                ");
                
                $bonus_amount = 3000; // Размер приветственного бонуса
                
                $stmt->execute([
                    $email,
                    $phone,
                    $password_hash,
                    $telegram_data['first_name'],
                    $telegram_data['last_name'],
                    $user_type,
                    $company_name ?? null,
                    $inn ?? null,
                    $kpp ?? null,
                    $bonus_amount,
                    $telegram_data['id'],
                    $telegram_data['username'] ?? null
                ]);
                
                $user_id = $db->getConnection()->lastInsertId();
                
                // Записываем операцию в историю
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO balance_history 
                    (user_id, amount, operation_type, description) 
                    VALUES (?, ?, 'bonus', 'Приветственный бонус за регистрацию')
                ");
                $stmt->execute([$user_id, $bonus_amount]);
                
                // Создаем квоты для пользователя
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO user_quotas 
                    (user_id, max_vms, max_cpu, max_ram, max_disk) 
                    VALUES (?, 3, 10, 10240, 200)
                ");
                $stmt->execute([$user_id]);
                
                // Отправляем приветственное сообщение в Telegram
                $telegram_message = "🎉 Добро пожаловать в HomeVlad Cloud, {$telegram_data['first_name']}!\n\n" .
                                   "Ваш аккаунт успешно зарегистрирован.\n" .
                                   "Email: $email\n" .
                                   "Телефон: $phone\n" .
                                   "Пароль: $password\n\n" .
                                   "Для управления серверами используйте наш бот: @HomeVladCloud_Bot";
                
                sendTelegramMessage($telegram_data['id'], $telegram_message);
                
                $db->getConnection()->commit();
                unset($_SESSION['telegram_data']);
                
                // Сохраняем информацию для отображения на странице успеха
                $_SESSION['registration_success'] = true;
                $_SESSION['welcome_bonus'] = $bonus_amount;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['telegram_registration'] = true;
                
                header('Location: registration_success.php');
                exit;
                
            } catch (PDOException $e) {
                $db->getConnection()->rollBack();
                $errors[] = "Ошибка при регистрации: " . $e->getMessage();
            }
        }
    } elseif (!isset($_SESSION['register_data'])) {
        $errors[] = "Сессия истекла. Пожалуйста, начните регистрацию заново.";
        $current_step = 1;
    } else {
        $user_type = $_POST['user_type'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        
        // Валидация
        if (empty($first_name) || empty($last_name)) {
            $errors[] = "Имя и фамилия обязательны для заполнения!";
        }
        
        if ($user_type != 'individual') {
            $company_name = trim($_POST['company_name'] ?? '');
            $inn = trim($_POST['inn'] ?? '');
            
            if (empty($company_name)) {
                $errors[] = "Название компании обязательно!";
            }
            
            if (empty($inn) || !preg_match('/^\d{10,12}$/', $inn)) {
                $errors[] = "ИНН должен содержать 10 или 12 цифр!";
            }
            
            if ($user_type == 'legal' && !isset($_POST['kpp'])) {
                $errors[] = "КПП обязателен для юридических лиц!";
            }
        }

        if (empty($errors)) {
            try {
                $db->getConnection()->beginTransaction();
                
                // Создаем пользователя
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO users 
                    (email, phone, password_hash, first_name, last_name, user_type, 
                     company_name, inn, kpp, email_verified, bonus_balance) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                
                $bonus_amount = 3000;
                
                $stmt->execute([
                    $_SESSION['register_data']['email'],
                    $_SESSION['register_data']['phone'],
                    $_SESSION['register_data']['password_hash'],
                    $first_name,
                    $last_name,
                    $user_type,
                    $company_name ?? null,
                    $inn ?? null,
                    $kpp ?? null,
                    $bonus_amount
                ]);
                
                $user_id = $db->getConnection()->lastInsertId();
                
                // Записываем операцию в историю
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO balance_history 
                    (user_id, amount, operation_type, description) 
                    VALUES (?, ?, 'bonus', 'Приветственный бонус за регистрацию')
                ");
                $stmt->execute([$user_id, $bonus_amount]);
                
                // Создаем квоты для пользователя
                $stmt = $db->getConnection()->prepare("
                    INSERT INTO user_quotas 
                    (user_id, max_vms, max_cpu, max_ram, max_disk) 
                    VALUES (?, 3, 10, 10240, 200)
                ");
                $stmt->execute([$user_id]);
                
                $db->getConnection()->commit();
                unset($_SESSION['register_data']);
                
                // Сохраняем информацию для отображения на странице успеха
                $_SESSION['registration_success'] = true;
                $_SESSION['welcome_bonus'] = $bonus_amount;
                $_SESSION['user_id'] = $user_id;
                
                header('Location: registration_success.php');
                exit;
                
            } catch (PDOException $e) {
                $db->getConnection()->rollBack();
                $errors[] = "Ошибка при регистрации: " . $e->getMessage();
            }
        }
    }
}

// Функция для проверки данных Telegram
function verifyTelegramAuthorization($auth_data) {
    $bot_token = '7733127948:AAHzUlwbL0Iw0dK-0h4d3KJXZDNA9aa6spo'; // Замените на токен вашего бота
    
    $check_hash = $auth_data['hash'];
    unset($auth_data['hash']);
    
    $data_check_arr = [];
    foreach ($auth_data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }
    
    sort($data_check_arr);
    $data_check_string = implode("\n", $data_check_arr);
    
    $secret_key = hash('sha256', $bot_token, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);
    
    if (strcmp($hash, $check_hash) !== 0) {
        return false;
    }
    
    if ((time() - $auth_data['auth_date']) > 86400) {
        return false;
    }
    
    return true;
}

// Функция для отправки сообщения в Telegram
function sendTelegramMessage($chat_id, $message) {
    $bot_token = '7733127948:AAHzUlwbL0Iw0dK-0h4d3KJXZDNA9aa6spo'; // Замените на токен вашего бота
    $api_url = "https://api.telegram.org/bot$bot_token/sendMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($api_url, false, $context);
    
    return $result !== false;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация | HomeVlad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <script src="https://telegram.org/js/telegram-widget.js?19" data-telegram-login="YOUR_TELEGRAM_BOT_NAME" data-size="large" data-onauth="onTelegramAuth(user)" data-request-access="write"></script>
    <style>
        <?php include '../login/css/register_styles.css'; ?>
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
            <div class="registration-steps">
                <div class="step <?= $current_step == 1 ? 'active' : ($current_step > 1 ? 'completed' : '') ?>">
                    <div class="step-number">1</div>
                    <div class="step-title"><?= isset($_SESSION['telegram_data']) ? 'Telegram' : 'Основные данные' ?></div>
                </div>
                <div class="step <?= $current_step == 2 ? 'active' : ($current_step > 2 ? 'completed' : '') ?>">
                    <div class="step-number">2</div>
                    <div class="step-title"><?= isset($_SESSION['telegram_data']) ? 'Данные' : 'Подтверждение' ?></div>
                </div>
                <div class="step <?= $current_step == 3 ? 'active' : '' ?>">
                    <div class="step-number">3</div>
                    <div class="step-title">Доп. информация</div>
                </div>
            </div>
            
            <h2 class="auth-title">
                <?php if ($current_step == 1): ?>
                    <i class="fas fa-user-plus"></i> Регистрация
                <?php elseif ($current_step == 2): ?>
                    <?php if (isset($_SESSION['telegram_data'])): ?>
                        <i class="fab fa-telegram"></i> Данные из Telegram
                    <?php else: ?>
                        <i class="fas fa-envelope"></i> Подтверждение email
                    <?php endif; ?>
                <?php else: ?>
                    <i class="fas fa-info-circle"></i> Дополнительная информация
                <?php endif; ?>
            </h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($current_step == 1): ?>
                <div class="telegram-login-container">
                    <script async src="https://telegram.org/js/telegram-widget.js?19" 
                            data-telegram-login="homevlad_support_bot" 
                            data-size="large" 
                            data-onauth="onTelegramAuth(user)" 
                            data-request-access="write"></script>
                    <div class="telegram-divider">или</div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="step" value="1">
                    
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control" required 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone" class="form-label">Номер телефона</label>
                        <input type="tel" id="phone" name="phone" class="form-control" required 
                               placeholder="+7XXXXXXXXXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Пароль</label>
                        <input type="password" id="password" name="password" class="form-control" required
                               placeholder="Не менее 8 символов, с заглавной буквой">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Подтвердите пароль</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <i class="fas fa-arrow-right"></i> Продолжить
                    </button>
                    
                    <div class="auth-footer">
                        Уже есть аккаунт? <a href="/login/login.php" class="auth-link">Войти</a>
                    </div>
                </form>
            
            <?php elseif ($current_step == 2): ?>
                <?php if (isset($_SESSION['telegram_data'])): ?>
                    <div class="telegram-user-info">
                        <?php if (!empty($_SESSION['telegram_data']['photo_url'])): ?>
                            <img src="<?= htmlspecialchars($_SESSION['telegram_data']['photo_url']) ?>" alt="Telegram Photo" class="telegram-photo">
                        <?php endif; ?>
                        <h3><?= htmlspecialchars($_SESSION['telegram_data']['first_name'] . ' ' . htmlspecialchars($_SESSION['telegram_data']['last_name'])) ?></h3>
                        <?php if (!empty($_SESSION['telegram_data']['username'])): ?>
                            <p>@<?= htmlspecialchars($_SESSION['telegram_data']['username']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" class="telegram-registration">
                        <input type="hidden" name="step" value="3">
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Номер телефона</label>
                            <input type="tel" id="phone" name="phone" class="form-control" required 
                                   placeholder="+7XXXXXXXXXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                        
                        <div class="user-type-selector">
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="individual" 
                                       <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'checked' : '' ?> required>
                                <div class="user-type-icon"><i class="fas fa-user"></i></div>
                                <div>Физическое лицо</div>
                            </label>
                            
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? '') == 'entrepreneur' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="entrepreneur"
                                       <?= ($_POST['user_type'] ?? '') == 'entrepreneur' ? 'checked' : '' ?>>
                                <div class="user-type-icon"><i class="fas fa-user-tie"></i></div>
                                <div>ИП</div>
                            </label>
                            
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? '') == 'legal' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="legal"
                                       <?= ($_POST['user_type'] ?? '') == 'legal' ? 'checked' : '' ?>>
                                <div class="user-type-icon"><i class="fas fa-building"></i></div>
                                <div>Юр. лицо</div>
                            </label>
                        </div>
                        
                        <button type="submit" class="btn-auth">
                            <i class="fas fa-arrow-right"></i> Продолжить
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="step" value="2">
                        
                        <div class="alert alert-success">
                            <p>Код подтверждения отправлен на email <strong><?= htmlspecialchars($_SESSION['register_data']['email'] ?? '') ?></strong>.</p>
                            <p>Введите 6-значный код из письма:</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="verification_code" class="form-label">Код подтверждения</label>
                            <input type="text" id="verification_code" name="verification_code" class="form-control" required
                                   placeholder="XXXXXX" maxlength="6" pattern="\d{6}">
                        </div>
                        
                        <button type="submit" class="btn-auth">
                            <i class="fas fa-check"></i> Подтвердить
                        </button>
                        
                        <div class="auth-footer">
                            Не получили код? <a href="?resend=1" class="auth-link" id="resend-code">Отправить повторно</a>
                        </div>
                    </form>
                <?php endif; ?>
            
            <?php elseif ($current_step == 3): ?>
                <form method="POST">
                    <input type="hidden" name="step" value="3">
                    
                    <?php if (!isset($_SESSION['telegram_data'])): ?>
                        <div class="user-type-selector">
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="individual" 
                                       <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'checked' : '' ?> required>
                                <div class="user-type-icon"><i class="fas fa-user"></i></div>
                                <div>Физическое лицо</div>
                            </label>
                            
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? '') == 'entrepreneur' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="entrepreneur"
                                       <?= ($_POST['user_type'] ?? '') == 'entrepreneur' ? 'checked' : '' ?>>
                                <div class="user-type-icon"><i class="fas fa-user-tie"></i></div>
                                <div>ИП</div>
                            </label>
                            
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? '') == 'legal' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="legal"
                                       <?= ($_POST['user_type'] ?? '') == 'legal' ? 'checked' : '' ?>>
                                <div class="user-type-icon"><i class="fas fa-building"></i></div>
                                <div>Юр. лицо</div>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="first_name" class="form-label">Имя</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" required
                                   value="<?= isset($_SESSION['telegram_data']) ? htmlspecialchars($_SESSION['telegram_data']['first_name']) : htmlspecialchars($_POST['first_name'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name" class="form-label">Фамилия</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" required
                                   value="<?= isset($_SESSION['telegram_data']) ? htmlspecialchars($_SESSION['telegram_data']['last_name']) : htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    <?php endif; ?>
                    
                    <div id="company-fields" style="<?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'display: none;' : '' ?>">
                        <div class="form-group">
                            <label for="company_name" class="form-label">Название компании</label>
                            <input type="text" id="company_name" name="company_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="inn" class="form-label">ИНН</label>
                            <input type="text" id="inn" name="inn" class="form-control"
                                   value="<?= htmlspecialchars($_POST['inn'] ?? '') ?>">
                        </div>
                        
                        <div id="kpp-field" style="<?= ($_POST['user_type'] ?? '') != 'legal' ? 'display: none;' : '' ?>">
                            <div class="form-group">
                                <label for="kpp" class="form-label">КПП (только для юр. лиц)</label>
                                <input type="text" id="kpp" name="kpp" class="form-control"
                                       value="<?= htmlspecialchars($_POST['kpp'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <i class="fas fa-check-circle"></i> Завершить регистрацию
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

    <script>
        // Обработка данных из Telegram Widget
        function onTelegramAuth(user) {
            // Создаем скрытую форму и отправляем данные на сервер
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            for (const key in user) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = user[key];
                form.appendChild(input);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Управление выбором типа пользователя
        document.addEventListener('DOMContentLoaded', function() {
            // Обработка выбора типа аккаунта
            const userTypeBtns = document.querySelectorAll('.user-type-btn');
            
            userTypeBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Удаляем класс active у всех кнопок
                    userTypeBtns.forEach(b => b.classList.remove('active'));
                    // Добавляем класс active текущей кнопке
                    this.classList.add('active');
                    // Устанавливаем checked соответствующему radio
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    
                    // Показываем/скрываем дополнительные поля
                    updateCompanyFields();
                });
            });
            
            // Обновление полей компании при загрузке
            updateCompanyFields();
            
            // Маска для телефона
            const phoneInput = document.getElementById('phone');
            if (phoneInput) {
                phoneInput.addEventListener('input', function(e) {
                    let value = this.value.replace(/\D/g, '');
                    if (value.length > 0) {
                        value = '+7' + value.substring(1);
                    }
                    this.value = value.substring(0, 12);
                });
            }
            
            function updateCompanyFields() {
                const selectedType = document.querySelector('input[name="user_type"]:checked')?.value || 'individual';
                const companyFields = document.getElementById('company-fields');
                const kppField = document.getElementById('kpp-field');
                
                if (selectedType === 'individual') {
                    companyFields.style.display = 'none';
                } else {
                    companyFields.style.display = 'block';
                    kppField.style.display = selectedType === 'legal' ? 'block' : 'none';
                }
            }
        });
    </script>
</body>
</html>