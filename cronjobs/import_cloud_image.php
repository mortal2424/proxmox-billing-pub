<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cloud_images.php';
require_once __DIR__ . '/../includes/node_api_credentials.php';
require_once __DIR__ . '/../includes/ProxmoxApiClient.php';
require_once __DIR__ . '/../includes/guest_operation_lock.php';

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
    $api = new ProxmoxApiClient(panelNodeApiCredentials($node));
    $path = '/nodes/' . rawurlencode($node['node_name']) . '/storage/' . rawurlencode($storage);
    $status = $api->request('GET', $path . '/status');
    if (empty($status['active']) || !in_array('import', explode(',', $status['content'] ?? ''), true)) {
        throw new RuntimeException('Нужно активное файловое хранилище Proxmox с типом содержимого Import');
    }

    withGuestOperationLocks(["cloud-image:$nodeId:$storage:$key"], static function () use ($image, $api, $node, $path, $storage, $key) {
        $download = static function (string $url, $output, int $limit): void {
            $curl = curl_init($url);
            $received = 0;
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FAILONERROR => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 1200,
                CURLOPT_USERAGENT => 'HomeVlad-Cloud-Image-Importer/1.0',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($output, $limit, &$received): int {
                    $received += strlen($data);
                    return $received <= $limit ? (int)fwrite($output, $data) : 0;
                },
            ]);
            try {
                if (curl_exec($curl) === false) {
                    $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                    throw new RuntimeException(
                        'Не удалось скачать ' . basename($url) . ' (HTTP ' . $http . ', cURL ' . curl_errno($curl) . ')'
                    );
                }
            } finally {
                curl_close($curl);
            }
            rewind($output);
        };
        $checksums = fopen('php://memory', 'w+b');
        $file = tmpfile();
        if (!$checksums || !$file) throw new RuntimeException('Нет временного хранилища для скачивания');
        try {
            $download($image['base'] . $image['checksums'], $checksums, 1048576);
            $manifest = stream_get_contents($checksums);
            $name = preg_quote($image['file'], '~');
            if (!preg_match('~^([a-fA-F0-9]+)[ \t]+\*?(?:\./)?' . $name . '[ \t\r]*$~m', $manifest, $match) &&
                !preg_match('~^SHA256\s*\(' . $name . '\)\s*=\s*([a-fA-F0-9]+)[ \t\r]*$~m', $manifest, $match)) {
                throw new RuntimeException('В файле издателя нет контрольной суммы выбранного образа');
            }
            $download($image['base'] . $image['file'], $file, 4294967296);
            $header = fread($file, 72);
            if (strlen($header) !== 72 || substr($header, 0, 4) !== "QFI\xfb" ||
                substr($header, 8, 12) !== str_repeat("\0", 12)) {
                throw new RuntimeException('Нужен самостоятельный образ QCOW2 без внешнего базового диска');
            }
            $size = unpack('J', substr($header, 24, 8))[1];
            $minimum = (int)ceil($size / 1073741824);
            if ($minimum < 1 || $minimum > 100) throw new RuntimeException('Недопустимый виртуальный размер образа');
            $filePath = stream_get_meta_data($file)['uri'];
            $hash = hash_file($image['hash'], $filePath);
            if (!hash_equals(strtolower($match[1]), $hash)) throw new RuntimeException('Контрольная сумма образа отличается от суммы издателя');

            $filename = "homevlad-$key-${minimum}g-" . substr($hash, 0, 12) . '.qcow2';
            $volume = "$storage:import/$filename";
            $list = static fn() => array_column($api->request('GET', $path . '/content', ['content' => 'import']), 'volid');
            if (!in_array($volume, $list(), true)) {
                $api->uploadImage($node['node_name'], $storage, $filePath, $filename, 'import');
            }
            if (!in_array($volume, $list(), true)) throw new RuntimeException('Proxmox не подтвердил наличие образа');
            echo "$volume\n";
        } finally {
            fclose($checksums);
            fclose($file);
        }
    });
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
