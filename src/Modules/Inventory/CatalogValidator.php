<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\ValidationResult;

final class CatalogValidator
{
    private const PRICE_PATTERN = '/^\d+(\.\d{1,2})?$/';

    public static function validatePrice(string $price): ValidationResult
    {
        $trimmed = trim($price);
        $errors = ($trimmed === '' || preg_match(self::PRICE_PATTERN, $trimmed) !== 1)
            ? ['precio_actual' => 'Price must be a valid non-negative decimal with at most 2 fractional digits.']
            : [];
        return new ValidationResult(['precio_actual' => $trimmed], $errors);
    }

    /** @param array<string, mixed> $input */
    public static function validateProduct(array $input): ValidationResult
    {
        $safe = [];
        $errors = [];
        $nombre = is_string($input['nombre'] ?? null) ? trim($input['nombre']) : '';
        if ($nombre === '') {
            $errors['nombre'] = 'Product name is required.';
        } elseif (mb_strlen($nombre) > 150) {
            $errors['nombre'] = 'Product name must not exceed 150 characters.';
        }
        $safe['nombre'] = $nombre;

        $precio = is_string($input['precio_actual'] ?? null) ? trim($input['precio_actual']) : '';
        if ($precio === '' || preg_match(self::PRICE_PATTERN, $precio) !== 1) {
            $errors['precio_actual'] = 'Price must be a valid non-negative decimal with at most 2 fractional digits.';
        }
        $safe['precio_actual'] = $precio;
        $safe['descripcion'] = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';

        $cat = $input['id_categoria'] ?? null;
        if ($cat !== null && $cat !== '') {
            if ((!is_int($cat) && !is_string($cat)) || !is_numeric($cat) || (int) $cat <= 0) {
                $errors['id_categoria'] = 'Category ID must be a positive integer.';
            } else {
                $safe['id_categoria'] = (string) (int) $cat;
            }
        }
        return new ValidationResult($safe, $errors);
    }

    /** @param array<string, mixed> $input */
    public static function validateCategory(array $input): ValidationResult
    {
        $safe = [];
        $errors = [];
        $nombre = is_string($input['nombre'] ?? null) ? trim($input['nombre']) : '';
        if ($nombre === '') {
            $errors['nombre'] = 'Category name is required.';
        } elseif (mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'Category name must not exceed 100 characters.';
        }
        $safe['nombre'] = $nombre;
        $safe['descripcion'] = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';
        return new ValidationResult($safe, $errors);
    }
}
