<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Настройки Telegram бота
$bot_token = '';
$bot_username = '';

// Создаем таблицы, если их нет
$pdo->exec("CREATE TABLE IF NOT EXISTS telegram_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_id VARCHAR(100),
    message_text TEXT,
    message_type ENUM('user', 'bot') NOT NULL,
    telegram_id BIGINT,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_telegram (user_id, telegram_id),
    INDEX idx_message_id (message_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS telegram_last_check (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    last_message_id BIGINT DEFAULT 0,
    last_check_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Получаем Telegram ID пользователя
$telegram_id = $user['telegram_id'] ?? null;
$is_telegram_connected = !empty($telegram_id);

// Функция для отправки сообщения в Telegram
function sendTelegramMessage($chat_id, $message, $parse_mode = 'HTML') {
    global $bot_token;

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        if ($response_data['ok']) {
            return [
                'success' => true,
                'message_id' => $response_data['result']['message_id']
            ];
        }
    }

    return ['success' => false, 'error' => 'Ошибка отправки сообщения в Telegram'];
}

// Функция для получения информации о боте
function getBotInfo() {
    global $bot_token;

    $url = "https://api.telegram.org/bot{$bot_token}/getMe";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        if ($response_data['ok']) {
            return [
                'success' => true,
                'bot_info' => $response_data['result']
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'Ошибка получения информации о боте'
    ];
}

// Функция для получения обновлений от бота
function getBotUpdates($offset = null) {
    global $bot_token;

    $url = "https://api.telegram.org/bot{$bot_token}/getUpdates";

    $params = [];
    if ($offset !== null) {
        $params['offset'] = $offset;
    }
    $params['timeout'] = 10;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        if ($response_data['ok']) {
            return [
                'success' => true,
                'updates' => $response_data['result']
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'Ошибка получения обновлений'
    ];
}

// Функция для получения ответа бота
function getBotResponse($message, $user) {
    $message_lower = strtolower(trim($message));

    // Основные команды
    $responses = [
        'привет' => "Привет, {$user['full_name']}! 👋 Чем могу помочь?",
        'здравствуй' => "Здравствуйте! Очень рад вас видеть. 🤗",
        'здравствуйте' => "Здравствуйте! Очень рад вас видеть. 🤗",

        'помощь' => "Я могу помочь с:\n• Состоянием ваших ВМ\n• Балансом и платежами\n• Техническими вопросами\n• Настройками аккаунта\n\nПросто задайте вопрос!",
        'помоги' => "Конечно, помогу! Опишите вашу проблему подробнее.",
        '/help' => "📋 <b>Доступные команды:</b>\n\n📊 <b>Баланс и платежи:</b>\n• /balance - Текущий баланс\n• /payment - Способы оплаты\n\n🖥️ <b>Виртуальные машины:</b>\n• /vms - Список ВМ\n• /create_vm - Создать ВМ\n• /restart_vm - Перезагрузить ВМ\n\n🛠️ <b>Поддержка:</b>\n• /support - Контакты поддержки\n• /ticket - Создать тикет\n\n⚙️ <b>Другое:</b>\n• /status - Статус системы\n• /profile - Профиль\n• /notifications - Уведомления",

        'баланс' => "💰 <b>Ваш баланс:</b>\n\nОсновной: {$user['balance']} ₽\nБонусный: {$user['bonus_balance']} ₽\n\nДля пополнения перейдите в раздел Биллинг или используйте команду /payment",
        '/balance' => "💰 <b>Ваш баланс:</b>\n\nОсновной: {$user['balance']} ₽\nБонусный: {$user['bonus_balance']} ₽\n\nДля пополнения перейдите в раздел Биллинг или используйте команду /payment",

        'оплата' => "💳 <b>Способы оплаты:</b>\n\n1. СБП (по QR-коду)\n2. Перевод на карту\n3. Счет для юр. лиц\n\nПодробности в разделе Биллинг.",
        '/payment' => "💳 <b>Способы оплаты:</b>\n\n1. СБП (по QR-коду)\n2. Перевод на карту\n3. Счет для юр. лиц\n\nПодробности в разделе Биллинг.",

        'вм' => "🖥️ <b>Ваши виртуальные машины:</b>\n\nИнформация доступна в разделе 'Мои ВМ'.\nТам вы можете:\n• Создать новую ВМ\n• Управлять существующими\n• Просматривать статистику",
        '/vms' => "🖥️ <b>Ваши виртуальные машины:</b>\n\nИнформация доступна в разделе 'Мои ВМ'.\nТам вы можете:\n• Создать новую ВМ\n• Управлять существующими\n• Просматривать статистику",

        'поддержка' => "🛠️ <b>Поддержка:</b>\n\nРаботаем Пн-Пт с 9:00 до 18:00 (МСК).\n\nКонтакты:\n• Email: support@homevlad.cloud\n• Telegram: @homevlad_support_bot\n• Телефон: +7 (964) 438-46-46 (экстренные случаи)",
        '/support' => "🛠️ <b>Поддержка:</b>\n\nРаботаем Пн-Пт с 9:00 до 18:00 (МСК).\n\nКонтакты:\n• Email: support@homevlad.cloud\n• Telegram: @homevlad_support_bot\n• Телефон: +7 (964) 438-46-46 (экстренные случаи)",

        'настройки' => "⚙️ <b>Настройки:</b>\n\nНастройки аккаунта можно изменить в соответствующем разделе.\nДоступно:\n• Изменение профиля\n• Настройка уведомлений\n• Безопасность\n• Платежная информация",
        '/settings' => "⚙️ <b>Настройки:</b>\n\nНастройки аккаунта можно изменить в соответствующем разделе.\nДоступно:\n• Изменение профиля\n• Настройка уведомлений\n• Безопасность\n• Платежная информация",

        'спасибо' => "Всегда рад помочь! Если будут вопросы - обращайтесь. 😊",
        'ок' => "Отлично! Если что-то понадобится - я здесь. 👍",
        'хорошо' => "Супер! Не стесняйтесь обращаться, если нужна помощь. 🙌",

        'статус' => "📊 <b>Статус системы:</b>\n\n✅ Все системы работают стабильно\n🟢 99.9% аптайм\n⏱️ Время ответа: < 100ms",
        '/status' => "📊 <b>Статус системы:</b>\n\n✅ Все системы работают стабильно\n🟢 99.9% аптайм\n⏱️ Время ответа: < 100ms",

        '/start' => "🎉 <b>Добро пожаловать в HomeVlad Cloud!</b>\n\nЯ ваш персональный помощник. Вот что я умею:\n• Показывать баланс\n• Управлять виртуальными машинами\n• Отвечать на технические вопросы\n• Связывать с поддержкой\n\nНапишите /help для списка команд!",

        'команды' => "📋 <b>Доступные команды:</b>\n\n/start - Начать диалог\n/help - Помощь\n/balance - Баланс\n/vms - Мои ВМ\n/support - Поддержка\n/status - Статус системы\n/settings - Настройки",
        '/commands' => "📋 <b>Доступные команды:</b>\n\n/start - Начать диалог\n/help - Помощь\n/balance - Баланс\n/vms - Мои ВМ\n/support - Поддержка\n/status - Статус системы\n/settings - Настройки",

        'бот жив' => "🤖 <b>Я жив и здоров!</b>\n\nСтатус: ✅ Активен\nВремя работы: 24/7\nГотов помочь с любыми вопросами!",
        'бот статус' => "🤖 <b>Информация о боте:</b>\n\n• Имя: HomeVlad Bot\n• Статус: ✅ Активен\n• Версия: 2.0\n• Время работы: Круглосуточно",

        'как дела' => "Всё отлично, спасибо! 😊 Готов помогать вам с управлением облачными сервисами.",
        'что нового' => "🎯 <b>Последние обновления:</b>\n\n• Добавлен раздел Telegram чата\n• Улучшена система уведомлений\n• Оптимизирована работа бота\n• Добавлены новые команды",

        'тестовое сообщение' => "✅ Тестовое сообщение получено!\n\nБот работает корректно.\nВремя получения: " . date('H:i:s'),

        '/create_vm' => "🖥️ <b>Создание ВМ:</b>\n\nДля создания виртуальной машины перейдите в раздел 'Заказать ВМ' в личном кабинете.",

        '/restart_vm' => "🔄 <b>Перезагрузка ВМ:</b>\n\nДля перезагрузки виртуальной машины перейдите в раздел 'Мои ВМ'.",

        '/ticket' => "🎫 <b>Создание тикета:</b>\n\nДля создания тикета в поддержку перейдите в раздел 'Поддержка'.",
    ];

    // Проверяем точные совпадения
    foreach ($responses as $keyword => $response) {
        if ($message_lower == strtolower($keyword) || strpos($message_lower, $keyword) !== false) {
            return $response;
        }
    }

    // Стандартные ответы для неизвестных сообщений
    $default_responses = [
        "🤔 Интересный вопрос! Для точного ответа лучше обратиться в техническую поддержку.",
        "📞 Понял ваш запрос. Рекомендую обратиться в раздел поддержки для детальной консультации.",
        "💡 Это хороший вопрос! Для получения точной информации создайте тикет в поддержку.",
        "🙏 Спасибо за сообщение! Рекомендую посмотреть FAQ или обратиться в поддержку.",
        "👨‍💻 По этому вопросу лучше проконсультироваться со специалистом поддержки. Они ответят быстро!",
        "🔍 Я не совсем понял ваш вопрос. Попробуйте переформулировать или используйте команду /help.",
        "📚 Похоже, у вас специфический вопрос. Рекомендую обратиться к документации или в поддержку.",
        "🎯 Для более точного ответа, пожалуйста, уточните ваш вопрос. Или используйте одну из команд: /help, /balance, /vms, /support",
    ];

    return $default_responses[array_rand($default_responses)];
}

// Получаем последние сообщения (от старых к новым для правильного отображения)
$messages = [];
if ($telegram_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM telegram_conversations
        WHERE (user_id = ? OR telegram_id = ?)
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$user_id, $telegram_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получаем статистику сообщений
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_messages,
        SUM(CASE WHEN message_type = 'user' THEN 1 ELSE 0 END) as user_messages,
        SUM(CASE WHEN message_type = 'bot' THEN 1 ELSE 0 END) as bot_messages,
        SUM(CASE WHEN message_type = 'bot' AND is_read = FALSE THEN 1 ELSE 0 END) as unread_messages,
        MIN(created_at) as first_message_date,
        MAX(created_at) as last_message_date
    FROM telegram_conversations
    WHERE user_id = $user_id OR telegram_id = $telegram_id
")->fetch();

// Проверяем статус бота
$bot_info = getBotInfo();
$bot_status = $bot_info['success'] ? 'active' : 'inactive';
$bot_name = $bot_info['success'] ? $bot_info['bot_info']['first_name'] : 'HomeVlad Bot';

// Проверяем новые сообщения от Telegram (для AJAX запроса)
if (isset($_GET['check_updates']) && $telegram_id) {
    // Получаем последний update_id из базы
    $last_check = $pdo->prepare("SELECT last_message_id FROM telegram_last_check WHERE user_id = ?");
    $last_check->execute([$user_id]);
    $last_check_data = $last_check->fetch();

    $last_update_id = $last_check_data['last_message_id'] ?? 0;

    // Получаем обновления
    $updates = getBotUpdates($last_update_id + 1);

    if ($updates['success']) {
        $new_messages = [];
        $last_message_id = $last_update_id;

        foreach ($updates['updates'] as $update) {
            if (isset($update['message']) && isset($update['message']['chat']['id'])) {
                $chat_id = $update['message']['chat']['id'];

                // Проверяем, что сообщение для этого пользователя
                if ($chat_id == $telegram_id) {
                    $message_id = $update['message']['message_id'];
                    $text = $update['message']['text'] ?? '';

                    if (!empty($text)) {
                        // Проверяем, нет ли уже такого сообщения
                        $stmt = $pdo->prepare("SELECT id FROM telegram_conversations WHERE message_id = ?");
                        $stmt->execute([$message_id]);

                        if (!$stmt->fetch()) {
                            // Определяем тип сообщения (от пользователя или бота)
                            $from_bot = isset($update['message']['from']['is_bot']) && $update['message']['from']['is_bot'];
                            $message_type = $from_bot ? 'bot' : 'user';

                            // Сохраняем сообщение
                            $stmt = $pdo->prepare("
                                INSERT INTO telegram_conversations
                                (user_id, message_id, message_text, message_type, telegram_id)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$user_id, $message_id, $text, $message_type, $telegram_id]);

                            $new_messages[] = [
                                'id' => $pdo->lastInsertId(),
                                'message_text' => $text,
                                'message_type' => $message_type,
                                'created_at' => date('Y-m-d H:i:s'),
                                'message_id' => $message_id
                            ];

                            // Если сообщение от бота, помечаем как непрочитанное
                            if ($message_type === 'bot') {
                                $pdo->prepare("UPDATE telegram_conversations SET is_read = FALSE WHERE id = ?")
                                    ->execute([$pdo->lastInsertId()]);
                            }
                        }
                    }

                    // Обновляем последний ID
                    if ($message_id > $last_message_id) {
                        $last_message_id = $message_id;
                    }
                }
            }
        }

        // Обновляем последний проверенный ID
        if ($last_check_data) {
            $pdo->prepare("UPDATE telegram_last_check SET last_message_id = ?, last_check_time = NOW() WHERE user_id = ?")
                ->execute([$last_message_id, $user_id]);
        } else {
            $pdo->prepare("INSERT INTO telegram_last_check (user_id, last_message_id) VALUES (?, ?)")
                ->execute([$user_id, $last_message_id]);
        }

        // Отправляем JSON ответ с новыми сообщениями
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'new_messages' => $new_messages,
                'count' => count($new_messages)
            ]);
            exit;
        }
    }
}

// Обработка отправки сообщения (исправленная)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        try {
            $message = trim($_POST['message']);

            if (empty($message)) {
                throw new Exception("Сообщение не может быть пустым");
            }

            if (strlen($message) > 4000) {
                throw new Exception("Сообщение слишком длинное (максимум 4000 символов)");
            }

            if (!$is_telegram_connected) {
                throw new Exception("Telegram не подключен. Подключите Telegram в настройках профиля.");
            }

            // Сохраняем сообщение пользователя в базу
            $stmt = $pdo->prepare("
                INSERT INTO telegram_conversations
                (user_id, message_text, message_type, telegram_id)
                VALUES (?, ?, 'user', ?)
            ");
            $stmt->execute([$user_id, $message, $telegram_id]);
            $local_message_id = $pdo->lastInsertId();

            // Отправляем сообщение в Telegram бот
            $telegram_response = sendTelegramMessage($telegram_id, $message);

            if (!$telegram_response['success']) {
                throw new Exception("Ошибка отправки в Telegram: " . ($telegram_response['error'] ?? 'Неизвестная ошибка'));
            }

            // Обновляем message_id в базе
            if (isset($telegram_response['message_id'])) {
                $stmt = $pdo->prepare("UPDATE telegram_conversations SET message_id = ? WHERE id = ?");
                $stmt->execute([$telegram_response['message_id'], $local_message_id]);
            }

            // Получаем ответ бота
            $bot_response = getBotResponse($message, $user);

            if ($bot_response) {
                // Добавляем небольшую задержку для имитации ответа бота
                sleep(1);

                // Отправляем ответ бота в Telegram
                $bot_telegram_response = sendTelegramMessage($telegram_id, $bot_response);

                if ($bot_telegram_response['success']) {
                    // Сохраняем ответ бота в базу
                    $stmt = $pdo->prepare("
                        INSERT INTO telegram_conversations
                        (user_id, message_text, message_type, telegram_id, message_id)
                        VALUES (?, ?, 'bot', ?, ?)
                    ");
                    $stmt->execute([$user_id, $bot_response, $telegram_id, $bot_telegram_response['message_id']]);
                }
            }

            $_SESSION['telegram_success'] = "Сообщение отправлено!";

            // Перенаправляем для предотвращения повторной отправки
            header("Location: telegram.php");
            exit;

        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Обработка быстрых команд через AJAX
    if (isset($_POST['quick_command']) && isset($_POST['ajax'])) {
        try {
            if (!$is_telegram_connected) {
                throw new Exception("Telegram не подключен");
            }

            $command = $_POST['quick_command'];

            // Определяем, что отправлять в зависимости от команды
            $command_map = [
                'start' => '/start',
                'help' => '/help',
                'balance' => '/balance',
                'vms' => '/vms',
                'support' => '/support',
                'status' => '/status',
                'settings' => '/settings',
                'hello' => 'Привет! Как дела?',
                'test' => 'Тестовое сообщение от быстрой команды',
                'bot_status' => 'бот статус',
                'commands' => 'команды'
            ];

            $message = $command_map[$command] ?? $command;

            // Сохраняем сообщение пользователя в базу
            $stmt = $pdo->prepare("
                INSERT INTO telegram_conversations
                (user_id, message_text, message_type, telegram_id)
                VALUES (?, ?, 'user', ?)
            ");
            $stmt->execute([$user_id, $message, $telegram_id]);
            $local_message_id = $pdo->lastInsertId();

            // Отправляем сообщение в Telegram
            $telegram_response = sendTelegramMessage($telegram_id, $message);

            if (!$telegram_response['success']) {
                throw new Exception("Ошибка отправки в Telegram");
            }

            // Обновляем message_id
            if (isset($telegram_response['message_id'])) {
                $pdo->prepare("UPDATE telegram_conversations SET message_id = ? WHERE id = ?")
                    ->execute([$telegram_response['message_id'], $local_message_id]);
            }

            // Получаем ответ бота
            $bot_response = getBotResponse($message, $user);

            if ($bot_response) {
                sleep(1);

                // Отправляем ответ бота
                $bot_telegram_response = sendTelegramMessage($telegram_id, $bot_response);

                if ($bot_telegram_response['success']) {
                    // Сохраняем ответ бота
                    $pdo->prepare("
                        INSERT INTO telegram_conversations
                        (user_id, message_text, message_type, telegram_id, message_id)
                        VALUES (?, ?, 'bot', ?, ?)
                    ")->execute([$user_id, $bot_response, $telegram_id, $bot_telegram_response['message_id']]);
                }
            }

            // Отправляем JSON ответ
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Команда отправлена',
                'command' => $command
            ]);
            exit;

        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
}

// Обработка других POST запросов (подключение, отключение и т.д.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Подключение Telegram
    if (isset($_POST['connect_telegram'])) {
        try {
            $telegram_id_input = trim($_POST['telegram_id']);

            if (empty($telegram_id_input)) {
                throw new Exception("Введите Telegram ID");
            }

            if (!preg_match('/^\d{5,}$/', $telegram_id_input)) {
                throw new Exception("Telegram ID должен содержать только цифры (минимум 5)");
            }

            // Проверяем существование пользователя в Telegram
            $test_message = "✅ Ваш аккаунт HomeVlad Cloud успешно подключен к Telegram боту!\n\nТеперь вы можете общаться с ботом прямо из личного кабинета.";
            $result = sendTelegramMessage($telegram_id_input, $test_message);

            if (!$result['success']) {
                throw new Exception("Не удалось отправить тестовое сообщение. Проверьте правильность ID и что вы начали диалог с ботом @homevlad_chat_bot.");
            }

            // Обновляем Telegram ID пользователя
            $stmt = $pdo->prepare("UPDATE users SET telegram_id = ? WHERE id = ?");
            $stmt->execute([$telegram_id_input, $user_id]);

            $_SESSION['telegram_success'] = "Telegram успешно подключен! ID: $telegram_id_input. Тестовое сообщение отправлено.";

            header("Location: telegram.php");
            exit;

        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Отключение Telegram
    if (isset($_POST['disconnect_telegram'])) {
        try {
            $pdo->prepare("UPDATE users SET telegram_id = NULL WHERE id = ?")->execute([$user_id]);
            $_SESSION['telegram_success'] = "Telegram успешно отключен";
            header("Location: telegram.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Очистка истории
    if (isset($_POST['clear_history'])) {
        try {
            $pdo->prepare("DELETE FROM telegram_conversations WHERE user_id = ?")->execute([$user_id]);
            $_SESSION['telegram_success'] = "История сообщений очищена";
            header("Location: telegram.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Пометить как прочитанные
    if (isset($_POST['mark_as_read'])) {
        try {
            $pdo->prepare("UPDATE telegram_conversations SET is_read = TRUE WHERE user_id = ? AND message_type = 'bot'")
                ->execute([$user_id]);
            $_SESSION['telegram_success'] = "Все сообщения помечены как прочитанные";
            header("Location: telegram.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Тестирование бота
    if (isset($_POST['test_bot'])) {
        try {
            if ($bot_info['success']) {
                $_SESSION['telegram_success'] = "✅ Бот активен! Имя: " . $bot_info['bot_info']['first_name'] .
                                               " (@" . $bot_info['bot_info']['username'] . ")";
            } else {
                throw new Exception("❌ Бот не отвечает: " . $bot_info['error']);
            }

            header("Location: telegram.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }

    // Тестовое сообщение
    if (isset($_POST['test_message'])) {
        try {
            if (!$is_telegram_connected) {
                throw new Exception("Telegram не подключен");
            }

            $test_message = "🤖 Тестовое сообщение от HomeVlad Bot\n\nВремя: " . date('H:i:s') . "\nСтатус: ✅ Бот работает корректно";
            $result = sendTelegramMessage($telegram_id, $test_message);

            if ($result['success']) {
                $pdo->prepare("
                    INSERT INTO telegram_conversations
                    (user_id, message_id, message_text, message_type, telegram_id)
                    VALUES (?, ?, ?, 'bot', ?)
                ")->execute([$user_id, $result['message_id'], $test_message, $telegram_id]);

                $_SESSION['telegram_success'] = "✅ Тестовое сообщение успешно отправлено в Telegram!";
            } else {
                throw new Exception("Ошибка отправки тестового сообщения: " . $result['error']);
            }

            header("Location: telegram.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['telegram_error'] = $e->getMessage();
            header("Location: telegram.php");
            exit;
        }
    }
}

$title = "Telegram Bot | HomeVlad Cloud";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-gradient);
            border-radius: 16px 16px 0 0;
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: var(--secondary-gradient);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .stat-icon.success {
            background: var(--success-gradient);
        }

        .stat-icon.warning {
            background: var(--warning-gradient);
        }

        .stat-icon.info {
            background: var(--info-gradient);
        }

        /* Статус бота */
        .bot-status-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .bot-status-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .bot-status-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .bot-status-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--secondary-gradient);
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.3);
        }

        .bot-status-icon.active {
            background: var(--success-gradient);
        }

        .bot-status-icon.inactive {
            background: var(--danger-gradient);
        }

        .bot-status-info h3 {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        body.dark-theme .bot-status-info h3 {
            color: #f1f5f9;
        }

        .bot-status-info p {
            color: #64748b;
            font-size: 14px;
        }

        body.dark-theme .bot-status-info p {
            color: #94a3b8;
        }

        .bot-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }

        .bot-status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .bot-status-badge.inactive {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .bot-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        /* Быстрые команды */
        .quick-commands-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .quick-commands-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        .commands-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .command-card {
            background: rgba(248, 250, 252, 0.8);
            border-radius: 12px;
            padding: 16px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        body.dark-theme .command-card {
            background: rgba(30, 41, 59, 0.5);
        }

        .command-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.1);
            border-color: rgba(0, 188, 212, 0.3);
        }

        .command-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            background: var(--secondary-gradient);
            margin-bottom: 12px;
        }

        .command-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        body.dark-theme .command-title {
            color: #f1f5f9;
        }

        .command-description {
            font-size: 12px;
            color: #64748b;
        }

        body.dark-theme .command-description {
            color: #94a3b8;
        }

        /* Чат */
        .chat-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 600px;
        }

        body.dark-theme .chat-container {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .chat-header {
            padding: 20px 24px;
            background: var(--secondary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .chat-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: rgba(248, 250, 252, 0.5);
        }

        body.dark-theme .chat-messages {
            background: rgba(30, 41, 59, 0.3);
        }

        /* Сообщения - ИСПРАВЛЕННЫЙ ПОРЯДОК */
        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            animation: messageSlide 0.3s ease;
        }

        /* Сообщения пользователя справа */
        .user-message {
            align-self: flex-end;
            background: var(--secondary-gradient);
            color: white;
            border-bottom-right-radius: 4px;
            margin-left: auto;
        }

        /* Сообщения бота слева */
        .bot-message {
            align-self: flex-start;
            background: white;
            color: #1e293b;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-bottom-left-radius: 4px;
        }

        body.dark-theme .bot-message {
            background: rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 12px;
            opacity: 0.8;
        }

        .message-content {
            line-height: 1.5;
            word-wrap: break-word;
            white-space: pre-line;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 4px;
            text-align: right;
        }

        .user-message .message-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .bot-message .message-time {
            color: #64748b;
        }

        body.dark-theme .bot-message .message-time {
            color: #94a3b8;
        }

        /* Форма отправки */
        .message-input-container {
            padding: 20px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
            background: white;
        }

        body.dark-theme .message-input-container {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .message-form {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .message-input-wrapper {
            flex: 1;
        }

        .message-textarea {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 24px;
            background: rgba(248, 250, 252, 0.8);
            color: #1e293b;
            font-size: 14px;
            resize: none;
            min-height: 48px;
            max-height: 120px;
            transition: all 0.3s ease;
        }

        body.dark-theme .message-textarea {
            background: rgba(30, 41, 59, 0.5);
            color: #cbd5e1;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .message-textarea:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--secondary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid rgba(148, 163, 184, 0.3);
            color: #64748b;
        }

        .btn-outline:hover {
            border-color: #00bcd4;
            color: #00bcd4;
            background: rgba(0, 188, 212, 0.05);
        }

        /* Уведомления */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 400px;
        }

        .notification.success {
            background: var(--success-gradient);
        }

        .notification.error {
            background: var(--danger-gradient);
        }

        /* Анимации */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .chat-container {
                height: 500px;
            }

            .commands-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .message {
                max-width: 85%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .commands-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Индикатор печати */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 12px 16px;
            background: white;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            align-self: flex-start;
            margin-bottom: 16px;
            animation: pulse 1.5s infinite;
        }

        body.dark-theme .typing-indicator {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: #00bcd4;
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-6px); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Стиль для непрочитанных сообщений */
        .unread-indicator {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 10px;
            height: 10px;
            background: var(--danger-gradient);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        /* Загрузчик для быстрых команд */
        .command-loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .command-card.loading .command-loader {
            opacity: 1;
            visibility: visible;
        }

        body.dark-theme .command-loader {
            background: rgba(30, 41, 59, 0.8);
        }
        /* === ОБЩИЙ ФУТЕР === */
        /* Исправляем футер для правильного отображения */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 80px 0 30px;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
            margin-top: auto;
            width: 100%;
        }

        .modern-footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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
    </style>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="main-container">
        <?php include '../templates/headers/user_sidebar.php'; ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fab fa-telegram"></i> Telegram Bot
                </h1>
                <div class="header-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="test_bot" class="btn btn-success">
                            <i class="fas fa-heartbeat"></i> Проверить бота
                        </button>
                    </form>
                    <button class="btn btn-outline" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <!-- Уведомления -->
            <?php if (isset($_SESSION['telegram_success'])): ?>
                <div class="notification success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['telegram_success']) ?></span>
                    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['telegram_success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['telegram_error'])): ?>
                <div class="notification error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['telegram_error']) ?></span>
                    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['telegram_error']); ?>
            <?php endif; ?>

            <!-- Статус бота -->
            <div class="bot-status-card">
                <div class="bot-status-header">
                    <div class="bot-status-icon <?= $bot_status ?>">
                        <i class="fab fa-telegram"></i>
                    </div>
                    <div class="bot-status-info">
                        <h3>HomeVlad Telegram Bot
                            <span class="bot-status-badge <?= $bot_status ?>">
                                <?= $bot_status === 'active' ? 'Активен' : 'Не активен' ?>
                            </span>
                        </h3>
                        <p><?= $bot_status === 'active' ? '✅ Бот активен и готов к работе!' : '❌ Бот временно недоступен' ?></p>
                    </div>
                </div>
                <div class="bot-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="test_bot" class="btn btn-outline">
                            <i class="fas fa-heartbeat"></i> Проверить статус
                        </button>
                    </form>
                    <?php if ($is_telegram_connected): ?>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="test_message" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Тестовое сообщение
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="https://t.me/homevlad_chat_bot" target="_blank" class="btn btn-success">
                        <i class="fab fa-telegram"></i> Открыть в Telegram
                    </a>
                </div>
            </div>

            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <div>
                            <div class="stat-title">Всего сообщений</div>
                            <div class="stat-value"><?= $stats['total_messages'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="stat-subtitle">
                        <?php if ($stats['first_message_date']): ?>
                            Первое: <?= date('d.m.Y', strtotime($stats['first_message_date'])) ?>
                        <?php else: ?>
                            Нет сообщений
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon success">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div>
                            <div class="stat-title">Отправлено вами</div>
                            <div class="stat-value"><?= $stats['user_messages'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="stat-subtitle">Ваши сообщения боту</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon info">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div>
                            <div class="stat-title">Получено от бота</div>
                            <div class="stat-value"><?= $stats['bot_messages'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="stat-subtitle">Ответы бота</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon warning">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <div class="stat-title">Непрочитанные</div>
                            <div class="stat-value"><?= $stats['unread_messages'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="stat-subtitle">
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="mark_as_read" class="btn btn-outline" style="padding: 4px 8px; font-size: 11px;">
                                Пометить как прочитанные
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if (!$is_telegram_connected): ?>
                <!-- Подключение Telegram -->
                <div class="quick-commands-section">
                    <h2 class="section-title">
                        <i class="fas fa-plug"></i> Подключите Telegram
                    </h2>
                    <p style="color: #64748b; margin-bottom: 20px;">
                        Подключите Telegram для общения с ботом напрямую в личном кабинете.
                    </p>
                    <form method="POST" style="display: flex; gap: 12px; align-items: flex-end;">
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b;">
                                Ваш Telegram ID
                            </label>
                            <input type="text" name="telegram_id"
                                   style="width: 100%; padding: 12px 16px; border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 8px;"
                                   placeholder="Пример: 123456789" required>
                            <div style="font-size: 12px; color: #64748b; margin-top: 6px;">
                                Чтобы получить свой Telegram ID, напишите <code>/start</code> боту
                                <a href="https://t.me/homevlad_chat_bot" target="_blank" style="color: #00bcd4;">@homevlad_chat_bot</a>
                            </div>
                        </div>
                        <button type="submit" name="connect_telegram" class="btn btn-primary">
                            <i class="fas fa-plug"></i> Подключить
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Быстрые команды -->
                <div class="quick-commands-section">
                    <h2 class="section-title">
                        <i class="fas fa-bolt"></i> Быстрые команды
                    </h2>
                    <p style="color: #64748b; margin-bottom: 20px;">
                        Нажмите на команду для быстрой отправки боту
                    </p>
                    <div class="commands-grid">
                        <div class="command-card" onclick="sendQuickCommand('start')" data-command="start">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: var(--success-gradient);">
                                <i class="fas fa-play"></i>
                            </div>
                            <div class="command-title">/start</div>
                            <div class="command-description">Начать диалог</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('help')" data-command="help">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: var(--info-gradient);">
                                <i class="fas fa-question-circle"></i>
                            </div>
                            <div class="command-title">/help</div>
                            <div class="command-description">Помощь и команды</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('balance')" data-command="balance">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="command-title">/balance</div>
                            <div class="command-description">Показать баланс</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('vms')" data-command="vms">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="command-title">/vms</div>
                            <div class="command-description">Мои ВМ</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('status')" data-command="status">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: var(--warning-gradient);">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="command-title">/status</div>
                            <div class="command-description">Статус системы</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('support')" data-command="support">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: var(--danger-gradient);">
                                <i class="fas fa-headset"></i>
                            </div>
                            <div class="command-title">/support</div>
                            <div class="command-description">Поддержка</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('hello')" data-command="hello">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                                <i class="fas fa-hand"></i>
                            </div>
                            <div class="command-title">Приветствие</div>
                            <div class="command-description">Поздороваться с ботом</div>
                        </div>

                        <div class="command-card" onclick="sendQuickCommand('bot_status')" data-command="bot_status">
                            <div class="command-loader">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                            <div class="command-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <div class="command-title">Статус бота</div>
                            <div class="command-description">Проверить работу бота</div>
                        </div>
                    </div>
                </div>

                <!-- Чат интерфейс -->
                <div class="chat-container">
                    <div class="chat-header">
                        <div class="chat-title">
                            <i class="fab fa-telegram"></i>
                            <span>Чат с HomeVlad Bot</span>
                            <?php if ($stats['unread_messages'] > 0): ?>
                                <span style="background: var(--danger-gradient); padding: 2px 8px; border-radius: 10px; font-size: 12px;">
                                    <?= $stats['unread_messages'] ?> новых
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="clear_history" class="btn btn-outline"
                                        style="color: white; border-color: rgba(255, 255, 255, 0.3); padding: 6px 12px;"
                                        onclick="return confirm('Очистить историю сообщений?')">
                                    <i class="fas fa-trash-alt"></i> Очистить
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="disconnect_telegram" class="btn btn-outline"
                                        style="color: white; border-color: rgba(255, 255, 255, 0.3); padding: 6px 12px;">
                                    <i class="fas fa-unlink"></i> Отключить
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <?php if (empty($messages)): ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px; text-align: center;">
                                <div style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px;">
                                    <i class="fas fa-comment-slash"></i>
                                </div>
                                <h3 style="color: #64748b; margin-bottom: 24px; font-size: 16px;">
                                    Начните общение с ботом
                                </h3>
                                <p style="color: #64748b; margin-bottom: 24px; font-size: 14px;">
                                    Используйте быстрые команды или напишите сообщение ниже
                                </p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message <?= $message['message_type'] === 'user' ? 'user-message' : 'bot-message' ?>"
                                     data-message-id="<?= $message['id'] ?>">
                                    <div class="message-header">
                                        <span style="font-weight: 600;">
                                            <?= $message['message_type'] === 'user' ? 'Вы' : 'HomeVlad Bot' ?>
                                        </span>
                                        <span style="margin-left: 8px;">
                                            <?= date('H:i', strtotime($message['created_at'])) ?>
                                        </span>
                                    </div>
                                    <div class="message-content">
                                        <?= nl2br(htmlspecialchars($message['message_text'])) ?>
                                    </div>
                                    <div class="message-time">
                                        <?= date('d.m.Y', strtotime($message['created_at'])) ?>
                                    </div>
                                    <?php if ($message['message_type'] === 'bot' && !$message['is_read']): ?>
                                        <div class="unread-indicator"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <div class="typing-indicator" id="typingIndicator" style="display: none;">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <span style="margin-left: 8px; font-size: 12px; color: #64748b;">бот печатает...</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="message-input-container">
                        <form method="POST" class="message-form" id="messageForm">
                            <input type="hidden" name="send_message" value="1">
                            <div class="message-input-wrapper">
                                <textarea name="message" class="message-textarea"
                                          placeholder="Введите сообщение для бота..."
                                          required maxlength="4000" id="messageInput"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding: 14px 24px;">
                                <i class="fas fa-paper-plane"></i> Отправить
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Автопрокрутка к последнему сообщению
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            // Отправка формы с помощью Ctrl+Enter
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && e.key === 'Enter') {
                        document.getElementById('messageForm').submit();
                    }
                });

                // Автоматический рост текстового поля
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }

            // Симуляция печати бота при отправке сообщения
            const messageForm = document.getElementById('messageForm');
            if (messageForm) {
                messageForm.addEventListener('submit', function(e) {
                    const message = messageInput?.value.trim();
                    if (message && message.length > 0) {
                        const typingIndicator = document.getElementById('typingIndicator');
                        if (typingIndicator) {
                            typingIndicator.style.display = 'flex';
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                        }
                    }
                });
            }

            // Удаление уведомлений
            setTimeout(() => {
                document.querySelectorAll('.notification').forEach(notification => {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                });
            }, 5000);

            // Фокус на поле ввода
            if (messageInput && <?= $is_telegram_connected ? 'true' : 'false' ?>) {
                messageInput.focus();
            }

            // Регулярная проверка новых сообщений
            <?php if ($is_telegram_connected): ?>
            startCheckingUpdates();
            <?php endif; ?>
        });

        // Функция для отправки быстрых команд через AJAX
        function sendQuickCommand(command) {
            const commandCard = document.querySelector(`.command-card[data-command="${command}"]`);

            if (commandCard) {
                // Показываем индикатор загрузки
                commandCard.classList.add('loading');

                // Отправляем AJAX запрос
                fetch('telegram.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `quick_command=${command}&ajax=1`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Скрываем индикатор загрузки
                        commandCard.classList.remove('loading');

                        // Показываем уведомление
                        showNotification('Команда отправлена: ' + command, 'success');

                        // Проверяем обновления через 1 секунду
                        setTimeout(checkForNewMessages, 1000);

                        // Проверяем обновления через 2 секунды для ответа бота
                        setTimeout(checkForNewMessages, 2000);
                    } else {
                        commandCard.classList.remove('loading');
                        showNotification('Ошибка: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    commandCard.classList.remove('loading');
                    showNotification('Ошибка сети: ' + error, 'error');
                });
            }
        }

        // Функция для проверки новых сообщений
        function checkForNewMessages() {
            fetch('telegram.php?check_updates=1&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.new_messages && data.new_messages.length > 0) {
                        // Добавляем новые сообщения в чат
                        data.new_messages.forEach(message => {
                            addMessageToChat(message);
                        });

                        // Обновляем статистику непрочитанных
                        updateUnreadCount();
                    }
                })
                .catch(error => console.error('Ошибка проверки обновлений:', error));
        }

        // Функция для добавления сообщения в чат
        function addMessageToChat(message) {
            const chatMessages = document.getElementById('chatMessages');

            // Создаем элемент сообщения
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${message.message_type === 'user' ? 'user-message' : 'bot-message'}`;
            messageDiv.dataset.messageId = message.id;

            const time = new Date(message.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const date = new Date(message.created_at).toLocaleDateString();

            messageDiv.innerHTML = `
                <div class="message-header">
                    <span style="font-weight: 600;">
                        ${message.message_type === 'user' ? 'Вы' : 'HomeVlad Bot'}
                    </span>
                    <span style="margin-left: 8px;">${time}</span>
                </div>
                <div class="message-content">
                    ${escapeHtml(message.message_text).replace(/\n/g, '<br>')}
                </div>
                <div class="message-time">${date}</div>
                ${message.message_type === 'bot' ? '<div class="unread-indicator"></div>' : ''}
            `;

            // Добавляем сообщение в конец чата
            chatMessages.appendChild(messageDiv);

            // Прокручиваем к последнему сообщению
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Функция для обновления счетчика непрочитанных
        function updateUnreadCount() {
            // Можно обновить счетчик непрочитанных в заголовке чата
            // Здесь просто перезагружаем страницу для обновления статистики
            // В реальном приложении лучше обновлять через AJAX
            setTimeout(() => {
                location.reload();
            }, 2000);
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            // Удаляем старые уведомления
            document.querySelectorAll('.notification').forEach(n => {
                if (!n.classList.contains('persistent')) {
                    n.remove();
                }
            });

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 3000);
        }

        // Функция для экранирования HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Запуск регулярной проверки обновлений
        function startCheckingUpdates() {
            // Проверяем сразу при загрузке
            checkForNewMessages();

            // Затем каждые 5 секунд
            setInterval(checkForNewMessages, 5000);
        }

        // Обработка нажатия клавиш для быстрых команд
        document.addEventListener('keydown', function(e) {
            // Ctrl+1 - /start
            if (e.ctrlKey && e.key === '1') {
                e.preventDefault();
                sendQuickCommand('start');
            }
            // Ctrl+2 - /help
            if (e.ctrlKey && e.key === '2') {
                e.preventDefault();
                sendQuickCommand('help');
            }
            // Ctrl+3 - /balance
            if (e.ctrlKey && e.key === '3') {
                e.preventDefault();
                sendQuickCommand('balance');
            }
            // Ctrl+4 - /status
            if (e.ctrlKey && e.key === '4') {
                e.preventDefault();
                sendQuickCommand('status');
            }
        });

        // Добавляем стили для анимаций
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
