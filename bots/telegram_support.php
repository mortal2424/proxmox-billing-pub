<?php

function telegramApi(string $token, string $method, array $payload, int $timeout = 8): mixed
{
    $curl = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $response = curl_exec($curl);
    $result = is_string($response) ? json_decode($response, true) : null;
    if (empty($result['ok'])) {
        throw new RuntimeException("Telegram API: {$method}", (int) ($result['error_code'] ?? 0));
    }
    return $result['result'];
}

final class TelegramSupportBot
{
    public function __construct(private PDO $pdo, private string $token) {}

    public function handleUpdate(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        $message = $callback['message'] ?? $update['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $sender = $callback['from'] ?? $message['from'] ?? [];
        if (($chat['type'] ?? '') !== 'private' || ($sender['id'] ?? null) !== ($chat['id'] ?? null)) {
            return;
        }
        if ($callback) {
            telegramApi($this->token, 'answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        $command = $callback['data'] ?? explode('@', strtok($message['text'] ?? '', " \n") ?: '', 2)[0];
        $chatId = $chat['id'];
        $menu = [
            [['text' => '📋 Мои обращения', 'callback_data' => 'tickets']],
            [['text' => '✍️ Создать обращение', 'callback_data' => 'new']],
        ];
        if (!in_array($command, ['tickets', '/tickets', 'new', '/new'], true) &&
            !preg_match('/^ticket_[1-9][0-9]*$/D', $command)) {
            $this->send($chatId, "🛟 <b>Поддержка HomeVlad</b>\n\nЗдесь можно проверить статус обращения и открыть переписку с поддержкой.", $menu);
            return;
        }

        $back = [['text' => '← Главное меню', 'callback_data' => 'menu']];
        if (in_array($command, ['new', '/new'], true)) {
            $this->send($chatId, "✍️ <b>Новое обращение</b>\n\nОткройте форму в кабинете. Укажите номер ВМ и приложите снимок экрана, если он поможет разобраться.",
                [[['text' => 'Открыть форму', 'url' => 'https://homevlad.ru/templates/support.php']], $back]);
            return;
        }

        $owner = $this->pdo->prepare('SELECT id FROM users WHERE telegram_id = ? AND is_active = 1 LIMIT 2');
        $owner->execute([$chatId]);
        $users = $owner->fetchAll(PDO::FETCH_COLUMN);
        if (count($users) !== 1) {
            $this->send($chatId, "🔐 Привяжите Telegram в личном кабинете, чтобы видеть свои обращения.",
                [[['text' => 'Привязать Telegram', 'url' => 'https://homevlad.ru/templates/telegram.php']], $back]);
            return;
        }

        if (str_starts_with($command, 'ticket_')) {
            $ticket = $this->pdo->prepare('SELECT id, subject, status FROM tickets WHERE user_id = ? AND id = ?');
            $ticket->execute([$users[0], (int) substr($command, 7)]);
            $row = $ticket->fetch();
            if (!$row) {
                $this->send($chatId, 'Обращение недоступно.', [$back]);
                return;
            }
            $title = htmlspecialchars($row['subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->send($chatId, "🎫 <b>#{$row['id']} · {$title}</b>\nСтатус: " . $this->status($row['status']),
                [[['text' => 'Открыть переписку', 'url' => 'https://homevlad.ru/templates/support.php?ticket_id=' . $row['id']]], $back]);
            return;
        }

        $tickets = $this->pdo->prepare('SELECT id, subject, status FROM tickets WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 8');
        $tickets->execute([$users[0]]);
        $rows = $tickets->fetchAll();
        $buttons = [];
        foreach ($rows as $row) {
            $buttons[] = [['text' => "#{$row['id']} · " . mb_strimwidth($row['subject'], 0, 38, '…', 'UTF-8') .
                ' · ' . $this->status($row['status']), 'callback_data' => 'ticket_' . $row['id']]];
        }
        $buttons[] = $back;
        $this->send($chatId, $rows ? '📋 <b>Последние обращения</b>' : 'У вас пока нет обращений.', $buttons);
    }

    private function status(?string $status): string
    {
        return match ($status) {
            'open' => 'Открыто',
            'answered' => 'Есть ответ',
            'closed' => 'Закрыто',
            'pending' => 'В работе',
            default => 'Статус неизвестен',
        };
    }

    private function send(int $chatId, string $text, array $buttons): void
    {
        telegramApi($this->token, 'sendMessage', [
            'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $buttons],
        ]);
    }
}
