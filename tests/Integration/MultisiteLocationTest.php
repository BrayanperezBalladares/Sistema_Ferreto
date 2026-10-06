<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\AlmacenCommand;
use App\Modules\Inventory\AlmacenQuery;
use App\Modules\Inventory\CountCommand;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\LocationQuery;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\StockCommand;
use App\Modules\Inventory\StockQuery;
use App\Modules\Inventory\SucursalCommand;
use App\Modules\Inventory\SucursalQuery;
use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class MultisiteLocationTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static SucursalCommand $sucursalCmd;
    private static SucursalQuery $sucursalQuery;
    private static AlmacenCommand $almacenCmd;
    private static AlmacenQuery $almacenQuery;
    private static LocationCommand $locCmd;
    private static LocationQuery $locQuery;
    private static ProductCommand $prodCmd;
    private static StockCommand $stockCmd;
    private static StockQuery $stockQuery;
    private static CountCommand $countCmd;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$config = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb = new Database(self::$config, useTestDatabase: true);
        self::$devDb  = new Database(self::$config, useTestDatabase: false);
        self::$tx     = new Transaction(self::$testDb);

        self::assertTestDatabaseIsolated(self::$testDb, self::$config);
        self::recordInitialDevState(self::$devDb);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');

        self::$sucursalCmd   = new SucursalCommand(self::$tx);
        self::$sucursalQuery = new SucursalQuery(self::$testDb);
        self::$almacenCmd    = new AlmacenCommand(self::$tx);
        self::$almacenQuery  = new AlmacenQuery(self::$testDb);
        self::$locCmd        = new LocationCommand(self::$tx);
        self::$locQuery      = new LocationQuery(self::$testDb);
        self::$prodCmd       = new ProductCommand(self::$tx);
        self::$stockCmd      = new StockCommand(self::$tx);
        self::$stockQuery    = new StockQuery(self::$testDb);
        self::$countCmd      = new CountCommand(self::$tx);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$config);
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = ['conteo_inventario', 'inventario_stock', 'ubicacion', 'almacen', 'sucursal', 'producto', 'categoria'];
        foreach ($tables as $tbl) {
            $pdo->exec("TRUNCATE TABLE {$tbl}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testLocationCreationUnderActiveWarehouseAndDuplicateRejection(): void
    {
        $bId = self::$sucursalCmd->create('SUC-01', 'Sucursal Central', 'Tegucigalpa');
        $wId = self::$almacenCmd->create($bId, 'ALM-01', 'Bodega Principal', 'bodega');

        $locId = self::$locCmd->create('LOC-A1', $wId, 'Estantería Principal');
        self::assertGreaterThan(0, $locId);

        $loc = self::$locQuery->findById($locId);
        self::assertIsArray($loc);
        self::assertSame($locId, $loc['id_ubicacion']);
        self::assertSame('LOC-A1', $loc['codigo']);
        self::assertSame('Estantería Principal', $loc['descripcion']);
        self::assertSame($wId, $loc['id_almacen']);
        self::assertSame('ALM-01', $loc['almacen_codigo']);
        self::assertSame('Bodega Principal', $loc['almacen_nombre']);
        self::assertSame('bodega', $loc['almacen_tipo']);
        self::assertSame(1, $loc['almacen_activo']);
        self::assertSame($bId, $loc['id_sucursal']);
        self::assertSame('SUC-01', $loc['sucursal_codigo']);
        self::assertSame('Sucursal Central', $loc['sucursal_nombre']);

        $byCode = self::$locQuery->findByCode('LOC-A1');
        self::assertIsArray($byCode);
        self::assertSame($locId, $byCode['id_ubicacion']);

        // Duplicate code rejection
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El código de la ubicación ya existe.');
        self::$locCmd->create('LOC-A1', $wId, 'Otro estante');
    }

    public function testValidationRejectsEmptyCodeAndInvalidWarehouse(): void
    {
        $bId = self::$sucursalCmd->create('SUC-VAL', 'Sucursal Val', 'SPS');
        $wId = self::$almacenCmd->create($bId, 'ALM-VAL', 'Almacén Val');

        try {
            self::$locCmd->create('   ', $wId);
            self::fail('Expected InvalidArgumentException for empty code');
        } catch (InvalidArgumentException $e) {
            self::assertSame('El código de la ubicación es obligatorio.', $e->getMessage());
        }

        try {
            self::$locCmd->create('LOC-OK', 0);
            self::fail('Expected InvalidArgumentException for invalid warehouse ID');
        } catch (InvalidArgumentException $e) {
            self::assertSame('El almacén es obligatorio.', $e->getMessage());
        }
    }

    public function testRejectCreationUnderInactiveWarehouse(): void
    {
        $bId = self::$sucursalCmd->create('SUC-INACT', 'Sucursal Inact', 'Comayagua');
        $wId = self::$almacenCmd->create($bId, 'ALM-INACT', 'Almacén Inactivo');
        self::$almacenCmd->toggleActive($wId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede crear una ubicación en un almacén inactivo o inexistente.');
        self::$locCmd->create('LOC-ERR', $wId);
    }

    public function testRejectCreationUnderNonexistentWarehouse(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede crear una ubicación en un almacén inactivo o inexistente.');
        self::$locCmd->create('LOC-NONEXIST', 999999);
    }

    public function testDeleteLocationRejectedWhenStockPositionsExist(): void
    {
        $bId = self::$sucursalCmd->create('SUC-DEL', 'Sucursal Del', 'Danlí');
        $wId = self::$almacenCmd->create($bId, 'ALM-DEL', 'Almacén Del');
        $locId = self::$locCmd->create('LOC-DEL-STOCK', $wId);

        $prodId = self::$prodCmd->register('Martillo Stanley', '250.00');
        self::$stockCmd->createPosition($prodId, $locId, '15.000');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede eliminar la ubicación porque tiene registros de stock asociados.');
        self::$locCmd->delete($locId);
    }

    public function testDeleteLocationRejectedWhenCountsExist(): void
    {
        $bId = self::$sucursalCmd->create('SUC-COUNT', 'Sucursal Count', 'Choluteca');
        $wId = self::$almacenCmd->create($bId, 'ALM-COUNT', 'Almacén Count');
        $locId = self::$locCmd->create('LOC-DEL-COUNT', $wId);

        $prodId = self::$prodCmd->register('Clavos 3 pulg', '50.00');
        $stockId = self::$stockCmd->createPosition($prodId, $locId, '10.000');

        self::$countCmd->record($stockId, '10.000', 'Conteo verificado');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede eliminar la ubicación porque tiene conteos de inventario asociados.');
        self::$locCmd->delete($locId);
    }

    public function testDeleteLocationSucceedsWhenEmpty(): void
    {
        $bId = self::$sucursalCmd->create('SUC-EMPTY', 'Sucursal Empty', 'Juticalpa');
        $wId = self::$almacenCmd->create($bId, 'ALM-EMPTY', 'Almacén Empty');
        $locId = self::$locCmd->create('LOC-EMPTY', $wId);

        $deleted = self::$locCmd->delete($locId);
        self::assertTrue($deleted);

        $loc = self::$locQuery->findById($locId);
        self::assertNull($loc);
    }

    public function testDynamicStockRollups(): void
    {
        // Branch 1 has Warehouse 1A and Warehouse 1B
        $b1 = self::$sucursalCmd->create('SUC-B1', 'Sucursal B1', 'Tegucigalpa');
        $w1A = self::$almacenCmd->create($b1, 'ALM-1A', 'Bodega 1A');
        $w1B = self::$almacenCmd->create($b1, 'ALM-1B', 'Bodega 1B');

        // Branch 2 has Warehouse 2A
        $b2 = self::$sucursalCmd->create('SUC-B2', 'Sucursal B2', 'San Pedro Sula');
        $w2A = self::$almacenCmd->create($b2, 'ALM-2A', 'Bodega 2A');

        // Locations
        $l1A1 = self::$locCmd->create('LOC-1A1', $w1A);
        $l1A2 = self::$locCmd->create('LOC-1A2', $w1A);
        $l1B1 = self::$locCmd->create('LOC-1B1', $w1B);
        $l2A1 = self::$locCmd->create('LOC-2A1', $w2A);

        // Product
        $pId = self::$prodCmd->register('Pintura Blanca 1 Gal', '450.00');

        // Create stock positions
        self::$stockCmd->createPosition($pId, $l1A1, '10.500');
        self::$stockCmd->createPosition($pId, $l1A2, '5.250');
        self::$stockCmd->createPosition($pId, $l1B1, '20.000');
        self::$stockCmd->createPosition($pId, $l2A1, '30.100');

        // Rollups by warehouse
        self::assertSame('15.750', self::$stockQuery->getWarehouseStock($pId, $w1A));
        self::assertSame('20.000', self::$stockQuery->getWarehouseStock($pId, $w1B));
        self::assertSame('30.100', self::$stockQuery->getWarehouseStock($pId, $w2A));
        self::assertSame('0.000', self::$stockQuery->getWarehouseStock($pId, 999999));

        // Rollups by branch
        self::assertSame('35.750', self::$stockQuery->getBranchStock($pId, $b1));
        self::assertSame('30.100', self::$stockQuery->getBranchStock($pId, $b2));
        self::assertSame('0.000', self::$stockQuery->getBranchStock($pId, 999999));

        // Stock breakdown by warehouse
        $breakdown = self::$stockQuery->getStockBreakdownByWarehouse($pId);
        self::assertCount(3, $breakdown);

        $quantitiesByWh = [];
        foreach ($breakdown as $row) {
            $quantitiesByWh[$row['almacen_codigo']] = $row['cantidad'];
            self::assertNotNull($row['id_sucursal']);
            self::assertNotNull($row['sucursal_codigo']);
        }

        self::assertSame('15.750', $quantitiesByWh['ALM-1A']);
        self::assertSame('20.000', $quantitiesByWh['ALM-1B']);
        self::assertSame('30.100', $quantitiesByWh['ALM-2A']);
    }

    public function testNullableSafeLocationQueryWithLegacyUnmappedLocation(): void
    {
        // Simulate legacy unmapped location (id_almacen IS NULL)
        $pdo = self::$testDb->pdo();
        $pdo->exec("INSERT INTO ubicacion (codigo, descripcion, estado_activo, created_at, updated_at) VALUES ('LEGACY-01', 'Ubicación histórica', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $legacyId = (int) $pdo->lastInsertId();

        // Mapped location
        $bId = self::$sucursalCmd->create('SUC-MAP', 'Sucursal Mapeada', 'Siguatepeque');
        $wId = self::$almacenCmd->create($bId, 'ALM-MAP', 'Almacén Mapeado');
        $mappedId = self::$locCmd->create('MAPPED-01', $wId, 'Ubicación mapeada');

        // LocationQuery::findById on unmapped
        $legacyLoc = self::$locQuery->findById($legacyId);
        self::assertIsArray($legacyLoc);
        self::assertSame('LEGACY-01', $legacyLoc['codigo']);
        self::assertNull($legacyLoc['id_almacen']);
        self::assertNull($legacyLoc['almacen_codigo']);
        self::assertNull($legacyLoc['almacen_nombre']);
        self::assertNull($legacyLoc['almacen_tipo']);
        self::assertNull($legacyLoc['almacen_activo']);
        self::assertNull($legacyLoc['id_sucursal']);
        self::assertNull($legacyLoc['sucursal_codigo']);
        self::assertNull($legacyLoc['sucursal_nombre']);

        // LocationQuery::all includes both
        $all = self::$locQuery->all();
        self::assertCount(2, $all);

        // LocationQuery::findByWarehouse filters correctly
        $whFiltered = self::$locQuery->findByWarehouse($wId);
        self::assertCount(1, $whFiltered);
        self::assertSame($mappedId, $whFiltered[0]['id_ubicacion']);
    }
}
