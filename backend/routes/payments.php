<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/rate_limit.php';
require_once __DIR__ . '/../utils/money.php';

function authenticateMerchant(): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        jsonResponse([
            'success' => false,
            'message' => 'Merchant authentication required.',
        ], 401);
    }

    $apiKey = trim($matches[1]);

    if ($apiKey === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid merchant credentials.',
        ], 401);
    }

    $pdo = getDatabaseConnection();

    $statement = $pdo->prepare(
        'SELECT
            k.id AS api_key_id,
            k.merchant_id,
            m.name AS merchant_name,
            m.merchant_code
         FROM merchant_api_keys k
         INNER JOIN merchants m ON m.id = k.merchant_id
         WHERE k.key_hash = :key_hash
           AND k.is_active = TRUE
           AND m.is_active = TRUE
         LIMIT 1'
    );

    $statement->execute([
        'key_hash' => hash('sha256', $apiKey),
    ]);

    $merchant = $statement->fetch();

    if (!$merchant) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid merchant credentials.',
        ], 401);
    }

    $update = $pdo->prepare(
        'UPDATE merchant_api_keys
         SET last_used_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $update->execute([
        'id' => $merchant['api_key_id'],
    ]);

    return $merchant;
}

function generatePaymentTransactionReference(): string
{
    return 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
}

