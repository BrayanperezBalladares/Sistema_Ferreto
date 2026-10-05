<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\ValidationResult;

final class BranchValidator
{
    /**
     * @param array<string, mixed> $input
     */
    public static function validateBranch(array $input): ValidationResult
    {
        $safe = [];
        $errors = [];

        $codigo = is_string($input['codigo'] ?? null) ? trim($input['codigo']) : '';
        if ($codigo === '') {
            $errors['codigo'] = 'El código de la sucursal es obligatorio.';
        } elseif (mb_strlen($codigo) > 30) {
            $errors['codigo'] = 'El código de la sucursal no debe exceder los 30 caracteres.';
        }
        $safe['codigo'] = $codigo;

        $nombre = is_string($input['nombre'] ?? null) ? trim($input['nombre']) : '';
        if ($nombre === '') {
            $errors['nombre'] = 'El nombre de la sucursal es obligatorio.';
        } elseif (mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'El nombre de la sucursal no debe exceder los 100 caracteres.';
        }
        $safe['nombre'] = $nombre;

        $ciudad = is_string($input['ciudad'] ?? null) ? trim($input['ciudad']) : '';
        if ($ciudad === '') {
            $errors['ciudad'] = 'La ciudad es obligatoria.';
        } elseif (mb_strlen($ciudad) > 100) {
            $errors['ciudad'] = 'La ciudad no debe exceder los 100 caracteres.';
        }
        $safe['ciudad'] = $ciudad;

        $direccion = is_string($input['direccion'] ?? null) ? trim($input['direccion']) : '';
        if (mb_strlen($direccion) > 255) {
            $errors['direccion'] = 'La dirección no debe exceder los 255 caracteres.';
        }
        $safe['direccion'] = $direccion;

        $telefono = is_string($input['telefono'] ?? null) ? trim($input['telefono']) : '';
        if (mb_strlen($telefono) > 30) {
            $errors['telefono'] = 'El teléfono no debe exceder los 30 caracteres.';
        }
        $safe['telefono'] = $telefono;

        return new ValidationResult($safe, $errors);
    }
}
