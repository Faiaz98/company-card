<?php

declare(strict_types=1);

if ($argc < 6) {
    echo "Usage:\n";
    echo "php tests/concurrency.php <api_key> <card_credential> <amount> <requests> <expected_initial_balance>\n\n";

    echo "Example:\n";
    echo "php tests/concurrency.php cc_live_xxx card_credential_here 10.00 20 100.00\n";

    exit(1);
}

$apiKey = $argv[1];
$cardCredential = $argv[2];
$amount = $argv[3];
$requestCount = (int) $argv[4];
$expectedInitialBalance = $argv[5];

if ($requestCount < 1) {
    echo "Request count must be greater than 0.\n";
    exit(1);
}

$url = 'http://localhost:8000/api/payments/charge';

$multiHandle = curl_multi_init();

$handles = [];

for ($i = 0; $i < $requestCount; $i++) {
    $payload = json_encode([
        'card_credential' => $cardCredential,
        'amount' => $amount,
        'idempotency_key' => 'CONCURRENCY-' . bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR);

    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    curl_multi_add_handle($multiHandle, $handle);

    $handles[] = $handle;
}

$start = microtime(true);

$running = null;

do {
    $status = curl_multi_exec($multiHandle, $running);

    if ($running) {
        curl_multi_select($multiHandle, 1.0);
    }
} while ($running && $status === CURLM_OK);

$elapsed = (microtime(true) - $start) * 1000;

$successes = 0;
$insufficientBalance = 0;
$otherFailures = 0;

$results = [];

foreach ($handles as $index => $handle) {
    $response = curl_multi_getcontent($handle);
    $httpStatus = curl_getinfo(
        $handle,
        CURLINFO_HTTP_CODE
    );

    $decoded = json_decode($response, true);

    $results[] = [
        'request' => $index + 1,
        'status' => $httpStatus,
        'response' => $decoded,
    ];

    if (
        $httpStatus >= 200 &&
        $httpStatus < 300 &&
        ($decoded['success'] ?? false) === true
    ) {
        $successes++;
    } elseif (
        $httpStatus === 409 &&
        ($decoded['message'] ?? '') === 'Insufficient wallet balance.'
    ) {
        $insufficientBalance++;
    } else {
        $otherFailures++;
    }

    curl_multi_remove_handle($multiHandle, $handle);
    curl_close($handle);
}

curl_multi_close($multiHandle);

$amountValue = (float) $amount;
$initialBalanceValue = (float) $expectedInitialBalance;

$expectedSuccessfulRequests = min(
    $requestCount,
    (int) floor($initialBalanceValue / $amountValue)
);

$expectedFinalBalance =
    $initialBalanceValue -
    ($expectedSuccessfulRequests * $amountValue);

echo "\n";
echo "========================================\n";
echo " Company Card Concurrency Test\n";
echo "========================================\n";
echo "\n";

echo "Requests:              {$requestCount}\n";
echo "Payment amount:        {$amount}\n";
echo "Initial balance:       {$expectedInitialBalance}\n";
echo "Expected successes:    {$expectedSuccessfulRequests}\n";
echo "Actual successes:      {$successes}\n";
echo "Insufficient balance:  {$insufficientBalance}\n";
echo "Other failures:        {$otherFailures}\n";
echo "Elapsed time:          " . number_format($elapsed, 2) . " ms\n";

echo "\n";

if (
    $successes === $expectedSuccessfulRequests &&
    $successes + $insufficientBalance === $requestCount &&
    $otherFailures === 0
) {
    echo "RESULT: PASS\n";
    echo "No overselling detected.\n";
} else {
    echo "RESULT: FAIL\n";
    echo "Unexpected concurrency result.\n";
}

echo "\n";