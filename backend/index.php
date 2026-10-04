<?php

declare(strict_types=1);

$allowedOrigin = 'http://localhost:5173';

header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/routes/health.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/employees.php';
require_once __DIR__ . '/routes/cards.php';
require_once __DIR__ . '/routes/wallets.php';
require_once __DIR__ . '/routes/merchants.php';
require_once __DIR__ . '/routes/payments.php';
require_once __DIR__ . '/routes/refunds.php';
require_once __DIR__ . '/config/csrf.php';

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($requestMethod === 'GET' && $requestUri === '/api/auth/csrf') {
    jsonResponse([
        'success' => true,
        'data' => [
            'token' => getCsrfToken(),
        ],
    ]);
}

if ($requestMethod === 'GET' && $requestUri === '/api/health') {
    healthCheck();
}

if ($requestMethod === 'POST' && $requestUri === '/api/auth/login') {
    login();
}

if ($requestMethod === 'GET' && $requestUri === '/api/auth/me') {
    currentUser();
}

if ($requestMethod === 'POST' && $requestUri === '/api/auth/logout') {
    logout();
}

if ($requestMethod === 'GET' && $requestUri === '/api/employees') {
    getEmployees();
}

if ($requestMethod === 'POST' && $requestUri === '/api/employees') {
    createEmployee();
}

if ($requestMethod === 'POST' && $requestUri === '/api/cards') {
    createCard();
}

if ($requestMethod === 'GET' && $requestUri === '/api/cards') {
    getEmployeeCard();
}

if ($requestMethod === 'POST' && $requestUri === '/api/wallets/top-up') {
    topUpWallet();
}

if ($requestMethod === 'GET' && $requestUri === '/api/wallets/transactions') {
    getWalletTransactions();
}

if ($requestMethod === 'POST' && $requestUri === '/api/merchants') {
    createMerchant();
}

if ($requestMethod === 'GET' && $requestUri === '/api/merchants') {
    getMerchants();
}

if ($requestMethod === 'POST' && $requestUri === '/api/payments/charge') {
    chargeCard();
}

if ($requestMethod === 'POST' && $requestUri === '/api/refunds') {
    refundPayment();
}

if ($requestMethod === 'POST' && $requestUri === '/api/cards/status') {
    updateCardStatus();
}

if ($requestMethod === 'POST' && $requestUri === '/api/cards/replace') {
    replaceCard();
}

http_response_code(404);

header('Content-Type: application/json');

echo json_encode([
    'success' => false,
    'message' => 'Route not found',
]);