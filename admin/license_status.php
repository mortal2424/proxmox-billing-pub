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
require __DIR__ . '/admin_sidebar.php';
?>
<style>
.license-page{margin-inline-start:280px;padding:clamp(1rem,3vw,2rem);color:var(--admin-header-text);min-height:calc(100vh - 70px)}
.admin-sidebar.compact + style + .license-page{margin-inline-start:70px}
.license-page h1{margin:0 0 1rem;font-size:clamp(1.5rem,3vw,2rem)}
.license-card{padding:clamp(1rem,2.5vw,1.5rem);background:var(--admin-card-bg);border:1px solid var(--admin-header-border);border-radius:14px;max-width:64rem}
.license-card p{margin:0 0 1rem}.license-card p:last-child{margin:1rem 0 0}
.license-table{overflow:auto}.license-table table{width:100%;min-width:22rem;border-collapse:collapse;text-align:left}
.license-table th,.license-table td{padding:.65rem .85rem;border-bottom:1px solid var(--admin-header-border)}
.license-table thead{background:var(--admin-hover-bg);color:var(--admin-header-text-secondary)}
@media(max-width:1200px){.license-page{margin-inline-start:70px}}
@media(max-width:768px){.license-page{margin-inline-start:0!important}}
</style>
<main class="main-content license-page">
    <h1>Лицензия панели</h1>
    <section class="license-card" aria-label="Статус и лимиты лицензии">
    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <p>Домен <strong><?= htmlspecialchars($claims['domain']) ?></strong>, тариф <strong><?= htmlspecialchars($claims['plan']) ?></strong>.</p>
        <div class="license-table"><table>
            <thead><tr><th>Ресурс</th><th>Использовано</th><th>Лимит</th></tr></thead>
            <tbody>
            <?php foreach (HOMEVLAD_LICENSE_LABELS as $metric => $label): ?>
                <tr><th scope="row"><?= htmlspecialchars($label) ?></th><td><?= $usage[$metric] ?></td>
                    <td><?= $claims['limits'][$metric] ?? 'Без лимита' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p>Подтверждение действует до <?= gmdate('d.m.Y H:i', $claims['valid_until']) ?> UTC.</p>
    <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/admin_footer.php'; ?>
