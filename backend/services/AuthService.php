<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

class AuthService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = getDatabaseConnection();
    }

    public function login(string $email, string $password): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                email,
                password_hash,
                role,
                is_active
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $statement->execute([
            'email' => strtolower(trim($email)),
        ]);

        $user = $statement->fetch();

        if (
            !$user ||
            !$user['is_active'] ||
            !password_verify($password, $user['password_hash'])
        ) {
            throw new RuntimeException('Invalid email or password.');
        }

        startSession();

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    public function getAuthenticatedUser(): ?array
    {
        startSession();

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                id,
                email,
                role,
                is_active
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $_SESSION['user_id'],
        ]);

        $user = $statement->fetch();

        if (!$user || !$user['is_active']) {
            $this->logout();

            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    public function logout(): void
    {
        startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'] ?? '',
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }
}