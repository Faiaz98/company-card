<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/auth.php';

function getEmployees(): never
{
    requireRole(['ADMIN']);

    $pdo = getDatabaseConnection();

    $statement = $pdo->query(
        'SELECT
            e.id,
            e.employee_code,
            e.full_name,
            u.email,
            e.is_active,
            w.balance,
            w.currency,
            e.created_at
         FROM employees e
         INNER JOIN users u ON u.id = e.user_id
         INNER JOIN wallets w ON w.employee_id = e.id
         ORDER BY e.id DESC'
    );

    jsonResponse([
        'success' => true,
        'data' => [
            'employees' => $statement->fetchAll(),
        ],
    ]);
}

function createEmployee(): never
{
    $admin = requireRole(['ADMIN']);

    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody ?: '', true);

    if (!is_array($body)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON request body.',
        ], 400);
    }

    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $employeeCode = strtoupper(trim((string) ($body['employee_code'] ?? '')));
    $fullName = trim((string) ($body['full_name'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if (
        $email === '' ||
        $employeeCode === '' ||
        $fullName === '' ||
        $password === ''
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'All fields are required.',
        ], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid email address.',
        ], 422);
    }

    if (strlen($password) < 8) {
        jsonResponse([
            'success' => false,
            'message' => 'Password must be at least 8 characters.',
        ], 422);
    }

    $pdo = getDatabaseConnection();

    try {
        $pdo->beginTransaction();

        $userStatement = $pdo->prepare(
            'INSERT INTO users (
                email,
                password_hash,
                role,
                is_active
            )
            VALUES (
                :email,
                :password_hash,
                :role,
                TRUE
            )'
        );

        $userStatement->execute([
            'email' => $email,
            'password_hash' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            'role' => 'EMPLOYEE',
        ]);

        $userId = (int) $pdo->lastInsertId();

        $employeeStatement = $pdo->prepare(
            'INSERT INTO employees (
                user_id,
                employee_code,
                full_name
            )
            VALUES (
                :user_id,
                :employee_code,
                :full_name
            )'
        );

        $employeeStatement->execute([
            'user_id' => $userId,
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
        ]);

        $employeeId = (int) $pdo->lastInsertId();

        $walletStatement = $pdo->prepare(
            'INSERT INTO wallets (
                employee_id,
                balance,
                currency
            )
            VALUES (
                :employee_id,
                0.00,
                "BDT"
            )'
        );

        $walletStatement->execute([
            'employee_id' => $employeeId,
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'data' => [
                'employee' => [
                    'id' => $employeeId,
                    'user_id' => $userId,
                    'employee_code' => $employeeCode,
                    'full_name' => $fullName,
                    'email' => $email,
                    'balance' => '0.00',
                    'currency' => 'BDT',
                ],
            ],
        ], 201);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'Email or employee code already exists.',
            ], 409);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to create employee.',
        ], 500);
    }
}