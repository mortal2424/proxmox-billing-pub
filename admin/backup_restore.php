<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup_restore.php';
require_once __DIR__ . '/admin_functions.php';
ensureSessionStarted();
checkAuth();
$pdo = (new Database())->getConnection();
$query = $pdo->prepare('SELECT is_admin,is_active,password_hash FROM users WHERE id=?');
$query->execute([(int)$_SESSION['user']['id']]);
$admin = $query->fetch(PDO::FETCH_ASSOC);
if (!$admin || !$admin['is_admin'] || !$admin['is_active']) { http_response_code(403); exit('Доступ запрещён'); }
$_SESSION['backup_csrf'] ??= bin2hex(random_bytes(32));
$filename = $_POST['backup_file'] ?? $_GET['file'] ?? '';
$error = null; $plan = null; $previewToken = '';
try {
    if (!is_string($filename) || basename($filename) !== $filename || !preg_match('/^[A-Za-z0-9_.-]+\.zip$/D', $filename)) throw new RuntimeException('Некорректное имя архива');
    $archive = __DIR__ . '/backups/' . $filename;
    $plan = inspectBackupArchive($archive);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['backup_csrf'] ?? null) || !hash_equals($_SESSION['backup_csrf'], $_POST['backup_csrf'])) throw new RuntimeException('Сессия формы истекла');
        $preview = $_SESSION['restore_preview'] ?? [];
        if (!is_string($_POST['preview_token'] ?? null) || !hash_equals($preview['token'] ?? '', $_POST['preview_token']) ||
            ($preview['file'] ?? '') !== $filename || ($preview['expires'] ?? 0) < time()) throw new RuntimeException('Предпросмотр истёк. Откройте архив снова');
        if (!is_string($_POST['confirm_password'] ?? null) || !password_verify($_POST['confirm_password'], $admin['password_hash'])) throw new RuntimeException('Неверный пароль администратора');
        if (($_POST['confirmation'] ?? '') !== 'ВОССТАНОВИТЬ') throw new RuntimeException('Введите слово ВОССТАНОВИТЬ');
        $mode = $_POST['restore_type'] ?? '';
        if (!is_string($mode)) throw new RuntimeException('Некорректный режим');
        unset($_SESSION['restore_preview']);
        session_write_close();
        $result = performBackupRestore(dirname(__DIR__), $archive, $mode, $preview['sha256']);
        session_start(); $_SESSION = []; session_regenerate_id(true); session_write_close();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Восстановление завершено</title><body style="font:18px system-ui;background:#0f172a;color:#e2e8f0;padding:8vw"><h1>Восстановление завершено</h1><p>Файлов восстановлено: ' . (int)$result['files'] . '. База данных: ' . ($result['database'] ? 'восстановлена' : 'не изменялась') . '.</p><p>Страховочная копия сохранена на сервере. Войдите снова.</p><a style="color:#6ee7b7" href="/login/login.php">Войти в панель</a></body></html>';
        exit;
    }
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Не удалось проверить архив';
    error_log('Backup restore: ' . get_class($exception));
    if (is_file(dirname(__DIR__) . '/backups/restore/recovery.json')) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Требуется аварийный откат</h1><p>' . htmlspecialchars($error) . '</p><p>Через SSH из корня сайта: <code>php cronjobs/restore_recovery.php rollback</code></p>';
        exit;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}
if ($plan) {
    $previewToken = bin2hex(random_bytes(24));
    $_SESSION['restore_preview'] = ['token' => $previewToken, 'file' => $filename, 'sha256' => hash_file('sha256', $archive), 'expires' => time() + 900];
}
$title = 'Восстановление резервной копии';
require __DIR__ . '/admin_header.php';
require __DIR__ . '/admin_sidebar.php';
?>
<main style="margin-left:var(--sidebar-width,260px);padding:24px;max-width:1100px" class="restore-content">
<style>@media(max-width:900px){.restore-content{margin-left:0!important}}.restore-content input,.restore-content select{width:100%;min-height:44px;margin:8px 0 18px}.restore-content label{display:block}.restore-content section{padding:24px;border:1px solid var(--border-color,#334155);border-radius:16px;margin:20px 0}</style>
<a href="/admin/backup.php">← Резервные копии</a>
<h1>Восстановление резервной копии</h1>
<?php if ($error): ?><p role="alert" class="alert alert-danger"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($plan): ?>
<section><h2><?= htmlspecialchars($filename) ?></h2>
<p>Создана: <?= htmlspecialchars((string)($plan['meta']['created_at'] ?? 'неизвестно')) ?> · Версия панели: <?= htmlspecialchars((string)($plan['meta']['system_version'] ?? 'не указана')) ?></p>
<p>Файлов сайта: <?= count($plan['files']) ?> · База данных: <?= $plan['database'] ? 'есть' : 'нет' ?> · Защищённых файлов пропущено: <?= $plan['skipped'] ?></p>
<p>Восстановление заменяет выбранные данные. Файлы, отсутствующие в архиве, сохраняются; таблицы базы заменяются полностью. Конфигурация, ключи, резервные копии и механизм восстановления сохраняются.</p>
<p>Перед заменой автоматически сохраняется страховочная копия. При ошибке выполняется откат. На время операции панель будет недоступна. Перед восстановлением базы остановите фоновые задания и внешние записи в неё.</p>
<details><summary>Какие файлы будут заменены</summary><ul><?php foreach ($plan['files'] as $file): ?><li><?= htmlspecialchars($file) ?></li><?php endforeach; ?></ul></details>
</section>
<form method="post" autocomplete="off">
<input type="hidden" name="backup_file" value="<?= htmlspecialchars($filename) ?>">
<input type="hidden" name="backup_csrf" value="<?= htmlspecialchars($_SESSION['backup_csrf']) ?>">
<input type="hidden" name="preview_token" value="<?= htmlspecialchars($previewToken) ?>">
<label>Компоненты<select name="restore_type"><?php if ($plan['database'] && $plan['files']): ?><option value="full">Файлы и база данных</option><?php endif; ?><?php if ($plan['database']): ?><option value="db">Только база данных</option><?php endif; ?><?php if ($plan['files']): ?><option value="files">Только файлы</option><?php endif; ?></select></label>
<label>Пароль администратора<input type="password" name="confirm_password" required autocomplete="current-password"></label>
<label>Введите ВОССТАНОВИТЬ<input name="confirmation" required pattern="ВОССТАНОВИТЬ"></label>
<button type="submit" class="btn btn-danger">Восстановить выбранные данные</button>
</form>
<?php endif; ?>
</main>
<?php require __DIR__ . '/admin_footer.php'; ?>
