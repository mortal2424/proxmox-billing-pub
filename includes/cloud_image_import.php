<?php
require_once __DIR__ . '/cloud_images.php';
require_once __DIR__ . '/node_api_credentials.php';
require_once __DIR__ . '/ProxmoxApiClient.php';
require_once __DIR__ . '/guest_operation_lock.php';

function validatedCloudDiskSize($file): int
{
    $header = fread($file, 104);
    if (strlen($header) < 72 || substr($header, 0, 4) !== "QFI\xfb" ||
        substr($header, 8, 12) !== str_repeat("\0", 12)) {
        throw new RuntimeException('Нужен самостоятельный QCOW2 без внешнего базового диска');
    }
    $version = unpack('N', substr($header, 4, 4))[1];
    if (!in_array($version, [2, 3], true) || ($version === 3 &&
        (strlen($header) < 104 || (unpack('J', substr($header, 72, 8))[1] & 4) !== 0))) {
        throw new RuntimeException('QCOW2 с внешним файлом данных не поддерживается');
    }
    if (unpack('N', substr($header, 32, 4))[1] !== 0) {
        throw new RuntimeException('Зашифрованные образы QCOW2 не поддерживаются');
    }
    $size = unpack('J', substr($header, 24, 8))[1];
    $minimum = (int)ceil($size / 1073741824);
    if ($minimum < 1 || $minimum > 100) throw new RuntimeException('Допустимый размер виртуального диска — до 100 ГиБ');
    return $minimum;
}

function uploadPreparedCloudImage(array $node, string $storage, string $key, string $file, ?PDO $db = null): string
{
    if (!isset(cloudImageCatalog($db)[$key]) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $storage)) {
        throw new InvalidArgumentException('Неизвестный образ или хранилище');
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) throw new RuntimeException('Файл загрузки недоступен');
    try {
        $minimum = validatedCloudDiskSize($handle);
    } finally {
        fclose($handle);
    }
    $hash = hash_file('sha256', $file);
    if ($hash === false) throw new RuntimeException('Не удалось прочитать образ');
    $filename = "homevlad-$key-{$minimum}g-" . substr($hash, 0, 12) . '.qcow2';
    $volume = "$storage:import/$filename";
    $api = new ProxmoxApiClient(panelNodeApiCredentials($node));
    $path = '/nodes/' . rawurlencode($node['node_name']) . '/storage/' . rawurlencode($storage);
    return withGuestOperationLocks(["cloud-image:{$node['id']}:$storage:$key"], static function () use ($api, $node, $path, $storage, $file, $filename, $volume): string {
        $status = $api->request('GET', $path . '/status');
        if (empty($status['active']) || !in_array('import', explode(',', $status['content'] ?? ''), true)) {
            throw new RuntimeException('Хранилище Import недоступно на выбранной ноде');
        }
        $inventory = static fn(): array => array_column($api->request('GET', $path . '/content', ['content'=>'import']), 'volid');
        if (!in_array($volume, $inventory(), true)) {
            $api->uploadImage($node['node_name'], $storage, $file, $filename, 'import');
            if (!in_array($volume, $inventory(), true)) throw new RuntimeException('Proxmox не подтвердил появление файла');
        }
        return $volume;
    });
}

