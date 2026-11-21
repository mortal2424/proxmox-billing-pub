<?php

require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/guest_network_probe.php';

$nodeId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$nodeId || count($argv) !== 2) {
    fwrite(STDERR, "Использование: php cronjobs/register_network_probe.php ID_НОДЫ\n");
    exit(2);
}
$db = (new Database())->getConnection();
$query = $db->prepare('SELECT id FROM proxmox_nodes WHERE id = ? AND is_active = 1');
$query->execute([$nodeId]);
if (!$query->fetchColumn()) throw new RuntimeException('Активная нода не найдена');

umask(0077);
$path = guestNetworkProbeDirectory() . "/$nodeId";
if (!is_dir($path) && !mkdir($path, 0700, true)) throw new RuntimeException('Не удалось создать каталог проверки');
$key = bin2hex(random_bytes(32));
$file = @fopen($path . '/key', 'x');
if (!$file) throw new RuntimeException('Ключ уже существует или каталог недоступен');
if (fwrite($file, $key) !== strlen($key)) {
    fclose($file);
    unlink($path . '/key');
    throw new RuntimeException('Не удалось сохранить ключ');
}
fclose($file);
echo $key, PHP_EOL;
