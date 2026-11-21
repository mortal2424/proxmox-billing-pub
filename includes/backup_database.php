<?php

/** Run the MySQL client without putting credentials in arguments, environment or logs. */
function backupDatabaseCommand(string $mode, string $sqlFile): void
{
    if ($mode === 'import' && (!is_file($sqlFile) || !is_readable($sqlFile) || filesize($sqlFile) < 100)) throw new RuntimeException('Нет корректного дампа для импорта');
    $candidates = $mode === 'export' ? ['/usr/bin/mariadb-dump', '/usr/bin/mysqldump'] : ['/usr/bin/mariadb', '/usr/bin/mysql'];
    $binary = null;
    foreach ($candidates as $candidate) if (is_executable($candidate)) { $binary = $candidate; break; }
    if (!$binary || !is_callable('proc_open')) throw new RuntimeException('Нужен установленный клиент MariaDB/MySQL и доступ к proc_open');
    $directory = dirname($sqlFile);
    $settings = tempnam($directory, '.mysql-');
    if ($settings === false || !chmod($settings, 0600)) throw new RuntimeException('Не удалось создать закрытую конфигурацию клиента БД');
    $quote = static fn($value) => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string)$value) . '"';
    $errorFile = $settings . '.error';
    try {
        $config = "[client]\nhost=" . $quote(DB_HOST) . "\nuser=" . $quote(DB_USER) . "\npassword=" . $quote(DB_PASS) . "\n";
        if (defined('DB_PORT')) $config .= 'port=' . (int)DB_PORT . "\n";
        if (file_put_contents($settings, $config) === false) throw new RuntimeException('Не удалось записать конфигурацию клиента БД');
        file_put_contents($errorFile, ''); chmod($errorFile, 0600);
        $args = [$binary, '--defaults-file=' . $settings, '--default-character-set=utf8mb4'];
        // Match the bundled MariaDB service, which is reachable only on the Compose network.
        if (getenv('HOMEVLAD_BACKUP_DB_LOCAL') === '1' && DB_HOST === 'db' &&
            str_starts_with(basename($binary), 'mariadb')) {
            $args[] = '--ssl=0';
        }
        if ($mode === 'export') {
            $args = array_merge($args, ['--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--', DB_NAME]);
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $sqlFile, 'w'], 2 => ['file', $errorFile, 'w']];
        } elseif ($mode === 'import') {
            // A restore replaces the database, including tables absent from the selected backup.
            $pdo = new PDO('mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . (int)DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($objects as [$name, $type]) {
                $pdo->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . str_replace('`', '``', $name) . '`');
            }
            $pdo = null;
            $args = array_merge($args, ['--binary-mode', '--local-infile=0', '--database=' . DB_NAME]);
            $descriptors = [0 => ['file', $sqlFile, 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errorFile, 'w']];
        } else throw new InvalidArgumentException('Неизвестная операция БД');
        $process = proc_open($args, $descriptors, $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException($mode === 'export' ? 'Не удалось создать дамп БД. Проверьте доступ клиента MySQL к базе.' : 'Не удалось импортировать дамп БД');
        }
        if ($mode === 'export' && (filesize($sqlFile) < 100 || !chmod($sqlFile, 0600))) throw new RuntimeException('Получен пустой дамп БД');
    } finally {
        if (is_file($settings)) unlink($settings);
        if (is_file($errorFile)) unlink($errorFile);
    }
}

function backupOperationLock(string $root)
{
    $directory = $root . '/backups/updates';
    if (is_link($root . '/backups') || is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true)) ||
        is_link($directory . '/installer.lock')) throw new RuntimeException('Недоступно хранилище блокировок');
    $lock = fopen($directory . '/installer.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Копирование, обновление или восстановление уже выполняется');
    return $lock;
}
