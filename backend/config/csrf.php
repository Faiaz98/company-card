<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

function getCsrfToken(): string
{
    startSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    startSession();

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !isset($_SESSION['csrf_token']) ||
        $token === '' ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid CSRF token.',
        ], 403);
    }
}