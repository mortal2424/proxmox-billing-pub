<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argc !== 3 && $argc !== 4) ||
    !in_array($argv[1], ['keygen', 'sign'], true) ||
    ($argv[1] === 'sign') !== ($argc === 4)) {
    fwrite(STDERR, "Usage: php tools/sign-update.php keygen PRIVATE_SEED\n" .
        "       php tools/sign-update.php sign PRIVATE_SEED MANIFEST\n");
    exit(2);
}

try {
    if (!extension_loaded('sodium')) throw new RuntimeException('The sodium extension is required.');
    $path = $argv[2];
    if ($argv[1] === 'keygen') {
        umask(0077);
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $file = @fopen($path, 'xb');
        if ($file === false) throw new RuntimeException('Cannot create seed. Existing files are not overwritten.');
        try {
            if (fwrite($file, $seed) !== strlen($seed) || !fflush($file)) {
                throw new RuntimeException('Cannot save the new seed.');
            }
        } catch (Throwable $error) {
            fclose($file);
            unlink($path);
            throw $error;
        }
        fclose($file);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        echo base64_encode(sodium_crypto_sign_publickey($keypair)), PHP_EOL;
    } else {
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Use a regular seed file accessible only to its owner.');
        }
        $seed = @file_get_contents($path, false, null, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES + 1);
        if (!is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('Invalid seed file.');
        }
        $manifest = @file_get_contents($argv[3], false, null, 0, 1048577);
        if (!is_string($manifest) || strlen($manifest) > 1048576) {
            throw new RuntimeException('Cannot read the manifest or its size exceeds 1 MiB.');
        }
        $envelope = json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
        if (($envelope['format'] ?? null) !== 1 || !is_array($envelope['files'] ?? null) ||
            $envelope['files'] === [] || !is_string($envelope['version'] ?? null)) {
            throw new RuntimeException('Invalid update manifest.');
        }
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secret = sodium_crypto_sign_secretkey($keypair);
        echo base64_encode(sodium_crypto_sign_detached($manifest, $secret)), PHP_EOL;
        sodium_memzero($secret);
    }
    sodium_memzero($keypair);
    sodium_memzero($seed);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
