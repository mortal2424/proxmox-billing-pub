<?php

function customerMetricsGuest(PDO $pdo, int $userId, string $type, array $params): ?int
{
    $internal = array_key_exists('guest_id', $params);
    $id = filter_var($params[$internal ? 'guest_id' : 'vm_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id || !in_array($type, ['qemu','lxc'], true)) return null;
    $key = $internal ? 'id' : 'vm_id';
    $query = $pdo->prepare("SELECT id FROM vms WHERE $key=? AND user_id=? AND vm_type=? AND status<>'deleted' LIMIT 2");
    $query->execute([$id, $userId, $type]);
    $matches = $query->fetchAll(PDO::FETCH_COLUMN);
    return count($matches) === 1 ? (int) $matches[0] : null;
}

function guestMetricRows(PDO $pdo, int $guestId, string $type, ?int $seconds): array
{
    $table = match ($type) {
        'qemu' => 'vm_metrics',
        'lxc' => 'lxc_metrics',
        default => throw new InvalidArgumentException('Invalid guest type'),
    };
    $filter = $seconds === null ? '' : ' AND timestamp >= ?';
    $order = $seconds === null ? 'DESC LIMIT 1' : 'ASC';
    $query = $pdo->prepare("SELECT timestamp,cpu_usage,mem_usage,mem_total,net_in,net_out,disk_read,disk_write
        FROM $table WHERE guest_id=?$filter ORDER BY timestamp $order");
    $query->execute($seconds === null ? [$guestId] : [$guestId, date('Y-m-d H:i:s', time()-$seconds)]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function historicalGuestMetrics(PDO $pdo, int $guestId, string $type, string $timeframe): array
{
    [$seconds, $format] = match ($timeframe) {
        'day' => [86400, 'd M H:i'],
        'week' => [604800, 'd M H:i'],
        'month' => [2678400, 'd M'],
        'year' => [31622400, 'M Y'],
        default => [3600, 'H:i'],
    };
    $rows = guestMetricRows($pdo, $guestId, $type, $seconds);
    if (!$rows) return ['success'=>false,'error'=>'Данные метрик не найдены.'];
    $result = ['success'=>true,'labels'=>[]];
    $columns = ['cpuData'=>'cpu_usage','memData'=>'mem_usage','memTotalData'=>'mem_total',
        'netInData'=>'net_in','netOutData'=>'net_out','diskReadData'=>'disk_read','diskWriteData'=>'disk_write'];
    foreach ($rows as $row) {
        $result['labels'][] = date($format, strtotime($row['timestamp']));
        foreach ($columns as $series=>$field) $result[$series][] = $row[$field];
    }
    return $result;
}
