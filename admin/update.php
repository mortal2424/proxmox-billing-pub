<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/update_installer.php';
require_once __DIR__ . '/admin_functions.php';
if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}
$_SESSION['update_csrf'] ??= bin2hex(random_bytes(32));
$pdo = (new Database())->getConnection();
$message = '';
$error = '';
$manifest = null;
$conflicts = [];
$releases = null;
$version = is_string($_REQUEST['version'] ?? null) ? $_REQUEST['version'] : '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) ||
            !hash_equals($_SESSION['update_csrf'], $_POST['csrf_token'])) {
            throw new RuntimeException('Неверный CSRF-токен');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'check') {
            $releases = refreshUpdateCatalogCache();
            $message = 'Список обновлений проверен.';
        } elseif (in_array($action, ['preview', 'install'], true)) {
            $manifest = releaseManifest($version);
            $conflicts = previewUpdate(dirname(__DIR__), $manifest);
            if ($action === 'install') {
                installUpdate($pdo, dirname(__DIR__), $manifest, static fn () => (new Database())->getConnection());
                $message = 'Обновление установлено. Версия записана в базу данных.';
            }
        } else {
            throw new RuntimeException('Неизвестное действие обновлятора');
        }
    }
    $releases ??= updateCatalog();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    try {
        $releases = updateCatalog();
    } catch (Throwable) {
        $releases = [];
    }
}
$currentVersion = homevladVersion($pdo->query('SELECT version FROM system_versions ORDER BY id DESC LIMIT 1')->fetchColumn());
$releases = array_filter($releases, static fn (array $release): bool =>
    homevladCompareVersions(updateReleaseLabel($release), $currentVersion) > 0
);
$title = 'Обновления | HomeVlad Cloud';
require __DIR__ . '/admin_header.php';
require __DIR__ . '/admin_sidebar.php';
?>
<main class="updates-main">
<div class="updates-heading">
<h1>Обновления</h1>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['update_csrf'], ENT_QUOTES) ?>">
<button name="action" value="check">Проверить обновления</button>
</form>
</div>
<?php if (getenv('HOMEVLAD_IMMUTABLE_IMAGE') === '1'): ?>
<p>Для установки обновления пересоберите Docker-образ и пересоздайте контейнер, сохранив тома.</p>
<?php endif; ?>
<p>Установлена версия <?= htmlspecialchars($currentVersion) ?></p>
<?php if ($message): ?><p role="status"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<p>Перед обновлением сохраните резервную копию базы данных. Изменённые файлы требуют ручного слияния. При конфликтах установка всего пакета блокируется.</p>
<?php if (!$releases && !$error): ?><p>Новых пакетов в каталоге нет.</p><?php endif; ?>
<?php foreach ($releases as $release): ?>
<section class="update-release">
<h2><?= htmlspecialchars(updateReleaseLabel($release)) ?></h2>
<p><?= nl2br(htmlspecialchars($release['summary'] ?? '')) ?></p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['update_csrf']) ?>">
<input type="hidden" name="version" value="<?= htmlspecialchars(updateReleaseLabel($release)) ?>">
<button name="action" value="preview">Проверить пакет и изменения</button>
</form>
</section>
<?php endforeach; ?>
<?php if ($manifest): ?>
<section class="update-release">
<h2>Пакет <?= htmlspecialchars($manifest['version']) ?></h2>
<p><?= nl2br(htmlspecialchars($manifest['changelog'] ?? '')) ?></p>
<p>Файлов в пакете <?= count($manifest['files'] ?? []) ?></p>
<?php if ($conflicts): ?>
<h3>Требуется ручное слияние</h3>
<ul><?php foreach ($conflicts as $file): ?><li><?= htmlspecialchars($file) ?></li><?php endforeach; ?></ul>
<p>Сохраните свои изменения и перенесите в них изменения пакета. Затем выполните проверку повторно.</p>
<?php elseif (getenv('HOMEVLAD_IMMUTABLE_IMAGE') !== '1' && homevladCompareVersions($manifest['version'], $currentVersion) > 0): ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['update_csrf']) ?>">
<input type="hidden" name="version" value="<?= htmlspecialchars($manifest['version']) ?>">
<button name="action" value="install">Установить обновление</button>
</form>
<?php endif; ?>
</section>
<?php endif; ?>
</main>
<style>
.updates-main { margin-left: 280px; padding: 24px; color: var(--db-text); background: var(--db-bg); min-height: 80vh; }
.updates-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.update-release { padding: 20px; margin-block: 16px; background: var(--db-card-bg); border: 1px solid var(--db-border); border-radius: 12px; overflow-wrap: anywhere; }
.updates-main button { padding: 12px 18px; border: 1px solid var(--db-border); border-radius: 8px; background: var(--db-accent-light); color: var(--db-text); cursor: pointer; }
@media(max-width:992px) { .updates-main { margin-left: 0; padding: 16px; } }
</style>
<?php require __DIR__ . '/admin_footer.php'; ?>
