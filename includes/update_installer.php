<?php
require_once __DIR__ . '/update_catalog.php';
require_once __DIR__ . '/version.php';

function updateTarget(string $root, string $path): string
{
    if (($path !== '.htaccess' && !preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_./ -]*$~D', $path)) ||
        (in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) || str_contains($path, '//') ||
        preg_match('~^(?:vendor|uploads|logs|backups|\.git)(?:/|$)|^includes/config\.php$~', $path)) {
        throw new RuntimeException('Недопустимый путь обновления');
    }
    $current = $root;
    foreach (explode('/', $path) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
            throw new RuntimeException("Символическая ссылка в пути $path");
        }
    }
    return $current;
}

function previewUpdate(string $root, array $manifest): array
{
    $files = $manifest['files'] ?? [];
    if (!is_array($files) || !$files || count($files) > 5000 ||
        !preg_match('/^[a-f0-9]{40}$/D', $manifest['source_commit'] ?? '')) {
        throw new RuntimeException('Некорректный список файлов');
    }
    $conflicts = [];
    foreach ($files as $path => $file) {
        $target = updateTarget($root, $path);
        if (!preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '') ||
            (($file['before'] ?? null) !== null && !preg_match('/^[a-f0-9]{64}$/D', $file['before']))) {
            throw new RuntimeException('Некорректные контрольные суммы');
        }
        $hash = is_file($target) ? hash_file('sha256', $target) : null;
        if ($hash !== $file['sha256'] && $hash !== ($file['before'] ?? null)) {
            $conflicts[] = $path;
        }
    }
    return $conflicts;
}

function replaceUpdateFile(string $source, string $target, int $mode): void
{
    $directory = dirname($target);
    $temp = @tempnam($directory, '.update-');
    try {
        // tempnam can fall back to the system temp directory when the target directory is unwritable.
        if ($temp === false || dirname($temp) !== realpath($directory) ||
            !copy($source, $temp) || !chmod($temp, $mode) || !rename($temp, $target)) {
            throw new RuntimeException('Не удалось заменить файл');
        }
    } finally {
        if ($temp !== false && is_file($temp)) {
            unlink($temp);
        }
    }
}

