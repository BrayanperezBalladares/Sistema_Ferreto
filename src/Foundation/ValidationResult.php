<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class ValidationResult
{
    /**
     * @param array<string, string> $safeInput
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        public array $safeInput,
        public array $fieldErrors,
    ) {
    }

    public function valid(): bool
    {
        return $this->fieldErrors === [];
    }
}
