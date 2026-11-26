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

function guestMetricRows(PDO $pdo, int $guestId, string $type, ?int $seconds, ?int $end = null): array
{
    $table = match ($type) {
        'qemu' => 'vm_metrics',
        'lxc' => 'lxc_metrics',
        default => throw new InvalidArgumentException('Invalid guest type'),
    };
    $filter = $seconds === null ? '' : ' AND timestamp BETWEEN ? AND ?';
    $order = $seconds === null ? 'DESC LIMIT 1' : 'ASC';
    $query = $pdo->prepare("SELECT timestamp,cpu_usage,mem_usage,mem_total,net_in,net_out,disk_read,disk_write
        FROM $table WHERE guest_id=?$filter ORDER BY timestamp $order");
    $parameters = [$guestId];
    if ($seconds !== null) {
        $end ??= guestMetricsClock($pdo);
        $parameters[] = gmdate('Y-m-d H:i:s', $end - $seconds);
        $parameters[] = gmdate('Y-m-d H:i:s', $end);
    }
    $query->execute($parameters);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

// Metrics are stored as DATETIME by the database clock. Keep that clock's
// wall time on the chart, independently of the PHP and browser time zones.
function guestMetricsClock(PDO $pdo): int
{
    $query = $pdo->prepare('SELECT NOW() AS metric_time');
    $query->execute();
    return strtotime($query->fetch(PDO::FETCH_ASSOC)['metric_time'] . ' UTC');
}

function historicalGuestMetrics(PDO $pdo, int $guestId, string $type, string $timeframe): array
{
    [$seconds, $format] = match ($timeframe) {
        'day' => [86400, 'd M H:i'],
        'week' => [604800, 'd M H:i'],
        'month' => [null, 'd M'],
        'year' => [null, 'M Y'],
        default => [3600, 'H:i'],
    };
    $end = guestMetricsClock($pdo);
    if ($seconds === null) {
        $date = new DateTimeImmutable('@' . $end);
        $day = (int) $date->format('j');
        $offset = $timeframe === 'month' ? '-1 month' : '-1 year';
        $month = $date->modify('first day of this month')->modify($offset);
        $start = $month->modify('+' . (min($day, (int) $month->format('t')) - 1) . ' days');
        $seconds = $end - $start->getTimestamp();
    }
    $rows = guestMetricRows($pdo, $guestId, $type, $seconds, $end);
    if (!$rows) return ['success'=>false,'error'=>'Данные метрик не найдены.'];
    $result = ['success'=>true,'labels'=>[],'timestamps'=>[],
        'rangeStart'=>($end - $seconds) * 1000,'rangeEnd'=>$end * 1000];
    $columns = ['cpuData'=>'cpu_usage','memData'=>'mem_usage','memTotalData'=>'mem_total',
        'netInData'=>'net_in','netOutData'=>'net_out','diskReadData'=>'disk_read','diskWriteData'=>'disk_write'];
    foreach ($rows as $row) {
        $timestamp = strtotime($row['timestamp'] . ' UTC');
        $result['timestamps'][] = $timestamp * 1000;
        $result['labels'][] = gmdate($format, $timestamp);
        foreach ($columns as $series=>$field) $result[$series][] = $row[$field];
    }
    return $result;
}
