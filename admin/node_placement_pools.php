<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
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
$inventory = ['network'=>[], 'storage'=>[]];
try {
    $api = placementClient($node, $pdo);
    $path = '/nodes/' . rawurlencode($node['node_name']);
    foreach ($api->request('GET', $path . '/network') as $entry) {
        if (in_array($entry['type'] ?? '', ['bridge','OVSBridge'], true)) $inventory['network'][] = $entry['iface'];
    }
    foreach ($api->request('GET', $path . '/storage') as $entry) {
        if (array_intersect(['images','rootdir'], explode(',', $entry['content'] ?? ''))) {
            $inventory['storage'][] = $entry['storage'];
        }
    }
} catch (Throwable $e) {
    $error = 'Инвентарь ноды недоступен. Существующие пулы можно удалить.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $kind = $_POST['kind'] ?? '';
        $pool = trim((string)($_POST['pool'] ?? ''));
        $action = $_POST['action'] ?? '';
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if (mb_strlen($displayName, 'UTF-8') > 100 || preg_match('/[[:cntrl:]]/u', $displayName)) {
            throw new InvalidArgumentException('Название должно содержать до 100 символов без управляющих знаков');
        }
        if (!isset($inventory[$kind]) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $pool) ||
            !in_array($action,['save','delete'],true)) throw new InvalidArgumentException('Некорректный пул');
        $members = $_POST['members'] ?? [];
        if ($action === 'delete') $members = [];
        if (!is_array($members) || array_diff($members, $inventory[$kind])) {
            throw new InvalidArgumentException('Выберите ресурсы из списка ноды');
        }
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM proxmox_nodes WHERE id=? FOR UPDATE')->execute([$id]);
        $pdo->prepare('DELETE FROM node_placement_pools WHERE node_id=? AND kind=? AND pool_name=?')->execute([$id,$kind,$pool]);
        $insert = $pdo->prepare('INSERT INTO node_placement_pools(node_id,kind,pool_name,resource,display_name) VALUES(?,?,?,?,?)');
        foreach (array_unique($members) as $member) $insert->execute([$id,$kind,$pool,$member,$displayName ?: null]);
        $pdo->commit();
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
<style>
<?php include __DIR__.'/css/admin_style.css'; ?>
body { min-height: 100vh; background: radial-gradient(ellipse at 12% 0%, #d8f1ee 0, transparent 45%), linear-gradient(145deg, #f4f8fc, #e8eef6); }
[data-theme="dark"] body, body.dark-theme { background: radial-gradient(ellipse at 12% 0%, #173c42 0, transparent 48%), linear-gradient(145deg, #0d1523, #172438); color: #e8f1f5; }
.placement-panel { max-width: 70rem; margin: 2rem auto; padding: 1rem; }
.placement-panel section { margin-block: 1.5rem; }
.placement-panel form { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-block: .75rem; padding: 1rem; border: 1px solid #a9b9c9; border-radius: .6rem; background: var(--admin-card-bg, #fff); }
[data-theme="dark"] .placement-panel form, body.dark-theme .placement-panel form { background: #172438; border-color: #42556e; }
.placement-panel fieldset { display: flex; flex-wrap: wrap; gap: .65rem; }
</style>
</head>
<body>
<main class="placement-panel">
<a href="nodes.php">← Ноды</a>
<h1>Пулы размещения · <?= $escape($node['node_name']) ?></h1>
<p>Одинаковые имена пулов объединяют ресурсы нескольких нод в одной зоне.</p>
<?php if ($error !== ''): ?><p role="alert"><?= $escape($error) ?></p><?php endif ?>
<?php foreach (['network'=>'Внешние сети','storage'=>'Хранилища'] as $kind=>$label): ?>
<section>
<h2><?= $escape($label) ?></h2>
<?php foreach ($pools[$kind] + [''=>[]] as $name=>$selected): ?>
<form method="post">
<input type="hidden" name="node_csrf" value="<?= $escape($_SESSION['node_csrf']) ?>">
<input type="hidden" name="kind" value="<?= $escape($kind) ?>">
<label>Имя пула <input name="pool" required maxlength="64" pattern="[A-Za-z0-9_.-]+" value="<?= $escape($name) ?>" <?= $name !== '' ? 'readonly' : '' ?>></label>
<label>Название для пользователей
<input name="display_name" maxlength="100" value="<?= $escape($labels[$kind][$name] ?? '') ?>" placeholder="<?= $kind === 'storage' ? 'Например, Быстрый NVMe SSD' : 'Например, Внешняя сеть' ?>">
</label>
<fieldset><legend>Ресурсы</legend>
<?php foreach (array_unique(array_merge($inventory[$kind],$selected)) as $resource): ?>
<label><input type="checkbox" name="members[]" value="<?= $escape($resource) ?>" <?= in_array($resource,$selected,true) ? 'checked' : '' ?>> <?= $escape($resource) ?></label>
<?php endforeach ?>
</fieldset>
<button name="action" value="save" <?= !$inventory[$kind] ? 'disabled' : '' ?>><?= $name === '' ? 'Создать пул' : 'Сохранить' ?></button>
<?php if ($name !== ''): ?><button name="action" value="delete">Удалить пул</button><?php endif ?>
</form>
<?php endforeach ?>
</section>
<?php endforeach ?>
</main>
</body>
</html>
