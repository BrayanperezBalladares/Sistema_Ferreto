<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use PDO;

final readonly class StockCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function createPosition(int $idProducto, int $idUbicacion, string $cantidad = '0.000'): int
    {
        return $this->tx->run(function (PDO $pdo) use ($idProducto, $idUbicacion, $cantidad): int {
            $stmt = $pdo->prepare(
                'INSERT INTO inventario_stock (id_producto, id_ubicacion, cantidad, created_at, updated_at) '
                . 'VALUES (:prod, :loc, :qty, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bindValue(':prod', $idProducto, PDO::PARAM_INT);
            $stmt->bindValue(':loc', $idUbicacion, PDO::PARAM_INT);
            $stmt->bindValue(':qty', $cantidad);
            $stmt->execute();

            return (int) $pdo->lastInsertId();
        });
    }

    public function updateQuantity(int $idStock, string $nuevaCantidad): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idStock, $nuevaCantidad): bool {
            $stmt = $pdo->prepare('UPDATE inventario_stock SET cantidad = :qty, updated_at = UTC_TIMESTAMP() WHERE id_stock = :id');
            $stmt->bindValue(':qty', $nuevaCantidad);
            $stmt->bindValue(':id', $idStock, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }
}
