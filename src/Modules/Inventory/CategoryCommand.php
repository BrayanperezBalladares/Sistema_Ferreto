<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Transaction;
use PDO;

final readonly class CategoryCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function create(string $name, ?string $description = null): int
    {
        return $this->tx->run(function (PDO $pdo) use ($name, $description): int {
            $stmt = $pdo->prepare(
                'INSERT INTO categoria (nombre, descripcion, created_at, updated_at) '
                . 'VALUES (:nombre, :descripcion, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bindValue(':nombre', trim($name));
            $stmt->bindValue(':descripcion', $description !== null ? trim($description) : null);
            $stmt->execute();
            return (int) $pdo->lastInsertId();
        });
    }
}
