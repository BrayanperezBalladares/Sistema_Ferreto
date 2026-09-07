<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\CountCommand;
use App\Modules\Inventory\CountQuery;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\StockCommand;
use App\Modules\Inventory\StockQuery;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class CountTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;
    private static CountQuery $countQuery;
    private static CountCommand $countCmd;
    private static StockQuery $stockQuery;
    private static StockCommand $stockCmd;
    private static LocationCommand $locCmd;
    private static ProductCommand $prodCmd;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
        $tx               = new Transaction(self::$testDb);

        self::$countQuery = new CountQuery(self::$testDb);
        self::$countCmd   = new CountCommand($tx);
        self::$stockQuery = new StockQuery(self::$testDb);
        self::$stockCmd   = new StockCommand($tx);
        self::$locCmd     = new LocationCommand($tx);
        self::$prodCmd    = new ProductCommand($tx);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'producto', 'categoria'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE {$tbl}");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createStockFixture(string $initialQty): int
    {
        $pId = self::$prodCmd->register('Item-' . uniqid(), '10.00');
        $lId = self::$locCmd->create('LOC-' . uniqid());

        return self::$stockCmd->createPosition($pId, $lId, $initialQty);
    }

    public function testRecordObservationalCountWithDiscrepancy(): void
    {
        $sId = $this->createStockFixture('10.000');

        $cId = self::$countCmd->record($sId, '8.500', 'Discrepancia en conteo físico');
        self::assertGreaterThan(0, $cId);

        $count = self::$countQuery->findById($cId);
        self::assertIsArray($count);
        self::assertSame($sId, $count['id_stock']);
        self::assertSame('10.000', $count['cantidad_sistema']);
        self::assertSame('8.500', $count['cantidad_contada']);
        self::assertSame('-1.500', $count['diferencia']);
        self::assertSame('Discrepancia en conteo físico', $count['notas']);
    }

    public function testRecordObservationalCountWithZeroDiscrepancy(): void
    {
        $sId = $this->createStockFixture('5.000');

        $cId = self::$countCmd->record($sId, '5.000');
        self::assertGreaterThan(0, $cId);

        $count = self::$countQuery->findById($cId);
        self::assertIsArray($count);
        self::assertSame('5.000', $count['cantidad_sistema']);
        self::assertSame('5.000', $count['cantidad_contada']);
        self::assertSame('0.000', $count['diferencia']);
        self::assertNull($count['notas']);
    }

    public function testRecordObservationalCountWithFractionalValues(): void
    {
        $sId = $this->createStockFixture('12.345');

        $cId = self::$countCmd->record($sId, '15.456');
        self::assertGreaterThan(0, $cId);

        $count = self::$countQuery->findById($cId);
        self::assertIsArray($count);
        self::assertSame('12.345', $count['cantidad_sistema']);
        self::assertSame('15.456', $count['cantidad_contada']);
        self::assertSame('3.111', $count['diferencia']);
    }

    public function testNegativeCountedQuantityRejectedByValidation(): void
    {
        $sId = $this->createStockFixture('10.000');

        $this->expectException(InvalidArgumentException::class);
        self::$countCmd->record($sId, '-1.000');
    }

    public function testPrecisionGreaterThanThreeDecimalsRejectedByValidation(): void
    {
        $sId = $this->createStockFixture('10.000');

        $this->expectException(InvalidArgumentException::class);
        self::$countCmd->record($sId, '10.1234');
    }

    public function testNonexistentStockPositionRejectedWithNoCountRow(): void
    {
        $this->expectException(RuntimeException::class);
        self::$countCmd->record(999999, '5.000');
    }

    public function testStockRemainsExactlyUnchangedAfterCountCreation(): void
    {
        $sId = $this->createStockFixture('15.000');

        $cId = self::$countCmd->record($sId, '12.000');
        self::assertGreaterThan(0, $cId);

        $stock = self::$stockQuery->findById($sId);
        self::assertIsArray($stock);
        self::assertSame('15.000', $stock['cantidad'], 'inventario_stock must not be modified by count creation');

        $count = self::$countQuery->findById($cId);
        self::assertIsArray($count);
        self::assertSame('-3.000', $count['diferencia']);
    }

    public function testRepeatedObservationsCreateSeparateHistoricalRows(): void
    {
        $sId = $this->createStockFixture('20.000');

        $cId1 = self::$countCmd->record($sId, '19.000', 'Primer conteo');
        $cId2 = self::$countCmd->record($sId, '18.500', 'Segundo conteo');

        self::assertGreaterThan(0, $cId1);
        self::assertGreaterThan($cId1, $cId2);

        $history = self::$countQuery->listByStock($sId);
        self::assertCount(2, $history);
        self::assertSame($cId2, $history[0]['id_conteo']);
        self::assertSame('18.500', $history[0]['cantidad_contada']);
        self::assertSame($cId1, $history[1]['id_conteo']);
        self::assertSame('19.000', $history[1]['cantidad_contada']);
    }

    public function testNoUpdateOrDeleteCountMethodsExposed(): void
    {
        $cmdRef = new ReflectionClass(CountCommand::class);
        $methods = array_map(fn($m) => $m->getName(), $cmdRef->getMethods());

        self::assertNotContains('update', $methods);
        self::assertNotContains('updateCount', $methods);
        self::assertNotContains('delete', $methods);
        self::assertNotContains('deleteCount', $methods);
        self::assertNotContains('reconcile', $methods);
        self::assertNotContains('adjust', $methods);
    }

    public function testMigrationReversalAndReRun(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $migrationsPath = dirname(__DIR__, 2) . '/database/migrations';

        $runner->revert('0006_create_conteo_inventario', $migrationsPath);
        $tables = self::$testDb->pdo()->query("SHOW TABLES LIKE 'conteo_inventario'")->fetchAll();
        self::assertCount(0, $tables);

        $runner->run($migrationsPath);
        $tables = self::$testDb->pdo()->query("SHOW TABLES LIKE 'conteo_inventario'")->fetchAll();
        self::assertCount(1, $tables);
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        $devDb  = new Database(self::$testConfig, useTestDatabase: false);
        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }
}
