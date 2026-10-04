<?php

declare(strict_types=1);

const API_BASE_URL = 'http://localhost:8000/api';

function printHeader(string $title): void
{
    echo "\n";
    echo "========================================\n";
    echo " {$title}\n";
    echo "========================================\n";
    echo "\n";
}

function request(
    string $method,
    string $url,
    string $apiKey,
    array $body
): array {
    $payload = json_encode(
        $body,
        JSON_THROW_ON_ERROR
    );

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);

        curl_close($curl);

        throw new RuntimeException(
            'Request failed: ' . $error
        );
    }

    $status = curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'API returned invalid JSON.'
        );
    }

    return [
        'status' => $status,
        'body' => $decoded,
    ];
}

function printResponse(array $response): void
{
    echo 'HTTP Status: ' . $response['status'] . "\n";

    echo json_encode(
        $response['body'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    echo "\n";
}

if ($argc < 3) {
    echo "Usage:\n";
    echo "\n";

    echo "Charge:\n";
    echo "php index.php charge <merchant_api_key> <card_credential> <amount>\n";

    echo "\n";

    echo "Refund:\n";
    echo "php index.php refund <merchant_api_key> <transaction_id> <amount>\n";

    echo "\n";

    echo "Example:\n";
    echo "php index.php charge cc_live_xxx card_xxx 25.00\n";
    echo "php index.php refund cc_live_xxx 123 25.00\n";

    exit(1);
}

$operation = strtolower(
    trim($argv[1])
);

$apiKey = trim($argv[2]);

if ($apiKey === '') {
    echo "Merchant API key is required.\n";
    exit(1);
}

try {
    if ($operation === 'charge') {
        if ($argc < 5) {
            echo "Charge requires:\n";
            echo "<merchant_api_key> <card_credential> <amount>\n";
            exit(1);
        }

        $cardCredential = trim($argv[3]);
        $amount = trim($argv[4]);

        if ($cardCredential === '') {
            echo "Card credential is required.\n";
            exit(1);
        }

        if ($amount === '') {
            echo "Amount is required.\n";
            exit(1);
        }

        $idempotencyKey =
            'SIM-CHARGE-' .
            bin2hex(random_bytes(16));

        printHeader('Merchant Payment');

        echo "Merchant: Company Card Demo Merchant\n";
        echo "Amount:   {$amount}\n";
        echo "\n";

        $response = request(
            'POST',
            API_BASE_URL . '/payments/charge',
            $apiKey,
            [
                'card_credential' => $cardCredential,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
            ]
        );

        printResponse($response);

        exit(
            $response['status'] >= 200 &&
            $response['status'] < 300
                ? 0
                : 1
        );
    }

    if ($operation === 'refund') {
        if ($argc < 5) {
            echo "Refund requires:\n";
            echo "<merchant_api_key> <transaction_id> <amount>\n";
            exit(1);
        }

        $transactionId = filter_var(
            $argv[3],
            FILTER_VALIDATE_INT
        );

        $amount = trim($argv[4]);

        if (!$transactionId || $transactionId < 1) {
            echo "Valid transaction ID is required.\n";
            exit(1);
        }

        if ($amount === '') {
            echo "Amount is required.\n";
            exit(1);
        }

        $idempotencyKey =
            'SIM-REFUND-' .
            bin2hex(random_bytes(16));

        printHeader('Merchant Refund');

        echo "Transaction: {$transactionId}\n";
        echo "Amount:      {$amount}\n";
        echo "\n";

        $response = request(
            'POST',
            API_BASE_URL . '/refunds',
            $apiKey,
            [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
            ]
        );

        printResponse($response);

        exit(
            $response['status'] >= 200 &&
            $response['status'] < 300
                ? 0
                : 1
        );
    }

    echo "Unknown operation: {$operation}\n";
    echo "Supported operations: charge, refund\n";

    exit(1);
} catch (Throwable $exception) {
    echo "\n";
    echo "ERROR: " . $exception->getMessage() . "\n";

    exit(1);
}