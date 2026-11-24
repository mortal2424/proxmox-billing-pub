<?php

function verifyUpdateManifestSignature(string $document, string $signature, string $trustedKey): void
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('Для проверки подписи требуется sodium');
    }
    $key = base64_decode(trim($trustedKey), true);
    $detached = base64_decode(trim($signature), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
        $detached === false || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES ||
        !sodium_crypto_sign_verify_detached($detached, $document, $key)) {
        throw new RuntimeException('Подпись манифеста недействительна');
    }
}
