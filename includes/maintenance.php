<?php

function ensureApplicationAvailable(): void
{
    if (!is_file(dirname(__DIR__) . '/backups/updates/recovery.json') ||
        (defined('HOMEVLAD_UPDATER_CONTEXT') && HOMEVLAD_UPDATER_CONTEXT)) {
        return;
    }
    $entry = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $root = dirname(__DIR__);
    if (in_array($entry, [$root . '/admin/update.php', $root . '/admin/ajax_apply_update.php'], true)) {
        return;
    }
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
    }
    exit('Выполняется обновление панели. Повторите запрос позже.');
}
