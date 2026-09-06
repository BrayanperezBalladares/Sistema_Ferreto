<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Logger
{
    public function error(string $correlationId, string $route, \Throwable $exception): void
    {
        error_log((string) json_encode([
            'level' => 'error',
            'correlation_id' => $correlationId,
            'route' => $route,
            'exception' => $exception::class,
            'location' => basename($exception->getFile()) . ':' . $exception->getLine(),
        ], JSON_UNESCAPED_SLASHES));
    }
}
