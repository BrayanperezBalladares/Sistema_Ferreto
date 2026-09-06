<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        private array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            is_string($path) ? $path : '/',
            self::strings($_GET),
            self::strings($_POST),
            $headers,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isHtmx(): bool
    {
        return strtolower($this->header('hx-request') ?? '') === 'true';
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
