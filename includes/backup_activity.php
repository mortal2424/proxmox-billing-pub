<?php

function backupActivityDirectory(): string
{
    $root = dirname(__DIR__) . '/backups';
    $path = $root . '/backup-activity';
    if (is_link($root) || is_link($path) ||
        (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) {
        throw new RuntimeException('Хранилище журнала резервного копирования недоступно');
    }
    return $path;
}

function backupActivityLog(string $id, string $stage, string $message): void
{
    try {
        $path = backupActivityDirectory() . '/activity.jsonl';
        if (is_link($path) || !($stream = fopen($path, 'c+b'))) throw new RuntimeException();
        try {
            if (!flock($stream, LOCK_EX) || !chmod($path, 0600)) throw new RuntimeException();
            if (fstat($stream)['size'] > 524288) {
                fseek($stream, -262144, SEEK_END);
                fgets($stream);
                $tail = stream_get_contents($stream);
                rewind($stream);
                ftruncate($stream, 0);
                fwrite($stream, $tail);
            }
            fseek($stream, 0, SEEK_END);
            $entry = json_encode(['time'=>date(DATE_ATOM),'id'=>$id,'stage'=>$stage,'message'=>$message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (fwrite($stream, $entry . "\n") !== strlen($entry) + 1) throw new RuntimeException();
        } finally {
            fclose($stream);
        }
    } catch (Throwable $error) {
        error_log('Backup activity journal unavailable');
    }
}

function recentBackupActivity(): array
{
    $path = backupActivityDirectory() . '/activity.jsonl';
    if (!is_file($path) || is_link($path)) return [];
    $stream = fopen($path, 'rb');
    if (!$stream) throw new RuntimeException('Журнал недоступен');
    try {
        if (!flock($stream, LOCK_SH)) throw new RuntimeException('Журнал недоступен');
        $rows = [];
        while (($line = fgets($stream)) !== false) {
            $entry = json_decode($line, true);
            if (is_array($entry)) $rows[] = $entry;
        }
        return array_reverse(array_slice($rows, -150));
    } finally {
        fclose($stream);
    }
}

function enqueueManualBackup(string $type, string $comment, ?int $scheduleId = null): string
{
    if (!in_array($type, ['db','full'], true)) throw new InvalidArgumentException('Неизвестный тип резервной копии');
    $directory = backupActivityDirectory();
    $id = bin2hex(random_bytes(8));
    $temp = tempnam($directory, '.request-');
    if ($temp === false) throw new RuntimeException('Не удалось подготовить заявку');
    try {
        $payload = json_encode(compact('type','comment','scheduleId'), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!chmod($temp, 0600) || file_put_contents($temp, $payload) !== strlen($payload) ||
            !rename($temp, "$directory/job-$id.json")) throw new RuntimeException('Не удалось поставить копию в очередь');
    } finally {
        if (is_file($temp)) unlink($temp);
    }
    backupActivityLog($id, 'queued', 'Заявка передана фоновому процессу, тип: ' . $type);
    return $id;
}

/** Called by the CLI scheduler under its existing exclusive lock. */
function runManualBackupQueue(): void
{
    global $pdo;
    $directory = backupActivityDirectory();
    foreach (glob($directory . '/job-*.json.running') ?: [] as $abandoned) {
        if (preg_match('/^job-([a-f0-9]{16})\.json\.running$/D', basename($abandoned), $match)) {
            backupActivityLog($match[1], 'failed', 'Процесс прервался. Проверьте список архивов перед повтором.');
            unlink($abandoned);
        }
    }
    foreach (glob($directory . '/job-*.json') ?: [] as $path) {
        if (!preg_match('/^job-([a-f0-9]{16})\.json$/D', basename($path), $match) || is_link($path)) continue;
        $id = $match[1];
        $job = json_decode((string)file_get_contents($path), true);
        // Claim before running to prevent a retry from producing extra archives after an interruption.
        if (!rename($path, $path . '.running')) continue;
        try {
            if (!is_array($job) || !in_array($job['type'] ?? '', ['db','full'], true)) {
                backupActivityLog($id, 'failed', 'Некорректная заявка');
                return;
            }
            $schedule = null;
            if (isset($job['scheduleId'])) {
                $query = $pdo->prepare('SELECT * FROM backup_schedules WHERE id = ?');
                $query->execute([(int)$job['scheduleId']]);
                $schedule = $query->fetch(PDO::FETCH_ASSOC);
                if (!$schedule) {
                    backupActivityLog($id, 'failed', 'Расписание удалено до начала выполнения');
                    return;
                }
            }
            $result = createBackup($job['type'], (string)($job['comment'] ?? ''), $schedule !== null,
                $schedule ? (int)$schedule['id'] : null, $schedule ? (int)$schedule['keep_count'] : MAX_BACKUPS, $id);
            if ($schedule && $result['success']) {
                $pdo->prepare('UPDATE backup_schedules SET last_run=NOW(), next_run=? WHERE id=?')->execute([
                    calculateNextRun($schedule['schedule_type'], $schedule['schedule_time'], $schedule['schedule_day']),
                    $schedule['id'],
                ]);
            }
        } catch (Throwable $error) {
            backupActivityLog($id, 'failed', 'Ошибка обработки заявки: ' . get_class($error));
        } finally {
            unlink($path . '.running');
        }
        return;
    }
}
