<?php

declare(strict_types=1);

function amountToCents(string $amount): int
{
    $amount = trim($amount);

    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Invalid monetary amount.');
    }

    [$whole, $decimal] = array_pad(
        explode('.', $amount, 2),
        2,
        '0'
    );

    $decimal = str_pad($decimal, 2, '0');

    $cents = ((int) $whole * 100) + (int) $decimal;

    if ($cents <= 0) {
        throw new InvalidArgumentException(
            'Amount must be greater than zero.'
        );
    }

    return $cents;
}

function centsToAmount(int $cents): string
{
    if ($cents < 0) {
        throw new InvalidArgumentException(
            'Amount cannot be negative.'
        );
    }

    return sprintf(
        '%d.%02d',
        intdiv($cents, 100),
        $cents % 100
    );
}