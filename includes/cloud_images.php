<?php

function cloudImageCatalog(?PDO $db = null): array
{
    $images = [];
    foreach (['22.04' => 'jammy', '24.04' => 'noble', '26.04' => 'resolute'] as $version => $release) {
        $images["ubuntu-$version"] = [
            'label' => "Ubuntu $version LTS", 'user' => 'ubuntu',
            'base' => "https://cloud-images.ubuntu.com/releases/$release/release/",
            'file' => "ubuntu-$version-server-cloudimg-amd64.img",
            'checksums' => 'SHA256SUMS', 'hash' => 'sha256',
        ];
    }
    foreach ([12 => 'bookworm', 13 => 'trixie'] as $version => $release) {
        $images["debian-$version"] = [
            'label' => "Debian $version", 'user' => 'debian',
            'base' => "https://cloud.debian.org/images/cloud/$release/latest/",
            'file' => "debian-$version-genericcloud-amd64.qcow2",
            'checksums' => 'SHA512SUMS', 'hash' => 'sha512',
        ];
    }
    foreach ([9, 10] as $version) {
        $file = "CentOS-Stream-GenericCloud-$version-latest.x86_64.qcow2";
        $images["centos-$version"] = [
            'label' => "CentOS Stream $version", 'user' => 'cloud-user',
            'cpu' => $version === 10 ? 'x86-64-v3' : 'x86-64-v2',
            'base' => "https://cloud.centos.org/centos/$version-stream/x86_64/images/",
            'file' => $file, 'checksums' => "$file.SHA256SUM", 'hash' => 'sha256',
        ];
    }
    foreach ([2022, 2025] as $version) {
        $images["windows-server-$version"] = [
            'label' => "Windows Server $version", 'user' => 'Administrator',
            'os_type' => 'windows', 'cpu' => 'x86-64-v2-AES',
            'minimum_ram' => 2048, 'minimum_disk' => 32,
        ];
    }
    if ($db !== null) {
        try {
            $rows = $db->query('SELECT image_key,label,guest_user FROM cloud_image_groups ORDER BY label')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $images[$row['image_key']] ??= ['label'=>$row['label'], 'user'=>$row['guest_user'], 'custom'=>true];
            }
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
        }
    }
    return $images;
}

function createCloudImageGroup(PDO $db, mixed $key, mixed $label, mixed $guestUser): void
{
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $key) ||
        !is_string($label) || trim($label) === '' || mb_strlen($label) > 100 || preg_match('/[[:cntrl:]]/u', $label) ||
        !is_string($guestUser) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $guestUser)) {
        throw new InvalidArgumentException('Укажите ключ образа, название и имя пользователя cloud-init.');
    }
    if (isset(cloudImageCatalog()[$key])) throw new InvalidArgumentException('Этот ключ уже используется встроенной группой.');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $db->prepare('INSERT INTO cloud_image_groups (image_key,label,guest_user) VALUES (?,?,?)')
                ->execute([$key, trim($label), $guestUser]);
            return;
        } catch (PDOException $error) {
            $code = (int)($error->errorInfo[1] ?? 0);
            if ($code === 1062) throw new InvalidArgumentException('Группа с таким ключом уже существует.');
            if ($code !== 1146 || $attempt !== 0) throw $error;
            $db->exec(file_get_contents(__DIR__ . '/../migrations/20261125_cloud_image_groups.sql'));
        }
    }
}

function cloudGuestAgentSnippet(callable $request, string $node, array $stores): ?string
{
    foreach ($stores as $store) {
        $storage = $store['storage'] ?? '';
        if (empty($store['active']) || empty($store['enabled']) ||
            !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $storage) ||
            !in_array('snippets', explode(',', $store['content'] ?? ''), true)) continue;

        $volume = $storage . ':snippets/homevlad-qemu-agent.yaml';
        try {
            $path = '/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/content';
            $contents = $request('GET', $path, ['content' => 'snippets']);
            if (in_array($volume, array_column($contents, 'volid'), true)) return $volume;
        } catch (Throwable $error) {
            error_log('Guest agent snippet inventory unavailable for storage ' . $storage);
        }
    }
    return null;
}

