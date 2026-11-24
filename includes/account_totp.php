<?php
declare(strict_types=1);

function accountTotpRow(PDO $db, int $id): ?array
{
    try {
        $query = $db->prepare('SELECT secret_box, last_step FROM account_totp WHERE user_id=?');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        return null; // Pre-migration installations cannot enroll.
    }
}

function accountTotpCrypt(string $value, bool $encrypt): string
{
    $encoded = getenv('BILLING_TOTP_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Настройка двухфакторной аутентификации недоступна.');
    }
    if ($encrypt) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
    }
    $box = base64_decode($value, true);
    $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if ($box === false || strlen($box) < $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('Настройка двухфакторной аутентификации повреждена.');
    }
    $secret = sodium_crypto_secretbox_open(substr($box, $nonceLength), substr($box, 0, $nonceLength), $key);
    if ($secret === false) throw new RuntimeException('Настройка двухфакторной аутентификации повреждена.');
    return $secret;
}

function accountTotpManualKey(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $available = 0;
    $bits = 0;
    $text = '';
    foreach (unpack('C*', $secret) as $byte) {
        $bits = ($bits << 8) | $byte;
        $available += 8;
        while ($available >= 5) {
            $available -= 5;
            $text .= $alphabet[($bits >> $available) & 31];
        }
        $bits &= (1 << $available) - 1;
    }
    if ($available) $text .= $alphabet[($bits << (5 - $available)) & 31];
    return $text;
}

function accountTotpCode(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $secret, true);
    $number = unpack('N', substr($hash, ord($hash[19]) & 15, 4))[1] & 0x7fffffff;
    return sprintf('%06d', $number % 1000000);
}

function accountTotpMatch(string $secret, mixed $code, ?int $now = null): ?int
{
    if (!is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) return null;
    $current = intdiv($now ?? time(), 30);
    for ($step = $current + 1; $step >= max(0, $current - 1); $step--) {
        if (hash_equals(accountTotpCode($secret, $step), $code)) return $step;
    }
    return null;
}

function accountTotpVerify(PDO $db, int $id, mixed $code): void
{
    $row = accountTotpRow($db, $id);
    if ($row === null) return;
    if (!is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) {
        throw new RuntimeException('Введите шестизначный код Google Authenticator.');
    }
    $window = intdiv(time(), 900);
    $attempt = $db->prepare('UPDATE account_totp SET attempts=IF(attempt_window=?,attempts+1,1), attempt_window=? WHERE user_id=? AND (attempt_window<>? OR attempts<8)');
    $attempt->execute([$window, $window, $id, $window]);
    if ($attempt->rowCount() !== 1) throw new RuntimeException('Слишком много попыток. Повторите позже.');
    $step = accountTotpMatch(accountTotpCrypt($row['secret_box'], false), $code);
    if ($step !== null && $step > (int)$row['last_step']) {
        $query = $db->prepare('UPDATE account_totp SET last_step=?, attempts=0 WHERE user_id=? AND last_step<? AND secret_box=?');
        $query->execute([$step, $id, $step, $row['secret_box']]);
        if ($query->rowCount() === 1) return;
    }
    throw new RuntimeException('Введите новый шестизначный код Google Authenticator.');
}
