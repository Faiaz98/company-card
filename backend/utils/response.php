<?php

declare(strict_types=1);

function jsonResponse(
    array $data,
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    header('Content-Type: application/json');

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
    );

    exit;
}