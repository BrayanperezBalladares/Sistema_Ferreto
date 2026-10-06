<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

final readonly class LocationCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function create(string $codigo, int $idAlmacen, ?string $descripcion = null): int
    {
        $codigo = trim($codigo);
        $descripcion = $descripcion !== null ? trim($descripcion) : null;
        if ($descripcion === '') {
            $descripcion = null;
        }

        if ($codigo === '') {
            throw new InvalidArgumentException('El código de la ubicación es obligatorio.');
        }

        if ($idAlmacen <= 0) {
            throw new InvalidArgumentException('El almacén es obligatorio.');
        }

        return $this->tx->run(function (PDO $pdo) use ($codigo, $idAlmacen, $descripcion): int {
            // Structural creation guard: warehouse must exist and be active
            $checkWh = $pdo->prepare('SELECT estado_activo FROM almacen WHERE id_almacen = :id');
            $checkWh->bindValue(':id', $idAlmacen, PDO::PARAM_INT);
            $checkWh->execute();
            $whRow = $checkWh->fetch(PDO::FETCH_ASSOC);

            if (!is_array($whRow)) {
                throw new DomainException('No se puede crear una ubicación en un almacén inactivo o inexistente.');
            }

            $whActive = isset($whRow['estado_activo']) && is_numeric($whRow['estado_activo'])
                ? (int) $whRow['estado_activo']
                : 0;

            if ($whActive !== 1) {
                throw new DomainException('No se puede crear una ubicación en un almacén inactivo o inexistente.');
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO ubicacion (id_almacen, codigo, descripcion, estado_activo, created_at, updated_at) '
                    . 'VALUES (:id_almacen, :codigo, :descripcion, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $stmt->bindValue(':id_almacen', $idAlmacen, PDO::PARAM_INT);
                $stmt->bindValue(':codigo', $codigo);
                $stmt->bindValue(':descripcion', $descripcion);
                $stmt->execute();

                return (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000 || (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23000')) {
                    throw new DomainException('El código de la ubicación ya existe.', 0, $e);
                }
                throw $e;
            }
        });
    }

    public function delete(int $idUbicacion): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idUbicacion): bool {
            // Referential deletion guard:
            // Check conteo_inventario associated with this location through stock positions
            $checkCount = $pdo->prepare(
                'SELECT COUNT(*) FROM conteo_inventario c '
                . 'INNER JOIN inventario_stock s ON s.id_stock = c.id_stock '
                . 'WHERE s.id_ubicacion = :id'
            );
            $checkCount->bindValue(':id', $idUbicacion, PDO::PARAM_INT);
            $checkCount->execute();
            if ((int) $checkCount->fetchColumn() > 0) {
                throw new DomainException('No se puede eliminar la ubicación porque tiene conteos de inventario asociados.');
            }

            // Check inventario_stock
            $checkStock = $pdo->prepare('SELECT COUNT(*) FROM inventario_stock WHERE id_ubicacion = :id');
            $checkStock->bindValue(':id', $idUbicacion, PDO::PARAM_INT);
            $checkStock->execute();
            if ((int) $checkStock->fetchColumn() > 0) {
                throw new DomainException('No se puede eliminar la ubicación porque tiene registros de stock asociados.');
            }

            $stmt = $pdo->prepare('DELETE FROM ubicacion WHERE id_ubicacion = :id');
            $stmt->bindValue(':id', $idUbicacion, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }
}
