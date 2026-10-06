<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

final readonly class AlmacenCommand
{
    /** @var list<string> */
    public const array ALLOWED_TYPES = ['bodega', 'mostrador', 'patio', 'merma'];

    public function __construct(private Transaction $tx)
    {
    }

    public function create(
        int $idSucursal,
        string $codigo,
        string $nombre,
        string $tipo = 'bodega'
    ): int {
        $codigo = trim($codigo);
        $nombre = trim($nombre);
        $tipo = trim($tipo);

        if ($codigo === '' || $nombre === '') {
            throw new InvalidArgumentException('El código y el nombre del almacén son obligatorios.');
        }

        if (mb_strlen($codigo) > 30 || !preg_match('/^[A-Za-z0-9_-]+$/', $codigo)) {
            throw new InvalidArgumentException('El código de almacén no es válido.');
        }

        if (!in_array($tipo, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de almacén no válido.');
        }

        return $this->tx->run(function (PDO $pdo) use ($idSucursal, $codigo, $nombre, $tipo): int {
            // Structural creation guard: parent branch must exist and be active
            $checkSucursal = $pdo->prepare('SELECT estado_activo FROM sucursal WHERE id_sucursal = :id');
            $checkSucursal->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $checkSucursal->execute();
            $sucursalRow = $checkSucursal->fetch(PDO::FETCH_ASSOC);

            if (!is_array($sucursalRow)) {
                throw new DomainException('No se puede crear un almacén en una sucursal inactiva o inexistente.');
            }

            $branchActive = isset($sucursalRow['estado_activo']) && is_numeric($sucursalRow['estado_activo'])
                ? (int) $sucursalRow['estado_activo']
                : 0;

            if ($branchActive !== 1) {
                throw new DomainException('No se puede crear un almacén en una sucursal inactiva o inexistente.');
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO almacen (id_sucursal, codigo, nombre, tipo, estado_activo, created_at, updated_at) '
                    . 'VALUES (:id_sucursal, :codigo, :nombre, :tipo, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $stmt->bindValue(':id_sucursal', $idSucursal, PDO::PARAM_INT);
                $stmt->bindValue(':codigo', $codigo);
                $stmt->bindValue(':nombre', $nombre);
                $stmt->bindValue(':tipo', $tipo);
                $stmt->execute();

                return (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000 || (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23000')) {
                    throw new DomainException('El código de almacén ya existe.', 0, $e);
                }
                throw $e;
            }
        });
    }

    public function update(int $idAlmacen, string $nombre, string $tipo): bool
    {
        $nombre = trim($nombre);
        $tipo = trim($tipo);

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del almacén no puede estar vacío.');
        }

        if (!in_array($tipo, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de almacén no válido.');
        }

        // Note: id_sucursal is intentionally excluded to enforce parent immutability
        return $this->tx->run(function (PDO $pdo) use ($idAlmacen, $nombre, $tipo): bool {
            $check = $pdo->prepare('SELECT id_almacen FROM almacen WHERE id_almacen = :id');
            $check->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
            $check->execute();
            if ($check->fetch() === false) {
                return false;
            }

            $stmt = $pdo->prepare(
                'UPDATE almacen SET nombre = :nombre, tipo = :tipo, updated_at = UTC_TIMESTAMP() WHERE id_almacen = :id'
            );
            $stmt->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
            $stmt->bindValue(':nombre', $nombre);
            $stmt->bindValue(':tipo', $tipo);
            $stmt->execute();

            return true;
        });
    }

    public function toggleActive(int $idAlmacen): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idAlmacen): bool {
            $check = $pdo->prepare('SELECT id_sucursal, estado_activo FROM almacen WHERE id_almacen = :id');
            $check->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return false;
            }

            $currentActive = isset($row['estado_activo']) && is_numeric($row['estado_activo'])
                ? (int) $row['estado_activo']
                : 0;

            if ($currentActive === 1) {
                // Deactivation is always permitted without parent check
                $stmt = $pdo->prepare('UPDATE almacen SET estado_activo = 0, updated_at = UTC_TIMESTAMP() WHERE id_almacen = :id');
                $stmt->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
                $stmt->execute();

                return true;
            }

            // Reactivation guard: parent branch must be active
            $idSucursal = isset($row['id_sucursal']) && is_numeric($row['id_sucursal'])
                ? (int) $row['id_sucursal']
                : 0;

            $checkSucursal = $pdo->prepare('SELECT estado_activo FROM sucursal WHERE id_sucursal = :id');
            $checkSucursal->bindValue(':id', $idSucursal, PDO::PARAM_INT);
            $checkSucursal->execute();
            $sucursalRow = $checkSucursal->fetch(PDO::FETCH_ASSOC);

            if (!is_array($sucursalRow)) {
                throw new DomainException('No se puede reactivar un almacén cuya sucursal está inactiva.');
            }

            $branchActive = isset($sucursalRow['estado_activo']) && is_numeric($sucursalRow['estado_activo'])
                ? (int) $sucursalRow['estado_activo']
                : 0;

            if ($branchActive !== 1) {
                throw new DomainException('No se puede reactivar un almacén cuya sucursal está inactiva.');
            }

            $stmt = $pdo->prepare('UPDATE almacen SET estado_activo = 1, updated_at = UTC_TIMESTAMP() WHERE id_almacen = :id');
            $stmt->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        });
    }
}
