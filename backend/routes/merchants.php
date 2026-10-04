<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/auth.php';

function generateMerchantApiKey(): string
{
    return 'cc_live_' . bin2hex(random_bytes(32));
}

function createMerchant(): never
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

    $name = trim((string) ($body['name'] ?? ''));
    $merchantCode = strtoupper(
        trim((string) ($body['merchant_code'] ?? ''))
    );

    if ($name === '' || $merchantCode === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Merchant name and merchant code are required.',
        ], 422);
    }

    if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $merchantCode)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid merchant code.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $pdo->beginTransaction();

        $merchantStatement = $pdo->prepare(
            'INSERT INTO merchants (
                name,
                merchant_code,
                is_active
            )
            VALUES (
                :name,
                :merchant_code,
                TRUE
            )'
        );

        $merchantStatement->execute([
            'name' => $name,
            'merchant_code' => $merchantCode,
        ]);

        $merchantId = (int) $pdo->lastInsertId();

        $apiKey = generateMerchantApiKey();
        $keyHash = hash('sha256', $apiKey);

        $keyReference = 'MK-' . strtoupper(
            bin2hex(random_bytes(6))
        );

        $keyStatement = $pdo->prepare(
            'INSERT INTO merchant_api_keys (
                merchant_id,
                key_reference,
                key_hash,
                is_active
            )
            VALUES (
                :merchant_id,
                :key_reference,
                :key_hash,
                TRUE
            )'
        );

        $keyStatement->execute([
            'merchant_id' => $merchantId,
            'key_reference' => $keyReference,
            'key_hash' => $keyHash,
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'merchant' => [
                    'id' => $merchantId,
                    'name' => $name,
                    'merchant_code' => $merchantCode,
                    'is_active' => true,
                ],
                'api_key' => [
                    'reference' => $keyReference,
                    'secret' => $apiKey,
                ],
            ],
            'message' => 'Store this API key securely. It will not be shown again.',
        ], 201);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'Merchant code already exists.',
            ], 409);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to create merchant.',
        ], 500);
    }
}

function getMerchants(): never
{
    requireRole(['ADMIN']);

    $pdo = getDatabaseConnection();

    $statement = $pdo->query(
        'SELECT
            m.id,
            m.name,
            m.merchant_code,
            m.is_active,
            m.created_at,
            COUNT(k.id) AS api_key_count
         FROM merchants m
         LEFT JOIN merchant_api_keys k
            ON k.merchant_id = m.id
           AND k.is_active = TRUE
         GROUP BY
            m.id,
            m.name,
            m.merchant_code,
            m.is_active,
            m.created_at
         ORDER BY m.id DESC'
    );

    jsonResponse([
        'success' => true,
        'data' => [
            'merchants' => $statement->fetchAll(),
        ],
    ]);
}