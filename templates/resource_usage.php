<?php
if (!isset($pdo, $user_id, $user)) { http_response_code(404); exit; }
require_once __DIR__.'/../includes/resource_usage.php';
require __DIR__.'/headers/workspace_preference.php';

$periods = ['hour'=>['Час',1],'day'=>['День',24],'month'=>['Месяц',720]];
$labels = ['cpu'=>['vCPU','vCPU'],'ram'=>['Оперативная память','МиБ'],'disk'=>['Дисковое пространство','ГиБ'],
    'bundle'=>['Готовые тарифы','шт.']];
$kindLabels = ['qemu'=>'Виртуальные машины','lxc'=>'Контейнеры LXC'];
$selectedPeriod = is_string($_GET['period'] ?? null) && isset($periods[$_GET['period']]) ? $_GET['period'] : 'hour';
$selectedKind = is_string($_GET['kind'] ?? null) && isset($kindLabels[$_GET['kind']]) ? $_GET['kind'] : '';
$selectedResource = is_string($_GET['resource'] ?? null) && isset($labels[$_GET['resource']]) ? $_GET['resource'] : '';
$query = $pdo->prepare("SELECT v.vm_type,v.cpu,v.ram,v.disk,v.status,v.is_custom,t.price AS tariff_price
    FROM vms v LEFT JOIN tariffs t ON t.id=v.tariff_id
    WHERE v.user_id=? AND v.status IN ('running','stopped','suspended')");
$query->execute([(int)$user_id]);
$groups = resourceUsageBreakdown($query->fetchAll(PDO::FETCH_ASSOC), guestResourceRates($pdo), $periods[$selectedPeriod][1]);
$costSum = static fn(array $rows): float => array_sum(array_column($rows, 'cost'));
$total = array_sum(array_map($costSum, $groups));
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static function (float $n): string {
    return number_format($n, $n > 0 && $n < 0.01 ? 6 : 2, ',', ' ') . ' ₽';
};
$formatQuantity = static fn(float $q): string => number_format($q, $q === floor($q) ? 0 : 2, ',', ' ');
header('Cache-Control: no-store');
$embedded = ($_GET['embed'] ?? '') === '1';
if ($embedded) {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Потребление ресурсов | HomeVlad Cloud</title>
<?php require __DIR__.'/../includes/app_head.php'; ?>
<link rel="stylesheet" href="/css/user-palette.css"><link rel="stylesheet" href="/css/themes.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($workspaceLite): ?><link rel="stylesheet" href="/css/workspace.css?v=6"><?php endif ?>
<link rel="stylesheet" href="/css/resource-usage.css?v=<?= (int)filemtime(__DIR__.'/../css/resource-usage.css') ?>">
</head><body<?= $embedded ? ' class="cabinet-embedded"' : ($workspaceLite ? ' class="workspace"' : '') ?>>
<?php if (!$embedded): ?>
<?php if ($workspaceLite): ?>
<?php require __DIR__.'/headers/workspace_nav.php'; ?><div class="workspace-layout">
<?php require __DIR__.'/headers/workspace_sidebar.php'; ?>
<?php else: ?>
<?php require __DIR__.'/headers/user_header.php'; ?>
<?php require __DIR__.'/headers/user_sidebar.php'; ?>
<?php endif ?>
<?php endif; ?>
<main class="resource-usage">
    <h1>Потребление ресурсов</h1>
    <div class="usage-summary">
        <div><span>Текущая стоимость</span><strong><?= $money($total) ?></strong></div>
        <nav aria-label="Период расчёта">
            <?php foreach ($periods as $id => [$label, $hours]): ?>
            <a <?= $id === $selectedPeriod ? 'aria-current="page"' : '' ?>
               href="?<?= $e(http_build_query(['view'=>'usage','period'=>$id,'kind'=>$selectedKind,'resource'=>$selectedResource]+($embedded?['embed'=>'1']:[]))) ?>"><?= $label ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <p class="usage-note">Оценка по текущей конфигурации. День — 24 часа, месяц — 30 дней. Для остановленных гостей учитывается только диск. Готовые тарифы показаны отдельной строкой.</p>
    <div class="usage-detail-heading"><h2>Детализация</h2><a href="/templates/billing.php?view=history"<?= $embedded ? ' target="_top"' : '' ?>>История списаний</a></div>
    <form class="usage-filters" method="get">
        <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif ?>
        <input type="hidden" name="view" value="usage"><input type="hidden" name="period" value="<?= $e($selectedPeriod) ?>">
        <label>Тип гостя <select name="kind"><option value="">Все гости</option>
            <?php foreach ($kindLabels as $id=>$label): ?><option value="<?= $id ?>" <?= $id===$selectedKind?'selected':'' ?>><?= $label ?></option><?php endforeach ?>
        </select></label>
        <label>Ресурс <select name="resource"><option value="">Все ресурсы</option>
            <?php foreach ($labels as $id=>[$label]): ?><option value="<?= $id ?>" <?= $id===$selectedResource?'selected':'' ?>><?= $label ?></option><?php endforeach ?>
        </select></label>
        <button type="submit">Применить</button>
    </form>
    <div class="usage-table">
        <div class="usage-columns usage-table-head"><span>Ресурс</span><span>Объём</span><span>Стоимость</span></div>
        <?php $visible = false; foreach ($kindLabels as $kind=>$kindLabel):
            if ($selectedKind !== '' && $selectedKind !== $kind) continue;
            $rows = $groups[$kind] ?? [];
            if ($selectedResource !== '') $rows = array_intersect_key($rows, [$selectedResource=>true]);
            if (!$rows) continue;
            $visible = true;
        ?>
        <details open>
            <summary class="usage-columns"><strong><?= $kindLabel ?></strong><span></span><strong><?= $money($costSum($rows)) ?></strong></summary>
            <?php foreach ($labels as $key=>[$label,$unit]): if (!isset($rows[$key])) continue; ?>
                <div class="usage-columns usage-metric">
                    <span><?= $label ?></span><span><?= $formatQuantity($rows[$key]['quantity']) ?> <?= $unit ?></span><span><?= $money($rows[$key]['cost']) ?></span>
                </div>
            <?php endforeach ?>
        </details>
        <?php endforeach; if (!$visible): ?><p class="usage-empty">По выбранным условиям ресурсов нет.</p><?php endif ?>
    </div>
</main>
<?php if ($workspaceLite && !$embedded): ?></div><?php endif ?>
</body></html>
