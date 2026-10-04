<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/rate_limit.php';
require_once __DIR__ . '/../utils/money.php';

function generateRefundTransactionReference(): string
{
    return 'REF-' . strtoupper(bin2hex(random_bytes(8)));
}

function refundPayment(): never
{
    $merchant = authenticateMerchant();

    enforceRateLimit(
        'refund:merchant:' . $merchant['merchant_id'],
        20,
        60
    );

    $body = json_decode(
        file_get_contents('php://input') ?: '',
        true
    );

    if (!is_array($body)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON request body.',
        ], 400);
    }

    $transactionId = filter_var(
        $body['transaction_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $amount = $body['amount'] ?? null;

    $idempotencyKey = trim(
        (string) ($body['idempotency_key'] ?? '')
    );

    if (!$transactionId || $transactionId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid transaction ID is required.',
        ], 422);
    }

    if (!is_string($amount)) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid refund amount is required.',
        ], 422);
    }

    try {
        $amountCents = amountToCents($amount);
    } catch (InvalidArgumentException $exception) {
        jsonResponse([
            'success' => false,
            'message' => 'Refund amount must be greater than 0 with up to 2 decimal places.',
        ], 422);
    }

    $amountForDatabase = centsToAmount($amountCents);

    if (
        $idempotencyKey === '' ||
        strlen($idempotencyKey) > 100
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'A valid idempotency key is required.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $pdo->beginTransaction();

        /*
         * Idempotency check.
         */
        $existingStatement = $pdo->prepare(
            'SELECT
                id,
                transaction_reference,
                type,
                status,
                amount,
                created_at
             FROM transactions
             WHERE idempotency_key = :idempotency_key
             LIMIT 1'
        );

        $existingStatement->execute([
            'idempotency_key' => $idempotencyKey,
        ]);

        $existing = $existingStatement->fetch();

        if ($existing) {
            $pdo->commit();

            jsonResponse([
                'success' => true,
                'data' => [
                    'transaction' => $existing,
                    'duplicate' => true,
                ],
            ]);
        }

        /*
         * Lock the original purchase.
         *
         * This prevents two simultaneous refunds from both
         * seeing the same refundable balance.
         */
        $originalStatement = $pdo->prepare(
            'SELECT
                id,
                transaction_reference,
                wallet_id,
                merchant_id,
                type,
                status,
                amount
             FROM transactions
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $originalStatement->execute([
            'id' => $transactionId,
        ]);

        $original = $originalStatement->fetch();

        if (!$original) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Original transaction not found.',
            ], 404);
        }

        if ($original['type'] !== 'PURCHASE') {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Only purchase transactions can be refunded.',
            ], 422);
        }

        if ($original['status'] !== 'COMPLETED') {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Only completed transactions can be refunded.',
            ], 422);
        }

        /*
         * The merchant that created the original purchase must
         * also be the merchant requesting the refund.
         */
        if (
            !$original['merchant_id'] ||
            (int) $original['merchant_id'] !==
            (int) $merchant['merchant_id']
        ) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'You cannot refund this transaction.',
            ], 403);
        }

        /*
         * Calculate how much has already been refunded.
         */
        $refundTotalStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS refunded_amount
             FROM transactions
             WHERE refund_of_transaction_id = :transaction_id
               AND type = "REFUND"
               AND status = "COMPLETED"'
        );

        $refundTotalStatement->execute([
            'transaction_id' => $transactionId,
        ]);

        $alreadyRefunded = (string) $refundTotalStatement->fetchColumn();

        $originalAmountCents = amountToCents(
            (string) $original['amount']
        );

        $alreadyRefundedCents = amountToCents(
            $alreadyRefunded
        );

        $remainingRefundableCents =
            $originalAmountCents - $alreadyRefundedCents;

        if ($amountCents > $remainingRefundableCents) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Refund amount exceeds the remaining refundable amount.',
                'data' => [
                    'original_amount' => centsToAmount(
                        $originalAmountCents
                    ),
                    'already_refunded' => centsToAmount(
                        $alreadyRefundedCents
                    ),
                    'remaining_refundable' => centsToAmount(
                        max(0, $remainingRefundableCents)
                    ),
                ],
            ], 409);
        }

        /*
         * Lock the wallet before crediting it.
         */
        $walletStatement = $pdo->prepare(
            'SELECT
                id,
                balance,
                currency
             FROM wallets
             WHERE id = :wallet_id
             LIMIT 1
             FOR UPDATE'
        );

        $walletStatement->execute([
            'wallet_id' => $original['wallet_id'],
        ]);

        $wallet = $walletStatement->fetch();

        if (!$wallet) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Wallet not found.',
            ], 404);
        }

        $currentBalanceCents = amountToCents(
            (string) $wallet['balance']
        );

        $newBalance = centsToAmount(
            $currentBalanceCents + $amountCents
        );

        $reference = generateRefundTransactionReference();

        /*
         * Create the refund transaction.
         */
        $transactionStatement = $pdo->prepare(
            'INSERT INTO transactions (
                transaction_reference,
                wallet_id,
                type,
                status,
                amount,
                created_by,
                idempotency_key,
                merchant_id,
                refund_of_transaction_id
            )
            VALUES (
                :reference,
                :wallet_id,
                "REFUND",
                "COMPLETED",
                :amount,
                NULL,
                :idempotency_key,
                :merchant_id,
                :refund_of_transaction_id
            )'
        );

        $transactionStatement->execute([
            'reference' => $reference,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
            'idempotency_key' => $idempotencyKey,
            'merchant_id' => $merchant['merchant_id'],
            'refund_of_transaction_id' => $transactionId,
        ]);

        $refundTransactionId = (int) $pdo->lastInsertId();

        /*
         * Record the wallet credit in the immutable ledger.
         */
        $ledgerStatement = $pdo->prepare(
            'INSERT INTO ledger_entries (
                transaction_id,
                wallet_id,
                entry_type,
                amount
            )
            VALUES (
                :transaction_id,
                :wallet_id,
                "CREDIT",
                :amount
            )'
        );

        $ledgerStatement->execute([
            'transaction_id' => $refundTransactionId,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
        ]);

        /*
         * Credit the employee wallet.
         */
        $balanceStatement = $pdo->prepare(
            'UPDATE wallets
             SET balance = :balance
             WHERE id = :wallet_id'
        );

        $balanceStatement->execute([
            'balance' => $newBalance,
            'wallet_id' => $wallet['id'],
        ]);

        /*
         * Audit the refund.
         */
        $auditStatement = $pdo->prepare(
            'INSERT INTO audit_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                metadata
            )
            VALUES (
                NULL,
                :action,
                :entity_type,
                :entity_id,
                :ip_address,
                :metadata
            )'
        );

        $auditStatement->execute([
            'action' => 'MERCHANT_PAYMENT_REFUND',
            'entity_type' => 'TRANSACTION',
            'entity_id' => $refundTransactionId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'metadata' => json_encode([
                'merchant_id' => (int) $merchant['merchant_id'],
                'merchant_code' => $merchant['merchant_code'],
                'original_transaction_id' => $transactionId,
                'original_transaction_reference' =>
                    $original['transaction_reference'],
                'amount' => $amountForDatabase,
                'refund_transaction_reference' => $reference,
            ]),
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'transaction' => [
                    'id' => $refundTransactionId,
                    'transaction_reference' => $reference,
                    'type' => 'REFUND',
                    'status' => 'COMPLETED',
                    'amount' => $amountForDatabase,
                    'currency' => $wallet['currency'],
                ],
                'original_transaction' => [
                    'id' => (int) $original['id'],
                    'transaction_reference' =>
                        $original['transaction_reference'],
                ],
                'merchant' => [
                    'id' => (int) $merchant['merchant_id'],
                    'code' => $merchant['merchant_code'],
                ],
            ],
        ], 201);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {
            $existingStatement = $pdo->prepare(
                'SELECT
                    id,
                    transaction_reference,
                    type,
                    status,
                    amount,
                    created_at
                 FROM transactions
                 WHERE idempotency_key = :idempotency_key
                 LIMIT 1'
            );

            $existingStatement->execute([
                'idempotency_key' => $idempotencyKey,
            ]);

            $existing = $existingStatement->fetch();

            if ($existing) {
                jsonResponse([
                    'success' => true,
                    'data' => [
                        'transaction' => $existing,
                        'duplicate' => true,
                    ],
                ]);
            }
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to process refund.',
        ], 500);
    }
}