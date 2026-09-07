<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\ValidationResult;

final class LocationValidator
{
    /**
     * @param array<string, mixed> $input
     */
    public static function validateLocation(array $input): ValidationResult
    {
        $safe = [];
        $errors = [];

        $codigo = is_string($input['codigo'] ?? null) ? trim($input['codigo']) : '';
        if ($codigo === '') {
            $errors['codigo'] = 'El código de la ubicación es obligatorio.';
        } elseif (mb_strlen($codigo) > 50) {
            $errors['codigo'] = 'El código de la ubicación no debe exceder los 50 caracteres.';
        }
        $safe['codigo'] = $codigo;

        $descripcion = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';
        $safe['descripcion'] = $descripcion;

        return new ValidationResult($safe, $errors);
    }
}
