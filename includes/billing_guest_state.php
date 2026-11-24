<?php

function updateBillingGuestState(PDO $pdo, int $guestId, ?string $suspensionReason): void
{
    $suspended = $suspensionReason !== null;
    $query = $pdo->prepare('UPDATE vms SET status=?,
        suspended_at=CASE WHEN ?=1 THEN NOW() ELSE NULL END, suspend_reason=? WHERE id=?');
    $query->execute([$suspended ? 'suspended' : 'running', (int)$suspended, $suspensionReason, $guestId]);
}
