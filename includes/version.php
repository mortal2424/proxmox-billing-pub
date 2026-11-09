<?php

const HOMEVLAD_VERSION = '2.5.1-beta7';

function homevladVersion($recordedVersion = null): string
{
    $recordedVersion = ltrim((string) $recordedVersion, 'vV');
    return version_compare($recordedVersion, HOMEVLAD_VERSION, '>')
        ? $recordedVersion
        : HOMEVLAD_VERSION;
}
