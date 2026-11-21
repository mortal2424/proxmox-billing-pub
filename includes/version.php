<?php

const HOMEVLAD_VERSION = '2.5.1-beta8.33';

function homevladVersion($recordedVersion = null): string
{
    $recordedVersion = ltrim((string) $recordedVersion, 'vV');
    return version_compare($recordedVersion, HOMEVLAD_VERSION, '>')
        ? $recordedVersion
        : HOMEVLAD_VERSION;
}
