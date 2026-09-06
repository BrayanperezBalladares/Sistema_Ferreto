<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }
        return $token;
    }

    public function valid(Request $request): bool
    {
        $sessionToken = $this->session->get('csrf');
        if (!is_string($sessionToken)) {
            return false;
        }

        $sent = $request->header('x-csrf-token') ?? ($request->body['_csrf'] ?? null);
        if (!is_string($sent)) {
            return false;
        }

        return hash_equals($sessionToken, $sent);
    }
}
