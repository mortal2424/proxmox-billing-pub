<?php

function cloudImageCatalog(): array
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
    return $images;
}

function cloudImportVolumes(callable $request, string $node, ?array $storage = null): array
{
    $path = '/nodes/' . rawurlencode($node) . '/storage';
    $storage ??= $request('GET', $path, []);
    $catalog = cloudImageCatalog();
    $images = [];
    foreach ($storage as $store) {
        $name = $store['storage'] ?? '';
        if (empty($store['active']) || empty($store['enabled']) ||
            !in_array('import', explode(',', $store['content'] ?? ''), true) ||
            !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) {
            continue;
        }
        try {
            $volumes = $request('GET', $path . '/' . rawurlencode($name) . '/content', ['content' => 'import']);
            foreach ($volumes as $volume) {
                $volid = $volume['volid'] ?? '';
                if (!preg_match('#^' . preg_quote($name, '#') . ':import/homevlad-([a-z0-9.-]+)-([1-9][0-9]{0,2})g-([a-f0-9]{12})\.qcow2$#D', $volid, $parts) ||
                    !isset($catalog[$parts[1]])) {
                    continue;
                }
                $images[$parts[1]][] = $catalog[$parts[1]] + [
                    'volid' => $volid, 'minimum_disk' => (int)$parts[2], 'uploaded' => (int)($volume['ctime'] ?? 0),
                ];
            }
        } catch (RuntimeException $error) {
            error_log('Cloud image inventory unavailable for storage ' . $name);
        }
    }
    foreach ($images as &$versions) {
        usort($versions, static fn($a, $b) => [$b['uploaded'], $a['volid']] <=> [$a['uploaded'], $b['volid']]);
    }
    unset($versions);
    return $images;
}
