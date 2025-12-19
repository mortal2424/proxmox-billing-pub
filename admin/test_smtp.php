<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    $settings = safeQuery($pdo, "SELECT * FROM smtp_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if (empty($settings) || empty($settings['host'])) {
        echo json_encode(['success' => false, 'message' => 'SMTP настройки не найдены']);
        exit;
    }
    
    // Используем PHPMailer для тестирования
    require_once '../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Настройки сервера
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['user'];
        $mail->Password = $settings['pass'];
        $mail->SMTPSecure = $settings['secure'];
        $mail->Port = $settings['port'];
        
        // Кодировка
        $mail->CharSet = 'UTF-8';
        
        // Отправитель
        $mail->setFrom($settings['from_email'], $settings['from_name']);
        
        // Получатель (админ)
        $adminEmail = $_SESSION['user']['email'] ?? 'admin@example.com';
        $mail->addAddress($adminEmail);
        
        // Тема и сообщение
        $mail->isHTML(true);
        $mail->Subject = 'Тестирование SMTP настроек - HomeVlad Cloud';
        $mail->Body = '
            <h2>Тестирование SMTP настроек</h2>
            <p>Это тестовое сообщение подтверждает, что SMTP настройки работают корректно.</p>
            <p><strong>Детали настройки:</strong></p>
            <ul>
                <li>Сервер: ' . htmlspecialchars($settings['host']) . '</li>
                <li>Порт: ' . htmlspecialchars($settings['port']) . '</li>
                <li>Пользователь: ' . htmlspecialchars($settings['user']) . '</li>
                <li>Шифрование: ' . htmlspecialchars($settings['secure']) . '</li>
            </ul>
            <p>Если вы получили это письмо, значит настройки SMTP работают правильно.</p>
            <p><em>Сообщение отправлено ' . date('d.m.Y H:i:s') . '</em></p>
        ';
        
        $mail->AltBody = 'Тестирование SMTP настроек HomeVlad Cloud. Сообщение отправлено ' . date('d.m.Y H:i:s');
        
        // Отправка
        $mail->send();
        
        echo json_encode([
            'success' => true,
            'message' => 'Тестовое письмо успешно отправлено на ' . $adminEmail
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка отправки: ' . $mail->ErrorInfo
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
?>