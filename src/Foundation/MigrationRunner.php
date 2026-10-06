<?php

declare(strict_types=1);

namespace App\Foundation;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Serialized, fail-stop migration runner for MariaDB.
 *
 * Contract:
 *  - One executable DDL statement per .up.sql or .down.sql file.
 *  - History (schema_migrations) is written ONLY after PDO execution succeeds.
 *  - Applied-migration checksums are validated before any pending work runs.
 *  - An exclusive per-database advisory lock serializes concurrent invocations.
 *  - DDL causes implicit commits on MariaDB; this runner is NOT transactional.
 *  - .down.sql files are compensating reversals, not transaction rollbacks.
 *  - If a down statement fails, the history record is preserved.
 */
final class MigrationRunner
{
    private readonly PDO $pdo;

    public function __construct(
        private readonly Database $db,
        private readonly int $lockTimeout = 10,
    ) {
        $this->pdo = $db->pdo();
    }

    public function run(string $migrationsPath): void
    {
        $lock = $this->lockName();
        $this->acquireLock($lock);
        try {
            $this->bootstrap();
            $this->validateChecksums($migrationsPath);
            foreach ($this->pendingMigrations($migrationsPath) as $identifier => $file) {
                $raw      = $this->readRawFile($file);
                $sql      = rtrim($raw);
                $checksum = $this->canonicalChecksum($raw);
                $this->pdo->exec($sql);           // implicit DDL commit; throws on failure
                $this->record($identifier, $checksum);
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Execute the compensating reversal for $identifier.
     * The history record is removed ONLY if the down statement succeeds without throwing.
     */
    public function revert(string $identifier, string $migrationsPath): void
    {
        $lock = $this->lockName();
        $this->acquireLock($lock);
        try {
            $file = $this->joinPath($migrationsPath, $identifier . '.down.sql');
            $this->pdo->exec($this->readSql($file));  // throws on failure; history preserved
            $stmt = $this->pdo->prepare('DELETE FROM schema_migrations WHERE identifier = :id');
            $stmt->execute([':id' => $identifier]);
        } finally {
            $this->releaseLock($lock);
        }
    }

    // -------------------------------------------------------------------------
    // Private — schema bootstrap
    // -------------------------------------------------------------------------

    private function bootstrap(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . '  identifier  VARCHAR(255) NOT NULL,'
            . '  checksum    CHAR(64)     NOT NULL,'
            . '  applied_at  DATETIME     NOT NULL,'
            . '  PRIMARY KEY (identifier)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    // -------------------------------------------------------------------------
    // Private — advisory lock (independent of any transaction)
    // -------------------------------------------------------------------------

    private function lockName(): string
    {
        $stmt   = $this->queryOrFail('SELECT DATABASE()');
        $dbName = (string) $stmt->fetchColumn();
        return 'ferreto_migrations_lock_' . $dbName;
    }

    private function acquireLock(string $name): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, $this->lockTimeout]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException(
                "Could not acquire migration lock '{$name}' within {$this->lockTimeout}s."
            );
        }
    }

    private function releaseLock(string $name): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
        $stmt->fetchAll(); // consume result set; prevents "commands out of sync"
    }

    // -------------------------------------------------------------------------
    // Private — checksum validation and pending resolution
    // -------------------------------------------------------------------------

    private function validateChecksums(string $migrationsPath): void
    {
        $stmt = $this->queryOrFail('SELECT identifier, checksum FROM schema_migrations');
        while (true) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $identifier = is_string($row['identifier'] ?? null) ? $row['identifier'] : '';
            $stored     = is_string($row['checksum']   ?? null) ? $row['checksum']   : '';
            $file       = $this->joinPath($migrationsPath, $identifier . '.up.sql');
            $raw        = $this->readRawFile($file);
            if (!$this->matchesAcceptedChecksum($stored, $raw)) {
                throw new RuntimeException(
                    "Checksum drift detected for applied migration: {$identifier}"
                );
            }
        }
    }

    /** @return array<string, string> identifier => absolute filepath, in deterministic order */
    private function pendingMigrations(string $migrationsPath): array
    {
        $stmt    = $this->queryOrFail('SELECT identifier FROM schema_migrations');
        $applied = [];
        while (true) {
            $value = $stmt->fetchColumn();
            if ($value === false) {
                break;
            }
            $applied[is_string($value) ? $value : (string) $value] = true;
        }

        $pattern = rtrim($migrationsPath, '/\\') . DIRECTORY_SEPARATOR . '*.up.sql';
        $files   = glob($pattern) ?: [];
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $id = basename($file, '.up.sql');
            if (!isset($applied[$id])) {
                $pending[$id] = $file;
            }
        }
        return $pending;
    }


    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    private function record(string $identifier, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (identifier, checksum, applied_at) '
            . 'VALUES (:id, :cs, UTC_TIMESTAMP())'
        );
        $stmt->execute([':id' => $identifier, ':cs' => $checksum]);
    }

    private function joinPath(string $base, string $filename): string
    {
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Compare stored checksum against bounded accepted line-ending candidates:
     *  1. Canonical LF checksum (LF line endings)
     *  2. Uniform CRLF checksum (historical Windows line endings)
     *  3. Raw filesystem byte checksum (exact bytes as read)
     */
    private function matchesAcceptedChecksum(string $stored, string $raw): bool
    {
        foreach ($this->acceptedChecksumCandidates($raw) as $candidate) {
            if (hash_equals($stored, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function acceptedChecksumCandidates(string $raw): array
    {
        $canonical = $this->canonicalChecksum($raw);
        $crlf      = $this->crlfChecksum($raw);
        $rawHash   = hash('sha256', rtrim($raw));

        return array_values(array_unique([$canonical, $crlf, $rawHash]));
    }

    private function canonicalChecksum(string $raw): string
    {
        $lf = str_replace("\r\n", "\n", $raw);
        return hash('sha256', rtrim($lf));
    }

    private function crlfChecksum(string $raw): string
    {
        $lf = str_replace("\r\n", "\n", $raw);
        $crlf = str_replace("\n", "\r\n", $lf);
        return hash('sha256', rtrim($crlf));
    }

    private function readRawFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read migration file: {$path}");
        }
        return $content;
    }

    private function readSql(string $path): string
    {
        return rtrim($this->readRawFile($path));
    }

    private function queryOrFail(string $sql): PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException("Query failed: {$sql}");
        }
        return $stmt;
    }
}
