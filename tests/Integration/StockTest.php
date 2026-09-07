<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\LocationQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class StockTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;
    private static LocationQuery $locQuery;
    private static LocationCommand $locCmd;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
        $tx               = new Transaction(self::$testDb);

        self::$locQuery   = new LocationQuery(self::$testDb);
        self::$locCmd     = new LocationCommand($tx);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['ubicacion', 'producto', 'categoria'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE {$tbl}");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testLocationCreationAndRetrieval(): void
    {
        $id = self::$locCmd->create('PASILLO-A1', 'Pasillo principal estante 1');
        self::assertGreaterThan(0, $id);

        $loc = self::$locQuery->findById($id);
        self::assertIsArray($loc);
        self::assertSame('PASILLO-A1', $loc['codigo']);
        self::assertSame('Pasillo principal estante 1', $loc['descripcion']);
        self::assertSame(1, (int) $loc['estado_activo']);

        $byCode = self::$locQuery->findByCode('PASILLO-A1');
        self::assertIsArray($byCode);
        self::assertSame($id, (int) $byCode['id_ubicacion']);

        $all = self::$locQuery->all();
        self::assertCount(1, $all);
    }

    public function testDuplicateLocationCodeRejection(): void
    {
        self::$locCmd->create('BODEGA-01');
        $this->expectException(PDOException::class);
        self::$locCmd->create('BODEGA-01');
    }

    public function testMigrationReversalAndReRun(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $migrationsPath = dirname(__DIR__, 2) . '/database/migrations';

        $runner->revert('0004_create_ubicacion', $migrationsPath);
        $tablesLoc = self::$testDb->pdo()->query("SHOW TABLES LIKE 'ubicacion'")->fetchAll();
        self::assertCount(0, $tablesLoc);

        $runner->run($migrationsPath);
        self::assertCount(1, self::$testDb->pdo()->query("SHOW TABLES LIKE 'ubicacion'")->fetchAll());
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        $devDb  = new Database(self::$testConfig, useTestDatabase: false);
        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }
}