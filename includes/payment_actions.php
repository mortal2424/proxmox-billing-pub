<?php

function processPendingPayment(PDO $pdo, int $paymentId, string $action): void
{
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Неизвестное действие');
    }

    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $query->execute([$paymentId]);
        $payment = $query->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('Платёж не найден');
        }
        if ($payment['status'] !== 'pending') {
            throw new RuntimeException('Платёж уже обработан');
        }

        if ($action === 'approve') {
            if ((float) $payment['amount'] <= 0) {
                throw new RuntimeException('Сумма платежа должна быть положительной');
            }
            $query = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
            $query->execute([$payment['amount'], $payment['user_id']]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Пользователь платежа не найден');
            }
            $query = $pdo->prepare("INSERT INTO balance_history
                (user_id, amount, operation_type, description, created_at)
                VALUES (?, ?, 'payment', ?, NOW())");
            $query->execute([$payment['user_id'], $payment['amount'], "Пополнение через платёж #$paymentId"]);
        }

        $query = $pdo->prepare('UPDATE payments SET status = ?, updated_at = NOW() WHERE id = ?');
        $query->execute([$action === 'approve' ? 'completed' : 'failed', $paymentId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
