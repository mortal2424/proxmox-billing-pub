<?php
require_once __DIR__ . '/../includes/ssh_functions.php';
require_once __DIR__ . '/../includes/config.php';

// Используем PHPMailer для отправки email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';


function safeQuery($pdo, $sql, $params = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error in columnExists(): " . $e->getMessage());
        return false;
    }
}

function getNodeSSHInfo($hostname, $username, $password) {
    try {
        $connection = @ssh2_connect($hostname, 22);
        if (!$connection) {
            throw new Exception('Не удалось подключиться к серверу');
        }

        if (!@ssh2_auth_password($connection, $username, $password)) {
            throw new Exception('Ошибка аутентификации');
        }

        $result = [];

        // 1. Получаем информацию о CPU
        $stream = ssh2_exec($connection, 'lscpu');
        stream_set_blocking($stream, true);
        $lscpu = stream_get_contents($stream);

        // Парсим данные CPU
        preg_match('/CPU\(s\):\s+(\d+)/', $lscpu, $matches);
        $logicalCpus = $matches[1] ?? 1;

        preg_match('/Thread\(s\) per core:\s+(\d+)/', $lscpu, $matches);
        $threadsPerCore = $matches[1] ?? 1;

        preg_match('/Core\(s\) per socket:\s+(\d+)/', $lscpu, $matches);
        $coresPerSocket = $matches[1] ?? 1;

        preg_match('/Socket\(s\):\s+(\d+)/', $lscpu, $matches);
        $sockets = $matches[1] ?? 1;

        // Рассчитываем физические ядра и потоки
        $physicalCores = $sockets * $coresPerSocket;
        $totalThreads = $physicalCores * $threadsPerCore;

        $result['cpu_physical'] = $physicalCores;
        $result['cpu_threads'] = $totalThreads;
        $result['cpu_sockets'] = $sockets;

        // Модель процессора
        $stream = ssh2_exec($connection, 'cat /proc/cpuinfo | grep "model name" | head -1 | cut -d":" -f2');
        stream_set_blocking($stream, true);
        $result['cpu_model'] = trim(stream_get_contents($stream)) ?: 'N/A';

        // 2. Получаем информацию о RAM
        $stream = ssh2_exec($connection, 'free -m | grep Mem');
        stream_set_blocking($stream, true);
        $ram = preg_split('/\s+/', trim(stream_get_contents($stream)));
        $result['ram_total'] = isset($ram[1]) ? round($ram[1] / 1024, 2) . ' GB' : 'N/A';
        $result['ram_used'] = isset($ram[2]) ? round($ram[2] / 1024, 2) . ' GB' : 'N/A';
        $result['ram_percent'] = (isset($ram[1]) && isset($ram[2]) && $ram[1] > 0)
            ? round(($ram[2] / $ram[1]) * 100, 1) . '%'
            : 'N/A';

        // 3. Получаем информацию о дисках (только физические)
        $stream = ssh2_exec($connection, 'df -h --output=source,size,used,pcent,target | grep -E "/dev/(sd|nvme|vd)" | grep -v "loop"');
        stream_set_blocking($stream, true);
        $disks = explode("\n", trim(stream_get_contents($stream)));
        $result['disks'] = [];

        foreach ($disks as $diskLine) {
            if (!empty($diskLine)) {
                $diskInfo = preg_split('/\s+/', trim($diskLine));
                if (count($diskInfo) >= 5) {
                    $result['disks'][] = [
                        'name' => $diskInfo[0],
                        'size' => $diskInfo[1],
                        'used' => $diskInfo[2],
                        'percent' => $diskInfo[3],
                        'mount' => $diskInfo[4]
                    ];
                }
            }
        }

        // 4. Получаем сетевую информацию
        $stream = ssh2_exec($connection, 'ip -o -4 addr show vmbr0 | awk \'{print $4}\' | cut -d/ -f1');
        stream_set_blocking($stream, true);
        $result['ip'] = trim(stream_get_contents($stream)) ?: 'N/A';

        $stream = ssh2_exec($connection, 'ip link show vmbr0 | grep "link/ether" | awk \'{print $2}\'');
        stream_set_blocking($stream, true);
        $result['mac'] = trim(stream_get_contents($stream)) ?: 'N/A';

        // 5. Получаем данные для графиков
        $result['stats'] = getNodeStats($hostname, $username, $password);

        ssh2_disconnect($connection);
        return $result;

    } catch (Exception $e) {
        if (isset($connection)) @ssh2_disconnect($connection);
        return ['error' => $e->getMessage()];
    }
}

