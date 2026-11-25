<?php
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/update_signature.php';

const HOMEVLAD_UPDATE_REPOSITORY = 'https://raw.githubusercontent.com/mortal2424/proxmox-billing-pub/';
const HOMEVLAD_UPDATE_CATALOG_CACHE = __DIR__ . '/../backups/updates/catalog.json';
const HOMEVLAD_UPDATE_CATALOG_MAX_AGE = 360;

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

function decodeUpdateCatalog(string $document): array
{
    $catalog = json_decode($document, true, 32, JSON_THROW_ON_ERROR);
    if (($catalog['format'] ?? null) !== 1 || !is_array($catalog['releases'] ?? null)) {
        throw new RuntimeException('Неподдерживаемый каталог обновлений');
    }
    return $catalog['releases'];
}

function updateCatalog(bool $live = false): array
{
    if (!$live && !is_link(HOMEVLAD_UPDATE_CATALOG_CACHE) && is_file(HOMEVLAD_UPDATE_CATALOG_CACHE)) {
        $modified = filemtime(HOMEVLAD_UPDATE_CATALOG_CACHE);
        if ($modified !== false && $modified >= time() - HOMEVLAD_UPDATE_CATALOG_MAX_AGE) {
            $document = @file_get_contents(HOMEVLAD_UPDATE_CATALOG_CACHE, false, null, 0, 1048577);
            if (is_string($document) && strlen($document) <= 1048576) {
                try {
                    return decodeUpdateCatalog($document);
                } catch (JsonException | RuntimeException $error) {
                    error_log('HomeVlad update catalog cache is invalid');
                }
            }
        }
    }
    return decodeUpdateCatalog(fetchUpdateDocument('main/updates/index.json'));
}

function refreshUpdateCatalogCache(): array
{
    $path = HOMEVLAD_UPDATE_CATALOG_CACHE;
    $directory = dirname($path);
    if (is_link(dirname($directory)) || is_link($directory) || is_link($path)) {
        throw new RuntimeException('Каталог обновлений не должен быть ссылкой');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог обновлений');
    }
    $document = fetchUpdateDocument('main/updates/index.json');
    $releases = decodeUpdateCatalog($document);
    $temporary = tempnam($directory, '.catalog-');
    if ($temporary === false) {
        throw new RuntimeException('Не удалось подготовить кэш каталога');
    }
    try {
        if (dirname($temporary) !== realpath($directory) ||
            file_put_contents($temporary, $document) !== strlen($document) ||
            !chmod($temporary, 0640) || !rename($temporary, $path)) {
            throw new RuntimeException('Не удалось сохранить кэш каталога');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    return $releases;
}

function isUpdateVersion(string $version): bool
{
    return preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*)?$/D', $version) === 1;
}

function updateReleaseLabel(array $release): string
{
    return $release['display_version'] ?? $release['version'];
}

function releaseManifest(string $version): array
{
    if (str_starts_with($version, 'v') || str_starts_with($version, 'V')) $version = substr($version, 1);
    if (!isUpdateVersion($version)) throw new RuntimeException('Некорректная версия обновления');
    foreach (updateCatalog(true) as $release) {
        $packageVersion = $release['version'] ?? '';
        $displayVersion = $release['display_version'] ?? $packageVersion;
        if ($version !== $packageVersion && $version !== $displayVersion) continue;
        $commit = $release['commit'] ?? '';
        $hash = $release['sha256'] ?? '';
        if (!preg_match('/^[a-f0-9]{40}$/D', $commit) || !preg_match('/^[a-f0-9]{64}$/D', $hash) ||
            !isUpdateVersion($packageVersion) || !isUpdateVersion($displayVersion) ||
            ($packageVersion !== $displayVersion && homevladCompareVersions($packageVersion, $displayVersion) !== 0)) {
            throw new RuntimeException('Некорректная запись каталога');
        }
        $raw = fetchUpdateDocument("$commit/updates/$packageVersion/manifest.json");
        if (!hash_equals($hash, hash('sha256', $raw))) {
            throw new RuntimeException('Контрольная сумма манифеста не совпадает');
        }
        $keyPath = getenv('HOMEVLAD_UPDATE_PUBLIC_KEY');
        if ($keyPath !== false && $keyPath !== '') {
            $publicKey = @file_get_contents($keyPath, false, null, 0, 256);
            if ($publicKey === false) throw new RuntimeException('Доверенный ключ обновлений недоступен');
            $signature = fetchUpdateDocument("$commit/updates/$packageVersion/manifest.json.sig", 256);
            verifyUpdateManifestSignature($raw, $signature, $publicKey);
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? '') !== $packageVersion || ($manifest['format'] ?? null) !== 1) {
            throw new RuntimeException('Некорректный манифест');
        }
        $manifest['version'] = $displayVersion;
        return $manifest;
    }
    throw new RuntimeException('Версия отсутствует в официальном каталоге');
}
