<?php

function consumeTelegramAuthorization(PDO $pdo, array $data, string $token): bool
{
    $signature = $data['hash'] ?? '';
    $time = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
    $now = time();
    if ($token === '' || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature) ||
        !$time || $time < $now - 300 || $time > $now + 30 ||
        !is_scalar($data['id'] ?? null) || !preg_match('/^[0-9]{1,20}$/D', (string) $data['id'])) return false;
    unset($data['hash']);
    ksort($data);
    $parts = [];
    foreach ($data as $key=>$value) {
        if (!is_scalar($value) || preg_match('/[\r\n]/', (string)$value)) return false;
        if ($value !== '') $parts[] = "$key=$value";
    }
    $expected = hash_hmac('sha256', implode("\n", $parts), hash('sha256', $token, true));
    if (!hash_equals($expected, $signature)) return false;
    try {
        $cleanup = $pdo->prepare('DELETE FROM telegram_auth_replays WHERE expires_at < ?');
        $cleanup->execute([$now]);
        $consume = $pdo->prepare('INSERT INTO telegram_auth_replays (digest, expires_at) VALUES (?,?)');
        return $consume->execute([hex2bin($signature),$time+300]);
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1062) error_log('Telegram replay protection unavailable');
        return false;
    }
}
