<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MigrationTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);
        self::recordInitialDevState(new Database(self::$testConfig, useTestDatabase: false));
    }

    protected function setUp(): void
    {
        $this->dropAllTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();
        foreach ($this->tempDirs as $dir) {
            $files = glob($dir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testIsolationGuardEnforced(): void
    {
        $selected = self::$testDb->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsString($selected);
        self::assertStringEndsWith('_test', $selected);
    }

    public function testRepositoryMigrationLifecycle(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $path   = dirname(__DIR__, 2) . '/database/migrations';

        $runner->run($path);

        $pdo   = self::$testDb->pdo();
        $probe = $pdo->query("SHOW TABLES LIKE 'infrastructure_probe'")->fetchColumn();
        self::assertSame('infrastructure_probe', $probe);

        $row = $pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0001_probe'")->fetch();
        self::assertIsArray($row);
        self::assertNotEmpty($row['checksum']);
        self::assertNotEmpty($row['applied_at']);

        // Idempotent re-run: does not re-apply or error
        $runner->run($path);

        // Compensating reversal removes table and history
        $runner->revert('0001_probe', $path);
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'infrastructure_probe'")->fetchColumn());
        self::assertFalse($pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0001_probe'")->fetch());
    }

    public function testDeterministicOrdering(): void
    {
        $dir = $this->createFixtureDir([
            '0002_second.up.sql' => 'CREATE TABLE _ord_b (id INT NOT NULL)',
            '0001_first.up.sql'  => 'CREATE TABLE _ord_a (id INT NOT NULL)',
        ]);

        (new MigrationRunner(self::$testDb))->run($dir);

        $history = self::$testDb->pdo()
            ->query('SELECT identifier FROM schema_migrations ORDER BY applied_at ASC, identifier ASC')
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['0001_first', '0002_second'], $history);
    }

    public function testFailStopOnInvalidSqlWithNoFalseHistory(): void
    {
        $dir = $this->createFixtureDir([
            '0001_ok.up.sql'    => 'CREATE TABLE _fs_ok (id INT NOT NULL)',
            '0002_bad.up.sql'   => 'THIS IS NOT VALID SQL',
            '0003_after.up.sql' => 'CREATE TABLE _fs_after (id INT NOT NULL)',
        ]);

        try {
            (new MigrationRunner(self::$testDb))->run($dir);
            self::fail('Expected migration execution to throw on invalid SQL.');
        } catch (Throwable) {
            // Expected
        }

        $pdo = self::$testDb->pdo();
        self::assertSame('_fs_ok', $pdo->query("SHOW TABLES LIKE '_fs_ok'")->fetchColumn());
        self::assertFalse($pdo->query("SHOW TABLES LIKE '_fs_after'")->fetchColumn());

        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['0001_ok'], $history);

        $lockConn = new Database(self::$testConfig, useTestDatabase: true);
        $lockName = 'ferreto_migrations_lock_' . self::$testConfig->get('TEST_DB_NAME');
        $acquired = $lockConn->pdo()->query("SELECT GET_LOCK('{$lockName}', 0)")->fetchColumn();
        self::assertSame(1, (int) $acquired, 'Runner must release its advisory lock in finally on failure.');
        $lockConn->pdo()->query("SELECT RELEASE_LOCK('{$lockName}')");
    }

    public function testChecksumDriftRejectionBeforePendingWork(): void
    {
        $dir = $this->createFixtureDir([
            '0001_base.up.sql' => 'CREATE TABLE _drift_base (id INT NOT NULL)',
        ]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Alter applied migration and add pending migration
        file_put_contents($dir . '/0001_base.up.sql', 'CREATE TABLE _drift_base (id INT NOT NULL, extra INT)');
        file_put_contents($dir . '/0002_pend.up.sql', 'CREATE TABLE _drift_pend (id INT NOT NULL)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_base');

        try {
            $runner->run($dir);
        } finally {
            self::assertFalse(
                self::$testDb->pdo()->query("SHOW TABLES LIKE '_drift_pend'")->fetchColumn(),
                'Pending migration must not execute when checksum drift is detected.'
            );
        }
    }

    public function testFailedDownMigrationPreservesHistory(): void
    {
        $dir = $this->createFixtureDir([
            '0001_test.up.sql'   => 'CREATE TABLE _fdm (id INT NOT NULL)',
            '0001_test.down.sql' => 'DROP TABLE non_existent_table_that_will_fail',
        ]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        try {
            $runner->revert('0001_test', $dir);
            self::fail('Expected revert to throw on invalid down statement.');
        } catch (Throwable) {
            // Expected
        }

        $history = self::$testDb->pdo()
            ->query("SELECT identifier FROM schema_migrations WHERE identifier = '0001_test'")
            ->fetchColumn();
        self::assertSame('0001_test', $history);
    }

    public function testAdvisoryLockContentionAndRelease(): void
    {
        $lockConn = new Database(self::$testConfig, useTestDatabase: true);
        $lockName = 'ferreto_migrations_lock_' . self::$testConfig->get('TEST_DB_NAME');

        $acquired = $lockConn->pdo()->query("SELECT GET_LOCK('{$lockName}', 5)")->fetchColumn();
        self::assertSame(1, (int) $acquired);

        $dir = $this->createFixtureDir([
            '0001_locked.up.sql' => 'CREATE TABLE _lck (id INT NOT NULL)',
        ]);

        try {
            (new MigrationRunner(self::$testDb, lockTimeout: 0))->run($dir);
            self::fail('Expected runner to fail when lock is held by another connection.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Could not acquire migration lock', $e->getMessage());
        } finally {
            $lockConn->pdo()->query("SELECT RELEASE_LOCK('{$lockName}')");
        }

        // Lock released: runner succeeds
        (new MigrationRunner(self::$testDb, lockTimeout: 2))->run($dir);
        self::assertSame('_lck', self::$testDb->pdo()->query("SHOW TABLES LIKE '_lck'")->fetchColumn());
    }

    public function testMultiStatementIsRejected(): void
    {
        $dir = $this->createFixtureDir([
            '0001_multi.up.sql' => "CREATE TABLE _m1 (id INT);\nCREATE TABLE _m2 (id INT);",
        ]);

        try {
            (new MigrationRunner(self::$testDb))->run($dir);
            self::fail('Expected multi-statement execution to fail.');
        } catch (Throwable) {
            // Expected: ATTR_MULTI_STATEMENTS is false
        }

        self::assertFalse(self::$testDb->pdo()->query("SHOW TABLES LIKE '_m2'")->fetchColumn());
    }

    public function testDevelopmentDatabaseUntouched(): void
    {
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);

        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        self::assertDevDatabaseUntouched($devDb, self::$testConfig);

        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertFalse(in_array('_m1', $tables, true));
        self::assertFalse(in_array('_m2', $tables, true));
    }

    /** @param array<string, string> $files */
    private function createFixtureDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/ferreto_mig_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
        return $dir;
    }

    private function dropAllTestTables(): void
    {
        $pdo    = self::$testDb->pdo();
        $stmt   = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($tables !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $t) {
                if (is_string($t)) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
