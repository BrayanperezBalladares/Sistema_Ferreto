<?php

declare(strict_types=1);

namespace App\Foundation;

use InvalidArgumentException;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(mixed $defaults): self
    {
        if (!is_array($defaults)
            || !isset($defaults['APP_ENV'], $defaults['DB_PORT'], $defaults['TEST_DB_PORT'])
            || !is_array($defaults['APP_ENV'])
            || !is_array($defaults['DB_PORT']) || count($defaults['DB_PORT']) !== 2
            || !is_array($defaults['TEST_DB_PORT']) || count($defaults['TEST_DB_PORT']) !== 2) {
            throw new InvalidArgumentException('Configuration defaults are invalid.');
        }
        $fields = [
            'APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASSWORD',
        ];
        $values = [];
        foreach ($fields as $field) {
            $value = getenv($field);
            if (!is_string($value) || trim($value) === '' || $value === '<set-locally>') {
                throw new InvalidArgumentException("Invalid configuration field: {$field}");
            }
            $values[$field] = $value;
        }

        if (!in_array($values['APP_ENV'], $defaults['APP_ENV'], true)) {
            throw new InvalidArgumentException('Invalid configuration field: APP_ENV');
        }
        foreach (['DB_PORT', 'TEST_DB_PORT'] as $field) {
            $port = filter_var($values[$field], FILTER_VALIDATE_INT);
            $range = $defaults[$field];
            if (!is_array($range) || !is_int($range[0] ?? null) || !is_int($range[1] ?? null)
                || $port === false || $port < $range[0] || $port > $range[1]) {
                throw new InvalidArgumentException("Invalid configuration field: {$field}");
            }
        }
        if (!str_ends_with($values['TEST_DB_NAME'], '_test')) {
            throw new InvalidArgumentException('Invalid configuration field: TEST_DB_NAME');
        }

        return new self($values);
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? throw new InvalidArgumentException("Unknown configuration field: {$field}");
    }
}