function chargeCard(): never
{
    $merchant = authenticateMerchant();

    enforceRateLimit(
        'payment:merchant:' . $merchant['merchant_id'],
        30,
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

    $credential = trim(
        (string) ($body['card_credential'] ?? '')
    );

    $amount = $body['amount'] ?? null;

    $idempotencyKey = trim(
        (string) ($body['idempotency_key'] ?? '')
    );

    if ($credential === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Card credential is required.',
        ], 422);
    }

    if (!is_string($amount)) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid payment amount is required.',
        ], 422);
    }

    try {
        $amountCents = amountToCents($amount);
    } catch (InvalidArgumentException $exception) {
        jsonResponse([
            'success' => false,
            'message' => 'Amount must be greater than 0 with up to 2 decimal places.',
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
    $credentialHash = hash('sha256', $credential);

    try {
        $pdo->beginTransaction();

        // Lock and revalidate the merchant credentials.
        $merchantLock = $pdo->prepare(
            'SELECT k.id
             FROM merchant_api_keys k
             INNER JOIN merchants m ON m.id = k.merchant_id
             WHERE k.id = :key_id
               AND k.merchant_id = :merchant_id
               AND k.is_active = TRUE
               AND m.is_active = TRUE
             LIMIT 1
             FOR UPDATE'
        );

        $merchantLock->execute([
            'key_id' => $merchant['api_key_id'],
            'merchant_id' => $merchant['merchant_id'],
        ]);

        if (!$merchantLock->fetch()) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Merchant is inactive.',
            ], 403);
        }

        // A repeated key must never charge the wallet again.
        $existing = $pdo->prepare(
            'SELECT
                id,
                merchant_id,
                transaction_reference,
                type,
                status,
                amount,
                created_at
             FROM transactions
             WHERE idempotency_key = :key
             LIMIT 1'
        );

        $existing->execute([
            'key' => $idempotencyKey,
        ]);

        $previous = $existing->fetch();

        if ($previous) {
            if (
                (int) ($previous['merchant_id'] ?? 0) !==
                (int) $merchant['merchant_id']
            ) {
                $pdo->rollBack();

                jsonResponse([
                    'success' => false,
                    'message' => 'Idempotency key has already been used.',
                ], 409);
            }

            $pdo->commit();

            jsonResponse([
                'success' => true,
                'data' => [
                    'transaction' => $previous,
                    'duplicate' => true,
                ],
            ]);
        }

        // Find and lock the card and its wallet.
        $cardQuery = $pdo->prepare(
            'SELECT
                c.id AS card_id,
                c.employee_id,
                c.status AS card_status,
                w.id AS wallet_id,
                w.balance,
                w.currency
             FROM cards c
             INNER JOIN employees e ON e.id = c.employee_id
             INNER JOIN wallets w ON w.employee_id = e.id
             WHERE c.credential_hash = :credential_hash
             LIMIT 1
             FOR UPDATE'
        );

        $cardQuery->execute([
            'credential_hash' => $credentialHash,
        ]);

        $card = $cardQuery->fetch();

        if (!$card) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Invalid card.',
            ], 404);
        }

        if ($card['card_status'] !== 'ACTIVE') {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Card is not active.',
            ], 403);
        }

        $walletQuery = $pdo->prepare(
            'SELECT
                id,
                balance,
                currency
             FROM wallets
             WHERE id = :wallet_id
             FOR UPDATE'
        );

        $walletQuery->execute([
            'wallet_id' => $card['wallet_id'],
        ]);

        $wallet = $walletQuery->fetch();

        if (!$wallet) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Wallet not found.',
            ], 404);
        }

        $balanceCents = amountToCents(
            (string) $wallet['balance']
        );

        if ($balanceCents < $amountCents) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Insufficient wallet balance.',
            ], 409);
        }

        $newBalance = centsToAmount(
            $balanceCents - $amountCents
        );

        $reference = generatePaymentTransactionReference();

        // Record which merchant initiated the payment.
        $insert = $pdo->prepare(
            'INSERT INTO transactions (
                transaction_reference,
                wallet_id,
                type,
                status,
                amount,
                created_by,
                idempotency_key,
                merchant_id
            )
            VALUES (
                :reference,
                :wallet_id,
                "PURCHASE",
                "COMPLETED",
                :amount,
                NULL,
                :idempotency_key,
                :merchant_id
            )'
        );

        $insert->execute([
            'reference' => $reference,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
            'idempotency_key' => $idempotencyKey,
            'merchant_id' => $merchant['merchant_id'],
        ]);

        $transactionId = (int) $pdo->lastInsertId();

        $ledger = $pdo->prepare(
            'INSERT INTO ledger_entries (
                transaction_id,
                wallet_id,
                entry_type,
                amount
            )
            VALUES (
                :transaction_id,
                :wallet_id,
                "DEBIT",
                :amount
            )'
        );

        $ledger->execute([
            'transaction_id' => $transactionId,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
        ]);

        $debit = $pdo->prepare(
            'UPDATE wallets
             SET balance = :balance
             WHERE id = :wallet_id'
        );

        $debit->execute([
            'balance' => $newBalance,
            'wallet_id' => $wallet['id'],
        ]);

        $audit = $pdo->prepare(
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

        $audit->execute([
            'action' => 'MERCHANT_CARD_CHARGE',
            'entity_type' => 'TRANSACTION',
            'entity_id' => $transactionId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'metadata' => json_encode([
                'merchant_id' => (int) $merchant['merchant_id'],
                'merchant_code' => $merchant['merchant_code'],
                'employee_id' => (int) $card['employee_id'],
                'amount' => $amountForDatabase,
                'transaction_reference' => $reference,
            ]),
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'transaction' => [
                    'id' => $transactionId,
                    'transaction_reference' => $reference,
                    'type' => 'PURCHASE',
                    'status' => 'COMPLETED',
                    'amount' => $amountForDatabase,
                    'currency' => $wallet['currency'],
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

        // Return an existing result for a concurrent duplicate request.
        if ($exception->getCode() === '23000') {
            $existing = $pdo->prepare(
                'SELECT
                    id,
                    merchant_id,
                    transaction_reference,
                    type,
                    status,
                    amount,
                    created_at
                 FROM transactions
                 WHERE idempotency_key = :key
                 LIMIT 1'
            );

            $existing->execute([
                'key' => $idempotencyKey,
            ]);

            $previous = $existing->fetch();

            if (
                $previous &&
                (int) ($previous['merchant_id'] ?? 0) ===
                (int) $merchant['merchant_id']
            ) {
                jsonResponse([
                    'success' => true,
                    'data' => [
                        'transaction' => $previous,
                        'duplicate' => true,
                    ],
                ]);
            }

            jsonResponse([
                'success' => false,
                'message' => 'Duplicate payment request.',
            ], 409);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to process payment.',
        ], 500);
    }
}