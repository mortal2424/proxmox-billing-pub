<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/update_queue.php';
require_once __DIR__ . '/../includes/db.php';

try {
    runQueuedPanelUpdate(dirname(__DIR__), static fn () => (new Database())->getConnection());
} catch (Throwable $error) {
    error_log('HomeVlad update worker: ' . $error->getMessage());
}

try {
    refreshUpdateCatalogCache();
} catch (Throwable $error) {
    error_log('HomeVlad update check failed: ' . $error->getMessage());
    exit(1);
}
