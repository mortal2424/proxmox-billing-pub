<?php
require_once __DIR__ . '/../includes/cli_only.php';
define('HOMEVLAD_UPDATER_CONTEXT', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/backup_restore.php';
$root = dirname(__DIR__);
$journalPath = $root . '/backups/restore/recovery.json';
if (($argv[1] ?? '') !== 'rollback') { fwrite(STDERR, "Usage: php cronjobs/restore_recovery.php rollback\n"); exit(2); }
$lock = fopen($root . '/backups/updates/installer.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Операция уже выполняется');
try {
    if (!is_file($journalPath) || is_link($journalPath)) throw new RuntimeException('Журнал восстановления не найден');
    $journal = json_decode(file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
    $work = realpath($journal['work'] ?? '');
    if (!$work || dirname($work) !== realpath($root . '/backups/restore')) throw new RuntimeException('Некорректный каталог страховочной копии');
    rollbackBackupRestore($root, $journal);
    restoreWriteJson($work . '/result.json', ['success' => false, 'rolled_back' => true, 'manual' => true]);
    if (!unlink($journalPath)) throw new RuntimeException('Не удалось закрыть журнал');
    echo "Откат завершён. Панель открыта.\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
