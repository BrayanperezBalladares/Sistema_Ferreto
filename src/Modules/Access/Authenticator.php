<?php

declare(strict_types=1);

namespace App\Modules\Access;

final readonly class Authenticator
{
    /**
     * Precomputed dummy bcrypt hash (cost 10) to mitigate username enumeration timing leaks.
     * Calculated using a constant non-privileged phrase with explicit cost 10.
     */
    private const string DUMMY_HASH = '$2y$10$e9f16z8F1EJWUuYNJozkxuGq4SBl1Q3p.FljewY.3DWzf6IZrUC9a';

    /**
     * Strict bcrypt maximum input boundary. Passwords exceeding 72 UTF-8 bytes
     * are rejected without truncation and never passed to bcrypt.
     */
    private const int MAX_PASSWORD_BYTES = 72;

    public function __construct(
        private UserQuery $query,
        private UserCommand $command,
    ) {
    }

    /**
     * Authenticates a user by username and password.
     *
     * @return array{id_usuario: int, username: string, rol: string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $normalized = trim($username);

        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return null;
        }

        if ($normalized === '') {
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        $user = $this->query->findByUsername($normalized);

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        if ($user['estado'] !== 'activo') {
            password_verify($password, $user['password_hash']);
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->command->recordFailure($normalized);
            return null;
        }

        $this->command->resetFailures($user['id_usuario']);

        return [
            'id_usuario' => $user['id_usuario'],
            'username'   => $user['username'],
            'rol'        => $user['rol'],
        ];
    }
}
