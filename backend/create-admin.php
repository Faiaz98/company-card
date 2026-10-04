<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    exit("This script can only be run from the command line.\n");
}

function prompt(string $message): string
{
    echo $message;

    $value = trim((string) fgets(STDIN));

    return $value;
}

$email = prompt('Admin email: ');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid email address.\n");
}

$password = prompt('Admin password: ');

if (strlen($password) < 8) {
    exit("Password must be at least 8 characters long.\n");
}

$confirmPassword = prompt('Confirm password: ');

if ($password !== $confirmPassword) {
    exit("Passwords do not match.\n");
}

try {
    $pdo = getDatabaseConnection();

    $checkStatement = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = :email
         LIMIT 1'
    );

    $checkStatement->execute([
        'email' => strtolower($email),
    ]);

    if ($checkStatement->fetch()) {
        exit("A user with this email already exists.\n");
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $statement = $pdo->prepare(
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

    $statement->execute([
        'email' => strtolower($email),
        'password_hash' => $passwordHash,
        'role' => 'ADMIN',
    ]);

    echo "Admin account created successfully.\n";
    echo "Email: {$email}\n";
} catch (PDOException $exception) {
    exit("Failed to create admin account.\n");
}