function cloudImportInventory(callable $request, string $node, ?array $storage = null): array
{
    $path = '/nodes/' . rawurlencode($node) . '/storage';
    $storage ??= $request('GET', $path, []);
    $result = [];
    foreach ($storage as $store) {
        $name = $store['storage'] ?? '';
        if (empty($store['active']) || empty($store['enabled']) ||
            !in_array('import', explode(',', $store['content'] ?? ''), true) ||
            !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) continue;
        try {
            foreach ($request('GET', $path . '/' . rawurlencode($name) . '/content', ['content'=>'import']) as $volume) {
                $volid = $volume['volid'] ?? '';
                if (is_string($volid) && preg_match('~^'.preg_quote($name,'~').':import/[A-Za-z0-9][A-Za-z0-9._ -]{0,174}\.(?:qcow2|raw)$~iD',$volid)) {
                    $result[$volid] = $volume;
                }
            }
        } catch (RuntimeException $error) {
            error_log('Cloud image inventory unavailable for storage ' . $name);
        }
    }
    return $result;
}

function cloudImportVolumes(callable $request, string $node, ?array $storage = null, ?PDO $db = null,
    ?int $nodeId = null, ?array $inventory = null): array
{
    $inventory ??= cloudImportInventory($request, $node, $storage);
    $catalog = cloudImageCatalog($db);
    $images = [];
    foreach ($inventory as $volid => $volume) {
        if (!preg_match('#^[A-Za-z0-9_.-]+:import/homevlad-([a-z0-9.-]+)-([1-9][0-9]{0,2})g-([a-f0-9]{12})\.qcow2$#D',$volid,$parts) ||
            !isset($catalog[$parts[1]])) continue;
        $images[$parts[1]][] = array_replace($catalog[$parts[1]], [
            'volid'=>$volid, 'minimum_disk'=>max((int)$parts[2],$catalog[$parts[1]]['minimum_disk']??1),
            'uploaded'=>(int)($volume['ctime']??0),
        ]);
    }
    if ($db !== null && $nodeId !== null) {
        foreach (cloudImageSettings($db,$nodeId) as $key=>$setting) {
            $source=$setting['source_volume']??'';
            if ($source === '') continue;
            if (!isset($catalog[$key],$inventory[$source])) {
                unset($images[$key]);
                continue;
            }
            $images[$key]=[array_replace($catalog[$key],[
                'volid'=>$source, 'existing_source'=>true, 'minimum_disk'=>max((int)$setting['source_minimum_disk'],$catalog[$key]['minimum_disk']??1),
                'uploaded'=>(int)($inventory[$source]['ctime']??0),
            ])];
        }
    }
    foreach ($images as &$versions) {
        usort($versions, static fn($a, $b) => [$b['uploaded'], $a['volid']] <=> [$a['uploaded'], $b['volid']]);
    }
    unset($versions);
    return $images;
}

function cloudImageCpuSupported(array $image, array $cpuInfo): bool
{
    if (!isset($image['cpu'])) return true;

    $flags = preg_split('/\s+/', trim((string)($cpuInfo['flags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $required = ['cx16', 'lahf_lm', 'pni', 'popcnt', 'sse4_1', 'sse4_2', 'ssse3'];
    if ($image['cpu'] === 'x86-64-v3') {
        array_push($required, 'abm', 'avx', 'avx2', 'bmi1', 'bmi2', 'f16c', 'fma', 'movbe', 'xsave');
    }
    if ($image['cpu'] === 'x86-64-v2-AES') $required[] = 'aes';
    return !array_diff($required, $flags);
}

function cloudImageSettings(PDO $db, int $nodeId): array
{
    $query = $db->prepare('SELECT image_key, cloud_image_settings.* FROM cloud_image_settings WHERE node_id=?');
    $query->execute([$nodeId]);
    return $query->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}

function publishedCloudImages(PDO $db, int $nodeId, array $images): array
{
    // PDO fetches the first selected column as the FETCH_UNIQUE index.
    $query = $db->prepare('SELECT image_key, display_name, storage, enabled FROM cloud_image_settings WHERE node_id=?');
    $query->execute([$nodeId]);
    $settings = $query->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    foreach ($images as $key => $versions) {
        if ((($versions[0]['os_type'] ?? '') === 'windows' || !empty($versions[0]['custom'])) && empty($settings[$key]['storage'])) unset($images[$key]);
    }
    foreach ($settings as $key => $setting) {
        if (empty($setting['enabled'])) {
            unset($images[$key]);
            continue;
        }
        if (!isset($images[$key])) continue;
        $remaining = [];
        foreach ($images[$key] as $image) {
            if ($setting['storage'] !== null && !str_starts_with($image['volid'], $setting['storage'] . ':')) continue;
            if ($setting['display_name'] !== '') $image['label'] = $setting['display_name'];
            $remaining[] = $image;
        }
        if ($remaining) $images[$key] = $remaining;
        else unset($images[$key]);
    }
    return $images;
}
