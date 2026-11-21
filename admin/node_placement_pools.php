<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/admin_functions.php';
require_once __DIR__ . '/../includes/zone_placement.php';

$pdo = (new Database())->getConnection();
requireNodeAdministrator($pdo);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$query = $pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=?');
$query->execute([$id]);
$node = $query->fetch();
if (!$node) { http_response_code(404); exit('Нода не найдена'); }
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$error = '';
$inventoryError = '';
$submitted = null;
$notice = $_SESSION['placement_pool_notice'][$id] ?? '';
unset($_SESSION['placement_pool_notice'][$id]);
$inventory = ['network'=>[], 'storage'=>[]];
try {
    $api = placementClient($node, $pdo);
    $path = '/nodes/' . rawurlencode($node['node_name']);
    $interfaces = $api->request('GET', $path . '/network', ['type'=>'any_bridge']);
    foreach ($interfaces as $entry) {
        if (in_array($entry['type'] ?? '', ['bridge','OVSBridge'], true)) $inventory['network'][] = $entry['iface'];
    }
    foreach (appliedPlacementSdn($api, $interfaces) as $entry) {
        if (strlen($entry['name']) <= 64) $inventory['network'][] = $entry['name'];
    }
    foreach ($api->request('GET', $path . '/storage') as $entry) {
        if (array_intersect(['images','rootdir'], explode(',', $entry['content'] ?? ''))) {
            $inventory['storage'][] = $entry['storage'];
        }
    }
} catch (Throwable $e) {
    $inventoryError = 'Инвентарь ноды загружен не полностью. Пулы можно редактировать и удалять.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $kind = $_POST['kind'] ?? '';
        $pool = $_POST['pool'] ?? '';
        $action = $_POST['action'] ?? '';
        $displayName = $_POST['display_name'] ?? '';
        $members = $_POST['members'] ?? [];
        if (!is_string($kind) || !isset($inventory[$kind]) || !is_string($pool) ||
            !is_string($displayName) || !in_array($action, ['create', 'edit', 'delete'], true) ||
            !is_array($members) || array_filter($members, static fn($value) => !is_string($value))) {
            throw new InvalidArgumentException('Некорректные данные пула');
        }
        $pool = trim($pool);
        $displayName = trim($displayName);
        $submitted = compact('kind', 'pool', 'action', 'displayName', 'members');
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $pool)) {
            throw new InvalidArgumentException('Код пула должен содержать от 1 до 64 букв латиницы, цифр, точек, дефисов или подчёркиваний');
        }
        if ($action !== 'delete' && (mb_strlen($displayName, 'UTF-8') > 100 || preg_match('/[[:cntrl:]]/u', $displayName))) {
            throw new InvalidArgumentException('Название должно содержать до 100 символов без управляющих знаков');
        }

        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM proxmox_nodes WHERE id=? FOR UPDATE')->execute([$id]);
        $current = $pdo->prepare('SELECT resource FROM node_placement_pools WHERE node_id=? AND kind=? AND pool_name=? FOR UPDATE');
        $current->execute([$id, $kind, $pool]);
        $savedMembers = $current->fetchAll(PDO::FETCH_COLUMN);
        if ($action === 'create' && $savedMembers) {
            throw new InvalidArgumentException('Пул с таким кодом уже существует на этой ноде');
        }
        if ($action !== 'create' && !$savedMembers) {
            throw new InvalidArgumentException('Пул уже удалён. Обновите страницу');
        }
        if ($action !== 'delete' && (!$members || array_diff($members, array_merge($inventory[$kind], $savedMembers)))) {
            throw new InvalidArgumentException('Выберите хотя бы один ресурс из списка');
        }
        if ($action !== 'create') {
            $pdo->prepare('DELETE FROM node_placement_pools WHERE node_id=? AND kind=? AND pool_name=?')->execute([$id, $kind, $pool]);
        }
        if ($action !== 'delete') {
            $insert = $pdo->prepare('INSERT INTO node_placement_pools(node_id,kind,pool_name,resource,display_name) VALUES(?,?,?,?,?)');
            foreach (array_unique($members) as $member) {
                $insert->execute([$id, $kind, $pool, $member, $displayName === '' ? null : $displayName]);
            }
        }
        $pdo->commit();
        $_SESSION['placement_pool_notice'][$id] = match ($action) {
            'create' => 'Пул создан.',
            'edit' => 'Пул сохранён.',
            'delete' => 'Пул удалён.',
        };
        header('Location: node_placement_pools.php?id=' . $id, true, 303);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось сохранить пул';
    }
}
$query = $pdo->prepare('SELECT kind,pool_name,resource,display_name FROM node_placement_pools WHERE node_id=? ORDER BY kind,pool_name,resource');
$query->execute([$id]);
$pools = ['network'=>[], 'storage'=>[]];
$labels = [];
foreach ($query->fetchAll() as $entry) {
    $pools[$entry['kind']][$entry['pool_name']][] = $entry['resource'];
    $labels[$entry['kind']][$entry['pool_name']] = $entry['display_name'];
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Пулы размещения | HomeVlad</title>
<link rel="stylesheet" href="/admin/css/admin_style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.placement-workspace {
    --pool-card: #fff;
    --pool-text: #172438;
    --pool-muted: #445a72;
    --pool-border: #c6d4e1;
    --pool-field: #f5f9fc;
    margin-left: 280px;
    min-height: 100vh;
    padding: clamp(1rem, 3vw, 2.5rem);
    color: var(--pool-text);
    background: radial-gradient(ellipse at 10% 0%, #ccece7, transparent 50%), linear-gradient(145deg, #f5f9fd, #e6edf5);
}
[data-theme="dark"] .placement-workspace, body.dark-theme .placement-workspace {
    --pool-card: #172438;
    --pool-text: #edf4f7;
    --pool-muted: #bdcbdc;
    --pool-border: #42556e;
    --pool-field: #22334a;
    background: radial-gradient(ellipse at 10% 0%, #173c42, transparent 50%), linear-gradient(145deg, #0e1727, #172438);
}
.admin-sidebar.compact + .placement-workspace { margin-left: 70px; }
.placement-panel { max-width: 72rem; margin: auto; }
.placement-panel h1 { margin-block: .6rem; font-size: clamp(1.5rem, 4vw, 2.2rem); }
.placement-panel h2 { margin-block: 1.2rem .6rem; font-size: 1.2rem; }
.placement-panel p { color: var(--pool-muted); }
.pool-back, .pool-entry summary {
    display: flex; align-items: center; gap: .65rem; color: inherit;
    border: 1px solid var(--pool-border); border-radius: .55rem; background: var(--pool-card);
    padding: .55rem .75rem; text-decoration: none; cursor: pointer;
}
.pool-back { display: inline-flex; }
.pool-list { display: grid; gap: .45rem; }
.pool-entry summary { flex-wrap: wrap; overflow-wrap: anywhere; }
.pool-entry summary::-webkit-details-marker { display: none; }
.pool-entry summary::before { content: '›'; font-weight: bold; }
.pool-entry[open] summary::before { transform: rotate(90deg); }
.pool-entry summary small { color: var(--pool-muted); }
.pool-entry summary .pool-entry-action { margin-left: auto; font-weight: 600; }
.pool-entry-create summary { width: fit-content; background: #007b72; color: white; }
.pool-card {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .65rem;
    margin-block: .4rem .65rem;
    padding: .75rem;
    border: 1px solid var(--pool-border);
    border-radius: .9rem;
    background: var(--pool-card);
    box-shadow: 0 .25rem 1rem #0d203514;
}
.pool-card > label { display: grid; gap: .4rem; font-weight: 600; }
.pool-card input:not([type=hidden]):not([type=checkbox]) {
    min-width: 0; width: 100%; box-sizing: border-box; padding: .42rem .6rem;
    border: 1px solid var(--pool-border); border-radius: .5rem;
    font: inherit; color: inherit; background: var(--pool-field);
}
.pool-card fieldset {
    grid-column: 1 / -1; min-width: 0; display: flex; flex-wrap: wrap; gap: .55rem;
    max-height: 10rem; overflow: auto; padding: .45rem; border: 1px solid var(--pool-border); border-radius: .55rem;
}
.pool-card fieldset label {
    display: inline-flex; align-items: center; gap: .45rem; padding: .25rem .45rem;
    border: 1px solid var(--pool-border); border-radius: .4rem; background: var(--pool-field);
    overflow-wrap: anywhere;
}
.pool-card input[type=checkbox] { accent-color: #007b72; width: 1.05rem; height: 1.05rem; }
.pool-actions { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: .5rem; }
.pool-actions button {
    border: 1px solid transparent; border-radius: .5rem; min-height: 2.65rem;
    padding: .45rem .9rem; font: 600 .9rem system-ui; cursor: pointer;
}
.pool-actions button[value=create], .pool-actions button[value=edit] { background: #007b72; color: white; }
.pool-actions button[value=delete] { background: #fde8ec; color: #8a2435; }
.pool-actions button:disabled { opacity: .5; cursor: not-allowed; }
.placement-panel :focus-visible { outline: 2px solid #008c82; outline-offset: 2px; }
@media (max-width: 1200px) { .placement-workspace { margin-left: 70px; } }
@media (max-width: 768px) {
    .placement-workspace, .admin-sidebar.compact + .placement-workspace { margin-left: 0; }
    .pool-card { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/admin_header.php'; ?>
<?php require __DIR__ . '/admin_sidebar.php'; ?>
<main class="placement-workspace"><div class="placement-panel">
<a class="pool-back" href="nodes.php">← К списку нод</a>
<h1>Пулы размещения · <?= $escape($node['node_name']) ?></h1>
<p>Одинаковые коды пулов объединяют ресурсы нескольких нод в одной зоне.</p>
<?php if ($inventoryError !== ''): ?><p role="status"><?= $escape($inventoryError) ?></p><?php endif ?>
<?php if ($notice !== ''): ?><p role="status"><?= $escape($notice) ?></p><?php endif ?>
<?php if ($error !== ''): ?><p role="alert"><?= $escape($error) ?></p><?php endif ?>
<?php foreach (['network' => 'Внешние сети', 'storage' => 'Хранилища'] as $kind => $label): ?>
<section>
<h2><?= $escape($label) ?></h2>
<?php if (!$pools[$kind]): ?><p>Пулов пока нет.</p><?php endif ?>
<div class="pool-list">
<?php foreach ($pools[$kind] + ['' => []] as $name => $saved): ?>
<?php
    $name = (string)$name;
    $creating = $name === '';
    $editing = $submitted && $submitted['kind'] === $kind && (
        $creating ? $submitted['action'] === 'create' : $submitted['action'] === 'edit' && $submitted['pool'] === $name
    );
    $selected = $editing ? $submitted['members'] : $saved;
    $options = array_unique(array_merge($inventory[$kind], $saved));
?>
<details class="pool-entry<?= $creating ? ' pool-entry-create' : '' ?>" <?= $editing ? 'open' : '' ?>>
<summary>
<?php if ($creating): ?>
    Создать пул
<?php else: ?>
    <strong><?= $escape($labels[$kind][$name] ?: $name) ?></strong>
    <?php if (!empty($labels[$kind][$name])): ?><small><?= $escape($name) ?></small><?php endif ?>
    <small><?= count($saved) ?> · <?= $escape(implode(', ', $saved)) ?></small>
    <span class="pool-entry-action">Редактировать</span>
<?php endif ?>
</summary>
<form method="post" class="pool-card">
<input type="hidden" name="node_csrf" value="<?= $escape($_SESSION['node_csrf']) ?>">
<input type="hidden" name="kind" value="<?= $escape($kind) ?>">
<label>Код пула
<input name="pool" required maxlength="64" pattern="[A-Za-z0-9_.\-]+" value="<?= $escape($editing && $creating ? $submitted['pool'] : $name) ?>" <?= $creating ? '' : 'readonly' ?>>
</label>
<label>Название для пользователей
<input name="display_name" maxlength="100" value="<?= $escape($editing ? $submitted['displayName'] : ($labels[$kind][$name] ?? '')) ?>" placeholder="<?= $kind === 'storage' ? 'Например, Быстрый NVMe SSD' : 'Например, Внешняя сеть' ?>">
</label>
<fieldset><legend>Ресурсы</legend>
<?php if (!$options): ?><span>Нет доступных ресурсов.</span><?php endif ?>
<?php foreach ($options as $resource): ?>
<label><input type="checkbox" name="members[]" value="<?= $escape($resource) ?>" <?= in_array($resource, $selected, true) ? 'checked' : '' ?>>
<?= $escape(str_contains($resource, '/') ? 'SDN · ' . $resource : $resource) ?>
<?php if (!in_array($resource, $inventory[$kind], true)): ?><small>вне инвентаря</small><?php endif ?>
</label>
<?php endforeach ?>
</fieldset>
<div class="pool-actions">
<button name="action" value="<?= $creating ? 'create' : 'edit' ?>" <?= !$options ? 'disabled' : '' ?>><?= $creating ? 'Создать пул' : 'Сохранить' ?></button>
<?php if (!$creating): ?>
<button name="action" value="delete" formnovalidate onclick="return confirm('Удалить этот пул с текущей ноды?')">Удалить пул</button>
<?php endif ?>
</div>
</form>
</details>
<?php endforeach ?>
</div>
</section>
<?php endforeach ?>
</div></main>
<?php require __DIR__ . '/admin_footer.php'; ?>
</body>
</html>
