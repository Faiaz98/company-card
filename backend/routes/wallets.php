<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/money.php';

function generateTransactionReference(): string
{
    return 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
}

function topUpWallet(): never
{
    $admin = requireRole(['ADMIN']);

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

    $employeeId = filter_var(
        $body['employee_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $amount = $body['amount'] ?? null;
    $idempotencyKey = trim(
        (string) ($body['idempotency_key'] ?? '')
    );

    if (!$employeeId || $employeeId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid employee ID is required.',
        ], 422);
    }

    if (!is_string($amount)) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid top-up amount is required.',
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

    if ($idempotencyKey === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Idempotency key is required.',
        ], 422);
    }

    if (strlen($idempotencyKey) > 100) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid idempotency key.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $existingStatement = $pdo->prepare(
            'SELECT
                id,
                transaction_reference,
                wallet_id,
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

        $existingTransaction = $existingStatement->fetch();

        if ($existingTransaction) {
            jsonResponse([
                'success' => true,
                'data' => [
                    'transaction' => $existingTransaction,
                    'duplicate' => true,
                ],
            ]);
        }

        $pdo->beginTransaction();

        $walletStatement = $pdo->prepare(
            'SELECT
                w.id,
                w.employee_id,
                w.balance,
                w.currency,
                e.full_name,
                e.employee_code
             FROM wallets w
             INNER JOIN employees e
                 ON e.id = w.employee_id
             WHERE w.employee_id = :employee_id
               AND e.is_active = TRUE
             LIMIT 1
             FOR UPDATE'
        );

        $walletStatement->execute([
            'employee_id' => $employeeId,
        ]);

        $wallet = $walletStatement->fetch();

        if (!$wallet) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Employee wallet not found.',
            ], 404);
        }

        $currentBalanceCents = amountToCents(
            (string) $wallet['balance']
        );

        $newBalance = centsToAmount(
            $currentBalanceCents + $amountCents
        );

        $transactionReference = generateTransactionReference();

        $transactionStatement = $pdo->prepare(
            'INSERT INTO transactions (
                transaction_reference,
                wallet_id,
                type,
                status,
                amount,
                created_by,
                idempotency_key
            )
            VALUES (
                :transaction_reference,
                :wallet_id,
                "TOP_UP",
                "COMPLETED",
                :amount,
                :created_by,
                :idempotency_key
            )'
        );

        $transactionStatement->execute([
            'transaction_reference' => $transactionReference,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
            'created_by' => $admin['id'],
            'idempotency_key' => $idempotencyKey,
        ]);

        $transactionId = (int) $pdo->lastInsertId();

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
            'transaction_id' => $transactionId,
            'wallet_id' => $wallet['id'],
            'amount' => $amountForDatabase,
        ]);

        $balanceStatement = $pdo->prepare(
            'UPDATE wallets
             SET balance = :balance
             WHERE id = :wallet_id'
        );

        $balanceStatement->execute([
            'balance' => $newBalance,
            'wallet_id' => $wallet['id'],
        ]);

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
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :ip_address,
                :metadata
            )'
        );

        $auditStatement->execute([
            'user_id' => $admin['id'],
            'action' => 'WALLET_TOP_UP',
            'entity_type' => 'TRANSACTION',
            'entity_id' => $transactionId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'metadata' => json_encode([
                'employee_id' => $employeeId,
                'amount' => $amountForDatabase,
                'transaction_reference' => $transactionReference,
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();

        $resultStatement = $pdo->prepare(
            'SELECT
                w.id AS wallet_id,
                w.employee_id,
                w.balance,
                w.currency
             FROM wallets w
             WHERE w.id = :wallet_id
             LIMIT 1'
        );

        $resultStatement->execute([
            'wallet_id' => $wallet['id'],
        ]);

        $result = $resultStatement->fetch();

        jsonResponse([
            'success' => true,
            'data' => [
                'transaction' => [
                    'id' => $transactionId,
                    'transaction_reference' => $transactionReference,
                    'type' => 'TOP_UP',
                    'status' => 'COMPLETED',
                    'amount' => $amountForDatabase,
                ],
                'wallet' => $result,
            ],
        ], 201);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'This top-up request has already been processed.',
            ], 409);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to process wallet top-up.',
        ], 500);
    }
}

function getWalletTransactions(): never
{
    $user = requireRole(['ADMIN', 'EMPLOYEE']);

    $employeeId = filter_var(
        $_GET['employee_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($user['role'] === 'EMPLOYEE') {
        $pdo = getDatabaseConnection();

        $employeeStatement = $pdo->prepare(
            'SELECT id
             FROM employees
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $employeeStatement->execute([
            'user_id' => $user['id'],
        ]);

        $employee = $employeeStatement->fetch();

        if (!$employee) {
            jsonResponse([
                'success' => false,
                'message' => 'Employee profile not found.',
            ], 404);
        }

        $employeeId = (int) $employee['id'];
    }

    if (!$employeeId || $employeeId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid employee ID is required.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    $statement = $pdo->prepare(
        'SELECT
            t.id,
            t.transaction_reference,
            t.type,
            t.status,
            t.amount,
            t.created_at,
            le.entry_type
         FROM transactions t
         INNER JOIN wallets w
             ON w.id = t.wallet_id
         LEFT JOIN ledger_entries le
             ON le.transaction_id = t.id
         WHERE w.employee_id = :employee_id
         ORDER BY t.id DESC'
    );

    $statement->execute([
        'employee_id' => $employeeId,
    ]);

    jsonResponse([
        'success' => true,
        'data' => [
            'transactions' => $statement->fetchAll(),
        ],
    ]);
}