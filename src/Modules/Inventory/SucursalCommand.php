<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

final readonly class SucursalCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function create(
        string $codigo,
        string $nombre,
        string $ciudad,
        ?string $direccion = null,
        ?string $telefono = null
    ): int {
        $codigo = trim($codigo);
        $nombre = trim($nombre);
        $ciudad = trim($ciudad);
        $direccion = $direccion !== null ? trim($direccion) : null;
        $telefono = $telefono !== null ? trim($telefono) : null;

        if ($codigo === '' || $nombre === '' || $ciudad === '') {
            throw new InvalidArgumentException('El código, nombre y ciudad son obligatorios.');
        }

        return $this->tx->run(function (PDO $pdo) use ($codigo, $nombre, $ciudad, $direccion, $telefono): int {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO sucursal (codigo, nombre, ciudad, direccion, telefono, estado_activo, created_at, updated_at) '
                    . 'VALUES (:codigo, :nombre, :ciudad, :direccion, :telefono, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $stmt->bindValue(':codigo', $codigo);
                $stmt->bindValue(':nombre', $nombre);
                $stmt->bindValue(':ciudad', $ciudad);
                $stmt->bindValue(':direccion', $direccion !== null && $direccion !== '' ? $direccion : null);
                $stmt->bindValue(':telefono', $telefono !== null && $telefono !== '' ? $telefono : null);
                $stmt->execute();

                return (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000 || (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23000')) {
                    throw new DomainException('El código de sucursal ya existe.', 0, $e);
                }
                throw $e;
            }
        });
    }

    public function update(
        int $idSucursal,
        string $nombre,
        string $ciudad,
        ?string $direccion = null,
        ?string $telefono = null
    ): bool {
        $nombre = trim($nombre);
        $ciudad = trim($ciudad);
        $direccion = $direccion !== null ? trim($direccion) : null;
        $telefono = $telefono !== null ? trim($telefono) : null;

        if ($nombre === '' || $ciudad === '') {
            throw new InvalidArgumentException('El nombre y la ciudad son obligatorios.');
        }

        return $this->tx->run(function (PDO $pdo) use ($idSucursal, $nombre, $ciudad, $direccion, $telefono): bool {
            $check = $pdo->prepare('SELECT id_sucursal FROM sucursal WHERE id_sucursal = :id');
            $check->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $check->execute();
            if ($check->fetch() === false) {
                return false;
            }

            $stmt = $pdo->prepare(
                'UPDATE sucursal '
                . 'SET nombre = :nombre, ciudad = :ciudad, direccion = :direccion, telefono = :telefono, updated_at = UTC_TIMESTAMP() '
                . 'WHERE id_sucursal = :id'
            );
            $stmt->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $stmt->bindValue(':nombre', $nombre);
            $stmt->bindValue(':ciudad', $ciudad);
            $stmt->bindValue(':direccion', $direccion !== null && $direccion !== '' ? $direccion : null);
            $stmt->bindValue(':telefono', $telefono !== null && $telefono !== '' ? $telefono : null);
            $stmt->execute();

            return true;
        });
    }

    public function toggleActive(int $idSucursal): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idSucursal): bool {
            $check = $pdo->prepare('SELECT estado_activo FROM sucursal WHERE id_sucursal = :id');
            $check->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return false;
            }

            $currentActive = isset($row['estado_activo']) && is_numeric($row['estado_activo'])
                ? (int) $row['estado_activo']
                : 0;
            $newActive = $currentActive === 1 ? 0 : 1;

            $stmt = $pdo->prepare('UPDATE sucursal SET estado_activo = :activo, updated_at = UTC_TIMESTAMP() WHERE id_sucursal = :id');
            $stmt->bindValue(':activo', $newActive, PDO::PARAM_INT);
            $stmt->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        });
    }
}
