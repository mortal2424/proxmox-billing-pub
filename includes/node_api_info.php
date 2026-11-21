<?php
require_once __DIR__.'/ProxmoxApiClient.php';
require_once __DIR__.'/node_api_credentials.php';

function panelNodeApiInfo(array $node, bool $includeStorage = true): array
{
    $api = new ProxmoxApiClient(panelNodeApiCredentials($node));
    $base = '/nodes/'.rawurlencode($node['node_name']);
    $status = $api->request('GET', $base.'/status');
    $memory = $status['memory'] ?? [];
    $cpu = $status['cpuinfo'] ?? [];
    $total = (int)($memory['total'] ?? 0);
    $used = (int)($memory['used'] ?? 0);
    $percent = $total > 0 ? round(100*$used/$total, 2) : 0;
    $result = [
        'cpu_physical'=>(int)($cpu['cores']??1), 'cpu_threads'=>(int)($cpu['cpus']??1),
        'cpu_sockets'=>(int)($cpu['sockets']??1), 'cpu_model'=>$cpu['model']??'N/A',
        'ram_total'=>round($total/1073741824,2).' GB', 'ram_used'=>round($used/1073741824,2).' GB',
        'ram_percent'=>$percent.'%', 'disks'=>[], 'storages'=>[], 'storage_groups'=>[],
        'ip'=>$node['hostname'], 'mac'=>'N/A',
        'stats'=>['cpu_usage'=>round(100*($status['cpu']??0),2), 'ram_usage'=>$percent,
            'ram_total'=>round($total/1048576,2), 'network_rx_bytes'=>0, 'network_tx_bytes'=>0,
            'network_rx_mbits'=>null, 'network_tx_mbits'=>null],
    ];
    $storages = $includeStorage ? $api->request('GET', $base.'/storage') : [];
    foreach ($storages as $storage) {
        $type = (string)($storage['type']??'unknown');
        $entry = ['name'=>(string)$storage['storage'], 'type'=>$type, 'active'=>!empty($storage['active']),
            'total'=>(int)($storage['total']??0), 'used'=>(int)($storage['used']??0),
            'available'=>(int)($storage['avail']??0)];
        $result['storages'][]=$entry;
        $result['storage_groups'][$type]??=['total'=>0,'used'=>0,'available'=>0];
        if($entry['active'])foreach(['total','used','available']as$key)$result['storage_groups'][$type][$key]+=$entry[$key];
    }
    // RRD netin/netout are bytes per second, not cumulative interface counters.
    try {
        $rows=$api->request('GET',$base.'/rrddata',['timeframe'=>'hour','cf'=>'AVERAGE']);
        $now = time();
        $timestamp = $now - 601;
        foreach ($rows as $row) {
            if (!is_numeric($row['time'] ?? null) || !is_numeric($row['netin'] ?? null) ||
                !is_numeric($row['netout'] ?? null)) continue;
            $time = (int) $row['time'];
            if ($time <= $timestamp || $time > $now || $row['netin'] < 0 || $row['netout'] < 0) continue;
            $timestamp = $time;
            $result['stats']['network_rx_mbits'] = round((float) $row['netin'] * 8 / 1000000, 2);
            $result['stats']['network_tx_mbits'] = round((float) $row['netout'] * 8 / 1000000, 2);
        }
    } catch (RuntimeException $error) {
        // CPU and memory remain available when Proxmox cannot return RRD data.
    }
    return $result;
}
