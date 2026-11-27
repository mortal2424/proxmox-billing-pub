<?php
declare(strict_types=1);

/** Fixed provider endpoints keep token credentials and JWKS fetches on trusted hosts. */
function billingOidcProvider(string $provider): array
{
    return match ($provider) {
        'google' => [
            'authorization' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        default => throw new InvalidArgumentException('Unknown identity provider'),
    };
}

function billingOidcDecode(string $value): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value) ||
        ($decoded = base64_decode(strtr($value, '-_', '+/'), true)) === false) {
        throw new RuntimeException('Invalid identity token encoding');
    }
    return $decoded;
}

function billingOidcDer(int $tag, string $bytes): string
{
    $length = strlen($bytes);
    if ($length < 128) return chr($tag) . chr($length) . $bytes;
    $size = ltrim(pack('N', $length), "\0");
    return chr($tag) . chr(128 + strlen($size)) . $size . $bytes;
}

function billingOidcRsaKey(array $jwk): string
{
    $integer = static function (string $bytes): string {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) >= 128) $bytes = "\0" . $bytes;
        return billingOidcDer(2, $bytes);
    };
    $n = billingOidcDecode($jwk['n'] ?? '');
    $e = billingOidcDecode($jwk['e'] ?? '');
    if (strlen($n) < 256 || strlen($n) > 1024 || strlen($e) > 8) {
        throw new RuntimeException('Invalid provider signing key');
    }
    $rsa = billingOidcDer(48, $integer($n) . $integer($e));
    $der = billingOidcDer(48, hex2bin('300d06092a864886f70d0101010500') . billingOidcDer(3, "\0" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Validate the signed identity returned by the server-side code exchange. */
function billingOidcIdentity(string $provider, string $jwt, array $keys, string $clientId, string $nonce, int $now): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3 || strlen($jwt) > 16384) throw new RuntimeException('Invalid identity token');
    [$headerPart, $claimsPart, $signaturePart] = $parts;
    $header = json_decode(billingOidcDecode($headerPart), true, 12, JSON_THROW_ON_ERROR);
    if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
        throw new RuntimeException('Unexpected identity signature algorithm');
    }
    $matches = array_values(array_filter($keys, static fn($key) =>
        is_array($key) && ($key['kid'] ?? null) === $header['kid'] &&
        ($key['kty'] ?? null) === 'RSA' && ($key['use'] ?? 'sig') === 'sig' && ($key['alg'] ?? 'RS256') === 'RS256'));
    if (count($matches) !== 1 || openssl_verify($headerPart . '.' . $claimsPart,
            billingOidcDecode($signaturePart), billingOidcRsaKey($matches[0]), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Invalid identity signature');
    }
    $claims = json_decode(billingOidcDecode($claimsPart), true, 12, JSON_THROW_ON_ERROR);
    if (!in_array($claims['iss'] ?? null, billingOidcProvider($provider)['issuers'], true) ||
        ($claims['aud'] ?? null) !== $clientId || ($claims['azp'] ?? $clientId) !== $clientId ||
        !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now ||
        !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 ||
        !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce']) ||
        !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
        throw new RuntimeException('Invalid identity claims');
    }
    $email = $claims['email'] ?? '';
    $verified = in_array($claims['email_verified'] ?? null, [true, 'true'], true);
    return [
        'provider' => $provider,
        'subject' => $claims['sub'],
        'email_authoritative' => $verified && is_string($email) && (
            str_ends_with(strtolower($email), '@gmail.com') ||
            (is_string($claims['hd'] ?? null) && $claims['hd'] !== '' && str_ends_with(strtolower($email), '@'.strtolower($claims['hd'])))
        ),
        'email' => $verified && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
    ];
}

/** Caller persists the returned transaction in a dedicated short-lived browser session. */
function billingOidcStart(string $provider, string $clientId, string $callback): array
{
    $endpoints = billingOidcProvider($provider);
    $transaction = [
        'provider' => $provider,
        'state' => bin2hex(random_bytes(32)),
        'nonce' => bin2hex(random_bytes(32)),
        'until' => time() + 600,
    ];
    $parameters = [
        'client_id' => $clientId,
        'redirect_uri' => $callback,
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => $transaction['state'],
        'nonce' => $transaction['nonce'],
    ];
    $transaction['verifier'] = bin2hex(random_bytes(32));
    $parameters['code_challenge_method'] = 'S256';
    $parameters['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $transaction['verifier'], true)), '+/', '-_'), '=');
    return [$transaction, $endpoints['authorization'] . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)];
}

function billingOidcJson(string $url, ?array $form = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if ($form !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
    }
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($response) || $status !== 200 || strlen($response) > 65536) {
        throw new RuntimeException('Identity provider unavailable');
    }
    $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid identity provider response');
    return $data;
}

/** Caller removes the transaction before invoking this function. */
function billingOidcFinish(array $transaction, array $response, string $clientId, string $secret, string $callback): array
{
    $state = $response['state'] ?? null;
    $code = $response['code'] ?? null;
    if (($transaction['until'] ?? 0) <= time() || !is_string($state) ||
        !hash_equals($transaction['state'], $state) || !is_string($code) || $code === '' || strlen($code) > 4096 ||
        isset($response['error'])) {
        throw new RuntimeException('Invalid or expired authorization response');
    }
    $provider = $transaction['provider'];
    $endpoints = billingOidcProvider($provider);
    $form = [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $callback,
        'client_id' => $clientId, 'client_secret' => $secret,
    ];
    if ($provider === 'google') $form['code_verifier'] = $transaction['verifier'];
    $tokens = billingOidcJson($endpoints['token'], $form);
    $keys = billingOidcJson($endpoints['jwks']);
    return billingOidcIdentity($provider, $tokens['id_token'] ?? '', $keys['keys'] ?? [],
        $clientId, $transaction['nonce'], time());
}

function googleLoginEnabled(PDO $db): bool
{
    try {
        return (bool)$db->query("SELECT enabled FROM web_login_google
            WHERE id=1 AND client_id<>'' AND secret_box<>''")->fetchColumn();
    } catch (PDOException $error) {
        return false;
    }
}
