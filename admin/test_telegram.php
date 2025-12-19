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

// Получаем данные из POST запроса
$type = $_POST['bot_type'] ?? 'support';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    if ($type === 'support') {
        $settings = safeQuery($pdo, "SELECT * FROM telegram_support_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } else {
        $settings = safeQuery($pdo, "SELECT * FROM telegram_chat_bot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    if (empty($settings) || empty($settings['bot_token'])) {
        echo json_encode(['success' => false, 'message' => 'Бот не настроен']);
        exit;
    }

    // Получаем всех администраторов, у которых есть telegram_id
    $admins = safeQuery($pdo, 
        "SELECT id, full_name, telegram_id, telegram_username 
         FROM users 
         WHERE is_admin = 1 
           AND telegram_id IS NOT NULL 
           AND telegram_id != '' 
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($admins)) {
        echo json_encode(['success' => false, 'message' => 'Ни у одного администратора не указан Telegram ID. Пожалуйста, укажите Telegram ID в настройках профиля хотя бы одного администратора.']);
        exit;
    }
    
    // Выбираем первого администратора с telegram_id
    $adminData = $admins[0];
    $telegramId = $adminData['telegram_id'];
    $adminName = $adminData['full_name'] ?? 'Администратор';
    $adminId = $adminData['id'];

    $botToken = $settings['bot_token'];

    // Шаг 1: Проверяем подключение к Telegram API
    $apiUrl = "https://api.telegram.org/bot{$botToken}/getMe";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'message' => 'Ошибка подключения к Telegram API']);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if (!$data['ok']) {
        echo json_encode(['success' => false, 'message' => 'Некорректный токен бота']);
        exit;
    }
    
    $botName = $data['result']['username'];
    $firstName = $data['result']['first_name'];
    
    // Шаг 2: Отправляем тестовое сообщение администратору
    $messageText = "✅ Тестовое сообщение от бота {$firstName} (@{$botName})\n\n";
    $messageText .= "Привет, {$adminName}!\n";
    $messageText .= "Это тестовое сообщение подтверждает, что бот успешно настроен и готов к работе.\n\n";
    $messageText .= "Тип бота: " . ($type === 'support' ? "Support Bot" : "Chat Bot") . "\n";
    $messageText .= "Время отправки: " . date('d.m.Y H:i:s') . "\n\n";
    $messageText .= "Бот успешно подключен к панели управления HomeVlad Cloud!";
    
    $sendMessageUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
    
    $postData = [
        'chat_id' => $telegramId,
        'text' => $messageText,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendMessageUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $sendResponse = curl_exec($ch);
    $sendHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($sendHttpCode !== 200) {
        $sendData = json_decode($sendResponse, true);
        $errorMessage = $sendData['description'] ?? 'Неизвестная ошибка';
        echo json_encode(['success' => false, 'message' => 'Ошибка отправки сообщения: ' . $errorMessage]);
        exit;
    }
    
    $sendData = json_decode($sendResponse, true);
    if (!$sendData['ok']) {
        echo json_encode(['success' => false, 'message' => 'Ошибка отправки сообщения: ' . ($sendData['description'] ?? 'Неизвестная ошибка')]);
        exit;
    }
    
    // Отправляем сообщение всем остальным администраторам (опционально)
    $additionalAdminsSent = 0;
    if (count($admins) > 1) {
        for ($i = 1; $i < count($admins); $i++) {
            $additionalAdmin = $admins[$i];
            if (!empty($additionalAdmin['telegram_id'])) {
                $additionalPostData = [
                    'chat_id' => $additionalAdmin['telegram_id'],
                    'text' => "✅ Тестовое сообщение от бота {$firstName} (@{$botName})\n\nБот был успешно протестирован администратором {$adminName}.\nТип бота: " . ($type === 'support' ? "Support Bot" : "Chat Bot") . "\nВремя: " . date('d.m.Y H:i:s'),
                    'parse_mode' => 'HTML'
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $sendMessageUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $additionalPostData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                
                $additionalResponse = curl_exec($ch);
                $additionalHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($additionalHttpCode === 200) {
                    $additionalAdminsSent++;
                }
            }
        }
    }
    
    $responseMessage = "✅ Бот {$firstName} (@{$botName}) успешно подключен\n";
    $responseMessage .= "📨 Тестовое сообщение отправлено администратору {$adminName}";
    
    if ($additionalAdminsSent > 0) {
        $responseMessage .= " и {$additionalAdminsSent} другим администраторам";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $responseMessage,
        'bot_info' => [
            'name' => $firstName,
            'username' => $botName,
            'admin_name' => $adminName,
            'admin_id' => $adminId,
            'message_sent' => true,
            'total_admins_notified' => 1 + $additionalAdminsSent
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
?>