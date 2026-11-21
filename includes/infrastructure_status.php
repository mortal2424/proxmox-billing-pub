<?php
require_once __DIR__ . '/guest_network_probe.php';
$infrastructureAdmin = $infrastructureAdmin ?? false;
$infrastructureNodes = $pdo->query("SELECT n.id, n.node_name, c.id AS zone_id, c.name AS zone, n.status, n.last_check,
    TIMESTAMPDIFF(SECOND, n.last_check, NOW()) AS health_age,
    s.cpu_usage, s.ram_usage, s.network_rx_mbits, s.network_tx_mbits, s.created_at,
    TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS metric_age
    FROM proxmox_nodes n JOIN proxmox_clusters c ON c.id = n.cluster_id
    LEFT JOIN node_stats s ON s.id = (
        SELECT r.id FROM node_stats r WHERE r.node_id = n.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1
    )
    WHERE n.is_active = 1 AND c.is_active = 1"
    . ($infrastructureAdmin ? '' : ' AND n.available_for_users = 1')
    . ' ORDER BY c.name, c.id, n.node_name')->fetchAll(PDO::FETCH_ASSOC);
$infrastructureZones = [];
foreach ($infrastructureNodes as &$infrastructureRow) {
    $healthAge = $infrastructureRow['health_age'];
    $infrastructureRow['health'] = $healthAge !== null && (int) $healthAge >= 0 && (int) $healthAge <= 900
        ? ($infrastructureRow['status'] ?? 'unknown') : 'stale';
    $zone = &$infrastructureZones[$infrastructureRow['zone_id']];
    $zone ??= ['name' => $infrastructureRow['zone'], 'total' => 0, 'online' => 0, 'offline' => 0];
    $zone['total']++;
    if (in_array($infrastructureRow['health'], ['online', 'offline'], true)) $zone[$infrastructureRow['health']]++;
}
unset($infrastructureRow, $zone);
?>
<section class="infrastructure-status">
<h2>Состояние инфраструктуры</h2>
<p>Доступность API, загрузка CPU и памяти, входящий и исходящий трафик. Сбор выполняется каждые 5 минут.</p>
<?php if ($infrastructureZones): ?>
<ul class="infrastructure-zones" aria-label="Доступность API по зонам">
<?php foreach ($infrastructureZones as $zone): ?>
<li><strong><?= htmlspecialchars($zone['name']) ?></strong>
<span><?= $zone['online'] ?> из <?= $zone['total'] ?> отвечают</span>
<?php if ($zone['offline']): ?><span><?= $zone['offline'] ?> без ответа</span><?php endif; ?>
<?php $unknown = $zone['total'] - $zone['online'] - $zone['offline']; ?>
<?php if ($unknown): ?><span><?= $unknown ?> без подтверждённого статуса</span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<div class="infrastructure-scroll">
<table>
<caption>Гипервизоры по зонам доступности</caption>
<thead><tr><th>Зона / узел</th><th>API</th><th>CPU</th><th>Память</th><th>Сеть, приём / передача</th><th>Интернет из гостя</th><th>Последний замер</th></tr></thead>
<tbody>
<?php foreach ($infrastructureNodes as $infrastructureNode):
    $fresh = $infrastructureNode['metric_age'] !== null && (int) $infrastructureNode['metric_age'] >= 0 && (int) $infrastructureNode['metric_age'] <= 900;
    $networkFresh = $fresh && $infrastructureNode['network_rx_mbits'] !== null && $infrastructureNode['network_tx_mbits'] !== null;
    $probe = guestNetworkProbeStatus((int) $infrastructureNode['id'], time());
?>
<tr>
<td><?= htmlspecialchars($infrastructureNode['zone']) ?><br><strong><?= htmlspecialchars($infrastructureNode['node_name']) ?></strong></td>
<td><?= match ($infrastructureNode['health']) {
    'online' => 'Доступен', 'offline' => 'Нет ответа', 'stale' => 'Нет свежей проверки', default => 'Неизвестно',
} ?></td>
<?php foreach (['cpu_usage' => 'CPU', 'ram_usage' => 'Память'] as $metric => $label): ?>
<td><?php if ($fresh): ?>
<progress aria-label="<?= $label ?>" max="100" value="<?= max(0, min(100, (float) $infrastructureNode[$metric])) ?>"></progress>
<?= round((float) $infrastructureNode[$metric], 1) ?>%
<?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<td><?= $networkFresh ? round((float) $infrastructureNode['network_rx_mbits'], 2) . ' / ' . round((float) $infrastructureNode['network_tx_mbits'], 2) . ' Мбит/с' : 'Нет свежего замера' ?></td>
<td><?= htmlspecialchars($probe['label']) ?><?php if (isset($probe['checked_at'])): ?><br><small><?= date('H:i:s T', $probe['checked_at']) ?></small><?php endif; ?></td>
<td><?= htmlspecialchars($infrastructureNode['created_at'] ?? 'Замеров нет') ?><?= !$fresh && $infrastructureNode['created_at'] !== null ? ' · данные устарели' : '' ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$infrastructureNodes): ?><tr><td colspan="7">Доступных узлов пока нет.</td></tr><?php endif; ?>
</tbody></table>
</div>
<p>Трафик берётся из статистики Proxmox. Интернет проверяется по HTTPS из выделенной ВМ в её сетевом пуле.</p>
</section>
<style>
.infrastructure-status { padding: 20px; border-radius: 12px; background: var(--workspace-surface, var(--db-card-bg, white)); color: var(--workspace-ink, var(--db-text, #193c32)); margin-bottom: 20px; border: 1px solid var(--workspace-border, var(--db-border, #d5e5de)); }
.infrastructure-scroll { overflow-x: auto; }
.infrastructure-zones { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 10px; list-style: none; margin: 16px 0; padding: 0; }
.infrastructure-zones li { display: grid; gap: 4px; padding: 10px 12px; border: 1px solid var(--workspace-border, var(--db-border, #d5e5de)); border-radius: 8px; overflow-wrap: anywhere; }
.infrastructure-status table { width: 100%; border-collapse: collapse; text-align: left; }
.infrastructure-status th, .infrastructure-status td { padding: 12px 10px; border-bottom: 1px solid var(--workspace-border, var(--db-border, #d5e5de)); }
.infrastructure-status progress { display: block; width: 70px; accent-color: #287963; }
.infrastructure-status p { line-height: 1.5; }
</style>
