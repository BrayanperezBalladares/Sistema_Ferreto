<?php

declare(strict_types=1);

namespace App\Foundation;

use Closure;
use LogicException;
use PDO;
use Throwable;

/**
 * Explicit transaction boundary for all state-changing data work.
 *
 * Contract:
 *   - Commits when the callable returns normally.
 *   - Rolls back and rethrows on any Throwable.
 *   - Rejects nesting with LogicException (thrown before BEGIN).
 *
 * Note on MariaDB DDL: DDL statements (CREATE TABLE, ALTER TABLE, DROP TABLE)
 * cause implicit commits. This boundary is designed for DML (INSERT, UPDATE,
 * DELETE). Do not use Transaction::run() to wrap DDL migrations.
 */
final class Transaction
{
    private bool $active = false;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Run $work inside a database transaction.
     *
     * @template T
     * @param Closure(PDO): T $work
     * @return T
     * @throws LogicException when called from within an already-active transaction.
     * @throws Throwable the original exception after rolling back.
     */
    public function run(Closure $work): mixed
    {
        if ($this->active) {
            throw new LogicException(
                'Nested transactions are not supported. '
                . 'Flatten the operation or use a single transaction boundary.'
            );
        }

        $pdo = $this->db->pdo();
        $this->active = true;
        $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            $this->active = false;
        }
    }
}