function installUpdate(PDO $pdo, string $root, array $manifest, ?Closure $connect = null, ?Closure $progress = null): void
{
    if (getenv('HOMEVLAD_IMMUTABLE_IMAGE') === '1') {
        http_response_code(403);
        throw new RuntimeException('Обновите Docker-образ и пересоздайте контейнер, сохранив тома.');
    }
    if ($connect) {
        $pdo = $connect();
    }
    $storage = $root . '/backups/updates';
    if (!is_dir($storage) && !mkdir($storage, 0700, true)) {
        throw new RuntimeException('Не удалось создать каталог обновлений');
    }
    if (is_link($root . '/backups') || is_link($storage)) {
        throw new RuntimeException('Каталог обновлений не должен быть ссылкой');
    }
    if (is_link($storage . '/installer.lock') || is_link($storage . '/recovery.json')) {
        throw new RuntimeException('Недопустимая ссылка в каталоге обновлений');
    }
    $lock = fopen($storage . '/installer.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Другое обновление уже выполняется');
    }
    $work = $storage . '/' . bin2hex(random_bytes(16));
    $applied = [];
    $committing = false;
    $journalWritten = false;
    try {
        if (is_file($storage . '/recovery.json') || is_file($root . '/backups/restore/recovery.json')) {
            throw new RuntimeException('Предыдущее обновление прервано. Требуется восстановление по журналу');
        }
        $current = homevladVersion($pdo->query('SELECT version FROM system_versions ORDER BY id DESC LIMIT 1')->fetchColumn());
        if (homevladCompareVersions($manifest['version'], $current) <= 0 || ($manifest['from'] ?? '') !== $current) {
            throw new RuntimeException('Обновление не соответствует установленной версии');
        }
        if (previewUpdate($root, $manifest)) {
            throw new RuntimeException('Есть изменённые файлы. Выполните ручное слияние до установки');
        }
        if (!mkdir($work, 0700)) {
            throw new RuntimeException('Не удалось подготовить обновление');
        }
        $total = count($manifest['files']);
        $downloaded = 0;
        if ($progress) $progress('downloading', 0, $total);
        foreach ($manifest['files'] as $path => $file) {
            $target = updateTarget($root, $path);
            $bytes = fetchUpdateDocument($manifest['source_commit'] . '/' . str_replace('%2F', '/', rawurlencode($path)), 16777216);
            if (!hash_equals($file['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException("Повреждён файл $path");
            }
            $staged = $work . '/' . hash('sha256', $path);
            if (file_put_contents($staged, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Не удалось сохранить пакет');
            }
            if (is_file($target) && (!copy($target, $staged . '.before') || !chmod($staged . '.before', fileperms($target) & 0777))) {
                throw new RuntimeException("Не удалось сохранить исходный файл $path");
            }
            if ($progress) $progress('downloading', ++$downloaded, $total);
        }
        if (previewUpdate($root, $manifest)) {
            throw new RuntimeException('Файлы изменились во время подготовки');
        }
        if ($connect) {
            $pdo = $connect();
        }
        $current = homevladVersion($pdo->query('SELECT version FROM system_versions ORDER BY id DESC LIMIT 1')->fetchColumn());
        if (($manifest['from'] ?? '') !== $current) {
            throw new RuntimeException('Установленная версия изменилась во время загрузки');
        }
        $pdo->beginTransaction();
        $journal = ['work' => $work, 'files' => $manifest['files'], 'version' => $manifest['version']];
        $encoded = json_encode($journal, JSON_THROW_ON_ERROR);
        $journalDraft = $work . '/journal.json';
        if (file_put_contents($journalDraft, $encoded, LOCK_EX) !== strlen($encoded) ||
            !rename($journalDraft, $storage . '/recovery.json')) {
            throw new RuntimeException('Не удалось создать журнал восстановления');
        }
        $journalWritten = true;
        if ($progress) $progress('applying', 0, $total);
        foreach ($manifest['files'] as $path => $file) {
            $target = updateTarget($root, $path);
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException("Не удалось создать каталог $path");
            }
            try {
                replaceUpdateFile($work . '/' . hash('sha256', $path), $target, 0644);
            } catch (RuntimeException $error) {
                throw new RuntimeException("Не удалось установить $path", 0, $error);
            }
            $applied[] = $path;
            if ($progress) $progress('applying', count($applied), $total);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }
        }
        $query = $pdo->prepare('INSERT INTO system_versions (version, release_date, description) VALUES (?, CURDATE(), ?)');
        $query->execute([$manifest['version'], $manifest['changelog'] ?? '']);
        $query = $pdo->prepare("INSERT INTO system_updates (version, update_type, description, success, backup_path)
            VALUES (?, 'upgrade', ?, 1, ?)");
        $query->execute([$manifest['version'], $manifest['changelog'] ?? '', $work]);
        $committing = true;
        $pdo->commit();
        $committing = false;
        unlink($storage . '/recovery.json');
    } catch (Throwable $error) {
        if ($committing) {
            try {
                if (!$connect) {
                    throw new RuntimeException('Нужна проверка результата фиксации БД');
                }
                $verification = $connect();
                $query = $verification->prepare('SELECT COUNT(*) FROM system_versions WHERE version = ?');
                $query->execute([$manifest['version']]);
                if ((int) $query->fetchColumn() > 0) {
                    unlink($storage . '/recovery.json');
                    return;
                }
                throw new RuntimeException('Результат фиксации не подтверждён');
            } catch (Throwable $verificationError) {
                throw new RuntimeException('Не удалось проверить фиксацию БД. Сохранён журнал восстановления', 0, $error);
            }
        }
        $rollbackConfirmed = true;
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            $rollbackConfirmed = false;
            error_log('Update database rollback could not be confirmed');
        }
        $restored = true;
        foreach (array_reverse($applied) as $path) {
            $target = updateTarget($root, $path);
            $backup = $work . '/' . hash('sha256', $path) . '.before';
            try {
                if (is_file($backup)) {
                    replaceUpdateFile($backup, $target, fileperms($backup) & 0777);
                } elseif (!unlink($target)) {
                    throw new RuntimeException('Не удалось удалить добавленный файл');
                }
            } catch (Throwable $restoreError) {
                $restored = false;
                error_log('Could not restore update file: ' . $path);
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }
        }
        if ($journalWritten && $rollbackConfirmed && $restored) {
            unlink($storage . '/recovery.json');
        }
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
