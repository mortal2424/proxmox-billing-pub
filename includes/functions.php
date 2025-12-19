<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// Функция для получения настроек SMTP из базы данных
function getSmtpSettings() {
    try {
        $db = new Database();
        $stmt = $db->getConnection()->prepare("SELECT * FROM smtp_settings ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching SMTP settings: " . $e->getMessage());
        return null;
    }
}

function sendEmail($to, $subject, $body) {
    // Получаем настройки SMTP из базы данных
    $smtpSettings = getSmtpSettings();
    
    if (!$smtpSettings) {
        error_log("SMTP settings not found in database");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Настройки SMTP из базы данных
        $mail->isSMTP();
        $mail->Host = $smtpSettings['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtpSettings['user'];
        $mail->Password = $smtpSettings['pass'];
        
        // Настройка шифрования из базы данных
        if ($smtpSettings['secure'] == 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpSettings['port'] ?: 587;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtpSettings['port'] ?: 465;
        }
        
        $mail->CharSet = 'UTF-8';

        // Отправитель из базы данных
        $mail->setFrom($smtpSettings['from_email'], $smtpSettings['from_name']);
        $mail->addAddress($to);

        // Контент письма
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Ошибка отправки email: " . $mail->ErrorInfo);
        return false;
    }
}

function sendVerificationEmail($email, $code) {
    // Получаем настройки SMTP из базы данных
    $smtpSettings = getSmtpSettings();
    
    if (!$smtpSettings) {
        error_log("SMTP settings not found in database");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Настройки SMTP из базы данных
        $mail->isSMTP();
        $mail->Host = $smtpSettings['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtpSettings['user'];
        $mail->Password = $smtpSettings['pass'];
        
        // Настройка шифрования из базы данных
        if ($smtpSettings['secure'] == 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpSettings['port'] ?: 587;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtpSettings['port'] ?: 465;
        }
        
        $mail->CharSet = 'UTF-8';

        // Отправитель из базы данных
        $mail->setFrom($smtpSettings['from_email'], $smtpSettings['from_name']);
        $mail->addAddress($email);

        // Содержание письма
        $mail->isHTML(true);
        $mail->Subject = 'Подтверждение email для HomeVlad Cloud';

        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .code { font-size: 24px; font-weight: bold; color: #6c5ce7; padding: 10px 20px; background: #f5f6fa; display: inline-block; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2>Подтверждение регистрации</h2>
                    <p>Ваш код подтверждения:</p>
                    <div class='code'>$code</div>
                    <p>Введите этот код на странице регистрации для завершения процесса.</p>
                    <p>Код действителен в течение 1 часа.</p>
                    <p>Если вы не регистрировались в HomeVlad Cloud, проигнорируйте это письмо.</p>
                </div>
            </body>
            </html>
        ";

        $mail->AltBody = "Ваш код подтверждения: $code\n\nВведите этот код на странице регистрации.";

        $mail->send();

        // Для логгирования (можно удалить в продакшене)
        file_put_contents(__DIR__ . '/../logs/email_logs.txt', "Email sent to: $email at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

        return true;
    } catch (Exception $e) {
        // Логирование ошибок
        file_put_contents(__DIR__ . '/../logs/email_errors.txt', "Error sending to $email: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

function generateVerificationCode() {
    return rand(100000, 999999);
}

function validatePhone($phone) {
    return preg_match('/^\+7\d{10}$/', $phone);
}

function redirectIfLoggedIn() {
    if (isset($_SESSION['user_id'])) {
        header('Location: /templates/dashboard.php');
        exit;
    }
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    return $protocol . "://" . $_SERVER['HTTP_HOST'];
}

/**
 * Получает бонусный баланс пользователя
 */
function getBonusBalance($user_id) {
    global $db;
    try {
        $stmt = $db->getConnection()->prepare("
            SELECT bonus_balance FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result['bonus_balance'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error getting bonus balance: " . $e->getMessage());
        return 0;
    }
}

/**
 * Обновляет бонусный баланс пользователя
 */
function updateBonusBalance($user_id, $amount, $description = '') {
    global $db;
    try {
        $db->getConnection()->beginTransaction();

        // 1. Обновляем баланс
        $stmt = $db->getConnection()->prepare("
            UPDATE users
            SET bonus_balance = bonus_balance + ?
            WHERE id = ?
        ");
        $stmt->execute([$amount, $user_id]);

        // 2. Записываем в историю (если есть таблица)
        if (!empty($description)) {
            $stmt = $db->getConnection()->prepare("
                INSERT INTO balance_history
                (user_id, amount, operation_type, description)
                VALUES (?, ?, 'bonus', ?)
            ");
            $stmt->execute([$user_id, $amount, $description]);
        }

        $db->getConnection()->commit();
        return true;
    } catch (PDOException $e) {
        $db->getConnection()->rollBack();
        error_log("Error updating bonus balance: " . $e->getMessage());
        return false;
    }
}

function getCurrentUsage($userId) {
    global $pdo;

    $usage = [
        'vms' => 0,
        'cpu' => 0,
        'ram' => 0,
        'disk' => 0
    ];

    // Получаем количество ВМ
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vms WHERE user_id = ?");
    $stmt->execute([$userId]);
    $usage['vms'] = $stmt->fetchColumn();

    // Получаем суммарные ресурсы
    $stmt = $pdo->prepare("
        SELECT SUM(cpu) as total_cpu, SUM(ram) as total_ram, SUM(disk) as total_disk
        FROM vms
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $resources = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resources) {
        $usage['cpu'] = $resources['total_cpu'] ?? 0;
        $usage['ram'] = $resources['total_ram'] ?? 0;
        $usage['disk'] = $resources['total_disk'] ?? 0;
    }

    return $usage;
}