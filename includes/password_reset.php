<?php

function canonicalPanelOrigin(): string
{
    $origin = rtrim(getenv('HOMEVLAD_PUBLIC_URL') ?: (defined('HOMEVLAD_CANONICAL_URL') ? HOMEVLAD_CANONICAL_URL : ''), '/');
    $parts = parse_url($origin);
    if (!filter_var($origin, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host']) || array_intersect(['user','pass','path','query','fragment'], array_keys($parts))) {
        throw new RuntimeException('Configure the canonical HTTPS URL');
    }
    return $origin;
}

function passwordResetDigest(string $token): ?string
{
    return preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token) : null;
}

function consumePasswordReset(PDO $pdo, string $email, string $token, string $password): bool
{
    $digest = passwordResetDigest($token);
    if ($digest === null) return false;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $consume = $pdo->prepare('DELETE FROM password_resets WHERE email=? AND token_digest=? AND expires_at > NOW()');
        $consume->execute([$email, $digest]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $change = $pdo->prepare('UPDATE users SET password_hash=? WHERE email=? AND is_active=1');
        $change->execute([$passwordHash, $email]);
        if ($change->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
