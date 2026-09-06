<?php

declare(strict_types=1);

namespace App\Foundation;

use PDO;
use Pdo\Mysql;

/**
 * Wraps a single MariaDB PDO connection built from validated Config.
 *
 * PDO flags enforced:
 *   - ERRMODE_EXCEPTION
 *   - FETCH_ASSOC
 *   - EMULATE_PREPARES = false
 *   - PERSISTENT = false
 *   - Pdo\Mysql::ATTR_MULTI_STATEMENTS = false  (PHP 8.5+)
 *   - charset=utf8mb4
 *   - UTC session time_zone
 */
final class Database
{
    private readonly PDO $pdo;

    public function __construct(Config $config, bool $useTestDatabase = false)
    {
        $prefix = $useTestDatabase ? 'TEST_DB_' : 'DB_';

        $host     = $config->get($prefix . 'HOST');
        $port     = $config->get($prefix . 'PORT');
        $dbname   = $config->get($prefix . 'NAME');
        $user     = $config->get($prefix . 'USER');
        $password = $config->get($prefix . 'PASSWORD');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            Mysql::ATTR_MULTI_STATEMENTS => false,
        ]);

        // Enforce UTC for all date/time operations in this session.
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
