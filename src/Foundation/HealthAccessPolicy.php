<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class HealthAccessPolicy
{
    /** @var list<string> */
    private const ALLOWED_ENVIRONMENTS = ['development', 'test'];

    public function __construct(private ?string $environment = null)
    {
    }

    public function allowsDiagnosticPost(?string $environmentOverride = null): bool
    {
        $serverEnv = is_string($_SERVER['APP_ENV'] ?? null) ? $_SERVER['APP_ENV'] : null;
        $envVar = is_string($_ENV['APP_ENV'] ?? null) ? $_ENV['APP_ENV'] : null;

        $env = $environmentOverride
            ?? $this->environment
            ?? (getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : null)
            ?? $serverEnv
            ?? $envVar
            ?? '';

        return in_array(trim($env), self::ALLOWED_ENVIRONMENTS, true);
    }
}
