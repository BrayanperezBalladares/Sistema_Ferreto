<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\ValidationResult;

final class StockValidator
{
    private const QUANTITY_PATTERN = '/^\d+(\.\d{1,3})?$/';

    public static function validateQuantity(string $quantity): ValidationResult
    {
        $trimmed = trim($quantity);
        $errors = ($trimmed === '' || preg_match(self::QUANTITY_PATTERN, $trimmed) !== 1)
            ? ['cantidad' => 'Quantity must be a valid non-negative decimal with at most 3 fractional digits.']
            : [];

        return new ValidationResult(['cantidad' => $trimmed], $errors);
    }
}
