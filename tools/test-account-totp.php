<?php
require __DIR__ . '/../includes/account_totp.php';

$secret = '12345678901234567890';
$checks = [
    accountTotpCode($secret, 1) === '287082',
    accountTotpCode($secret, intdiv(1111111109, 30)) === '081804',
    accountTotpCode($secret, intdiv(20000000000, 30)) === '353130',
    accountTotpManualKey('foobar') === 'MZXW6YTBOI',
    accountTotpMatch($secret, '287082', 59) === 1,
    accountTotpMatch($secret, '287082', 120) === null,
    accountTotpMatch($secret, 287082, 59) === null,
];
putenv('BILLING_TOTP_KEY=' . base64_encode(random_bytes(32)));
$encrypted = accountTotpCrypt($secret, true);
$checks[] = accountTotpCrypt($encrypted, false) === $secret;
putenv('BILLING_TOTP_KEY=' . base64_encode(random_bytes(32)));
try {
    accountTotpCrypt($encrypted, false);
    $checks[] = false;
} catch (RuntimeException $expected) {
    $checks[] = true;
}
if (in_array(false, $checks, true)) throw new RuntimeException('TOTP regression failed');
echo "TOTP RFC test vectors, Base32, time window and encrypted secret passed\n";
