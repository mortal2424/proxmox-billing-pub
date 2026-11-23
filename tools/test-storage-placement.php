<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/storage_placement.php';

$gib = 1073741824;
$store = static fn(string $id, int $free, int $total, string $content='images', bool $active=true): array =>
    ['storage'=>$id,'avail'=>$free * $gib,'total'=>$total * $gib,'content'=>$content,'active'=>$active,'enabled'=>true];
$catalog = [
    $store('a', 60, 100), $store('b', 65, 200), $store('only-container', 90, 100, 'rootdir'),
    $store('not-in-pool', 98, 100), $store('offline', 99, 100, 'images', false),
];
if (bestPlacementStorage($catalog, ['a','b','only-container','offline'], 'qemu', 20)['name'] !== 'a' ||
    bestPlacementStorage($catalog, ['a','b','only-container','offline'], 'lxc', 20)['name'] !== 'only-container' ||
    bestPlacementStorage($catalog, ['a','b'], 'qemu', 65) !== null ||
    bestPlacementStorage([$store('on-other-node', 99, 100)], ['a','b'], 'qemu', 1) !== null) {
    throw new RuntimeException('Storage placement regression');
}
echo "Storage type, capacity, pool membership and ranking checks passed\n";
