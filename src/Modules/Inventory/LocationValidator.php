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

        $idAlmacen = $input['id_almacen'] ?? null;
        if ($idAlmacen === null || $idAlmacen === '' || (is_string($idAlmacen) && trim($idAlmacen) === '')) {
            $errors['id_almacen'] = 'El almacén es obligatorio.';
            $safe['id_almacen'] = '';
        } elseif ((!is_int($idAlmacen) && !is_string($idAlmacen)) || !is_numeric($idAlmacen) || (int) $idAlmacen <= 0) {
            $errors['id_almacen'] = 'El almacén seleccionado no es válido.';
            $safe['id_almacen'] = is_scalar($idAlmacen) ? (string) $idAlmacen : '';
        } else {
            $safe['id_almacen'] = (string) (int) $idAlmacen;
        }

        $descripcion = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';
        $safe['descripcion'] = $descripcion;

        return new ValidationResult($safe, $errors);
    }
}
