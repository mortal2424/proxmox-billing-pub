<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/update_catalog.php';

try {
    refreshUpdateCatalogCache();
} catch (Throwable $error) {
    error_log('HomeVlad update check failed: ' . $error->getMessage());
    exit(1);
}
