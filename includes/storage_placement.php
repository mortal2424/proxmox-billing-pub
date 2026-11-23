<?php
declare(strict_types=1);

/** Inventory passed here is the response from /nodes/{selected-node}/storage. */
function bestPlacementStorage(array $inventory, array $members, string $type, int $diskGiB): ?array
{
    $required = $diskGiB * 1073741824 + ($type === 'qemu' ? 67108864 : 0);
    $contentType = $type === 'qemu' ? 'images' : 'rootdir';
    $best = null;
    foreach ($inventory as $storage) {
        $name = $storage['storage'] ?? '';
        $free = (float)($storage['avail'] ?? 0);
        $total = (float)($storage['total'] ?? 0);
        if (empty($storage['active']) || empty($storage['enabled']) || !in_array($name, $members, true) ||
            !in_array($contentType, explode(',', (string)($storage['content'] ?? '')), true) || $free < $required) {
            continue;
        }
        $remaining = $free - $required;
        $headroom = $total > 0 ? min(1, $remaining / $total) : 0;
        $score = [-$headroom, -$remaining, $name];
        if ($best === null || $score < $best['score']) {
            $best = ['name'=>$name, 'remaining'=>$remaining, 'headroom'=>$headroom, 'score'=>$score];
        }
    }
    return $best;
}
