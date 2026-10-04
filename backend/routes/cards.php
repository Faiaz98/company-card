<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/auth.php';

function generateCardCredential(): string
{
    return bin2hex(random_bytes(32));
}

function generateCardReference(): string
{
    return 'CARD-' . strtoupper(bin2hex(random_bytes(5)));
}

function createCard(): never
{
    requireRole(['ADMIN']);

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

    if (!$employeeId || $employeeId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid employee ID is required.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    $employeeStatement = $pdo->prepare(
        'SELECT id
         FROM employees
         WHERE id = :id
           AND is_active = TRUE
         LIMIT 1'
    );

    $employeeStatement->execute([
        'id' => $employeeId,
    ]);

    if (!$employeeStatement->fetch()) {
        jsonResponse([
            'success' => false,
            'message' => 'Employee not found.',
        ], 404);
    }

    $existingStatement = $pdo->prepare(
        'SELECT id
         FROM cards
         WHERE employee_id = :employee_id
           AND status = "ACTIVE"
         LIMIT 1'
    );

    $existingStatement->execute([
        'employee_id' => $employeeId,
    ]);

    if ($existingStatement->fetch()) {
        jsonResponse([
            'success' => false,
            'message' => 'Employee already has an active card.',
        ], 409);
    }

    $credential = generateCardCredential();
    $credentialHash = hash('sha256', $credential);
    $cardReference = generateCardReference();

    $statement = $pdo->prepare(
        'INSERT INTO cards (
            employee_id,
            card_reference,
            credential_hash,
            status
        )
        VALUES (
            :employee_id,
            :card_reference,
            :credential_hash,
            "ACTIVE"
        )'
    );

    $statement->execute([
        'employee_id' => $employeeId,
        'card_reference' => $cardReference,
        'credential_hash' => $credentialHash,
    ]);

    jsonResponse([
        'success' => true,
        'data' => [
            'card' => [
                'id' => (int) $pdo->lastInsertId(),
                'employee_id' => $employeeId,
                'card_reference' => $cardReference,
                'credential' => $credential,
                'status' => 'ACTIVE',
            ],
        ],
    ], 201);
}

function getEmployeeCard(): never
{
    $user = requireRole(['ADMIN', 'EMPLOYEE']);

    $employeeId = filter_var(
        $_GET['employee_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$employeeId || $employeeId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid employee ID is required.',
        ], 422);
    }

    /*
     * Employees may only inspect their own card.
     */
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

        if (!$employee || (int) $employee['id'] !== $employeeId) {
            jsonResponse([
                'success' => false,
                'message' => 'You do not have permission to view this card.',
            ], 403);
        }
    }

    $pdo = $pdo ?? getDatabaseConnection();

    $statement = $pdo->prepare(
        'SELECT
            id,
            employee_id,
            card_reference,
            status,
            issued_at,
            revoked_at
         FROM cards
         WHERE employee_id = :employee_id
         ORDER BY id DESC
         LIMIT 1'
    );

    $statement->execute([
        'employee_id' => $employeeId,
    ]);

    $card = $statement->fetch();

    if (!$card) {
        jsonResponse([
            'success' => false,
            'message' => 'No card found.',
        ], 404);
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'card' => $card,
        ],
    ]);
}

function updateCardStatus(): never
{
    requireRole(['ADMIN']);

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

    $cardId = filter_var(
        $body['card_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $status = strtoupper(
        trim((string) ($body['status'] ?? ''))
    );

    if (!$cardId || $cardId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid card ID is required.',
        ], 422);
    }

    if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'REVOKED'], true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid card status.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $pdo->beginTransaction();

        $cardStatement = $pdo->prepare(
            'SELECT
                id,
                employee_id,
                status,
                card_reference
             FROM cards
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $cardStatement->execute([
            'id' => $cardId,
        ]);

        $card = $cardStatement->fetch();

        if (!$card) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Card not found.',
            ], 404);
        }

        if ($card['status'] === 'REVOKED') {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'A revoked card cannot be reactivated.',
            ], 409);
        }

        if (
            $status === 'ACTIVE' &&
            $card['status'] === 'SUSPENDED'
        ) {
            $activeStatement = $pdo->prepare(
                'SELECT id
                 FROM cards
                 WHERE employee_id = :employee_id
                   AND status = "ACTIVE"
                   AND id <> :id
                 LIMIT 1'
            );

            $activeStatement->execute([
                'employee_id' => $card['employee_id'],
                'id' => $cardId,
            ]);

            if ($activeStatement->fetch()) {
                $pdo->rollBack();

                jsonResponse([
                    'success' => false,
                    'message' => 'Employee already has another active card.',
                ], 409);
            }
        }

        $update = $pdo->prepare(
            'UPDATE cards
             SET
                status = :status,
                revoked_at = CASE
                    WHEN :status = "REVOKED"
                    THEN CURRENT_TIMESTAMP
                    ELSE revoked_at
                END
             WHERE id = :id'
        );

        $update->execute([
            'status' => $status,
            'id' => $cardId,
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'card' => [
                    'id' => (int) $card['id'],
                    'employee_id' => (int) $card['employee_id'],
                    'card_reference' => $card['card_reference'],
                    'status' => $status,
                ],
            ],
        ]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to update card status.',
        ], 500);
    }
}

function replaceCard(): never
{
    requireRole(['ADMIN']);

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

    $cardId = filter_var(
        $body['card_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$cardId || $cardId < 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Valid card ID is required.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $pdo->beginTransaction();

        $cardStatement = $pdo->prepare(
            'SELECT
                id,
                employee_id,
                status
             FROM cards
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $cardStatement->execute([
            'id' => $cardId,
        ]);

        $oldCard = $cardStatement->fetch();

        if (!$oldCard) {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'Card not found.',
            ], 404);
        }

        if ($oldCard['status'] === 'REVOKED') {
            $pdo->rollBack();

            jsonResponse([
                'success' => false,
                'message' => 'A revoked card cannot be replaced.',
            ], 409);
        }

        $credential = generateCardCredential();
        $credentialHash = hash('sha256', $credential);
        $cardReference = generateCardReference();

        $revoke = $pdo->prepare(
            'UPDATE cards
             SET
                status = "REVOKED",
                revoked_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $revoke->execute([
            'id' => $cardId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO cards (
                employee_id,
                card_reference,
                credential_hash,
                status
            )
            VALUES (
                :employee_id,
                :card_reference,
                :credential_hash,
                "ACTIVE"
            )'
        );

        $insert->execute([
            'employee_id' => $oldCard['employee_id'],
            'card_reference' => $cardReference,
            'credential_hash' => $credentialHash,
        ]);

        $newCardId = (int) $pdo->lastInsertId();

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'old_card_id' => (int) $oldCard['id'],
                'new_card' => [
                    'id' => $newCardId,
                    'employee_id' => (int) $oldCard['employee_id'],
                    'card_reference' => $cardReference,
                    'credential' => $credential,
                    'status' => 'ACTIVE',
                ],
            ],
            'message' => 'Card replaced successfully. Store the new credential securely.',
        ], 201);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to replace card.',
        ], 500);
    }
}