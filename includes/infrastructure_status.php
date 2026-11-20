<?php
$infrastructureAdmin = $infrastructureAdmin ?? false;
$infrastructureNodes = $pdo->query("SELECT n.id, n.node_name, c.name AS zone, n.status, n.last_check,
    TIMESTAMPDIFF(SECOND, n.last_check, NOW()) AS health_age,
    s.cpu_usage, s.ram_usage, s.network_rx_mbits, s.network_tx_mbits, s.created_at,
    TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS metric_age
    FROM proxmox_nodes n JOIN proxmox_clusters c ON c.id = n.cluster_id
    LEFT JOIN node_stats s ON s.id = (
        SELECT r.id FROM node_stats r WHERE r.node_id = n.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1
    )
    WHERE n.is_active = 1 AND c.is_active = 1"
    . ($infrastructureAdmin ? '' : ' AND n.available_for_users = 1')
    . ' ORDER BY c.name, n.node_name')->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="infrastructure-status">
<h2>Состояние инфраструктуры</h2>
<p>Доступность API, CPU, память и средняя сетевая активность за интервал сбора. Замеры обновляются каждые 5 минут.</p>
<div class="infrastructure-scroll">
<table>
<caption>Гипервизоры по зонам доступности</caption>
<thead><tr><th>Зона / узел</th><th>API</th><th>CPU</th><th>Память</th><th>Сеть, приём / передача</th><th>Последний замер</th></tr></thead>
<tbody>
<?php foreach ($infrastructureNodes as $infrastructureNode):
    $fresh = $infrastructureNode['metric_age'] !== null && (int) $infrastructureNode['metric_age'] >= 0 && (int) $infrastructureNode['metric_age'] <= 900;
    $healthFresh = $infrastructureNode['health_age'] !== null && (int) $infrastructureNode['health_age'] >= 0 && (int) $infrastructureNode['health_age'] <= 900;
?>
<tr>
<td><?= htmlspecialchars($infrastructureNode['zone']) ?><br><strong><?= htmlspecialchars($infrastructureNode['node_name']) ?></strong></td>
<td><?= $healthFresh ? match ($infrastructureNode['status']) {
    'online' => 'Доступен', 'offline' => 'Нет ответа', default => 'Неизвестно',
} : 'Нет свежей проверки' ?></td>
<?php foreach (['cpu_usage', 'ram_usage'] as $metric): ?>
<td><?php if ($fresh): ?>
<progress max="100" value="<?= max(0, min(100, (float) $infrastructureNode[$metric])) ?>"></progress>
<?= round((float) $infrastructureNode[$metric], 1) ?>%
<?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<td><?= $fresh ? round((float) $infrastructureNode['network_rx_mbits'], 2) . ' / ' . round((float) $infrastructureNode['network_tx_mbits'], 2) . ' Мбит/с' : '—' ?></td>
<td><?= htmlspecialchars($infrastructureNode['created_at'] ?? 'Замеров нет') ?><?= !$fresh ? ' · данные устарели' : '' ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$infrastructureNodes): ?><tr><td colspan="6">Доступных узлов пока нет.</td></tr><?php endif; ?>
</tbody></table>
</div>
<p>Показатели трафика отражают активность интерфейса мониторинга. Они не являются проверкой доступности внешней сети.</p>
</section>
<style>
.infrastructure-status { padding: 20px; border-radius: 12px; background: var(--workspace-surface, var(--db-card-bg, white)); color: var(--workspace-ink, var(--db-text, #193c32)); margin-bottom: 20px; border: 1px solid var(--workspace-border, var(--db-border, #d5e5de)); }
.infrastructure-scroll { overflow-x: auto; }
.infrastructure-status table { width: 100%; border-collapse: collapse; text-align: left; }
.infrastructure-status th, .infrastructure-status td { padding: 12px 10px; border-bottom: 1px solid var(--workspace-border, var(--db-border, #d5e5de)); }
.infrastructure-status progress { display: block; width: 70px; accent-color: #287963; }
.infrastructure-status p { line-height: 1.5; }
</style>
