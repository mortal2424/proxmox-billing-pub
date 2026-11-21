<?php
require_once __DIR__ . '/backup_database.php';

function backupRestorePath(string $path): bool
{
    if ($path === '' || strlen($path) > 1024 || str_contains($path, '\\') || str_contains($path, "\0") ||
        str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f:]/', $path)) return false;
    foreach (explode('/', rtrim($path, '/')) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
    return true;
}

function backupRestoreProtected(string $path): bool
{
    $parts = explode('/', strtolower($path));
    foreach ($parts as $part) {
        if (in_array($part, ['.git', 'backups', 'logs', 'node_modules', 'vendor', 'cache', 'tmp'], true) ||
            str_starts_with($part, '.env') || preg_match('/(?:^config\.php$|\.(?:pem|key|cnf)$)/', $part)) return true;
    }
    return in_array(strtolower($path), ['includes/db.php', 'includes/maintenance.php', 'includes/backup_database.php',
        'includes/backup_restore.php', 'admin/backup_restore.php', 'cronjobs/restore_recovery.php'], true);
}

/** Inspect names before ever extracting data. No extractTo(): every destination is checked individually. */
function inspectBackupArchive(string $filename): array
{
    if (!is_file($filename) || is_link($filename)) throw new RuntimeException('Архив не найден');
    $zip = new ZipArchive();
    if ($zip->open($filename, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Архив повреждён');
    try {
        if ($zip->numFiles > 50000) throw new RuntimeException('В архиве слишком много файлов');
        $metaIndex = $zip->locateName('backup_meta.json');
        if ($metaIndex === false || $zip->statIndex($metaIndex)['size'] > 65536) throw new RuntimeException('Нет корректных метаданных архива');
        $meta = json_decode($zip->getFromIndex($metaIndex), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($meta) || !in_array($meta['type'] ?? '', ['db', 'files', 'full'], true)) throw new RuntimeException('Неизвестный тип резервной копии');
        foreach (['comment', 'created_at', 'system_version'] as $field) {
            if (isset($meta[$field]) && !is_string($meta[$field])) throw new RuntimeException('Некорректные метаданные архива');
        }
        $files = []; $seen = []; $total = 0; $skipped = 0; $database = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = $stat['name'];
            if (!backupRestorePath($name) || isset($seen[$name])) throw new RuntimeException('Недопустимые или повторяющиеся пути в архиве');
            $seen[$name] = true;
            $opsys = 0; $attributes = 0;
            if (!$zip->getExternalAttributesIndex($i, $opsys, $attributes)) throw new RuntimeException('Не удалось проверить атрибуты ZIP');
            $kind = ($attributes >> 16) & 0170000;
            if ($opsys === 3 && !in_array($kind, [0, 0100000, 0040000], true)) throw new RuntimeException('Ссылки и специальные файлы в архиве запрещены');
            $total += $stat['size'];
            if ($stat['size'] > 536870912 || $total > 2147483648 || !empty($stat['encryption_method'])) throw new RuntimeException('Превышен лимит размера архива или архив зашифрован');
            if (str_ends_with($name, '/') || $name === 'backup_meta.json') continue;
            if ($name === 'database/database_dump.sql') {
                if ($stat['size'] < 100) throw new RuntimeException('Дамп БД пуст или повреждён');
                $database = true; continue;
            }
            if (str_starts_with($name, 'database/')) throw new RuntimeException('Неизвестный файл в разделе базы данных');
            if (backupRestoreProtected($name)) { $skipped++; continue; }
            // Uploaded data must never add a PHP executable to the uploads directory.
            if (str_starts_with(strtolower($name), 'uploads/') && preg_match('/\.(?:php\d*|phtml|phar|htaccess)$/i', $name)) throw new RuntimeException('Исполняемый файл в uploads запрещён');
            $files[] = $name;
        }
        if (in_array($meta['type'], ['db', 'full'], true) && !$database) throw new RuntimeException('В архиве отсутствует дамп БД');
        if (in_array($meta['type'], ['files', 'full'], true) && !$files) throw new RuntimeException('В архиве отсутствуют файлы сайта');
        return ['meta' => $meta, 'files' => $files, 'database' => $database, 'bytes' => $total, 'skipped' => $skipped];
    } finally { $zip->close(); }
}

function restoreWriteJson(string $path, array $value): void
{
    $temporary = $path . '.part';
    if (file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false ||
        !chmod($temporary, 0600) || !rename($temporary, $path)) throw new RuntimeException('Не удалось сохранить журнал восстановления');
}

function restoreSafeTarget(string $root, string $path): string
{
    if (!backupRestorePath($path) || backupRestoreProtected($path)) throw new RuntimeException('Защищённый путь восстановления');
    $current = $root;
    foreach (explode('/', $path) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) throw new RuntimeException('Целевой путь содержит символическую ссылку');
    }
    if (file_exists($current) && !is_file($current)) throw new RuntimeException('Целевой путь занят каталогом');
    return $current;
}

function restoreAtomicCopy(string $source, string $target, int $mode = 0644): void
{
    if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) throw new RuntimeException('Не удалось создать целевой каталог');
    $temporary = $target . '.restore-' . bin2hex(random_bytes(8));
    try {
        if (!copy($source, $temporary) || !chmod($temporary, $mode) || !rename($temporary, $target)) throw new RuntimeException('Не удалось заменить файл');
        if (function_exists('opcache_invalidate')) opcache_invalidate($target, true);
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function rollbackBackupRestore(string $root, array $journal): void
{
    $work = $journal['work'];
    if ($journal['database']) backupDatabaseCommand('import', $work . '/database-before.sql');
    foreach (array_reverse($journal['files'], true) as $path => $info) {
        $target = restoreSafeTarget($root, $path);
        if ($info['existed']) {
            $before = $work . '/before/' . $path;
            clearstatcache(true, $target);
            $originalHash = hash_file('sha256', $before);
            if ($originalHash !== false && is_file($target) && (fileperms($target) & 0777) === $info['mode'] &&
                hash_file('sha256', $target) === $originalHash) {
                continue;
            }
            restoreAtomicCopy($before, $target, $info['mode']);
        } elseif (is_file($target) && !unlink($target)) {
            throw new RuntimeException('Не удалось откатить добавленный файл');
        }
    }
}

/** Restore only after explicit administrator confirmation. Never invoked by an updater or cron. */
function performBackupRestore(string $root, string $archive, string $mode, string $expectedHash): array
{
    if (!in_array($mode, ['db', 'files', 'full'], true)) throw new RuntimeException('Неизвестный режим восстановления');
    ignore_user_abort(true); set_time_limit(0);
    $storage = $root . '/backups/restore'; $updates = $root . '/backups/updates';
    foreach ([$root . '/backups', $storage, $updates] as $dir) {
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) throw new RuntimeException('Недоступно закрытое хранилище восстановления');
    }
    foreach ([$updates . '/installer.lock', $storage . '/recovery.json'] as $path) if (is_link($path)) throw new RuntimeException('Недопустимая ссылка в хранилище');
    $lock = fopen($updates . '/installer.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Обновление или восстановление уже выполняется');
    $journalPath = $storage . '/recovery.json'; $journal = null; $started = false;
    try {
        if (is_file($journalPath) || is_file($updates . '/recovery.json')) throw new RuntimeException('Есть незавершённая операция. Сначала выполните восстановление по журналу');
        $plan = inspectBackupArchive($archive);
        if (!hash_equals($expectedHash, hash_file('sha256', $archive))) throw new RuntimeException('Архив изменился после предпросмотра');
        $db = $mode !== 'files'; $files = $mode !== 'db' ? $plan['files'] : [];
        if (($db && !$plan['database']) || ($mode !== 'db' && !$files)) throw new RuntimeException('В архиве нет выбранного компонента');
        if (disk_free_space($storage) < $plan['bytes'] * 3 + 104857600) throw new RuntimeException('Недостаточно свободного места для распаковки и страховочной копии');
        $work = $storage . '/' . bin2hex(random_bytes(16));
        if (!mkdir($work, 0700)) throw new RuntimeException('Не удалось подготовить восстановление');
        if (!copy($archive, $work . '/source.zip') || !hash_equals($expectedHash, hash_file('sha256', $work . '/source.zip'))) throw new RuntimeException('Не удалось сохранить проверенную копию архива');
        chmod($work . '/source.zip', 0600);
        $zip = new ZipArchive();
        if ($zip->open($work . '/source.zip', ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Архив недоступен');
        $phpCli = null;
        foreach ([PHP_SAPI === 'cli' ? PHP_BINARY : '', '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if ($candidate && is_executable($candidate)) { $phpCli = $candidate; break; }
        }
        $journal = ['work' => $work, 'archive_sha256' => $expectedHash, 'database' => $db, 'files' => [], 'created_at' => date(DATE_ATOM)];
        try {
            foreach (array_merge($files, $db ? ['database/database_dump.sql'] : []) as $path) {
                $stage = $work . '/stage/' . $path;
                if (!is_dir(dirname($stage)) && !mkdir(dirname($stage), 0700, true)) throw new RuntimeException('Не удалось создать каталог распаковки');
                $input = $zip->getStream($path); $output = fopen($stage, 'xb');
                if (!$input || !$output) throw new RuntimeException('Не удалось распаковать файл');
                try { $copied = stream_copy_to_stream($input, $output); }
                finally { fclose($input); fclose($output); }
                $stat = $zip->statName($path);
                if ($copied !== $stat['size'] || strtolower(hash_file('crc32b', $stage)) !== sprintf('%08x', $stat['crc'])) throw new RuntimeException('Нарушена целостность файла в архиве');
                chmod($stage, 0600);
                if ($path === 'database/database_dump.sql') continue;
                if (preg_match('/\.(?:php\d*|phtml)$/i', $path)) {
                    if (!$phpCli) throw new RuntimeException('Для проверки PHP-файлов нужен PHP CLI');
                    $process = proc_open([$phpCli, '-n', '-l', $stage], [0 => ['file', '/dev/null', 'r'],
                        1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('PHP-файл архива не совместим с установленной версией PHP: ' . $path);
                }
                $target = restoreSafeTarget($root, $path); $existed = is_file($target);
                $journal['files'][$path] = ['existed' => $existed, 'mode' => $existed ? fileperms($target) & 0777 : 0644];
                if ($existed) {
                    $before = $work . '/before/' . $path;
                    if (!is_dir(dirname($before))) mkdir(dirname($before), 0700, true);
                    if (!copy($target, $before)) throw new RuntimeException('Не удалось сохранить исходный файл');
                    chmod($before, 0600);
                }
            }
        } finally { $zip->close(); }
        if ($db) backupDatabaseCommand('export', $work . '/database-before.sql');
        restoreWriteJson($work . '/plan.json', $journal);
        restoreWriteJson($journalPath, $journal); $started = true;
        if ($db) backupDatabaseCommand('import', $work . '/stage/database/database_dump.sql');
        foreach ($journal['files'] as $path => $info) restoreAtomicCopy($work . '/stage/' . $path, restoreSafeTarget($root, $path), $info['mode']);
        restoreWriteJson($work . '/result.json', ['success' => true, 'finished_at' => date(DATE_ATOM)]);
        if (!unlink($journalPath)) throw new RuntimeException('Не удалось завершить журнал восстановления');
        return ['files' => count($journal['files']), 'database' => $db, 'safety_directory' => $work];
    } catch (Throwable $error) {
        if ($started) {
            try {
                rollbackBackupRestore($root, $journal);
                restoreWriteJson($journal['work'] . '/result.json', ['success' => false, 'rolled_back' => true]);
                unlink($journalPath);
            } catch (Throwable $rollbackError) {
                throw new RuntimeException('Восстановление прервано, автоматический откат не завершён. Сайт закрыт; используйте cronjobs/restore_recovery.php через SSH.', 0, $error);
            }
        }
        throw $error;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
