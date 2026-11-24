<?php
require __DIR__ . '/../includes/cloud_image_import.php';

$disk = "QFI\xfb" . pack('N', 3) . str_repeat("\0", 16) . pack('J', 1073741825) . str_repeat("\0", 72);
$run = static function (string $image): int {
    $stream = fopen('php://memory', 'w+b');
    try {
        fwrite($stream, $image);
        rewind($stream);
        return validatedCloudDiskSize($stream);
    } finally {
        fclose($stream);
    }
};
if ($run($disk) !== 2) throw new RuntimeException('Wrong minimum disk size');
foreach ([
    substr_replace($disk, pack('J', 8), 8, 8),
    substr_replace($disk, pack('J', 4), 72, 8),
    substr_replace($disk, pack('N', 1), 32, 4),
    substr_replace($disk, pack('J', 101 * 1073741824), 24, 8),
    '<!DOCTYPE html>',
] as $invalid) {
    try {
        $run($invalid);
    } catch (RuntimeException $expected) {
        continue;
    }
    throw new RuntimeException('Invalid cloud image accepted');
}
echo "PASS QCOW2 upload size, HTML, backing file, external data and encryption validation\n";
