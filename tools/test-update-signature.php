<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require $argv[1] . '/includes/update_signature.php';

$path = tempnam(sys_get_temp_dir(), 'homevlad-update-trust-');
$pair = sodium_crypto_sign_keypair();
$document = '{"format":1}';
$signature = base64_encode(sodium_crypto_sign_detached($document, sodium_crypto_sign_secretkey($pair)));
$expectFailure = static function (callable $verify, string $message): void {
    try {
        $verify();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $message)) return;
        throw $error;
    }
    throw new RuntimeException('An invalid package was accepted');
};
try {
    putenv('HOMEVLAD_UPDATE_PUBLIC_KEY');
    putenv('HOMEVLAD_UPDATE_SIGNATURE_REQUIRED=1');
    $fetch = static fn (): string => $signature;
    $verify = static fn () => verifyConfiguredUpdateSignature($document, $fetch);
    $expectFailure($verify, 'HOMEVLAD_UPDATE_PUBLIC_KEY');
    putenv("HOMEVLAD_UPDATE_PUBLIC_KEY=$path.missing");
    $expectFailure($verify, 'ключ обновлений недоступен');
    putenv("HOMEVLAD_UPDATE_PUBLIC_KEY=$path");
    file_put_contents($path, base64_encode(sodium_crypto_sign_publickey($pair)));
    $verify();
    $expectFailure(static fn () => verifyConfiguredUpdateSignature($document, static function (): string {
        throw new RuntimeException('HTTP 404');
    }), 'Подпись пакета');
    $expectFailure(static fn () => verifyConfiguredUpdateSignature($document . ' ', $fetch), 'Подпись манифеста');
    file_put_contents($path, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
    $expectFailure($verify, 'Подпись манифеста');
    putenv('HOMEVLAD_UPDATE_SIGNATURE_REQUIRED=0');
    putenv('HOMEVLAD_UPDATE_PUBLIC_KEY');
    verifyConfiguredUpdateSignature($document, static function (): string {
        throw new LogicException('Optional unsigned package fetched a signature');
    });
    echo "Update trust: valid signature, missing key/signature, tampering, wrong key and legacy mode passed\n";
} finally {
    unlink($path);
}
