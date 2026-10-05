<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Console;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\AlmacenCommand;
use App\Modules\Inventory\AlmacenQuery;
use App\Modules\Inventory\CountCommand;
use App\Modules\Inventory\LocationQuery;
use App\Modules\Inventory\MultisiteCliHandler;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\StockCommand;
use App\Modules\Inventory\SucursalCommand;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConsoleMultisiteMappingTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static SucursalCommand $sucursalCmd;
    private static AlmacenCommand $almacenCmd;
    private static LocationQuery $locationQuery;
    private static AlmacenQuery $almacenQuery;
    private static ProductCommand $prodCmd;
    private static StockCommand $stockCmd;
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
        self::$almacenCmd    = new AlmacenCommand(self::$tx);
        self::$locationQuery = new LocationQuery(self::$testDb);
        self::$almacenQuery  = new AlmacenQuery(self::$testDb);
        self::$prodCmd       = new ProductCommand(self::$tx);
        self::$stockCmd      = new StockCommand(self::$tx);
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

    /**
     * @param resource $out
     * @param resource $err
     */
    private function createHandler($out, $err): MultisiteCliHandler
    {
        return new MultisiteCliHandler(
            self::$testDb,
            self::$locationQuery,
            self::$almacenQuery,
            $out,
            $err
        );
    }

    private function insertLegacyLocation(string $code, string $description = 'Legacy unmapped'): int
    {
        $stmt = self::$testDb->pdo()->prepare(
            'INSERT INTO ubicacion (codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) '
            . 'VALUES (:codigo, :descripcion, 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':codigo'      => $code,
            ':descripcion' => $description,
        ]);

        return (int) self::$testDb->pdo()->lastInsertId();
    }

    public function testMapLocationByIdAndByCodeSucceedsAtomically(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-MAP-01', 'Sucursal Central', 'Tegucigalpa');
        $whId1    = self::$almacenCmd->create($branchId, 'ALM-MAP-01', 'Almacén 1', 'bodega');
        $whId2    = self::$almacenCmd->create($branchId, 'ALM-MAP-02', 'Almacén 2', 'mostrador');

        $locId1 = $this->insertLegacyLocation('LOC-LEG-01', 'Estante Legado 1');
        $locId2 = $this->insertLegacyLocation('LOC-LEG-02', 'Estante Legado 2');

        // 1. Map by ID
        $out1 = fopen('php://memory', 'w+');
        $err1 = fopen('php://memory', 'w+');
        self::assertIsResource($out1);
        self::assertIsResource($err1);

        $handler1 = $this->createHandler($out1, $err1);
        $code1 = $handler1->handleMapLocation([(string) $locId1, (string) $whId1]);
        self::assertSame(0, $code1);

        rewind($out1);
        $stdout1 = stream_get_contents($out1);
        self::assertIsString($stdout1);
        self::assertStringContainsString("Ubicación 'LOC-LEG-01' mapeada exitosamente al almacén 'ALM-MAP-01'.", $stdout1);

        $loc1After = self::$locationQuery->findById($locId1);
        self::assertNotNull($loc1After);
        self::assertSame($whId1, $loc1After['id_almacen']);
        self::assertSame('ALM-MAP-01', $loc1After['almacen_codigo']);

        // 2. Map by Code
        $out2 = fopen('php://memory', 'w+');
        $err2 = fopen('php://memory', 'w+');
        self::assertIsResource($out2);
        self::assertIsResource($err2);

        $handler2 = $this->createHandler($out2, $err2);
        $code2 = $handler2->handleMapLocation(['LOC-LEG-02', 'ALM-MAP-02']);
        self::assertSame(0, $code2);

        rewind($out2);
        $stdout2 = stream_get_contents($out2);
        self::assertIsString($stdout2);
        self::assertStringContainsString("Ubicación 'LOC-LEG-02' mapeada exitosamente al almacén 'ALM-MAP-02'.", $stdout2);

        $loc2After = self::$locationQuery->findById($locId2);
        self::assertNotNull($loc2After);
        self::assertSame($whId2, $loc2After['id_almacen']);
        self::assertSame('ALM-MAP-02', $loc2After['almacen_codigo']);
    }

    public function testMapLocationFailsOnNonexistentLocationOrWarehouse(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-NON', 'Sucursal Non', 'San Pedro Sula');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-VALID', 'Almacén Valido');
        $locId    = $this->insertLegacyLocation('LOC-VALID');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);

        // Nonexistent location by numeric ID
        $res1 = $handler->handleMapLocation(['999999', (string) $whId]);
        self::assertSame(1, $res1);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '999999' no existe.", stream_get_contents($err));

        // Nonexistent location by code
        ftruncate($err, 0);
        rewind($err);
        $res2 = $handler->handleMapLocation(['NONEXISTENT-CODE', (string) $whId]);
        self::assertSame(1, $res2);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación 'NONEXISTENT-CODE' no existe.", stream_get_contents($err));

        // Nonexistent warehouse by numeric ID
        ftruncate($err, 0);
        rewind($err);
        $res3 = $handler->handleMapLocation([(string) $locId, '999999']);
        self::assertSame(1, $res3);
        rewind($err);
        self::assertStringContainsString("Error: El almacén '999999' no existe.", stream_get_contents($err));

        // Nonexistent warehouse by code
        ftruncate($err, 0);
        rewind($err);
        $res4 = $handler->handleMapLocation([(string) $locId, 'WH-NONEXISTENT']);
        self::assertSame(1, $res4);
        rewind($err);
        self::assertStringContainsString("Error: El almacén 'WH-NONEXISTENT' no existe.", stream_get_contents($err));

        // Ensure location remains unmapped
        $loc = self::$locationQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertNull($loc['id_almacen']);
    }

    public function testMapLocationFailsOnInactiveWarehouseAndLocationRemainsNull(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-INACT', 'Sucursal Inactiva', 'La Ceiba');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-INACT', 'Almacén Inactivo');
        self::$almacenCmd->toggleActive($whId);

        $locId = $this->insertLegacyLocation('LOC-INACT-TEST');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);
        $res = $handler->handleMapLocation([(string) $locId, (string) $whId]);
        self::assertSame(1, $res);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString("Error: El almacén 'ALM-INACT' está inactivo.", $stderr);

        // Assert location remains NULL
        $loc = self::$locationQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertNull($loc['id_almacen']);
    }

    public function testMapLocationFailsWhenLocationAlreadyMappedEnforcingImmutability(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-IMM', 'Sucursal Imm', 'Comayagua');
        $whId1    = self::$almacenCmd->create($branchId, 'ALM-IMM-1', 'Almacén 1');
        $whId2    = self::$almacenCmd->create($branchId, 'ALM-IMM-2', 'Almacén 2');

        $locId = $this->insertLegacyLocation('LOC-IMM-TEST');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);

        // First mapping succeeds
        $res1 = $handler->handleMapLocation([(string) $locId, (string) $whId1]);
        self::assertSame(0, $res1);

        // Repeated mapping to the SAME warehouse fails
        ftruncate($err, 0);
        rewind($err);
        $res2 = $handler->handleMapLocation([(string) $locId, (string) $whId1]);
        self::assertSame(1, $res2);
        rewind($err);
        $err2 = stream_get_contents($err);
        self::assertIsString($err2);
        self::assertStringContainsString("Error: La ubicación 'LOC-IMM-TEST' ya tiene un almacén asignado o no se pudo mapear.", $err2);

        // Remapping attempt to a DIFFERENT warehouse fails
        ftruncate($err, 0);
        rewind($err);
        $res3 = $handler->handleMapLocation([(string) $locId, (string) $whId2]);
        self::assertSame(1, $res3);
        rewind($err);
        $err3 = stream_get_contents($err);
        self::assertIsString($err3);
        self::assertStringContainsString("Error: La ubicación 'LOC-IMM-TEST' ya tiene un almacén asignado o no se pudo mapear.", $err3);

        // Ensure location is still mapped to whId1
        $loc = self::$locationQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertSame($whId1, $loc['id_almacen']);
    }

    public function testMapLocationPreservesLocationStockAndCountRecordsWithoutAlteration(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-PRES', 'Sucursal Preservar', 'Choluteca');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-PRES', 'Almacén Preservar');

        $locId = $this->insertLegacyLocation('LOC-PRES-TEST', 'Descripción Intacta');

        $prodId = self::$prodCmd->register('Disco Diamante 7 pulg', '350.00');
        $stockId = self::$stockCmd->createPosition($prodId, $locId, '25.500');

        $countId = self::$countCmd->record($stockId, '25.500', 'Conteo verificado en sitio');

        // Map location
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);
        $res = $handler->handleMapLocation([(string) $locId, (string) $whId]);
        self::assertSame(0, $res);

        // Verify Location attributes
        $loc = self::$locationQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertSame($locId, $loc['id_ubicacion']);
        self::assertSame('LOC-PRES-TEST', $loc['codigo']);
        self::assertSame('Descripción Intacta', $loc['descripcion']);
        self::assertSame($whId, $loc['id_almacen']);

        // Verify Stock Position record
        $stmtStock = self::$testDb->pdo()->prepare('SELECT * FROM inventario_stock WHERE id_stock = :id');
        $stmtStock->execute([':id' => $stockId]);
        $stockRow = $stmtStock->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stockRow);
        self::assertSame($stockId, (int) $stockRow['id_stock']);
        self::assertSame($prodId, (int) $stockRow['id_producto']);
        self::assertSame($locId, (int) $stockRow['id_ubicacion']);
        self::assertSame('25.500', $stockRow['cantidad']);

        // Verify Count record
        $stmtCount = self::$testDb->pdo()->prepare('SELECT * FROM conteo_inventario WHERE id_conteo = :id');
        $stmtCount->execute([':id' => $countId]);
        $countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($countRow);
        self::assertSame($countId, (int) $countRow['id_conteo']);
        self::assertSame($stockId, (int) $countRow['id_stock']);
        self::assertSame('25.500', $countRow['cantidad_contada']);
        self::assertSame('Conteo verificado en sitio', $countRow['notas']);
    }

    public function testVerifyLocationsMappedFailsWhenUnmappedLocationsExist(): void
    {
        $lId1 = $this->insertLegacyLocation('LOC-UNMAP-A');
        $lId2 = $this->insertLegacyLocation('LOC-UNMAP-B');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);
        $res = $handler->handleVerifyLocationsMapped([]);
        self::assertSame(1, $res);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString('Error: Se encontraron 2 ubicación(es) sin asignar a un almacén:', $stderr);
        self::assertStringContainsString("  - ID {$lId1}: LOC-UNMAP-A", $stderr);
        self::assertStringContainsString("  - ID {$lId2}: LOC-UNMAP-B", $stderr);
    }

    public function testVerifyLocationsMappedSucceedsWhenAllLocationsAreMapped(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-VERIF', 'Sucursal Verif', 'Danlí');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-VERIF', 'Almacén Verif');

        $lId1 = $this->insertLegacyLocation('LOC-VERIF-1');
        $lId2 = $this->insertLegacyLocation('LOC-VERIF-2');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);
        $handler->handleMapLocation([(string) $lId1, (string) $whId]);
        $handler->handleMapLocation([(string) $lId2, (string) $whId]);

        // Now run verify
        $outV = fopen('php://memory', 'w+');
        $errV = fopen('php://memory', 'w+');
        self::assertIsResource($outV);
        self::assertIsResource($errV);

        $handlerV = $this->createHandler($outV, $errV);
        $res = $handlerV->handleVerifyLocationsMapped([]);
        self::assertSame(0, $res);

        rewind($outV);
        $stdout = stream_get_contents($outV);
        self::assertIsString($stdout);
        self::assertStringContainsString('Verificación exitosa: Todas las ubicaciones están mapeadas a un almacén.', $stdout);

        rewind($errV);
        $stderr = stream_get_contents($errV);
        self::assertIsString($stderr);
        self::assertEmpty($stderr);
    }

    public function testInvalidArgumentsReturnStatus64(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);

        // map-location with 0 args
        self::assertSame(64, $handler->handleMapLocation([]));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php map-location <location> <warehouse>', stream_get_contents($err));

        // map-location with 1 arg
        ftruncate($err, 0);
        rewind($err);
        self::assertSame(64, $handler->handleMapLocation(['LOC']));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php map-location <location> <warehouse>', stream_get_contents($err));

        // map-location with 3 args
        ftruncate($err, 0);
        rewind($err);
        self::assertSame(64, $handler->handleMapLocation(['LOC', 'WH', 'EXTRA']));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php map-location <location> <warehouse>', stream_get_contents($err));

        // map-location rejecting options starting with '-'
        ftruncate($err, 0);
        rewind($err);
        self::assertSame(64, $handler->handleMapLocation(['--help', 'WH']));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php map-location <location> <warehouse>', stream_get_contents($err));

        ftruncate($err, 0);
        rewind($err);
        self::assertSame(64, $handler->handleMapLocation(['LOC', '--option']));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php map-location <location> <warehouse>', stream_get_contents($err));

        // map-location strictly checks integer IDs and does not parse float '1.5' as int 1
        ftruncate($err, 0);
        rewind($err);
        self::assertSame(1, $handler->handleMapLocation(['1.5', 'WH']));
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '1.5' no existe.", stream_get_contents($err));

        // verify-locations-mapped with 1 arg
        ftruncate($err, 0);
        rewind($err);
        self::assertSame(64, $handler->handleVerifyLocationsMapped(['extra_arg']));
        rewind($err);
        self::assertStringContainsString('Usage: php scripts/console.php verify-locations-mapped', stream_get_contents($err));

        // Console dispatch validation
        $root = dirname(__DIR__, 2);
        $console = new Console($root, null, $handler);

        self::assertSame(64, $console->run(['map-location']));
        self::assertSame(64, $console->run(['map-location', 'only-one']));
        self::assertSame(64, $console->run(['map-location', 'loc', 'wh', 'extra']));
        self::assertSame(64, $console->run(['verify-locations-mapped', 'unexpected']));
    }

    public function testConsoleDispatchWithInjectedHandler(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-DISP', 'Sucursal Disp', 'Juticalpa');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-DISP', 'Almacén Disp');
        $locId    = $this->insertLegacyLocation('LOC-DISP');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = $this->createHandler($out, $err);
        $root    = dirname(__DIR__, 2);
        $console = new Console($root, null, $handler);

        $exitMap = $console->run(['map-location', 'LOC-DISP', 'ALM-DISP']);
        self::assertSame(0, $exitMap);

        $loc = self::$locationQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertSame($whId, $loc['id_almacen']);

        $exitVerify = $console->run(['verify-locations-mapped']);
        self::assertSame(0, $exitVerify);
    }

    public function testLocationIdentifierResolutionContract(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-LOC-RES', 'Sucursal Loc Res', 'Managua');
        $whId     = self::$almacenCmd->create($branchId, 'ALM-LOC-RES', 'Almacén Loc Res');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $handler = $this->createHandler($out, $err);

        // 1. Ordinary strict ID
        $locStrictId = $this->insertLegacyLocation('LOC-STRICT-ID');
        $res1 = $handler->handleMapLocation([(string) $locStrictId, (string) $whId]);
        self::assertSame(0, $res1);
        $locRow1 = self::$locationQuery->findById($locStrictId);
        self::assertNotNull($locRow1);
        self::assertSame($whId, $locRow1['id_almacen']);

        // 2. Ordinary alphanumeric code
        $locAlphaId = $this->insertLegacyLocation('LOC-ALPHA-CODE');
        $res2 = $handler->handleMapLocation(['LOC-ALPHA-CODE', (string) $whId]);
        self::assertSame(0, $res2);
        $locRow2 = self::$locationQuery->findById($locAlphaId);
        self::assertNotNull($locRow2);
        self::assertSame($whId, $locRow2['id_almacen']);

        // 3. Numeric code where no colliding ID exists
        $locNumCodeId = $this->insertLegacyLocation('8888');
        self::assertNull(self::$locationQuery->findById(8888));
        $res3 = $handler->handleMapLocation(['8888', (string) $whId]);
        self::assertSame(0, $res3);
        $locRow3 = self::$locationQuery->findById($locNumCodeId);
        self::assertNotNull($locRow3);
        self::assertSame($whId, $locRow3['id_almacen']);

        // 4. Numeric ID where no colliding code exists
        $locNumId = $this->insertLegacyLocation('LOC-NO-COLLIDE-CODE');
        self::assertNull(self::$locationQuery->findByCode((string) $locNumId));
        $res4 = $handler->handleMapLocation([(string) $locNumId, (string) $whId]);
        self::assertSame(0, $res4);
        $locRow4 = self::$locationQuery->findById($locNumId);
        self::assertNotNull($locRow4);
        self::assertSame($whId, $locRow4['id_almacen']);

        // 5. Numeric token where ID and code resolve to DIFFERENT rows (COLLISION)
        $pdo = self::$testDb->pdo();
        $pdo->exec("INSERT INTO ubicacion (id_ubicacion, codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) VALUES (500, 'LOC-ROW-A', 'Row A', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO ubicacion (id_ubicacion, codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) VALUES (501, '500', 'Row B', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        $prodId = self::$prodCmd->register('Producto Collision Loc', '10.00');
        $stockA = self::$stockCmd->createPosition($prodId, 500, '100.000');
        $stockB = self::$stockCmd->createPosition($prodId, 501, '200.000');
        $countA = self::$countCmd->record($stockA, '100.000', 'Count A initial');
        $countB = self::$countCmd->record($stockB, '200.000', 'Count B initial');

        $stmtStock = $pdo->prepare('SELECT id_stock, id_producto, id_ubicacion, cantidad FROM inventario_stock WHERE id_stock = :id');
        $stmtCount = $pdo->prepare('SELECT id_conteo, id_stock, cantidad_sistema, cantidad_contada, diferencia, notas, created_at FROM conteo_inventario WHERE id_conteo = :id');

        $stmtStock->execute([':id' => $stockA]);
        $stockABefore = $stmtStock->fetch(PDO::FETCH_ASSOC);
        $stmtStock->execute([':id' => $stockB]);
        $stockBBefore = $stmtStock->fetch(PDO::FETCH_ASSOC);

        $stmtCount->execute([':id' => $countA]);
        $countABefore = $stmtCount->fetch(PDO::FETCH_ASSOC);
        $stmtCount->execute([':id' => $countB]);
        $countBBefore = $stmtCount->fetch(PDO::FETCH_ASSOC);

        ftruncate($err, 0);
        rewind($err);
        $resColl = $handler->handleMapLocation(['500', (string) $whId]);
        self::assertSame(1, $resColl, 'Collision between location ID and location code must be rejected with non-zero exit code.');

        rewind($err);
        $errColl = stream_get_contents($err);
        self::assertIsString($errColl);
        self::assertStringContainsString("Error: El identificador de ubicación '500' es ambiguo", $errColl);

        // Verify NO id_almacen mutation on either row
        $rowAAfter = self::$locationQuery->findById(500);
        $rowBAfter = self::$locationQuery->findById(501);
        self::assertNotNull($rowAAfter);
        self::assertNotNull($rowBAfter);
        self::assertNull($rowAAfter['id_almacen'], 'Row A id_almacen must remain NULL after collision rejection.');
        self::assertNull($rowBAfter['id_almacen'], 'Row B id_almacen must remain NULL after collision rejection.');

        // Verify stock positions unchanged
        $stmtStock->execute([':id' => $stockA]);
        $stockAAfter = $stmtStock->fetch(PDO::FETCH_ASSOC);
        $stmtStock->execute([':id' => $stockB]);
        $stockBAfter = $stmtStock->fetch(PDO::FETCH_ASSOC);
        self::assertSame($stockABefore, $stockAAfter, 'Stock on location A must be unchanged.');
        self::assertSame($stockBBefore, $stockBAfter, 'Stock on location B must be unchanged.');

        // Verify counts unchanged
        $stmtCount->execute([':id' => $countA]);
        $countAAfter = $stmtCount->fetch(PDO::FETCH_ASSOC);
        $stmtCount->execute([':id' => $countB]);
        $countBAfter = $stmtCount->fetch(PDO::FETCH_ASSOC);
        self::assertSame($countABefore, $countAAfter, 'Count on location A must be unchanged.');
        self::assertSame($countBBefore, $countBAfter, 'Count on location B must be unchanged.');

        // 6. Both lookups resolving to same row (id == code)
        $pdo->exec("INSERT INTO ubicacion (id_ubicacion, codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) VALUES (600, '600', 'Same row loc', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $resSame = $handler->handleMapLocation(['600', (string) $whId]);
        self::assertSame(0, $resSame);
        $rowSame = self::$locationQuery->findById(600);
        self::assertNotNull($rowSame);
        self::assertSame($whId, $rowSame['id_almacen']);

        // 7. Malformed fractional token
        ftruncate($err, 0);
        rewind($err);
        $resFrac = $handler->handleMapLocation(['1.9', (string) $whId]);
        self::assertSame(1, $resFrac);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '1.9' no existe.", stream_get_contents($err));

        // 8. Scientific notation
        ftruncate($err, 0);
        rewind($err);
        $resSci = $handler->handleMapLocation(['1e2', (string) $whId]);
        self::assertSame(1, $resSci);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '1e2' no existe.", stream_get_contents($err));

        // 9. Zero
        ftruncate($err, 0);
        rewind($err);
        $resZero = $handler->handleMapLocation(['0', (string) $whId]);
        self::assertSame(1, $resZero);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '0' no existe.", stream_get_contents($err));

        // 10. Negative
        ftruncate($err, 0);
        rewind($err);
        $resNeg = $handler->handleMapLocation(['-1', (string) $whId]);
        self::assertSame(64, $resNeg);
        rewind($err);
        self::assertStringContainsString("Usage: php scripts/console.php map-location <location> <warehouse>", stream_get_contents($err));

        // 11. Nonexistent identifier
        ftruncate($err, 0);
        rewind($err);
        $resNon = $handler->handleMapLocation(['999999', (string) $whId]);
        self::assertSame(1, $resNon);
        rewind($err);
        self::assertStringContainsString("Error: La ubicación '999999' no existe.", stream_get_contents($err));
    }

    public function testWarehouseIdentifierResolutionContract(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-WH-RES', 'Sucursal Wh Res', 'Leon');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $handler = $this->createHandler($out, $err);

        // 1. Ordinary strict ID
        $whStrictId = self::$almacenCmd->create($branchId, 'ALM-STRICT-ID', 'Almacen Strict ID');
        $loc1 = $this->insertLegacyLocation('LOC-WH-1');
        $res1 = $handler->handleMapLocation([(string) $loc1, (string) $whStrictId]);
        self::assertSame(0, $res1);
        $locRow1 = self::$locationQuery->findById($loc1);
        self::assertNotNull($locRow1);
        self::assertSame($whStrictId, $locRow1['id_almacen']);

        // 2. Ordinary alphanumeric code
        $whAlphaId = self::$almacenCmd->create($branchId, 'ALM-ALPHA-CODE', 'Almacen Alpha Code');
        $loc2 = $this->insertLegacyLocation('LOC-WH-2');
        $res2 = $handler->handleMapLocation([(string) $loc2, 'ALM-ALPHA-CODE']);
        self::assertSame(0, $res2);
        $locRow2 = self::$locationQuery->findById($loc2);
        self::assertNotNull($locRow2);
        self::assertSame($whAlphaId, $locRow2['id_almacen']);

        // 3. Numeric code where no colliding ID exists
        $whNumCodeId = self::$almacenCmd->create($branchId, '7777', 'Almacen 7777');
        self::assertNull(self::$almacenQuery->findById(7777));
        $loc3 = $this->insertLegacyLocation('LOC-WH-3');
        $res3 = $handler->handleMapLocation([(string) $loc3, '7777']);
        self::assertSame(0, $res3);
        $locRow3 = self::$locationQuery->findById($loc3);
        self::assertNotNull($locRow3);
        self::assertSame($whNumCodeId, $locRow3['id_almacen']);

        // 4. Numeric ID where no colliding code exists
        $whNumId = self::$almacenCmd->create($branchId, 'ALM-NO-COLLIDE-CODE', 'Almacen No Collide');
        self::assertNull(self::$almacenQuery->findByCode((string) $whNumId));
        $loc4 = $this->insertLegacyLocation('LOC-WH-4');
        $res4 = $handler->handleMapLocation([(string) $loc4, (string) $whNumId]);
        self::assertSame(0, $res4);
        $locRow4 = self::$locationQuery->findById($loc4);
        self::assertNotNull($locRow4);
        self::assertSame($whNumId, $locRow4['id_almacen']);

        // 5. Numeric token where ID and code resolve to DIFFERENT rows (COLLISION)
        $pdo = self::$testDb->pdo();
        $pdo->exec("INSERT INTO almacen (id_almacen, id_sucursal, codigo, nombre, tipo, estado_activo, created_at, updated_at) VALUES (700, {$branchId}, 'ALM-ROW-A', 'Wh Row A', 'bodega', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO almacen (id_almacen, id_sucursal, codigo, nombre, tipo, estado_activo, created_at, updated_at) VALUES (701, {$branchId}, '700', 'Wh Row B', 'bodega', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        $locColl = $this->insertLegacyLocation('LOC-WH-COLLIDE');
        $prodId  = self::$prodCmd->register('Producto Collision Wh', '25.00');
        $stockColl = self::$stockCmd->createPosition($prodId, $locColl, '50.000');
        $countColl = self::$countCmd->record($stockColl, '50.000', 'Count Coll initial');

        $stmtStock = $pdo->prepare('SELECT id_stock, id_producto, id_ubicacion, cantidad FROM inventario_stock WHERE id_stock = :id');
        $stmtCount = $pdo->prepare('SELECT id_conteo, id_stock, cantidad_sistema, cantidad_contada, diferencia, notas, created_at FROM conteo_inventario WHERE id_conteo = :id');

        $stmtStock->execute([':id' => $stockColl]);
        $stockBefore = $stmtStock->fetch(PDO::FETCH_ASSOC);

        $stmtCount->execute([':id' => $countColl]);
        $countBefore = $stmtCount->fetch(PDO::FETCH_ASSOC);

        ftruncate($err, 0);
        rewind($err);
        $resColl = $handler->handleMapLocation([(string) $locColl, '700']);
        self::assertSame(1, $resColl, 'Collision between warehouse ID and warehouse code must be rejected with non-zero exit code.');

        rewind($err);
        $errColl = stream_get_contents($err);
        self::assertIsString($errColl);
        self::assertStringContainsString("Error: El identificador de almacén '700' es ambiguo", $errColl);

        // Verify NO id_almacen mutation
        $locCollAfter = self::$locationQuery->findById($locColl);
        self::assertNotNull($locCollAfter);
        self::assertNull($locCollAfter['id_almacen'], 'Location id_almacen must remain NULL after collision rejection.');

        // Verify stock unchanged
        $stmtStock->execute([':id' => $stockColl]);
        $stockAfter = $stmtStock->fetch(PDO::FETCH_ASSOC);
        self::assertSame($stockBefore, $stockAfter, 'Stock on location must be unchanged.');

        // Verify counts unchanged
        $stmtCount->execute([':id' => $countColl]);
        $countAfter = $stmtCount->fetch(PDO::FETCH_ASSOC);
        self::assertSame($countBefore, $countAfter, 'Count on location must be unchanged.');

        // 6. Both lookups resolving to same row (id == code)
        $pdo->exec("INSERT INTO almacen (id_almacen, id_sucursal, codigo, nombre, tipo, estado_activo, created_at, updated_at) VALUES (800, {$branchId}, '800', 'Same row wh', 'bodega', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $locSame = $this->insertLegacyLocation('LOC-WH-SAME');
        $resSame = $handler->handleMapLocation([(string) $locSame, '800']);
        self::assertSame(0, $resSame);
        $locSameAfter = self::$locationQuery->findById($locSame);
        self::assertNotNull($locSameAfter);
        self::assertSame(800, $locSameAfter['id_almacen']);

        // 7. Malformed fractional token
        $locFrac = $this->insertLegacyLocation('LOC-WH-FRAC');
        ftruncate($err, 0);
        rewind($err);
        $resFrac = $handler->handleMapLocation([(string) $locFrac, '1.9']);
        self::assertSame(1, $resFrac);
        rewind($err);
        self::assertStringContainsString("Error: El almacén '1.9' no existe.", stream_get_contents($err));
        $rowFrac = self::$locationQuery->findById($locFrac);
        self::assertNotNull($rowFrac);
        self::assertNull($rowFrac['id_almacen']);

        // 8. Scientific notation
        $locSci = $this->insertLegacyLocation('LOC-WH-SCI');
        ftruncate($err, 0);
        rewind($err);
        $resSci = $handler->handleMapLocation([(string) $locSci, '1e2']);
        self::assertSame(1, $resSci);
        rewind($err);
        self::assertStringContainsString("Error: El almacén '1e2' no existe.", stream_get_contents($err));
        $rowSci = self::$locationQuery->findById($locSci);
        self::assertNotNull($rowSci);
        self::assertNull($rowSci['id_almacen']);

        // 9. Zero
        $locZero = $this->insertLegacyLocation('LOC-WH-ZERO');
        ftruncate($err, 0);
        rewind($err);
        $resZero = $handler->handleMapLocation([(string) $locZero, '0']);
        self::assertSame(1, $resZero);
        rewind($err);
        self::assertStringContainsString("Error: El almacén '0' no existe.", stream_get_contents($err));
        $rowZero = self::$locationQuery->findById($locZero);
        self::assertNotNull($rowZero);
        self::assertNull($rowZero['id_almacen']);

        // 10. Negative
        $locNeg = $this->insertLegacyLocation('LOC-WH-NEG');
        ftruncate($err, 0);
        rewind($err);
        $resNeg = $handler->handleMapLocation([(string) $locNeg, '-1']);
        self::assertSame(64, $resNeg);
        rewind($err);
        self::assertStringContainsString("Usage: php scripts/console.php map-location <location> <warehouse>", stream_get_contents($err));
        $rowNeg = self::$locationQuery->findById($locNeg);
        self::assertNotNull($rowNeg);
        self::assertNull($rowNeg['id_almacen']);

        // 11. Nonexistent identifier
        $locNon = $this->insertLegacyLocation('LOC-WH-NON');
        ftruncate($err, 0);
        rewind($err);
        $resNon = $handler->handleMapLocation([(string) $locNon, '999999']);
        self::assertSame(1, $resNon);
        rewind($err);
        self::assertStringContainsString("Error: El almacén '999999' no existe.", stream_get_contents($err));
        $rowNon = self::$locationQuery->findById($locNon);
        self::assertNotNull($rowNon);
        self::assertNull($rowNon['id_almacen']);
    }
}
