<?php

function lxcTemplateLabel(string $volume): string
{
    $file = preg_replace('/\.tar(?:\.(?:zst|gz|xz|bz2|lz4))?$/i', '', basename($volume));
    $distributions = [
        'alpine' => 'Alpine Linux', 'almalinux' => 'AlmaLinux', 'archlinux' => 'Arch Linux',
        'centos' => 'CentOS', 'debian' => 'Debian', 'devuan' => 'Devuan', 'fedora' => 'Fedora',
        'opensuse' => 'openSUSE', 'rockylinux' => 'Rocky Linux', 'ubuntu' => 'Ubuntu',
    ];
    if (!preg_match('/^([a-z]+)-(\d+(?:\.\d+)*)(?:-([a-z0-9-]+))?/i', $file, $parts) ||
        !isset($distributions[strtolower($parts[1])])) {
        return str_replace('_', ' · ', $file);
    }

    $release = $distributions[strtolower($parts[1])] . ' ' . $parts[2];
    $variant = $parts[3] ?? '';
    if (str_starts_with($variant, 'turnkey-')) {
        return 'TurnKey ' . ucfirst(str_replace('-', ' ', substr($variant, 8))) . ' · ' . $release;
    }
    $suffix = match ($variant) {
        'standard' => ' · стандартный', 'default' => ' · базовый', '' => '',
        default => ' · ' . str_replace('-', ' ', $variant),
    };
    $build = explode('_', $file)[1] ?? '';
    return $release . $suffix . ($build !== '' ? " · $build" : '');
}
