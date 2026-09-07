<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class CountCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function record(int $idStock, string $cantidadContada, ?string $notas = null): int
    {
        $validation = StockValidator::validateQuantity($cantidadContada);
        if (!$validation->valid()) {
            throw new InvalidArgumentException($validation->fieldErrors['quantity'] ?? 'Invalid counted quantity.');
        }

        return $this->tx->run(function (PDO $pdo) use ($idStock, $cantidadContada, $notas): int {
            $sql = 'INSERT INTO conteo_inventario ('
                . 'id_stock, cantidad_sistema, cantidad_contada, diferencia, notas, created_at'
                . ') SELECT '
                . 's.id_stock, s.cantidad, :qty_val, (:qty_calc - s.cantidad), :notas, UTC_TIMESTAMP() '
                . 'FROM inventario_stock s '
                . 'WHERE s.id_stock = :id_stock';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':qty_val', $cantidadContada);
            $stmt->bindValue(':qty_calc', $cantidadContada);
            $stmt->bindValue(':notas', $notas !== null ? trim($notas) : null, $notas !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':id_stock', $idStock, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException("Stock position #{$idStock} does not exist.");
            }

            return (int) $pdo->lastInsertId();
        });
    }
}
