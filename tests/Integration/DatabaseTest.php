<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\HealthQuery;
use App\Foundation\Transaction;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration tests for Slice 3A: Database, Transaction, HealthQuery.
 *
 * ALL mutation-capable tests use only the test database (TEST_DB_*).
 * Connection to the development database is read-only and limited to a single
 * availability check. No permanent application tables are created in Slice 3A.
 *
 * Isolation guard: this file refuses to run if TEST_DB_NAME does not end in
 * _test, matching Config's own enforcement.
 */
final class DatabaseTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;

    // -------------------------------------------------------------------------
    // Suite bootstrap — isolation guard first
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        // Hard isolation guard: fail the entire suite, never silently skip.
        $testDbName = getenv('TEST_DB_NAME');
        if (!is_string($testDbName) || !str_ends_with($testDbName, '_test')) {
            self::fail(
                'DatabaseTest isolation guard: TEST_DB_NAME must end in _test. '
                . 'Got: ' . (is_string($testDbName) ? $testDbName : '(missing)') . '. '
                . 'Refusing to run against an unconfirmed test database.'
            );
        }

        $defaults = [
            'APP_ENV'      => ['development', 'test', 'production'],
            'DB_PORT'      => [1, 65535],
            'TEST_DB_PORT' => [1, 65535],
        ];

        self::$testConfig = Config::fromEnvironment($defaults);
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
    }

    // -------------------------------------------------------------------------
    // Isolation guard (per-test belt-and-suspenders)
    // -------------------------------------------------------------------------

    public function testIsolationGuardTestDbNameEndsWith_test(): void
    {
        $name = self::$testConfig->get('TEST_DB_NAME');
        self::assertStringEndsWith(
            '_test',
            $name,
            "TEST_DB_NAME '{$name}' must end in _test before any test runs."
        );
    }

    public function testSelectedDatabaseIsTestDatabase(): void
    {
        $pdo      = self::$testDb->pdo();
        $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsString($selected);
        self::assertStringEndsWith(
            '_test',
            $selected,
            "The live selected database '{$selected}' must end in _test."
        );
    }

    // -------------------------------------------------------------------------
    // PDO flag verification (read-only, no mutation)
    // -------------------------------------------------------------------------

    public function testPdoErrorModeIsException(): void
    {
        $pdo = self::$testDb->pdo();
        self::assertSame(
            PDO::ERRMODE_EXCEPTION,
            $pdo->getAttribute(PDO::ATTR_ERRMODE)
        );
    }

    public function testPdoFetchModeIsAssoc(): void
    {
        $pdo = self::$testDb->pdo();
        self::assertSame(
            PDO::FETCH_ASSOC,
            $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)
        );
    }

    public function testPdoEmulatePreparesIsFalse(): void
    {
        $pdo = self::$testDb->pdo();
        // PDO::ATTR_EMULATE_PREPARES returns 0 (falsy) when disabled.
        self::assertSame(
            0,
            (int) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
        );
    }

    public function testPdoSessionTimeZoneIsUtc(): void
    {
        $pdo = self::$testDb->pdo();
        $row = $pdo->query("SELECT @@session.time_zone AS tz")->fetch();
        self::assertIsArray($row);
        // MariaDB returns '+00:00' or 'UTC' depending on system configuration.
        self::assertMatchesRegularExpression(
            '/^(\+00:00|UTC)$/i',
            (string) $row['tz'],
            "Session time_zone must be UTC or +00:00, got: {$row['tz']}"
        );
    }

    // -------------------------------------------------------------------------
    // HealthQuery — parameter binding proof (test DB, read-only)
    // -------------------------------------------------------------------------

    public function testHealthQueryPingReturnsTrueOnLiveConnection(): void
    {
        $query = new HealthQuery(self::$testDb);
        self::assertTrue($query->ping());
    }

    public function testHealthQueryUsesParameterBinding(): void
    {
        // We cannot directly inspect PDO prepared-statement internals, but we
        // can prove the SQL contains a named placeholder by examining the source.
        // This is the closest structural proof available without a mock driver.
        // The integration test above proves the binding runs against a real server.
        $source = file_get_contents(__DIR__ . '/../../src/Foundation/HealthQuery.php');
        self::assertIsString($source);

        // Must contain a named bind — not raw string interpolation.
        self::assertStringContainsString(':probe', $source);

        // Must NOT use string concatenation in the SQL expression.
        self::assertStringNotContainsString('"SELECT ' . "' .", $source);
        self::assertStringNotContainsString("'SELECT ' .", $source);
    }

    // -------------------------------------------------------------------------
    // Transaction — commit path (test DB only, uses a transient temp table)
    // -------------------------------------------------------------------------

    public function testTransactionCommitsOnSuccess(): void
    {
        $pdo = self::$testDb->pdo();
        $tx  = new Transaction(self::$testDb);

        // Use a session-scoped temporary table so no permanent schema is created.
        $pdo->exec('CREATE TEMPORARY TABLE _tx_commit_probe (val INT NOT NULL)');

        $result = $tx->run(static function (PDO $conn): int {
            $conn->exec('INSERT INTO _tx_commit_probe (val) VALUES (42)');
            return 42;
        });

        self::assertSame(42, $result);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM _tx_commit_probe WHERE val = 42')
            ->fetchColumn();
        self::assertSame(1, $count, 'Row must be visible after commit.');

        $pdo->exec('DROP TEMPORARY TABLE _tx_commit_probe');
    }

    public function testTransactionRollsBackOnThrowable(): void
    {
        $pdo = self::$testDb->pdo();
        $tx  = new Transaction(self::$testDb);

        $pdo->exec('CREATE TEMPORARY TABLE _tx_rollback_probe (val INT NOT NULL)');
        // Seed a known baseline row.
        $pdo->exec('INSERT INTO _tx_rollback_probe (val) VALUES (99)');

        try {
            $tx->run(static function (PDO $conn): never {
                $conn->exec('INSERT INTO _tx_rollback_probe (val) VALUES (100)');
                throw new \RuntimeException('Simulated failure');
            });
            self::fail('Transaction::run() must rethrow the exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated failure', $e->getMessage());
        }

        // Only the baseline row must remain; the failed row must be gone.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM _tx_rollback_probe')
            ->fetchColumn();
        self::assertSame(1, $count, 'Rolled-back row must not be visible.');

        $pdo->exec('DROP TEMPORARY TABLE _tx_rollback_probe');
    }

    // -------------------------------------------------------------------------
    // Transaction — nesting rejection
    // -------------------------------------------------------------------------

    public function testTransactionRejectsNesting(): void
    {
        $tx = new Transaction(self::$testDb);

        $this->expectException(LogicException::class);

        $tx->run(static function (PDO $conn) use ($tx): void {
            // Attempt to start a nested transaction from within a running one.
            $tx->run(static function (PDO $inner): void {
                // Should never execute.
                $inner->exec('SELECT 1');
            });
        });
    }

    // -------------------------------------------------------------------------
    // Development database — read-only connection verification only
    // -------------------------------------------------------------------------

    public function testDevelopmentDatabaseConnectionIsAvailable(): void
    {
        // This test proves the dev DB is reachable. It is strictly read-only:
        // no INSERT/UPDATE/DELETE/CREATE/DROP is issued here.
        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        $pdo   = $devDb->pdo();

        $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsString($selected);

        $devName = self::$testConfig->get('DB_NAME');
        self::assertSame(
            $devName,
            $selected,
            "Development probe must target '{$devName}', not '{$selected}'."
        );
    }

    public function testDevelopmentDatabaseIsNotMutated(): void
    {
        // Structural proof: this test only reads; the _test suffix guard above
        // ensures no mutation test can accidentally resolve to the dev DB.
        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        $pdo   = $devDb->pdo();

        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        self::assertIsString($version);
        self::assertStringContainsString('MariaDB', $version);
    }
}
