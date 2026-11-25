<?php

const HOMEVLAD_VERSION = '2.5.1-Pre-release1.4';

function homevladVersion($recordedVersion = null): string
{
    $recordedVersion = ltrim((string) $recordedVersion, 'vV');
    return homevladCompareVersions($recordedVersion, HOMEVLAD_VERSION) > 0
        ? $recordedVersion
        : HOMEVLAD_VERSION;
}

function homevladCompareVersions(string $left, string $right): int
{
    $normalize = static fn (string $version): string => preg_replace('/-Pre-release(?=\d)/i', '-RC', $version);
    return version_compare($normalize($left), $normalize($right));
}
