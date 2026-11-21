<?php

require_once __DIR__ . '/node_credentials.php';

const GUEST_PASSWORD_VISIBILITY = 86400;

function generateGuestPassword(): string
{
    return 'Hv9!' . bin2hex(random_bytes(16));
}

function guestPasswordKey(): string
{
    return hash_hkdf('sha256', nodeCredentialKey(), 32, 'homevlad-guest-initial-password-v1');
}

function saveGuestPassword(PDO $pdo, int $guestId, string $password, string $username, bool $applied): void
{
    $nonce = random_bytes(12);
    $tag = '';
    $expires = time() + GUEST_PASSWORD_VISIBILITY;
    $context = "$guestId:$username:" . (int) $applied . ":$expires";
    $ciphertext = openssl_encrypt($password, 'aes-256-gcm', guestPasswordKey(), OPENSSL_RAW_DATA, $nonce, $tag, $context);
    if ($ciphertext === false) throw new RuntimeException('Не удалось защитить пароль гостя');

    $query = $pdo->prepare('INSERT INTO guest_initial_passwords (guest_id, ciphertext, username, applied, expires_at)
        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), username = VALUES(username),
        applied = VALUES(applied), expires_at = VALUES(expires_at)');
    $query->execute([$guestId, $nonce . $tag . $ciphertext, $username, (int) $applied, $expires]);
}

function visibleGuestPassword(PDO $pdo, int $guestId, int $ownerId): ?array
{
    $now = time();
    $query = $pdo->prepare("SELECT p.ciphertext, p.username, p.applied, p.expires_at
        FROM guest_initial_passwords p JOIN vms v ON v.id = p.guest_id
        WHERE p.guest_id = ? AND v.user_id = ? AND v.status <> 'deleted' AND p.expires_at > ?");
    $query->execute([$guestId, $ownerId, $now]);
    $record = $query->fetch(PDO::FETCH_ASSOC);
    if (!$record) return null;

    $ciphertext = $record['ciphertext'];
    $context = "$guestId:{$record['username']}:" . (int) $record['applied'] . ":{$record['expires_at']}";
    $password = openssl_decrypt(substr($ciphertext, 28), 'aes-256-gcm', guestPasswordKey(), OPENSSL_RAW_DATA,
        substr($ciphertext, 0, 12), substr($ciphertext, 12, 16), $context);
    if ($password === false) throw new RuntimeException('Не удалось прочитать пароль гостя');
    if ((int) $record['expires_at'] <= time()) return null;
    return ['password' => $password, 'username' => $record['username'],
        'applied' => (bool) $record['applied'], 'expires_at' => (int) $record['expires_at']];
}

function proposeGuestPassword(PDO $pdo, int $guestId, int $ownerId): void
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT vm_type, os_type FROM vms
            WHERE id = ? AND user_id = ? AND status <> 'deleted' FOR UPDATE");
        $query->execute([$guestId, $ownerId]);
        $guest = $query->fetch(PDO::FETCH_ASSOC);
        if (!$guest) throw new RuntimeException('Гость недоступен');
        if (visibleGuestPassword($pdo, $guestId, $ownerId) === null) {
            $previous = $pdo->prepare('SELECT username FROM guest_initial_passwords WHERE guest_id = ?');
            $previous->execute([$guestId]);
            $username = $previous->fetchColumn() ?: ($guest['os_type'] === 'windows' ? 'Administrator' : 'root');
            saveGuestPassword($pdo, $guestId, generateGuestPassword(), $username, false);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
