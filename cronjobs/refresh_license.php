<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/license_guard.php';
try {
    $config = homevladLicenseConfig();
    if ($config === null) exit;
    [$key] = homevladLicenseCredentials($config);
    if ($key === '') exit;
    $pdo = (new Database())->getConnection();
    homevladLicenseLease($config, homevladLicenseUsage($pdo));
} catch (Throwable $error) {
    fwrite(STDERR, "HomeVlad license refresh unavailable\n");
    exit(1);
}
