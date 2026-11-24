<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/license_guard.php';

putenv('HOMEVLAD_LICENSE_CONFIG');
putenv('HOMEVLAD_LICENSE_REQUIRED=0');
if (homevladLicenseConfig() !== null) throw new RuntimeException('Optional mode should support absent configuration');

putenv('HOMEVLAD_LICENSE_REQUIRED=1');
$expectFailure = static function (): void {
    try {
        homevladLicenseConfig();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Mandatory mode accepted missing or damaged licensing configuration');
};
$expectFailure();
$path = tempnam(sys_get_temp_dir(), 'license-config-');
try {
    putenv('HOMEVLAD_LICENSE_CONFIG=' . $path);
    file_put_contents($path, '<?php return [];');
    $expectFailure();
    file_put_contents($path, '<?php return [;');
    $expectFailure();
    $config = ['client' => __FILE__, 'domain' => 'test.example.com', 'cache' => $path . '.cache',
        'public_key' => str_repeat('A', 43), 'nonce' => str_repeat('0', 64),
        'source_ip' => '192.0.2.3', 'key_file' => $path . '.key'];
    file_put_contents($path, '<?php return ' . var_export(array_replace($config, ['public_key' => 'bad']), true) . ';');
    $expectFailure();
    file_put_contents($path, '<?php return ' . var_export($config, true) . ';');
    if (homevladLicenseConfig() !== $config) throw new RuntimeException('Valid configuration was rejected');
} finally {
    unlink($path);
}
echo "Optional and mandatory licensing configuration checks passed\n";
