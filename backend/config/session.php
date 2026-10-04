<?php

declare(strict_types=1);

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('company_card_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}