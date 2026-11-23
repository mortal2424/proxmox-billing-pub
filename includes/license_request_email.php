<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function licenseRequestSmtp(PDO $db): ?array
{
    $smtp = $db->query('SELECT host,port, user AS username, pass AS password, secure AS encryption, from_email,from_name FROM smtp_settings ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$smtp || trim($smtp['host']) === '' || !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) return null;
    return $smtp;
}

function sendLicenseRequestEmail(array $smtp, string $reply, string $code, string $recipient): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new DomainException('Отправка запроса не настроена. Скопируйте код вручную.');
    $reply = trim($reply);
    if (strlen($reply) > 254 || !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Укажите корректный email для ответа.');
    }
    if (($_SESSION['license_mail_next'] ?? 0) > time()) {
        throw new DomainException('Повторить отправку можно через минуту.');
    }
    $_SESSION['license_mail_next'] = time() + 60;
    $request = readPairingRequest($code);
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout = 8;
        $mail->Host = $smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPAuth = $smtp['username'] !== '';
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = match ($smtp['encryption']) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($recipient);
        $mail->addReplyTo($reply);
        $mail->Subject = 'Запрос лицензии';
        $mail->Body = "Email для ответа: $reply\nПродукт: {$request['product']}\nДомен: {$request['domain']}\nIP: {$request['ip']}\n\nКод установки:\n$code\n";
        $mail->send();
    } catch (Throwable $error) {
        error_log('License request email failed: ' . get_class($error));
        throw new DomainException('Письмо не отправлено. Проверьте настройки SMTP или скопируйте код вручную.');
    }
}
