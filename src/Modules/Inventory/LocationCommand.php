<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use PDO;

final readonly class LocationCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function create(string $codigo, ?string $descripcion = null): int
    {
        return $this->tx->run(function (PDO $pdo) use ($codigo, $descripcion): int {
            $stmt = $pdo->prepare(
                'INSERT INTO ubicacion (codigo, descripcion, estado_activo, created_at, updated_at) '
                . 'VALUES (:codigo, :descripcion, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bindValue(':codigo', trim($codigo));
            $stmt->bindValue(':descripcion', $descripcion !== null ? trim($descripcion) : null);
            $stmt->execute();
            return (int) $pdo->lastInsertId();
        });
    }
}
