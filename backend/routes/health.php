<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';

function healthCheck(): never
{
    try {
        $pdo = getDatabaseConnection();

        $statement = $pdo->query('SELECT 1');

        if ($statement->fetchColumn() !== 1) {
            jsonResponse([
                'success' => false,
                'message' => 'Database health check failed',
            ], 500);
        }

        jsonResponse([
            'success' => true,
            'data' => [
                'api' => 'ok',
                'database' => 'ok',
            ],
        ]);
    } catch (PDOException $exception) {
        jsonResponse([
            'success' => false,
            'message' => 'Database connection failed',
        ], 500);
    }
}