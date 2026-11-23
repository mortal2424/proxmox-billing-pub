<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';

$pdo = (new Database())->getConnection();
requireNodeAdministrator($pdo);
header('Cache-Control: no-store');
$title = 'Лицензия панели';
$usage = [];
$claims = null;
$error = null;
try {
    $config = homevladLicenseConfig();
    if ($config === null) throw new DomainException('Лицензирование не настроено.');
    $usage = homevladLicenseUsage($pdo);
    $claims = homevladLicenseLease($config, $usage);
} catch (Throwable $exception) {
    $error = $exception instanceof DomainException ? $exception->getMessage() : 'Лицензия недоступна.';
}
require __DIR__ . '/admin_header.php';
?>
<main>
    <h1>Лицензия панели</h1>
    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <p>Домен <strong><?= htmlspecialchars($claims['domain']) ?></strong>, тариф <strong><?= htmlspecialchars($claims['plan']) ?></strong>.</p>
        <table>
            <thead><tr><th>Ресурс</th><th>Использовано</th><th>Лимит</th></tr></thead>
            <tbody>
            <?php foreach (HOMEVLAD_LICENSE_LABELS as $metric => $label): ?>
                <tr><th scope="row"><?= htmlspecialchars($label) ?></th><td><?= $usage[$metric] ?></td>
                    <td><?= $claims['limits'][$metric] ?? 'Без лимита' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>Подтверждение действует до <?= gmdate('d.m.Y H:i', $claims['valid_until']) ?> UTC.</p>
    <?php endif; ?>
</main>
