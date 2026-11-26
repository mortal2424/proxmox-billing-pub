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

function configuredUpdateTrust(): array
{
    $required = getenv('HOMEVLAD_UPDATE_SIGNATURE_REQUIRED');
    if (!in_array($required, [false, '', '0', '1'], true)) {
        throw new RuntimeException('HOMEVLAD_UPDATE_SIGNATURE_REQUIRED должен быть 0 или 1');
    }
    $path = getenv('HOMEVLAD_UPDATE_PUBLIC_KEY');
    if ($path === false || $path === '') {
        if ($required === '1') throw new RuntimeException('Для обязательной проверки подписи задайте HOMEVLAD_UPDATE_PUBLIC_KEY');
        return ['required' => false, 'public_key' => null];
    }
    $publicKey = @file_get_contents($path, false, null, 0, 256);
    if ($publicKey === false) throw new RuntimeException('Доверенный ключ обновлений недоступен');
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('Для проверки подписи требуется sodium');
    }
    $decoded = base64_decode(trim($publicKey), true);
    if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new RuntimeException('Некорректный формат доверенного ключа обновлений');
    }
    return ['required' => $required === '1', 'public_key' => $publicKey];
}

function verifyConfiguredUpdateSignature(string $document, callable $fetchSignature): void
{
    $publicKey = configuredUpdateTrust()['public_key'];
    if ($publicKey === null) return;
    try {
        $signature = $fetchSignature();
    } catch (Throwable $error) {
        throw new RuntimeException('Подпись пакета обновления недоступна', 0, $error);
    }
    verifyUpdateManifestSignature($document, $signature, $publicKey);
}
