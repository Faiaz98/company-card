<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/rate_limit.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../utils/response.php';

function getRequestBody(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON request body.',
        ], 400);
    }

    return $data;
}

function login(): never
{   
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    enforceRateLimit(
        'login:' . $ip,
        5,
        60
    );

    $body = getRequestBody();

    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if (!is_string($email) || !is_string($password)) {
        jsonResponse([
            'success' => false,
            'message' => 'Email and password are required.',
        ], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'message' => 'A valid email address is required.',
        ], 422);
    }

    if ($password === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Password is required.',
        ], 422);
    }

    try {
        $authService = new AuthService();

        $user = $authService->login($email, $password);

        jsonResponse([
            'success' => true,
            'data' => [
                'user' => $user,
            ],
        ]);
    } catch (RuntimeException $exception) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid email or password.',
        ], 401);
    } catch (Throwable $exception) {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to process login.',
        ], 500);
    }
}

function currentUser(): never
{
    $authService = new AuthService();

    $user = $authService->getAuthenticatedUser();

    if ($user === null) {
        jsonResponse([
            'success' => false,
            'message' => 'Authentication required.',
        ], 401);
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'user' => $user,
        ],
    ]);
}

function logout(): never
{
    $authService = new AuthService();

    $authService->logout();

    jsonResponse([
        'success' => true,
        'message' => 'Logged out successfully.',
    ]);
}