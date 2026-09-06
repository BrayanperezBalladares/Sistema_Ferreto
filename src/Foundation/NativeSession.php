<?php

declare(strict_types=1);

namespace App\Foundation;

final class NativeSession implements Session
{
    public function __construct(bool $secure)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $secure,
            ]);
            session_start();
        }
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): mixed
    {
        $value = $this->get($key);
        unset($_SESSION[$key]);
        return $value;
    }
}
