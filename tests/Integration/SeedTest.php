<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\SeedRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeedTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;

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
    }

    public function testIsolationGuardEnforced(): void
    {
        $selected = self::$testDb->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsString($selected);
        self::assertStringEndsWith('_test', $selected);
    }

    public function testSeedRefusesProductionEnvironment(): void
    {
        $runner = new SeedRunner(self::$testDb, 'production');
        $seeds  = dirname(__DIR__, 2) . '/database/seeds';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database seeding is prohibited in production environment.');

        $runner->run($seeds);
    }

    public function testSeedFailsClearlyWhenSchemaNotApplied(): void
    {
        // Table infrastructure_probe does not exist because migrations have not run
        $runner = new SeedRunner(self::$testDb, 'development');
        $seeds  = dirname(__DIR__, 2) . '/database/seeds';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Required table 'infrastructure_probe' does not exist. Run migrations first.");

        $runner->run($seeds);
    }

    public function testDevelopmentSeedIsDeterministicAndIdempotent(): void
    {
        $pdo        = self::$testDb->pdo();
        $migRunner  = new MigrationRunner(self::$testDb);
        $migrations = dirname(__DIR__, 2) . '/database/migrations';
        $seeds      = dirname(__DIR__, 2) . '/database/seeds';

        // 1. Apply schema
        $migRunner->run($migrations);

        $seedRunner = new SeedRunner(self::$testDb, 'development');

        // 2. First seed run
        $seedRunner->run($seeds);

        $rows = $pdo->query('SELECT id, created_at FROM infrastructure_probe')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['id']);

        // 3. Second seed run (proves idempotency: no duplicates, no error)
        $seedRunner->run($seeds);

        $rowsAfter = $pdo->query('SELECT id, created_at FROM infrastructure_probe')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rowsAfter, 'Second seed execution must not create duplicate rows.');
        self::assertSame(1, (int) $rowsAfter[0]['id']);
    }

    public function testDevelopmentDatabaseUntouched(): void
    {
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);

        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        self::assertDevDatabaseUntouched($devDb, self::$testConfig);
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
