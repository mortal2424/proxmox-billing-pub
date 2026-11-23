<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/../includes/console_settings.php';
require_once __DIR__ . '/admin_functions.php';

$pdo = (new Database())->getConnection();
requireNodeAdministrator($pdo);
header('Cache-Control: no-store');
$status = consoleBrokerStatus();
$consoleNodes = $pdo->query('SELECT id,node_name FROM proxmox_nodes ORDER BY node_name')->fetchAll(PDO::FETCH_KEY_PAIR);
$error = null;
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $origin = $_POST['origin'] ?? '';
        $ttl = filter_var($_POST['ttl'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($origin) || $ttl === false) throw new DomainException('Некорректные настройки.');
        $pins = [];
        foreach ($consoleNodes as $id => $name) {
            $value = $_POST['pins'][$id] ?? '';
            if (!is_string($value)) throw new DomainException('Некорректный отпечаток.');
            $value = strtolower(str_replace(':', '', trim($value)));
            if ($value === '') continue;
            if (!preg_match('/^[a-f0-9]{64}$/D', $value)) throw new DomainException('Нужен SHA-256 отпечаток сертификата.');
            $pins[$id] = $value;
        }
        saveConsoleSettings($origin, $ttl, $status, $pins);
        $saved = true;
    } catch (DomainException | RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}
$settings = consoleSettings();
$title = 'Настройки VNC';
require __DIR__ . '/admin_header.php';
require __DIR__ . '/admin_sidebar.php';
?>
<style>
.console-settings-page{margin-inline-start:280px;padding:clamp(1rem,3vw,2rem);color:var(--admin-header-text)}
.console-settings-card{background:var(--admin-card-bg);border:1px solid var(--admin-header-border);padding:1.25rem;border-radius:12px;max-width:44rem}
.console-settings-guide{margin-top:1rem}
.console-settings-guide code{overflow-wrap:anywhere}
.console-settings-guide a{color:var(--admin-header-text);text-decoration:underline}
.console-settings-card form,.console-settings-card label{display:grid;gap:.55rem}.console-settings-card form{margin-top:1rem}
.console-settings-card select,.console-settings-card input,.console-settings-card button{padding:.5rem;color:inherit;background:var(--admin-hover-bg);border:1px solid var(--admin-header-border);border-radius:6px}
@media(max-width:1200px){.console-settings-page{margin-inline-start:70px}}
@media(max-width:768px){.console-settings-page{margin-inline-start:0}}
</style>
<main class="main-content console-settings-page">
<h1>Настройки VNC</h1>
<section class="console-settings-card">
<?php if ($saved): ?><p role="status">Настройки сохранены. Они применятся к новым сеансам.</p><?php endif ?>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif ?>
<?php if (consoleRemoteBroker() !== null): ?>
<p>Внешний прокси <code><?= htmlspecialchars(consoleRemoteBroker()) ?></code></p>
<?php else: ?>
<p>Локальный сокет <code><?= htmlspecialchars(consoleSocketPath()) ?></code></p>
<?php endif ?>
<?php if ($status['status'] === 'ok'): ?><p role="status">Прокси подключён.</p><?php endif ?>
<?php if ($status['status'] !== 'ok'): ?>
<p role="status"><?= $status['status'] === 'legacy' ? 'Прокси отвечает. Для настройки из панели требуется обновить его версию.' : 'Прокси не отвечает. Проверьте соединение и конфигурацию выбранного режима.' ?></p>
<?php elseif (consoleSettingsPath() !== null): ?>
<form method="post">
<input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf']) ?>">
<label>HTTPS-адрес панели<select name="origin" required>
<?php foreach ($status['origins'] as $origin): ?>
<option value="<?= htmlspecialchars($origin) ?>" <?= ($settings['origin'] ?? '') === $origin ? 'selected' : '' ?>><?= htmlspecialchars($origin) ?></option>
<?php endforeach ?>
</select></label>
<label>Срок билета, секунды<input type="number" name="ttl" min="15" max="90" value="<?= (int)($settings['ticket_ttl'] ?? $status['ticket_ttl']) ?>" required></label>
<?php if ($consoleNodes): ?><fieldset>
<legend>SHA-256 сертификатов Proxmox</legend>
<p>Заполняйте после проверки отпечатка. Пустое поле использует настройки TLS прокси.</p>
<?php foreach ($consoleNodes as $id => $name): ?>
<label><?= htmlspecialchars($name) ?><input name="pins[<?= (int)$id ?>]" maxlength="95" value="<?= htmlspecialchars($settings['pins'][$id] ?? '') ?>" spellcheck="false"></label>
<?php endforeach; ?></fieldset><?php endif ?>
<button>Сохранить</button>
</form>
<?php else: ?>
<p>Для сохранения параметров укажите путь <code>HOMEVLAD_CONSOLE_SETTINGS</code> к JSON-файлу вне веб-корня.</p>
<?php endif ?>
</section>
<section class="console-settings-card console-settings-guide" aria-labelledby="console-hosting-title">
<h2 id="console-hosting-title">VNC на хостинге без root</h2>
<ol>
    <li>Запустите <code>console/bridge.py</code> с Python и aiohttp на своём VPS или другом сервере с доступом к API Proxmox. Опубликуйте прокси через HTTPS с поддержкой WebSocket.</li>
    <li>Создайте общий секрет командой <code>openssl rand -hex 32</code>. На прокси задайте <code>CONSOLE_ISSUER_SECRET</code> и укажите HTTPS-адрес панели в <code>CONSOLE_ORIGIN</code>.</li>
    <li>На PHP-хостинге с cURL создайте <code>homevlad-console.php</code> на уровень выше веб-корня. Задайте в нём HTTPS-адрес прокси в <code>HOMEVLAD_CONSOLE_BROKER</code>, тот же секрет в <code>HOMEVLAD_CONSOLE_BROKER_SECRET</code> и путь к приватному JSON в <code>HOMEVLAD_CONSOLE_SETTINGS</code>.</li>
    <li>Откройте эту страницу. Когда появится статус «Прокси подключён», сохраните настройки и откройте консоль тестовой ВМ из веб-панели.</li>
</ol>
<p><a href="https://github.com/mortal2424/proxmox-billing-pub/blob/main/console/README.md#хостинг-без-root">Пример файла конфигурации и требования к прокси</a></p>
</section>
</main>
<?php require __DIR__ . '/admin_footer.php'; ?>
