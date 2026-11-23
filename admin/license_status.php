<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/admin_functions.php';

$pdo = (new Database())->getConnection();
requireNodeAdministrator($pdo);
header('Cache-Control: no-store');
$title = 'Лицензия панели';
$usage = [];
$claims = null;
$request = null;
$status = ['mode' => 'restricted'];
$csrf = $_SESSION['license_csrf'] ??= bin2hex(random_bytes(32));
$error = null;
try {
    $config = homevladLicenseConfig();
    if ($config === null) throw new DomainException('Лицензирование не настроено.');
    $request = homevladLicenseRequest($config);
    $force = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403);
            exit('Обновите страницу и повторите действие.');
        }
        if (($_POST['action'] ?? '') === 'activate') {
            $key = $_POST['license_key'] ?? '';
            if (!is_string($key) || strlen($key) > 128) throw new DomainException('Проверьте формат ключа лицензии.');
            homevladActivateLicense($pdo, $config, trim($key));
        } elseif (($_POST['action'] ?? '') === 'refresh') {
            $force = true;
        }
    }
    $usage = homevladLicenseUsage($pdo);
    $status = homevladLicenseMode($config, $usage, $force);
    $claims = $status['claims'] ?? null;
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
.license-activation{margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--admin-header-border)}
.license-activation h2{margin:.8rem 0 .4rem;font-size:1.1rem}.license-form{display:grid;gap:.5rem;margin-top:.7rem}
.license-input{box-sizing:border-box;width:100%;padding:.65rem;border:1px solid var(--admin-header-border);border-radius:7px;background:var(--admin-hover-bg);color:var(--admin-header-text)}
.license-form button{justify-self:start;padding:.55rem .85rem;border:0;border-radius:7px;background:var(--admin-accent-hover);color:white;cursor:pointer}
.license-table thead{background:var(--admin-hover-bg);color:var(--admin-header-text-secondary)}
@media(max-width:1200px){.license-page{margin-inline-start:70px}}
@media(max-width:768px){.license-page{margin-inline-start:0!important}}
</style>
<main class="main-content license-page">
    <h1>Лицензия панели</h1>
    <section class="license-card" aria-label="Статус и лимиты лицензии">
    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php elseif ($status['mode'] === 'trial'): ?>
        <p>Пробный период действует до <?= gmdate('d.m.Y H:i', $status['until']) ?> UTC.</p>
    <?php elseif ($status['mode'] === 'restricted'): ?>
        <p>Ограниченный режим. Управление существующими ВМ доступно. Активируйте лицензию для добавления ресурсов.</p>
    <?php elseif ($claims): ?>
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
        <form method="post" class="license-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" name="action" value="refresh">Обновить лимиты</button></form>
    <?php endif; ?>
    <?php if ($request): ?>
    <div class="license-activation">
        <h2>Код запроса</h2><p>Передайте его администратору центра лицензирования.</p>
        <textarea class="license-input" aria-label="Код установки" readonly rows="4"><?= htmlspecialchars($request) ?></textarea>
        <h2>Активация</h2><form method="post" class="license-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <label for="license-key">Ключ от администратора</label>
            <input class="license-input" id="license-key" name="license_key" type="password" maxlength="128" required autocomplete="off" placeholder="dvl1_…">
            <button type="submit" name="action" value="activate">Активировать</button>
        </form>
    </div>
    <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/admin_footer.php'; ?>
