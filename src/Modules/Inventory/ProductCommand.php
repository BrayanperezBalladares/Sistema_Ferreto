<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use PDO;

final readonly class ProductCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function register(string $nombre, string $precioActual, ?int $idCategoria = null, ?string $desc = null): int
    {
        return $this->tx->run(function (PDO $pdo) use ($nombre, $precioActual, $idCategoria, $desc): int {
            $stmt = $pdo->prepare(
                'INSERT INTO producto (id_categoria, nombre, descripcion, precio_actual, estado_activo, created_at, updated_at) '
                . 'VALUES (:id_categoria, :nombre, :descripcion, :precio_actual, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bindValue(':id_categoria', $idCategoria, $idCategoria === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':nombre', trim($nombre));
            $stmt->bindValue(':descripcion', $desc !== null ? trim($desc) : null);
            $stmt->bindValue(':precio_actual', $precioActual);
            $stmt->execute();
            return (int) $pdo->lastInsertId();
        });
    }

    public function updatePrice(int $idProducto, string $nuevoPrecio): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idProducto, $nuevoPrecio): bool {
            $stmt = $pdo->prepare('UPDATE producto SET precio_actual = :precio, updated_at = UTC_TIMESTAMP() WHERE id_producto = :id');
            $stmt->bindValue(':precio', $nuevoPrecio);
            $stmt->bindValue(':id', $idProducto, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        });
    }

    public function deactivate(int $idProducto): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idProducto): bool {
            $stmt = $pdo->prepare('UPDATE producto SET estado_activo = 0, updated_at = UTC_TIMESTAMP() WHERE id_producto = :id');
            $stmt->bindValue(':id', $idProducto, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        });
    }

    public function activate(int $idProducto): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idProducto): bool {
            $stmt = $pdo->prepare('UPDATE producto SET estado_activo = 1, updated_at = UTC_TIMESTAMP() WHERE id_producto = :id');
            $stmt->bindValue(':id', $idProducto, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        });
    }
}
