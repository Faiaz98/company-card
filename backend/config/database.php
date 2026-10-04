<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = '127.0.0.1';
    $database = 'company_card';
    $username = 'root';
    $password = '';

    $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}