<?php

const NODE_PASSWORD_PREFIX = 'gcm:v1:';

function nodeCredentialKey(): string
{
    $encoded = getenv('PROXMOX_NODE_KEY');
    if ($encoded === false || $encoded === '') {
        $path = dirname(__DIR__, 2) . '/.proxmox-node-key';
        $encoded = is_readable($path) ? trim(file_get_contents($path)) : '';
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Ключ шифрования узлов отсутствует или имеет неверный формат');
    }
    return $key;
}

function encryptNodePassword(string $password): string
{
    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt($password, 'aes-256-gcm', nodeCredentialKey(), OPENSSL_RAW_DATA, $nonce, $tag, NODE_PASSWORD_PREFIX);
    if ($ciphertext === false) {
        throw new RuntimeException('Не удалось зашифровать пароль узла');
    }
    $stored = NODE_PASSWORD_PREFIX . base64_encode($nonce . $tag . $ciphertext);
    if (strlen($stored) > 255) {
        throw new LengthException('Пароль узла слишком длинный для сохранения');
    }
    return $stored;
}

function decryptNodePassword(?string $stored): string
{
    $stored ??= '';
    if (!str_starts_with($stored, NODE_PASSWORD_PREFIX)) {
        return $stored;
    }
    $data = base64_decode(substr($stored, strlen(NODE_PASSWORD_PREFIX)), true);
    if ($data === false || strlen($data) < 28) {
        throw new RuntimeException('Повреждён зашифрованный пароль узла');
    }
    $password = openssl_decrypt(substr($data, 28), 'aes-256-gcm', nodeCredentialKey(), OPENSSL_RAW_DATA,
        substr($data, 0, 12), substr($data, 12, 16), NODE_PASSWORD_PREFIX);
    if ($password === false) {
        throw new RuntimeException('Не удалось расшифровать пароль узла');
    }
    return $password;
}
