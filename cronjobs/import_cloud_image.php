<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cloud_image_import.php';

[$script, $nodeId, $storage, $key] = array_pad($argv, 4, '');
$image = cloudImageCatalog()[$key] ?? null;
if (count($argv) !== 4 || !ctype_digit($nodeId) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $storage) || !$image) {
    fwrite(STDERR, "Использование: php cronjobs/import_cloud_image.php ID_НОДЫ ХРАНИЛИЩЕ КОД_ОБРАЗА\n");
    exit(2);
}

try {
    $db = (new Database())->getConnection();
    $query = $db->prepare('SELECT * FROM proxmox_nodes WHERE id=? AND is_active=1');
    $query->execute([(int)$nodeId]);
    $node = $query->fetch();
    if (!$node) throw new RuntimeException('Активная нода не найдена');
    echo importCloudImage($node, $storage, $key) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
