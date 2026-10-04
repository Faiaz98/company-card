<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/response.php';

function requireAuth(): array
{
    $authService = new AuthService();

    $user = $authService->getAuthenticatedUser();

    if ($user === null) {
        jsonResponse([
            'success' => false,
            'message' => 'Authentication required.',
        ], 401);
    }

    return $user;
}

function requireRole(array $allowedRoles): array
{
    $user = requireAuth();

    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonResponse([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    return $user;
}