// Функции для работы с тикетами
function getStatusText($status) {
    $statuses = [
        'open' => 'Открыт',
        'answered' => 'Отвечен',
        'pending' => 'В ожидании',
        'closed' => 'Закрыт'
    ];
    return $statuses[$status] ?? $status;
}

function getPriorityText($priority) {
    $priorities = [
        'low' => 'Низкий',
        'medium' => 'Средний',
        'high' => 'Высокий',
        'critical' => 'Критичный'
    ];
    return $priorities[$priority] ?? $priority;
}

function getDepartmentText($department) {
    $departments = [
        'technical' => 'Технический',
        'billing' => 'Биллинг',
        'general' => 'Общие'
    ];
    return $departments[$department] ?? $department;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// Отправка уведомления в Telegram
function sendTelegramNotification($chat_id, $message) {
    if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN)) {
        error_log("Telegram notifications disabled - missing token");
        return false;
    }

    $url = "https://api.telegram.org/bot".TELEGRAM_BOT_TOKEN."/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];

    try {
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            throw new Exception("Telegram API request failed");
        }
        return true;
    } catch (Exception $e) {
        error_log("Telegram error: " . $e->getMessage());
        return false;
    }
}

function sendEmailNotification($to, $subject, $message) {
    if (!defined('SMTP_HOST') || empty(SMTP_HOST)) {
        error_log("SMTP not configured in config.php");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = constant('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;

        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = buildEmailTemplate($subject, $message);
        $mail->AltBody = strip_tags($message);

        // Отключаем debug для production
        $mail->SMTPDebug = 0;

        $result = $mail->send();

        if (!$result) {
            throw new Exception("Failed to send email: " . $mail->ErrorInfo);
        }

        return $result;
    } catch (Exception $e) {
        error_log("Email send error to $to: " . $e->getMessage());
        file_put_contents(__DIR__ . '/../logs/smtp_errors.log', date('[Y-m-d H:i:s]') . " Error sending to $to: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

// Уведомление поддержке о новом тикете
function sendNotificationToSupport($ticket_id) {
    global $pdo;

    try {
        $ticket = $pdo->prepare("SELECT t.*, u.email, u.full_name, u.telegram_id FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $ticket->execute([$ticket_id]);
        $ticket = $ticket->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new Exception("Ticket #$ticket_id not found");
        }

        // Подготовка данных
        $user_name = $ticket['full_name'] ?: $ticket['email'];
        $priority = getPriorityText($ticket['priority']);
        $date = date('d.m.Y H:i', strtotime($ticket['created_at']));
        $ticket_url = "https://homevlad.ru/admin/ticket.php?ticket_id=" . $ticket['id'];
        $department = getDepartmentText($ticket['department']);

        // Telegram сообщение для администраторов
        if (defined('TELEGRAM_CHAT_ID') && TELEGRAM_CHAT_ID) {
            $tg_message = "📌 <b>Новый тикет #{$ticket['id']}</b>\n";
            $tg_message .= "┌─────────────────\n";
            $tg_message .= "│ 👤 <b>Пользователь:</b> $user_name\n";
            $tg_message .= "│ 📝 <b>Тема:</b> {$ticket['subject']}\n";
            $tg_message .= "│ 🏷️ <b>Отдел:</b> $department\n";
            $tg_message .= "│ ⚠️ <b>Приоритет:</b> $priority\n";
            $tg_message .= "│ 📅 <b>Дата:</b> $date\n";
            $tg_message .= "└─────────────────\n";
            $tg_message .= "<a href=\"$ticket_url\">🔗 Перейти к тикету</a>";

            sendTelegramNotification(TELEGRAM_CHAT_ID, $tg_message);
        }

        // Email уведомление администраторам
        $email_subject = "Новый тикет #{$ticket['id']}: {$ticket['subject']}";
        $email_content = "
            <p>Поступил новый запрос в техническую поддержку:</p>
            <div style='margin: 15px 0; padding: 15px; background: #f5f7fa; border-radius: 6px;'>
                <p><strong>Пользователь:</strong> $user_name</p>
                <p><strong>Тема:</strong> {$ticket['subject']}</p>
                <p><strong>Отдел:</strong> $department</p>
                <p><strong>Приоритет:</strong> <span style='color: " . getPriorityColor($ticket['priority']) . "'>$priority</span></p>
                <p><strong>Дата создания:</strong> $date</p>
                <p><strong>Сообщение:</strong><br>" . nl2br(htmlspecialchars($ticket['message'])) . "</p>
            </div>
            <p>Пожалуйста, ответьте на тикет в кратчайшие сроки.</p>
            <p><a href=\"$ticket_url\" style=\"display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px;\">Перейти к тикету</a></p>
        ";

        // Получаем список администраторов
        $admins = $pdo->query("SELECT email FROM users WHERE is_admin = 1 AND email IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $admin_email) {
            sendEmailNotification($admin_email, $email_subject, $email_content);
        }

    } catch (Exception $e) {
        error_log("Notification error for ticket #$ticket_id: " . $e->getMessage());
    }
}

// Уведомление пользователю о ответе на тикет или изменении статуса
function sendNotificationToUser($ticket_id, $reply_message = null, $status_change = null) {
    global $pdo;

    try {
        $ticket = $pdo->prepare("SELECT t.*, u.email, u.full_name, u.telegram_id FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $ticket->execute([$ticket_id]);
        $ticket = $ticket->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new Exception("Ticket #$ticket_id not found");
        }

        $user_name = $ticket['full_name'] ?: $ticket['email'];
        $ticket_url = "https://homevlad.ru/support.php?ticket_id=" . $ticket['id'];
        $priority = getPriorityText($ticket['priority']);
        $status = getStatusText($ticket['status']);

        if ($reply_message) {
            // Уведомление о новом ответе
            $reply_text = nl2br(htmlspecialchars($reply_message));

            // Telegram уведомление пользователю
            if (!empty($ticket['telegram_id'])) {
                $tg_message = "💌 <b>Новый ответ по тикету #{$ticket['id']}</b>\n";
                $tg_message .= "┌─────────────────\n";
                $tg_message .= "│ 📝 <b>Тема:</b> {$ticket['subject']}\n";
                $tg_message .= "│ 🕒 <b>Время ответа:</b> " . date('d.m.Y H:i') . "\n";
                $tg_message .= "└─────────────────\n";
                $tg_message .= "<b>Ответ поддержки:</b>\n";
                $tg_message .= substr(strip_tags($reply_message), 0, 1000) . "\n\n";
                $tg_message .= "<a href=\"$ticket_url\">🔗 Перейти к тикету</a>";

                sendTelegramNotification($ticket['telegram_id'], $tg_message);
            }

            // Email уведомление
            $subject = "Ответ на ваш тикет #{$ticket['id']}: {$ticket['subject']}";
            $content = "
                <p>Здравствуйте, $user_name!</p>
                <p>По вашему тикету <strong>\"{$ticket['subject']}\"</strong> получен ответ от нашей поддержки:</p>
                <div style='background:#f5f7fa; padding:15px; border-radius:6px; margin:15px 0;'>
                    $reply_text
                </div>
                <p>Вы можете просмотреть полную переписку и ответить на тикет, перейдя по ссылке ниже:</p>
                <p><a href=\"$ticket_url\" style=\"display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px;\">Перейти к тикету</a></p>
            ";

            sendEmailNotification($ticket['email'], $subject, $content);
        } elseif ($status_change) {
            // Уведомление об изменении статуса
            $new_status = getStatusText($status_change);

            // Telegram уведомление
            if (!empty($ticket['telegram_id'])) {
                $tg_message = "🔄 <b>Изменение статуса тикета #{$ticket['id']}</b>\n";
                $tg_message .= "┌──────────────────────\n";
                $tg_message .= "│ 📝 <b>Тема:</b> {$ticket['subject']}\n";
                $tg_message .= "│ 🏷️ <b>Новый статус:</b> $new_status\n";
                $tg_message .= "└──────────────────────\n";
                $tg_message .= "<a href=\"$ticket_url\">🔗 Перейти к тикету</a>";

                sendTelegramNotification($ticket['telegram_id'], $tg_message);
            }

            // Email уведомление
            $subject = "Статус тикета #{$ticket['id']} изменен на \"$new_status\"";
            $content = "
                <p>Здравствуйте, $user_name!</p>
                <p>Статус вашего тикета <strong>\"{$ticket['subject']}\"</strong> был изменен на <strong>$new_status</strong>.</p>
                <p>Вы можете просмотреть текущее состояние тикета, перейдя по ссылке ниже:</p>
                <p><a href=\"$ticket_url\" style=\"display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px;\">Перейти к тикету</a></p>
            ";

            sendEmailNotification($ticket['email'], $subject, $content);
        }

    } catch (Exception $e) {
        error_log("User notification error for ticket #$ticket_id: " . $e->getMessage());
    }
}

function getPriorityColor($priority) {
    switch ($priority) {
        case 'low': return '#2E7D32';
        case 'medium': return '#FF8F00';
        case 'high': return '#C62828';
        case 'critical': return '#7B1FA2';
        default: return '#1565C0';
    }
}

// Функция для загрузки вложений
function uploadAttachment($ticket_id, $file) {
    if (!file_exists(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    // Проверка ошибок загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Ошибка загрузки файла');
    }

    // Проверка размера файла
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        throw new Exception('Файл слишком большой. Максимальный размер: ' . (UPLOAD_MAX_SIZE / 1024 / 1024) . 'MB');
    }

    // Проверка типа файла
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES)) {
        throw new Exception('Недопустимый тип файла');
    }

    // Генерируем уникальное имя файла
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('ticket_' . $ticket_id . '_', true) . '.' . $ext;
    $destination = UPLOAD_DIR . $filename;

    // Перемещаем файл
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Не удалось сохранить файл');
    }

    return [
        'file_name' => $file['name'],
        'file_path' => 'uploads/tickets/' . $filename,
        'file_size' => $file['size'],
        'file_type' => $mime
    ];
}

function buildEmailTemplate($title, $content) {
    $currentYear = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
    <style>
        <?php include 'css/admin_functions.css'; ?>
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>$title</h1>
        </div>
        <div class="email-body">
            <div class="email-content">
                $content
            </div>
            <a href="https://homevlad.ru" class="btn">Перейти на сайт</a>
        </div>
        <div class="email-footer">
            <p>© $currentYear HomeVlad Cloud. Все права защищены.</p>
            <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/*function logBackupAction($pdo, $user_id, $action, $filename, $details) {
    try {
        // Сначала создаем таблицу, если ее нет
        $createTableQuery = "
            CREATE TABLE IF NOT EXISTS backup_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                filename VARCHAR(255),
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($createTableQuery);

        // Затем вставляем запись
        $query = "INSERT INTO backup_logs (user_id, action, filename, details, ip_address)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $user_id,
            $action,
            $filename,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Не прерываем выполнение, просто логируем ошибку
        error_log("Failed to log backup action: " . $e->getMessage());
    }
}*/
