<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\LocationQuery;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\StockCommand;
use App\Modules\Inventory\StockQuery;
use App\Modules\Inventory\StockValidator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class StockTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;
    private static LocationQuery $locQuery;
    private static LocationCommand $locCmd;
    private static StockQuery $stockQuery;
    private static StockCommand $stockCmd;
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

        self::$locQuery   = new LocationQuery(self::$testDb);
        self::$locCmd     = new LocationCommand($tx);
        self::$stockQuery = new StockQuery(self::$testDb);
        self::$stockCmd   = new StockCommand($tx);
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

    public function testStockPositionCreationWithZeroAndFractionalQuantities(): void
    {
        $pId1 = self::$prodCmd->register('Tornillo 2 pulg', '2.50');
        $lId1 = self::$locCmd->create('ESTANTE-01');

        $sId1 = self::$stockCmd->createPosition($pId1, $lId1, '0.000');
        self::assertGreaterThan(0, $sId1);

        $pos1 = self::$stockQuery->getPosition($pId1, $lId1);
        self::assertIsArray($pos1);
        self::assertSame('0.000', $pos1['cantidad']);

        $pId2 = self::$prodCmd->register('Tuerca 3/8', '1.25');
        $lId2 = self::$locCmd->create('ESTANTE-02');

        $sId2 = self::$stockCmd->createPosition($pId2, $lId2, '125.750');
        self::assertGreaterThan(0, $sId2);

        $pos2 = self::$stockQuery->findById($sId2);
        self::assertIsArray($pos2);
        self::assertSame('125.750', $pos2['cantidad']);
    }

    public function testDuplicateStockPositionRejection(): void
    {
        $pId = self::$prodCmd->register('Tuerca 1/4', '0.50');
        $lId = self::$locCmd->create('CAJA-05');

        self::$stockCmd->createPosition($pId, $lId, '10.000');
        $this->expectException(PDOException::class);
        self::$stockCmd->createPosition($pId, $lId, '5.000');
    }

    public function testForeignKeyIntegrityEnforced(): void
    {
        $pId = self::$prodCmd->register('Arandela', '0.10');
        $this->expectException(PDOException::class);
        self::$stockCmd->createPosition($pId, 99999, '1.000');
    }

    public function testNegativeQuantityRejectedByDatabaseConstraint(): void
    {
        $pId = self::$prodCmd->register('Clavo 3 pulg', '1.00');
        $lId = self::$locCmd->create('ESTANTE-02');

        $this->expectException(PDOException::class);
        self::$stockCmd->createPosition($pId, $lId, '-1.000');
    }

    public function testQuantityValidationRules(): void
    {
        self::assertTrue(StockValidator::validateQuantity('0')->valid());
        self::assertTrue(StockValidator::validateQuantity('0.000')->valid());
        self::assertTrue(StockValidator::validateQuantity('15.5')->valid());
        self::assertTrue(StockValidator::validateQuantity('100.123')->valid());

        // Reject negative
        self::assertFalse(StockValidator::validateQuantity('-5.000')->valid());
        // Reject >3 decimal places
        self::assertFalse(StockValidator::validateQuantity('10.1234')->valid());
        // Reject invalid/empty
        self::assertFalse(StockValidator::validateQuantity('')->valid());
        self::assertFalse(StockValidator::validateQuantity('abc')->valid());
    }

    public function testReferentialIntegrityPreventsParentDeletionWhenStockExists(): void
    {
        $pId = self::$prodCmd->register('Pintura Azul', '50.00');
        $lId = self::$locCmd->create('ALMACEN-A');
        self::$stockCmd->createPosition($pId, $lId, '5.000');

        $this->expectException(PDOException::class);
        self::$testDb->pdo()->exec("DELETE FROM ubicacion WHERE id_ubicacion = {$lId}");
    }

    public function testMigrationReversalAndReRun(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $migrationsPath = dirname(__DIR__, 2) . '/database/migrations';
        if (file_exists($migrationsPath . '/0006_create_conteo_inventario.up.sql')) {
            $applied = self::$testDb->pdo()->query("SELECT 1 FROM schema_migrations WHERE identifier = '0006_create_conteo_inventario'")->fetch();
            if ($applied !== false) {
                $runner->revert('0006_create_conteo_inventario', $migrationsPath);
            }
        }

        $runner->revert('0005_create_inventario_stock', $migrationsPath);
        $tablesStock = self::$testDb->pdo()->query("SHOW TABLES LIKE 'inventario_stock'")->fetchAll();
        self::assertCount(0, $tablesStock);

        $runner->revert('0004_create_ubicacion', $migrationsPath);
        $tablesLoc = self::$testDb->pdo()->query("SHOW TABLES LIKE 'ubicacion'")->fetchAll();
        self::assertCount(0, $tablesLoc);

        $runner->run($migrationsPath);
        self::assertCount(1, self::$testDb->pdo()->query("SHOW TABLES LIKE 'ubicacion'")->fetchAll());
        self::assertCount(1, self::$testDb->pdo()->query("SHOW TABLES LIKE 'inventario_stock'")->fetchAll());
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        $devDb  = new Database(self::$testConfig, useTestDatabase: false);
        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }
}