function importCloudImage(array $node, string $storage, string $key): string
{
    $image = cloudImageCatalog()[$key] ?? null;
    if (!$image || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $storage)) {
        throw new InvalidArgumentException('Неизвестный образ или хранилище');
    }
    if (!isset($image['base'])) {
        throw new InvalidArgumentException('Этот образ нужно предварительно подготовить и загрузить в Proxmox');
    }
    $api = new ProxmoxApiClient(panelNodeApiCredentials($node));
    $path = '/nodes/' . rawurlencode($node['node_name']) . '/storage/' . rawurlencode($storage);
    $status = $api->request('GET', $path . '/status');
    if (empty($status['active']) || !in_array('import', explode(',', $status['content'] ?? ''), true)) {
        throw new RuntimeException('Нужно активное файловое хранилище Proxmox с типом содержимого Import');
    }

    return withGuestOperationLocks(["cloud-image:{$node['id']}:$storage:$key"], static function () use ($image, $api, $node, $path, $storage, $key): string {
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
            $minimum = validatedCloudDiskSize($file);
            $filePath = stream_get_meta_data($file)['uri'];
            $hash = hash_file($image['hash'], $filePath);
            if (!hash_equals(strtolower($match[1]), $hash)) throw new RuntimeException('Контрольная сумма образа отличается от суммы издателя');

            $filename = "homevlad-$key-{$minimum}g-" . substr($hash, 0, 12) . '.qcow2';
            $volume = "$storage:import/$filename";
            $list = static fn() => array_column($api->request('GET', $path . '/content', ['content' => 'import']), 'volid');
            if (!in_array($volume, $list(), true)) {
                $api->uploadImage($node['node_name'], $storage, $filePath, $filename, 'import');
            }
            if (!in_array($volume, $list(), true)) throw new RuntimeException('Proxmox не подтвердил наличие образа');
            return $volume;
        } finally {
            fclose($checksums);
            fclose($file);
        }
    });
}

function mountedCloudImageDirectory(): ?string
{
    $configured = getenv('HOMEVLAD_CLOUD_IMAGE_SOURCE_DIR');
    if (!$configured || !str_starts_with($configured, '/')) return null;
    $directory = realpath($configured);
    $webRoot = realpath(dirname(__DIR__));
    if ($directory === false || $directory === '/' || !is_dir($directory) ||
        $directory === $webRoot || str_starts_with($directory . '/', $webRoot . '/') ||
        str_starts_with($webRoot . '/', $directory . '/')) {
        return null;
    }
    return $directory;
}

function mountedCloudImagePath(string $name): ?string
{
    $directory = mountedCloudImageDirectory();
    if ($directory === null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,175}\.(?:qcow2|raw|img)$/iD', $name)) {
        return null;
    }
    $path = $directory . '/' . $name;
    return !is_link($path) && realpath($path) === $path && is_file($path) && is_readable($path) ? $path : null;
}

function mountedCloudImageNames(): array
{
    $directory = mountedCloudImageDirectory();
    if ($directory === null || !is_readable($directory)) return [];
    $names = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        $name = $entry->getFilename();
        if (mountedCloudImagePath($name) !== null) $names[] = $name;
    }
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function convertMountedCloudImage(array $node, string $storage, string $key, string $name): string
{
    $source = mountedCloudImagePath($name);
    if ($source === null) throw new RuntimeException('Файл отсутствует в подключённом хранилище');
    $file = fopen($source, 'rb');
    if ($file === false) throw new RuntimeException('Не удалось открыть исходный образ');
    try {
        $stat = fstat($file);
        $magic = fread($file, 4);
        rewind($file);
        $format = $magic === "QFI\xfb" ? 'qcow2' : 'raw';
        if ($format === 'qcow2') {
            validatedCloudDiskSize($file);
        } elseif (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'qcow2' ||
            !$stat || $stat['size'] < 512 || $stat['size'] > 100 * 1073741824) {
            throw new RuntimeException('Нужен RAW/IMG или самостоятельный QCOW2 до 100 ГиБ');
        }
        $output = tmpfile();
        if ($output === false) throw new RuntimeException('Нет места для временного образа');
        try {
            $target = stream_get_meta_data($output)['uri'];
            $process = proc_open(['/usr/bin/timeout', '20m', '/usr/bin/qemu-img', 'convert',
                '-f', $format, '-O', 'qcow2', '/proc/self/fd/3', $target],
                [0=>['file','/dev/null','r'], 1=>['file','/dev/null','w'],
                 2=>['file','/dev/null','w'], 3=>$file], $pipes);
            if (!is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('Конвертация не завершилась. Проверьте qemu-img, формат и свободное место.');
            }
            return uploadPreparedCloudImage($node, $storage, $key, $target, (new Database())->getConnection());
        } finally {
            fclose($output);
        }
    } finally {
        fclose($file);
    }
}
