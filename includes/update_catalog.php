<?php

const HOMEVLAD_UPDATE_REPOSITORY = 'https://raw.githubusercontent.com/mortal2424/proxmox-billing-pub/';

function fetchUpdateDocument(string $path, int $maxBytes = 1048576): string
{
    $body = '';
    $curl = curl_init(HOMEVLAD_UPDATE_REPOSITORY . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'HomeVlad-Updater',
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
            throw new RuntimeException('Не удалось получить обновление с GitHub');
        }
        return $body;
    } finally {
        curl_close($curl);
    }
}

function updateCatalog(): array
{
    $catalog = json_decode(fetchUpdateDocument('main/updates/index.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($catalog['format'] ?? null) !== 1 || !is_array($catalog['releases'] ?? null)) {
        throw new RuntimeException('Неподдерживаемый каталог обновлений');
    }
    return $catalog['releases'];
}

function releaseManifest(string $version): array
{
    foreach (updateCatalog() as $release) {
        if (($release['version'] ?? '') !== $version) {
            continue;
        }
        $commit = $release['commit'] ?? '';
        $hash = $release['sha256'] ?? '';
        if (!preg_match('/^[a-f0-9]{40}$/D', $commit) || !preg_match('/^[a-f0-9]{64}$/D', $hash) ||
            !preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/D', $version)) {
            throw new RuntimeException('Некорректная запись каталога');
        }
        $raw = fetchUpdateDocument("$commit/updates/$version/manifest.json");
        if (!hash_equals($hash, hash('sha256', $raw))) {
            throw new RuntimeException('Контрольная сумма манифеста не совпадает');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? '') !== $version || ($manifest['format'] ?? null) !== 1) {
            throw new RuntimeException('Некорректный манифест');
        }
        return $manifest;
    }
    throw new RuntimeException('Версия отсутствует в официальном каталоге');
}
