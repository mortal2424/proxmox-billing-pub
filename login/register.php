<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
redirectIfLoggedIn();

// Функция для получения данных бота из базы данных
function getTelegramBotData() {
    try {
        $db = new Database();
        $stmt = $db->getConnection()->prepare("SELECT bot_token, bot_name FROM telegram_support_bot ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['bot_token'])) {
            // Убираем символ @ из начала bot_name, если он есть
            $bot_name = $result['bot_name'];
            if (strpos($bot_name, '@') === 0) {
                $bot_name = substr($bot_name, 1);
            }
            
            return [
                'token' => $result['bot_token'],
                'name' => $bot_name
            ];
        } else {
            error_log("No Telegram bot data found in database");
            return null;
        }
    } catch (Exception $e) {
        error_log("Error fetching Telegram bot data: " . $e->getMessage());
        return null;
    }
}

// Создаем экземпляр Database
try {
    $db = new Database();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Получаем данные бота
$bot_data = getTelegramBotData();
$bot_token = $bot_data ? $bot_data['token'] : null;
$telegram_bot_username = $bot_data ? $bot_data['name'] : null;

$current_step = 1;
$errors = [];
$success = false;
$telegram_data = [];

// Функция для проверки Telegram авторизации
function verifyTelegramAuthorization($auth_data) {
    global $bot_token;
    
    if (!$bot_token) {
        error_log("Telegram bot token is not available");
        return false;
    }

    $check_hash = $auth_data['hash'];
    unset($auth_data['hash']);

    $data_check_arr = [];
    foreach ($auth_data as $key => $value) {
        if (!empty($value)) {
            $data_check_arr[] = $key . '=' . $value;
        }
    }

    sort($data_check_arr);
    $data_check_string = implode("\n", $data_check_arr);

    $secret_key = hash('sha256', $bot_token, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);

    if (strcmp($hash, $check_hash) !== 0) {
        error_log("Telegram hash verification failed");
        return false;
    }

    if ((time() - $auth_data['auth_date']) > 86400) {
        error_log("Telegram auth data expired");
        return false;
    }

    return true;
}

// Функция для отправки сообщения в Telegram
function sendTelegramMessage($chat_id, $message) {
    global $bot_token;
    
    if (!$bot_token) {
        error_log("Telegram bot token is not available");
        return false;
    }
    
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
            $success_message = "Новый код подтверждения отправлен на ваш email.";
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация | HomeVlad Cloud</title>
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

        /* Карточка регистрации */
        .auth-container {
            width: 100%;
            max-width: 520px;
            position: relative;
            z-index: 2;
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
            animation: fadeInUp 0.8s ease forwards;
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
            background: var(--secondary-gradient);
            border-radius: 24px 24px 0 0;
        }

        /* Шаги регистрации */
        .registration-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }

        .registration-steps::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 15%;
            right: 15%;
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
            background: var(--secondary-gradient);
            border-color: transparent;
            color: white;
            transform: scale(1.1);
            box-shadow: 0 8px 20px rgba(0, 188, 212, 0.3);
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
            background: var(--secondary-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(0, 188, 212, 0.3);
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
            color: #00bcd4;
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
            border-color: #00bcd4;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 188, 212, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-light);
        }

        /* Telegram Widget */
        .telegram-login-container {
            text-align: center;
            margin-bottom: 32px;
            position: relative;
        }

        .telegram-widget {
            display: inline-block;
            margin-bottom: 20px;
        }

        .telegram-divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: var(--text-light);
            font-size: 14px;
            font-weight: 600;
        }

        .telegram-divider::before,
        .telegram-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(148, 163, 184, 0.2);
        }

        .telegram-divider span {
            padding: 0 15px;
        }

        /* Стили для Telegram кнопки */
        .telegram-login-button {
            display: inline-block;
            background: linear-gradient(135deg, #0088cc, #0077b5);
            color: white;
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(0, 136, 204, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .telegram-login-button:hover {
            background: linear-gradient(135deg, #0077b5, #0066a1);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 136, 204, 0.4);
        }

        .telegram-login-button i {
            font-size: 18px;
        }

        /* Информация Telegram пользователя */
        .telegram-user-info {
            text-align: center;
            margin-bottom: 32px;
            padding: 20px;
            background: rgba(0, 136, 204, 0.05);
            border-radius: 16px;
            border: 2px solid rgba(0, 136, 204, 0.1);
        }

        .telegram-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 16px;
            border: 3px solid #0088cc;
            box-shadow: 0 8px 20px rgba(0, 136, 204, 0.2);
        }

        .telegram-user-info h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .telegram-user-info p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Выбор типа пользователя */
        .user-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }

        .user-type-btn {
            background: rgba(248, 250, 252, 0.8);
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .user-type-btn:hover {
            border-color: rgba(0, 188, 212, 0.3);
            background: rgba(0, 188, 212, 0.05);
            transform: translateY(-2px);
        }

        .user-type-btn.active {
            background: rgba(0, 188, 212, 0.1);
            border-color: #00bcd4;
            box-shadow: 0 8px 20px rgba(0, 188, 212, 0.15);
        }

        .user-type-icon {
            width: 48px;
            height: 48px;
            background: var(--secondary-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }

        .user-type-btn.active .user-type-icon {
            background: var(--secondary-gradient);
            transform: scale(1.1);
        }

        .user-type-btn div:last-child {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-type-btn input[type="radio"] {
            display: none;
        }

        /* Кнопки */
        .btn-auth {
            width: 100%;
            padding: 18px;
            background: var(--secondary-gradient);
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
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-auth:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.4);
        }

        .btn-auth:active {
            transform: translateY(-1px);
        }

        .btn-auth.telegram {
            background: var(--purple-gradient);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }

        .btn-auth.telegram:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            box-shadow: 0 12px 30px rgba(139, 92, 246, 0.4);
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

        .notification.info {
            background: linear-gradient(135deg, rgba(0, 188, 212, 0.1), rgba(0, 151, 167, 0.1));
            border: 1px solid rgba(0, 188, 212, 0.2);
            color: #0097a7;
        }

        .notification i {
            font-size: 18px;
        }

        /* Подтверждение email */
        .verification-container {
            text-align: center;
        }

        .verification-info {
            background: rgba(0, 188, 212, 0.05);
            border: 2px solid rgba(0, 188, 212, 0.1);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .verification-email {
            font-weight: 700;
            color: #00bcd4;
            font-size: 18px;
            margin: 8px 0;
        }

        .verification-code-input {
            font-size: 32px;
            letter-spacing: 8px;
            text-align: center;
            font-weight: 700;
            color: var(--text-primary);
            border: 2px solid rgba(0, 188, 212, 0.3);
            border-radius: 12px;
            padding: 16px;
            background: rgba(248, 250, 252, 0.8);
            width: 100%;
            max-width: 280px;
            margin: 0 auto 24px;
            display: block;
        }

        .verification-code-input:focus {
            outline: none;
            border-color: #00bcd4;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 188, 212, 0.1);
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
            }
            50% {
                transform: scale(1.05);
            }
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

            .user-type-selector {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .step-title {
                font-size: 12px;
            }

            .main-content {
                padding: 120px 15px 40px;
            }

            .registration-steps::before {
                left: 10%;
                right: 10%;
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

            .verification-code-input {
                font-size: 24px;
                letter-spacing: 6px;
                max-width: 220px;
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
                border-color: #00bcd4;
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

            .user-type-btn {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.1);
            }

            .user-type-btn div:last-child {
                color: #cbd5e1;
            }

            .step-number {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
                color: #94a3b8;
            }

            .registration-steps::before {
                background: rgba(255, 255, 255, 0.1);
            }

            .telegram-user-info {
                background: rgba(0, 136, 204, 0.1);
                border-color: rgba(0, 136, 204, 0.2);
            }

            .verification-info {
                background: rgba(0, 188, 212, 0.1);
                border-color: rgba(0, 188, 212, 0.2);
            }

            .verification-code-input {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(0, 188, 212, 0.3);
                color: #cbd5e1;
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
                <!-- Шаги регистрации -->
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

                <!-- Заголовок -->
                <div class="auth-header">
                    <?php if ($current_step == 1): ?>
                        <div class="auth-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h1 class="auth-title">Регистрация</h1>
                        <p class="auth-subtitle">
                            Создайте аккаунт и получите <strong>3000₽</strong> на первый заказ!<br>
                            Выберите удобный способ регистрации
                        </p>
                    <?php elseif ($current_step == 2): ?>
                        <?php if (isset($_SESSION['telegram_data'])): ?>
                            <div class="auth-icon" style="background: var(--purple-gradient);">
                                <i class="fab fa-telegram"></i>
                            </div>
                            <h1 class="auth-title">Telegram авторизация</h1>
                            <p class="auth-subtitle">
                                Данные получены из Telegram<br>
                                Заполните дополнительные поля
                            </p>
                        <?php else: ?>
                            <div class="auth-icon" style="background: var(--success-gradient);">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <h1 class="auth-title">Подтверждение email</h1>
                            <p class="auth-subtitle">
                                Мы отправили код подтверждения на ваш email<br>
                                Введите его для продолжения регистрации
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="auth-icon" style="background: var(--purple-gradient);">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h1 class="auth-title">Дополнительная информация</h1>
                        <p class="auth-subtitle">
                            Завершите регистрацию<br>
                            Укажите тип аккаунта и контактные данные
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

                <!-- Уведомления об успехе -->
                <?php if (isset($success_message)): ?>
                    <div class="notification success">
                        <i class="fas fa-check-circle"></i>
                        <p><?= htmlspecialchars($success_message) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Шаг 1: Основные данные / Telegram -->
                <?php if ($current_step == 1): ?>
                    <!-- Telegram авторизация (показываем только если есть username бота) -->
                    <?php if ($telegram_bot_username): ?>
                    <div class="telegram-login-container">
                        <div class="telegram-widget">
                            <script async src="https://telegram.org/js/telegram-widget.js?19"
                                    data-telegram-login="<?php echo htmlspecialchars($telegram_bot_username); ?>"
                                    data-size="large"
                                    data-userpic="false"
                                    data-radius="12"
                                    data-onauth="onTelegramAuth(user)"
                                    data-request-access="write"></script>
                        </div>
                        <div class="telegram-divider">
                            <span>или</span>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="notification warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Telegram авторизация временно недоступна
                        </div>
                    <?php endif; ?>

                    <!-- Обычная регистрация -->
                    <form method="POST" id="registrationForm">
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

                        <div class="form-group">
                            <label for="phone" class="form-label">
                                <i class="fas fa-phone"></i> Номер телефона
                            </label>
                            <input type="tel" id="phone" name="phone" class="form-control" required
                                   placeholder="+7XXXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                   autocomplete="tel">
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock"></i> Пароль
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

                        <button type="submit" class="btn-auth pulse">
                            <i class="fas fa-arrow-right"></i> Продолжить регистрацию
                        </button>

                        <div class="auth-links">
                            <a href="/login/login.php" class="auth-link">
                                <i class="fas fa-sign-in-alt"></i> Уже есть аккаунт? Войти
                            </a>
                        </div>
                    </form>

                <!-- Шаг 2: Подтверждение -->
                <?php elseif ($current_step == 2): ?>
                    <?php if (isset($_SESSION['telegram_data'])): ?>
                        <!-- Информация о Telegram пользователе -->
                        <div class="telegram-user-info">
                            <?php if (!empty($_SESSION['telegram_data']['photo_url'])): ?>
                                <img src="<?= htmlspecialchars($_SESSION['telegram_data']['photo_url']) ?>"
                                     alt="Telegram Photo"
                                     class="telegram-photo">
                            <?php endif; ?>
                            <h3><?= htmlspecialchars($_SESSION['telegram_data']['first_name'] . ' ' . ($_SESSION['telegram_data']['last_name'] ?? '')) ?></h3>
                            <?php if (!empty($_SESSION['telegram_data']['username'])): ?>
                                <p>@<?= htmlspecialchars($_SESSION['telegram_data']['username']) ?></p>
                            <?php endif; ?>
                        </div>

                        <form method="POST" class="telegram-registration">
                            <input type="hidden" name="step" value="3">

                            <div class="form-group">
                                <label for="email" class="form-label">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input type="email" id="email" name="email" class="form-control" required
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                       placeholder="your@email.com">
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">
                                    <i class="fas fa-phone"></i> Номер телефона
                                </label>
                                <input type="tel" id="phone" name="phone" class="form-control" required
                                       placeholder="+7XXXXXXXXXX"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>

                            <button type="submit" class="btn-auth telegram">
                                <i class="fas fa-arrow-right"></i> Продолжить
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Подтверждение email -->
                        <div class="verification-container">
                            <div class="verification-info">
                                <p>Код подтверждения отправлен на email:</p>
                                <div class="verification-email">
                                    <?= htmlspecialchars($_SESSION['register_data']['email'] ?? '') ?>
                                </div>
                                <p>Введите 6-значный код из письма:</p>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="step" value="2">

                                <input type="text"
                                       id="verification_code"
                                       name="verification_code"
                                       class="verification-code-input"
                                       required
                                       placeholder="000000"
                                       maxlength="6"
                                       pattern="\d{6}"
                                       autocomplete="off">

                                <button type="submit" class="btn-auth pulse">
                                    <i class="fas fa-check"></i> Подтвердить email
                                </button>
                            </form>

                            <div class="auth-links">
                                <a href="?resend=1" class="auth-link" id="resend-code">
                                    <i class="fas fa-redo"></i> Отправить код повторно
                                </a>
                                <br>
                                <a href="/login/register.php" class="auth-link" style="margin-top: 8px;">
                                    <i class="fas fa-edit"></i> Изменить email
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- Шаг 3: Дополнительная информация -->
                <?php elseif ($current_step == 3): ?>
                    <form method="POST">
                        <input type="hidden" name="step" value="3">

                        <!-- Тип пользователя -->
                        <div class="user-type-selector">
                            <label class="user-type-btn <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'active' : '' ?>">
                                <input type="radio" name="user_type" value="individual"
                                       <?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'checked' : '' ?> required>
                                <div class="user-type-icon"><i class="fas fa-user"></i></div>
                                <div>Физ. лицо</div>
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

                        <!-- Имя и фамилия -->
                        <?php if (!isset($_SESSION['telegram_data'])): ?>
                            <div class="form-group">
                                <label for="first_name" class="form-label">
                                    <i class="fas fa-user"></i> Имя
                                </label>
                                <input type="text" id="first_name" name="first_name" class="form-control" required
                                       value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                                       placeholder="Введите ваше имя">
                            </div>

                            <div class="form-group">
                                <label for="last_name" class="form-label">
                                    <i class="fas fa-user"></i> Фамилия
                                </label>
                                <input type="text" id="last_name" name="last_name" class="form-control" required
                                       value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                                       placeholder="Введите вашу фамилию">
                            </div>
                        <?php endif; ?>

                        <!-- Поля компании -->
                        <div id="company-fields" style="<?= ($_POST['user_type'] ?? 'individual') == 'individual' ? 'display: none;' : '' ?>">
                            <div class="form-group">
                                <label for="company_name" class="form-label">
                                    <i class="fas fa-building"></i> Название компании
                                </label>
                                <input type="text" id="company_name" name="company_name" class="form-control"
                                       value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>"
                                       placeholder="Введите название компании">
                            </div>

                            <div class="form-group">
                                <label for="inn" class="form-label">
                                    <i class="fas fa-id-card"></i> ИНН
                                </label>
                                <input type="text" id="inn" name="inn" class="form-control"
                                       value="<?= htmlspecialchars($_POST['inn'] ?? '') ?>"
                                       placeholder="10 или 12 цифр">
                            </div>

                            <div id="kpp-field" style="<?= ($_POST['user_type'] ?? '') != 'legal' ? 'display: none;' : '' ?>">
                                <div class="form-group">
                                    <label for="kpp" class="form-label">
                                        <i class="fas fa-id-card"></i> КПП (только для юр. лиц)
                                    </label>
                                    <input type="text" id="kpp" name="kpp" class="form-control"
                                           value="<?= htmlspecialchars($_POST['kpp'] ?? '') ?>"
                                           placeholder="9 цифр">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-auth pulse">
                            <i class="fas fa-check-circle"></i> Завершить регистрацию
                        </button>

                        <div class="auth-links">
                            <a href="#" onclick="history.back()" class="auth-link">
                                <i class="fas fa-arrow-left"></i> Вернуться назад
                            </a>
                        </div>
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
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Автофокус на поле email на первом шаге
            const emailField = document.getElementById('email');
            if (emailField) {
                emailField.focus();
            }

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

            // Управление выбором типа пользователя
            const userTypeBtns = document.querySelectorAll('.user-type-btn');

            userTypeBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    userTypeBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    updateCompanyFields();
                });
            });

            // Обновление полей компании
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

            // Инициализация при загрузке
            updateCompanyFields();

            // Анимация при загрузке
            const authCard = document.querySelector('.auth-card');
            authCard.style.opacity = '0';
            authCard.style.transform = 'translateY(20px)';

            setTimeout(() => {
                authCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                authCard.style.opacity = '1';
                authCard.style.transform = 'translateY(0)';
            }, 100);

            // Обработка повторной отправки кода
            const resendLink = document.getElementById('resend-code');
            if (resendLink) {
                resendLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
                    this.style.pointerEvents = 'none';

                    setTimeout(() => {
                        window.location.href = '?resend=1';
                    }, 1000);
                });
            }

            // Валидация пароля в реальном времени
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');

            if (passwordField && confirmPasswordField) {
                function validatePassword() {
                    const password = passwordField.value;
                    const confirmPassword = confirmPasswordField.value;

                    if (password.length < 8) {
                        passwordField.style.borderColor = '#ef4444';
                    } else if (!/[A-Z]/.test(password)) {
                        passwordField.style.borderColor = '#f59e0b';
                    } else {
                        passwordField.style.borderColor = '#10b981';
                    }

                    if (confirmPassword && password !== confirmPassword) {
                        confirmPasswordField.style.borderColor = '#ef4444';
                    } else if (confirmPassword) {
                        confirmPasswordField.style.borderColor = '#10b981';
                    }
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
        });

        // Обработка данных из Telegram Widget
        function onTelegramAuth(user) {
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
    </script>
</body>
</html>