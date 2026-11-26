<?php
require_once __DIR__ . '/update_installer.php';

function updateQueuePath(string $root): string
{
    $directory = $root . '/backups/updates';
    if (is_link($root . '/backups') || is_link($directory) ||
        (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
        throw new RuntimeException('Каталог очереди обновлений недоступен');
    }
    $path = $directory . '/background-job.json';
    if (is_link($path) || is_link($path . '.lock')) throw new RuntimeException('Недопустимый путь очереди');
    return $path;
}

function readUpdateJob(string $path, int $limit = 8192): ?array
{
    if (!is_file($path)) return null;
    if (is_link($path)) throw new RuntimeException('Недопустимый путь журнала');
    $raw = file_get_contents($path, false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) throw new RuntimeException('Не удалось прочитать состояние обновления');
    return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
}

function readPanelUpdateLog(string $root): ?array
{
    return readUpdateJob(dirname(updateQueuePath($root)) . '/last-install.json', 1048576);
}

function writeUpdateJob(string $path, array $state): void
{
    $file = tempnam(dirname($path), '.job-');
    if ($file === false) throw new RuntimeException('Не удалось подготовить состояние обновления');
    try {
        $body = json_encode($state + ['updated_at'=>time()], JSON_THROW_ON_ERROR);
        if (dirname($file) !== realpath(dirname($path)) || file_put_contents($file, $body) !== strlen($body) ||
            !chmod($file, 0600) || !rename($file, $path)) {
            throw new RuntimeException('Не удалось сохранить состояние обновления');
        }
    } finally {
        if (is_file($file)) unlink($file);
    }
}

function withUpdateQueueLock(string $path, callable $action): mixed
{
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Очередь обновлений недоступна');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Другое обновление уже выполняется');
        return $action();
    } finally {
        fclose($lock);
    }
}

function enqueuePanelUpdate(string $root, string $version): void
{
    if (getenv('HOMEVLAD_IMMUTABLE_IMAGE') === '1') {
        throw new RuntimeException('Для Docker обновите образ и пересоздайте контейнер.');
    }
    if (!isUpdateVersion($version)) throw new RuntimeException('Некорректная версия обновления');
    $path = updateQueuePath($root);
    withUpdateQueueLock($path, static function () use ($path, $version): void {
        $job = readUpdateJob($path);
        if (in_array($job['state'] ?? null, ['queued','downloading','applying'], true)) {
            throw new RuntimeException('Другое обновление уже ожидает запуска или выполняется');
        }
        writeUpdateJob($path, ['state'=>'queued','version'=>$version,'done'=>0,'total'=>0]);
    });
}

function runQueuedPanelUpdate(string $root, Closure $connect): void
{
    if (getenv('HOMEVLAD_IMMUTABLE_IMAGE') === '1') return;
    $path = updateQueuePath($root);
    if (!in_array(readUpdateJob($path)['state'] ?? null, ['queued','downloading','applying'], true)) return;
    withUpdateQueueLock($path, static function () use ($path, $root, $connect): void {
        $job = readUpdateJob($path);
        if (!in_array($job['state'] ?? null, ['queued','downloading','applying'], true)) return;
        $version = $job['version'];
        $logPath = dirname($path) . '/last-install.json';
        $files = [];
        if ($job['state'] !== 'queued') {
            try {
                $previous = readPanelUpdateLog($root);
                if (($previous['version'] ?? null) === $version) $files = $previous['files'] ?? [];
            } catch (Throwable) {
                error_log('Could not read previous update log');
            }
        }
        $progress = static function (string $state, int $done = 0, int $total = 0, string $message = '',
            ?array $file = null) use ($path, $version, $logPath, &$files): void {
            if ($file !== null) $files[] = $file;
            try {
                $stateData = compact('state','version','done','total','message');
                writeUpdateJob($path, $stateData);
                if ($file !== null || $done === 0 || $state === 'completed') {
                    writeUpdateJob($logPath, $stateData + ['files'=>$files]);
                }
            } catch (Throwable $error) {
                error_log('Could not write update progress: ' . $error->getMessage());
            }
        };
        try {
            $db = $connect();
            if ($job['state'] !== 'queued') {
                $current = homevladVersion($db->query('SELECT version FROM system_versions ORDER BY id DESC LIMIT 1')->fetchColumn());
                if ($current === $version && !is_file(dirname($path) . '/recovery.json')) {
                    $progress('completed', 1, 1);
                    return;
                }
                throw new RuntimeException('Фоновый процесс прервался. Проверьте версию и журнал восстановления перед повтором.');
            }
            $progress('downloading');
            $manifest = releaseManifest($version);
            installUpdate($db, $root, $manifest, $connect, $progress);
            $progress('completed', 1, 1);
        } catch (Throwable $error) {
            $progress('failed', 0, 0, $error->getMessage());
            error_log('Background update failed: ' . $error->getMessage());
        }
    });
}
