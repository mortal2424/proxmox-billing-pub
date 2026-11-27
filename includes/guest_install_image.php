<?php

function guestInstallImageLabel(array $guest): string
{
    return trim((string)($guest['installation_image'] ?? ''))
        ?: (trim((string)($guest['os_version'] ?? '')) ?: 'Образ не указан');
}

function guestInstallImageColumn(PDO $db): bool
{
    static $known;
    $known ??= new WeakMap();
    return $known[$db] ??= (bool)$db->query("SHOW COLUMNS FROM vms LIKE 'installation_image'")->fetch();
}

function saveGuestInstallImage(PDO $db, int $guestId, string $image): void
{
    if ($image === '' || !guestInstallImageColumn($db)) return;
    $db->prepare('UPDATE vms SET installation_image=? WHERE id=?')
        ->execute([mb_substr($image, 0, 320), $guestId]);
}
