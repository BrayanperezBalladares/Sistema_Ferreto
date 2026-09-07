<?php

declare(strict_types=1);

namespace App\Foundation;

use Closure;
use PDO;
use RuntimeException;

/**
 * Executes environment-specific database seeds within a transaction.
 *
 * Requirements:
 *  - Refuses execution when APP_ENV is 'production'.
 *  - Deterministic and idempotent: multiple runs produce identical state.
 *  - Runs inside Transaction::run() for atomic DML execution.
 *  - Does not create schema or tables; requires pre-existing migration state.
 */
final class SeedRunner
{
    public function __construct(
        private readonly Database $db,
        private readonly string $appEnv,
    ) {
    }

    public function run(string $seedsPath, string $seedName = 'development'): void
    {
        if ($this->appEnv === 'production') {
            throw new RuntimeException('Database seeding is prohibited in production environment.');
        }

        $file = rtrim($seedsPath, '/\\') . DIRECTORY_SEPARATOR . $seedName . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Seed file not found: {$file}");
        }

        /** @var mixed $seeder */
        $seeder = require $file;
        if (!is_callable($seeder)) {
            throw new RuntimeException("Seed file must return a callable: {$file}");
        }

        $tx = new Transaction($this->db);
        $tx->run(static function (PDO $pdo) use ($seeder): void {
            $seeder($pdo);
        });
    }
}
