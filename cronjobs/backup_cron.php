<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_functions.php';

// Логирование
$log_file = __DIR__ . '/../logs/backup_cron.log';
$log_dir = dirname($log_file);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

// Начало работы
log_message('=== Запуск CRON для бэкапов ===');

try {
    $db = new Database();
    $pdo = $db->getConnection();

    // Создаем таблицы если их нет
    createBackupLogsTable($pdo);
    createSchedulesTable($pdo);

    // Получаем активные расписания
    $stmt = safeQuery($pdo, "
        SELECT * FROM backup_schedules
        WHERE is_active = 1 AND (next_run IS NULL OR next_run <= NOW())
        ORDER BY next_run ASC
    ", [], 'backup_schedules');

    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        log_message('Нет активных расписаний для выполнения');
        exit(0);
    }

    log_message('Найдено расписаний: ' . count($schedules));

    foreach ($schedules as $schedule) {
        log_message("Обработка расписания: {$schedule['name']} (ID: {$schedule['id']})");

        // Выполняем бэкап
        $result = createBackup(
            $schedule['backup_type'],
            $schedule['comment'] . ' [Авто по расписанию: ' . $schedule['name'] . ']',
            true
        );

        if ($result['success']) {
            log_message("Бэкап создан успешно: {$result['filename']}");

            // Обновляем время следующего запуска
            $next_run = calculateNextRun(
                $schedule['schedule_type'],
                $schedule['schedule_time'],
                $schedule['schedule_day']
            );

            $updateStmt = safeQuery($pdo, "
                UPDATE backup_schedules
                SET last_run = NOW(), next_run = ?
                WHERE id = ?
            ", [$next_run, $schedule['id']], 'backup_schedules');

            log_message("Следующий запуск: $next_run");

        } else {
            log_message("Ошибка создания бэкапа: {$result['error']}");

            // При ошибке пробуем снова через час
            $next_run = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $updateStmt = safeQuery($pdo, "
                UPDATE backup_schedules
                SET next_run = ?
                WHERE id = ?
            ", [$next_run, $schedule['id']], 'backup_schedules');

            log_message("Повторная попытка: $next_run");
        }
    }

    log_message('=== Завершение CRON ===');

} catch (Exception $e) {
    log_message("КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage());
    exit(1);
}

/**
 * Расчет следующего запуска (дублируется из backup.php)
 */
function calculateNextRun($schedule_type, $schedule_time, $schedule_day = null) {
    $now = time();

    switch ($schedule_type) {
        case 'hourly':
            return date('Y-m-d H:i:00', strtotime('+1 hour', $now));

        case 'daily':
            $time = strtotime($schedule_time);
            $next = strtotime(date('Y-m-d') . ' ' . date('H:i', $time));
            if ($next <= $now) {
                $next = strtotime('+1 day', $next);
            }
            return date('Y-m-d H:i:s', $next);

        case 'weekly':
            $day = $schedule_day ?? 1;
            $time = strtotime($schedule_time);
            $next = strtotime('next Monday +' . ($day - 1) . ' days', $now);
            $next = strtotime(date('Y-m-d', $next) . ' ' . date('H:i', $time));
            if ($next <= $now) {
                $next = strtotime('+1 week', $next);
            }
            return date('Y-m-d H:i:s', $next);

        case 'monthly':
            $day = $schedule_day ?? 1;
            $time = strtotime($schedule_time);
            $next = strtotime(date('Y-m-' . str_pad($day, 2, '0', STR_PAD_LEFT)), $now);
            if ($next <= $now) {
                $next = strtotime('+1 month', $next);
            }
            $next = strtotime(date('Y-m-d', $next) . ' ' . date('H:i', $time));
            return date('Y-m-d H:i:s', $next);

        default:
            return date('Y-m-d H:i:s', strtotime('+1 day', $now));
    }
}

/**
 * Создание таблицы расписаний
 */
function createSchedulesTable() {
    global $pdo;

    try {
        $query = "
            CREATE TABLE IF NOT EXISTS backup_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                schedule_type ENUM('daily', 'weekly', 'monthly', 'hourly') NOT NULL,
                schedule_time TIME NULL,
                schedule_day INT NULL,
                backup_type ENUM('full', 'files', 'db') NOT NULL DEFAULT 'full',
                is_active BOOLEAN DEFAULT 1,
                last_run DATETIME NULL,
                next_run DATETIME NULL,
                keep_count INT DEFAULT 10,
                comment TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        safeQuery($pdo, $query, [], null, true);
    } catch (Exception $e) {
        throw new Exception("Не удалось создать таблицу backup_schedules: " . $e->getMessage());
    }
}
?>
