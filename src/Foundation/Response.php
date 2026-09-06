<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public static function redirect(string $target, int $status = 303): self
    {
        if (
            !str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || str_starts_with($target, '/\\')
            || str_contains($target, '\\')
            || strpbrk($target, "\r\n\t\0") !== false
        ) {
            throw new \InvalidArgumentException('Redirect target is unsafe.');
        }

        return new self($status, ['Location' => $target]);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
