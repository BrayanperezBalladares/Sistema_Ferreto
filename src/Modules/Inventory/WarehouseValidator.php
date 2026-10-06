<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\ValidationResult;

final class WarehouseValidator
{
    /**
     * @param array<string, mixed> $input
     */
    public static function validateWarehouse(array $input): ValidationResult
    {
        $safe = [];
        $errors = [];

        $idSucursal = $input['id_sucursal'] ?? null;
        if ($idSucursal === null || $idSucursal === '' || (is_string($idSucursal) && trim($idSucursal) === '')) {
            $errors['id_sucursal'] = 'La sucursal es obligatoria.';
            $safe['id_sucursal'] = '';
        } else {
            $isValidSucursal = (is_int($idSucursal) && $idSucursal > 0)
                || (is_string($idSucursal) && ctype_digit(trim($idSucursal)) && (int) trim($idSucursal) > 0);
            if (!$isValidSucursal) {
                $errors['id_sucursal'] = 'La sucursal seleccionada no es válida.';
                $safe['id_sucursal'] = is_scalar($idSucursal) ? (string) $idSucursal : '';
            } else {
                $safe['id_sucursal'] = (string) (int) $idSucursal;
            }
        }

        $codigo = is_string($input['codigo'] ?? null) ? trim($input['codigo']) : '';
        if ($codigo === '') {
            $errors['codigo'] = 'El código del almacén es obligatorio.';
        } elseif (mb_strlen($codigo) > 30) {
            $errors['codigo'] = 'El código del almacén no debe exceder los 30 caracteres.';
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $codigo)) {
            $errors['codigo'] = 'El código del almacén solo puede contener letras, números, guiones y guiones bajos.';
        }
        $safe['codigo'] = $codigo;

        $nombre = is_string($input['nombre'] ?? null) ? trim($input['nombre']) : '';
        if ($nombre === '') {
            $errors['nombre'] = 'El nombre del almacén es obligatorio.';
        } elseif (mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'El nombre del almacén no debe exceder los 100 caracteres.';
        }
        $safe['nombre'] = $nombre;

        $tipo = is_string($input['tipo'] ?? null) ? trim($input['tipo']) : '';
        if ($tipo === '' || !in_array($tipo, AlmacenCommand::ALLOWED_TYPES, true)) {
            $errors['tipo'] = 'Tipo de almacén no válido.';
        }
        $safe['tipo'] = $tipo;

        return new ValidationResult($safe, $errors);
    }
}
