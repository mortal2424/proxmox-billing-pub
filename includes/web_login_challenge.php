<?php
declare(strict_types=1);

function limitWebLogin(PDO $db, string $address): void
{
    $key = hash('sha256', inet_pton($address) ?: 'unknown');
    $now = time();
    $ends = $now + 900;
    $query = $db->prepare('INSERT INTO web_login_attempts (source,attempts,expires_at) VALUES (?,1,?)
        ON DUPLICATE KEY UPDATE attempts=IF(expires_at<=?,1,LEAST(attempts+1,31)),
        expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)');
    $query->execute([$key, $ends, $now, $now]);
    $query = $db->prepare('SELECT attempts FROM web_login_attempts WHERE source=?');
    $query->execute([$key]);
    if (random_int(1,100) === 1) $db->exec("DELETE FROM web_login_attempts WHERE expires_at <= " . (int)$now . " LIMIT 500");
    if ((int)$query->fetchColumn() > 30) throw new RuntimeException('Слишком много попыток. Повторите через 15 минут.');
}

function loginCaptchaSettings(PDO $db): array
{
    static $defaults = ['enabled'=>0, 'site_key'=>'', 'secret_box'=>'', 'hostname'=>''];
    return $db->query('SELECT enabled, site_key, secret_box, hostname FROM web_login_captcha WHERE id=1')
        ->fetch(PDO::FETCH_ASSOC) ?: $defaults;
}

function verifyLoginTurnstile(mixed $token, array $config): void
{
    if (!$config['secret_box'] || !$config['hostname'] || !$config['site_key']) {
        throw new RuntimeException('Проверка входа временно недоступна.');
    }
    $secret = accountTotpCrypt($config['secret_box'], false);
    $hostname = $config['hostname'];
    if (!is_string($token) || $token === '' || strlen($token) > 2048) {
        throw new RuntimeException('Пройдите проверку и повторите ввод пароля.');
    }
    $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6, CURLOPT_POSTFIELDS => http_build_query(['secret'=>$secret, 'response'=>$token]),
    ]);
    $body = curl_exec($curl);
    if ($body === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Проверка входа временно недоступна.');
    }
    $result = json_decode($body, true);
    if (($result['success'] ?? null) !== true || ($result['hostname'] ?? null) !== $hostname ||
        ($result['action'] ?? null) !== 'panel_login') {
        throw new RuntimeException('Проверка не пройдена. Повторите попытку.');
    }
}
