<?php
require __DIR__ . '/../includes/update_queue.php';

$root = sys_get_temp_dir() . '/hv-update-queue-' . bin2hex(random_bytes(8));
$path = updateQueuePath($root);
try {
    enqueuePanelUpdate($root, '2.5.1-Pre-release1.4');
    $job = readUpdateJob($path);
    if ($job['state'] !== 'queued' || $job['version'] !== '2.5.1-Pre-release1.4') exit(1);
    try {
        enqueuePanelUpdate($root, '2.5.1-Pre-release1.5');
        exit(2);
    } catch (RuntimeException $expected) {
        if (readUpdateJob($path) !== $job) exit(3);
    }
    runQueuedPanelUpdate($root, static fn () => throw new RuntimeException('Fixture failure'));
    if (readUpdateJob($path)['state'] !== 'failed') exit(4);
    enqueuePanelUpdate($root, '2.5.1-Pre-release1.4');
    if (readUpdateJob($path)['state'] !== 'queued') exit(5);
    echo "PASS enqueue, duplicate protection, error persistence and retry\n";
} finally {
    @unlink($path);
    @unlink($path . '.lock');
    @rmdir(dirname($path));
    @rmdir($root . '/backups');
    @rmdir($root);
}
