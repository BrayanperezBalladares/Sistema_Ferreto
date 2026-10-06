<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\AlmacenCommand;
use App\Modules\Inventory\AlmacenQuery;
use App\Modules\Inventory\SucursalCommand;
use App\Modules\Inventory\SucursalQuery;
use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class MultisiteDomainTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static SucursalQuery $sucursalQuery;
    private static SucursalCommand $sucursalCmd;
    private static AlmacenQuery $almacenQuery;
    private static AlmacenCommand $almacenCmd;

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

        $tx = new Transaction(self::$testDb);
        self::$sucursalQuery = new SucursalQuery(self::$testDb);
        self::$sucursalCmd   = new SucursalCommand($tx);
        self::$almacenQuery  = new AlmacenQuery(self::$testDb);
        self::$almacenCmd    = new AlmacenCommand($tx);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'almacen', 'sucursal'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE {$tbl}");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testBranchCreationAndRetrieval(): void
    {
        $id = self::$sucursalCmd->create('SUC-CENTRAL', 'Sucursal Central', 'Managua', 'Km 5 Carretera Norte', '2222-3333');
        self::assertGreaterThan(0, $id);

        $branch = self::$sucursalQuery->findById($id);
        self::assertNotNull($branch);
        self::assertSame($id, $branch['id_sucursal']);
        self::assertSame('SUC-CENTRAL', $branch['codigo']);
        self::assertSame('Sucursal Central', $branch['nombre']);
        self::assertSame('Managua', $branch['ciudad']);
        self::assertSame('Km 5 Carretera Norte', $branch['direccion']);
        self::assertSame('2222-3333', $branch['telefono']);
        self::assertSame(1, $branch['estado_activo']);
        self::assertNotEmpty($branch['created_at']);
        self::assertNotEmpty($branch['updated_at']);

        $byCode = self::$sucursalQuery->findByCode('SUC-CENTRAL');
        self::assertNotNull($byCode);
        self::assertSame($id, $byCode['id_sucursal']);
    }

    public function testBranchDuplicateCodeRejection(): void
    {
        self::$sucursalCmd->create('SUC-01', 'Sucursal 1', 'Leon');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El código de sucursal ya existe.');
        self::$sucursalCmd->create('SUC-01', 'Sucursal Duplicada', 'Chinandega');
    }

    public function testBranchEmptyCodeRejection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$sucursalCmd->create('', 'Sucursal', 'Granada');
    }

    public function testBranchEmptyNameRejection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$sucursalCmd->create('SUC-02', '   ', 'Granada');
    }

    public function testBranchEmptyCityRejection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$sucursalCmd->create('SUC-03', 'Sucursal 3', '');
    }

    public function testBranchUpdate(): void
    {
        $id = self::$sucursalCmd->create('SUC-MASAYA', 'Sucursal Masaya Original', 'Masaya');

        $updated = self::$sucursalCmd->update($id, 'Sucursal Masaya Nueva', 'Masaya Ciudad', 'Frente al parque', '8888-9999');
        self::assertTrue($updated);

        $branch = self::$sucursalQuery->findById($id);
        self::assertNotNull($branch);
        self::assertSame('Sucursal Masaya Nueva', $branch['nombre']);
        self::assertSame('Masaya Ciudad', $branch['ciudad']);
        self::assertSame('Frente al parque', $branch['direccion']);
        self::assertSame('8888-9999', $branch['telefono']);

        // Non-existent update returns false
        self::assertFalse(self::$sucursalCmd->update(99999, 'No existe', 'Ciudad'));

        // Empty field rejection on update
        $this->expectException(InvalidArgumentException::class);
        self::$sucursalCmd->update($id, '', 'Ciudad');
    }

    public function testBranchToggleActive(): void
    {
        $id = self::$sucursalCmd->create('SUC-MAT', 'Sucursal Matagalpa', 'Matagalpa');

        // Initial state is 1
        $branch = self::$sucursalQuery->findById($id);
        self::assertNotNull($branch);
        self::assertSame(1, $branch['estado_activo']);

        // Toggle to 0
        $toggled = self::$sucursalCmd->toggleActive($id);
        self::assertTrue($toggled);
        $branchDeactivated = self::$sucursalQuery->findById($id);
        self::assertNotNull($branchDeactivated);
        self::assertSame(0, $branchDeactivated['estado_activo']);

        // Toggle back to 1
        $toggledAgain = self::$sucursalCmd->toggleActive($id);
        self::assertTrue($toggledAgain);
        $branchReactivated = self::$sucursalQuery->findById($id);
        self::assertNotNull($branchReactivated);
        self::assertSame(1, $branchReactivated['estado_activo']);

        // Non-existent returns false
        self::assertFalse(self::$sucursalCmd->toggleActive(99999));
    }

    public function testWarehouseCreationWithValidTypes(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-WH-1', 'Sucursal Almacenes', 'Managua');

        $id1 = self::$almacenCmd->create($branchId, 'ALM-BOD', 'Almacén Bodega', 'bodega');
        $id2 = self::$almacenCmd->create($branchId, 'ALM-MOS', 'Almacén Mostrador', 'mostrador');
        $id3 = self::$almacenCmd->create($branchId, 'ALM-PAT', 'Almacén Patio', 'patio');
        $id4 = self::$almacenCmd->create($branchId, 'ALM-MER', 'Almacén Merma', 'merma');
        $id5 = self::$almacenCmd->create($branchId, 'ALM-DEF', 'Almacén Por Defecto'); // default bodega

        self::assertGreaterThan(0, $id1);
        self::assertGreaterThan(0, $id2);
        self::assertGreaterThan(0, $id3);
        self::assertGreaterThan(0, $id4);
        self::assertGreaterThan(0, $id5);

        $wh5 = self::$almacenQuery->findById($id5);
        self::assertNotNull($wh5);
        self::assertSame('bodega', $wh5['tipo']);
        self::assertSame(1, $wh5['estado_activo']);
    }

    public function testWarehouseInvalidTypeRejection(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-INV-TYPE', 'Sucursal Tipo', 'Managua');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tipo de almacén no válido.');
        self::$almacenCmd->create($branchId, 'ALM-INV', 'Almacén Inválido', 'tipo_invalido');
    }

    public function testWarehouseEmptyCodeRejection(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-EMPTY-1', 'Sucursal Empty 1', 'Managua');
        $this->expectException(InvalidArgumentException::class);
        self::$almacenCmd->create($branchId, '', 'Almacén');
    }

    public function testWarehouseEmptyNameRejection(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-EMPTY-2', 'Sucursal Empty 2', 'Managua');
        $this->expectException(InvalidArgumentException::class);
        self::$almacenCmd->create($branchId, 'ALM-01', '   ');
    }

    public function testWarehouseDuplicateCodeRejection(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-DUP', 'Sucursal Dup', 'Managua');
        self::$almacenCmd->create($branchId, 'ALM-DUP', 'Almacén 1');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El código de almacén ya existe.');
        self::$almacenCmd->create($branchId, 'ALM-DUP', 'Almacén Duplicado');
    }

    public function testWarehouseStructuralCreationGuardRejectsInactiveBranch(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-INACTIVE', 'Sucursal Inactiva', 'Managua');
        self::$sucursalCmd->toggleActive($branchId); // Deactivate branch

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede crear un almacén en una sucursal inactiva o inexistente.');
        self::$almacenCmd->create($branchId, 'ALM-FAIL', 'Almacén Falla');
    }

    public function testWarehouseStructuralCreationGuardRejectsNonExistentBranch(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede crear un almacén en una sucursal inactiva o inexistente.');
        self::$almacenCmd->create(99999, 'ALM-NON', 'Almacén No Existe');
    }

    public function testInactiveBranchDoesNotCascadeStatusToWarehouse(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-NO-CASCADE', 'Sucursal No Cascade', 'Managua');
        $whId = self::$almacenCmd->create($branchId, 'ALM-ACTIVE', 'Almacén Que Queda Activo');

        // Deactivate parent branch
        self::$sucursalCmd->toggleActive($branchId);
        $branch = self::$sucursalQuery->findById($branchId);
        self::assertNotNull($branch);
        self::assertSame(0, $branch['estado_activo']);

        // Warehouse itself remains active in its own state
        $warehouse = self::$almacenQuery->findById($whId);
        self::assertNotNull($warehouse);
        self::assertSame(1, $warehouse['estado_activo'], 'Deactivating parent branch must not cascade to warehouse estado_activo.');
    }

    public function testReactivationGuardProhibitsWarehouseReactivationUnderInactiveBranch(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-REACT', 'Sucursal React', 'Managua');
        $whId = self::$almacenCmd->create($branchId, 'ALM-REACT', 'Almacén React');

        // Deactivate warehouse while branch is still active (permitted)
        self::assertTrue(self::$almacenCmd->toggleActive($whId));
        $whDeactivated = self::$almacenQuery->findById($whId);
        self::assertNotNull($whDeactivated);
        self::assertSame(0, $whDeactivated['estado_activo']);

        // Now deactivate branch
        self::$sucursalCmd->toggleActive($branchId);

        // Attempt to reactivate warehouse: must be rejected by reactivation guard
        try {
            self::$almacenCmd->toggleActive($whId);
            self::fail('Expected DomainException when reactivating warehouse under inactive branch');
        } catch (DomainException $e) {
            self::assertSame('No se puede reactivar un almacén cuya sucursal está inactiva.', $e->getMessage());
        }

        // Warehouse must remain inactive
        $whStillInactive = self::$almacenQuery->findById($whId);
        self::assertNotNull($whStillInactive);
        self::assertSame(0, $whStillInactive['estado_activo']);

        // Reactivate parent branch
        self::$sucursalCmd->toggleActive($branchId);

        // Reactivating warehouse must now succeed
        self::assertTrue(self::$almacenCmd->toggleActive($whId));
        $whReactivated = self::$almacenQuery->findById($whId);
        self::assertNotNull($whReactivated);
        self::assertSame(1, $whReactivated['estado_activo']);
    }

    public function testWarehouseUpdatePreservesParentBranch(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-IMM', 'Sucursal Inmutable', 'Managua');
        $whId = self::$almacenCmd->create($branchId, 'ALM-IMM', 'Nombre Viejo', 'bodega');

        // Update warehouse name and type
        $updated = self::$almacenCmd->update($whId, 'Nombre Nuevo', 'patio');
        self::assertTrue($updated);

        $wh = self::$almacenQuery->findById($whId);
        self::assertNotNull($wh);
        self::assertSame('Nombre Nuevo', $wh['nombre']);
        self::assertSame('patio', $wh['tipo']);
        self::assertSame($branchId, $wh['id_sucursal'], 'Warehouse update must preserve parent branch id_sucursal.');

        // Non-existent warehouse update returns false
        self::assertFalse(self::$almacenCmd->update(99999, 'Otro', 'bodega'));

        // Invalid type rejection
        $this->expectException(InvalidArgumentException::class);
        self::$almacenCmd->update($whId, 'Nombre', 'tipo_invalido');
    }

    public function testQueriesReturnAccurateDataMatchingTypes(): void
    {
        $bId1 = self::$sucursalCmd->create('SUC-B', 'Branch B', 'Leon');
        $bId2 = self::$sucursalCmd->create('SUC-A', 'Branch A', 'Managua');
        self::$sucursalCmd->toggleActive($bId1); // Inactive

        // SucursalQuery: all() ordered by nombre ASC
        $allBranches = self::$sucursalQuery->all();
        self::assertCount(2, $allBranches);
        self::assertSame('Branch A', $allBranches[0]['nombre']);
        self::assertSame('Branch B', $allBranches[1]['nombre']);

        // SucursalQuery: findAll() vs findActive()
        self::assertCount(2, self::$sucursalQuery->findAll());
        $activeBranches = self::$sucursalQuery->findActive();
        self::assertCount(1, $activeBranches);
        self::assertSame($bId2, $activeBranches[0]['id_sucursal']);

        // Warehouses under Branch A
        $wId1 = self::$almacenCmd->create($bId2, 'W-ZETA', 'Zeta Warehouse', 'mostrador');
        $wId2 = self::$almacenCmd->create($bId2, 'W-ALFA', 'Alfa Warehouse', 'bodega');
        self::$almacenCmd->toggleActive($wId1); // Inactive

        // AlmacenQuery: all() ordered by nombre ASC with join
        $allWarehouses = self::$almacenQuery->all();
        self::assertCount(2, $allWarehouses);
        self::assertSame('Alfa Warehouse', $allWarehouses[0]['nombre']);
        self::assertSame('W-ALFA', $allWarehouses[0]['codigo']);
        self::assertSame('bodega', $allWarehouses[0]['tipo']);
        self::assertSame('SUC-A', $allWarehouses[0]['sucursal_codigo']);
        self::assertSame('Branch A', $allWarehouses[0]['sucursal_nombre']);

        // AlmacenQuery: findActive()
        $activeWarehouses = self::$almacenQuery->findActive();
        self::assertCount(1, $activeWarehouses);
        self::assertSame($wId2, $activeWarehouses[0]['id_almacen']);

        // AlmacenQuery: findByBranch()
        $branchWarehouses = self::$almacenQuery->findByBranch($bId2);
        self::assertCount(2, $branchWarehouses);
        $activeBranchWarehouses = self::$almacenQuery->findByBranch($bId2, activeOnly: true);
        self::assertCount(1, $activeBranchWarehouses);

        // AlmacenQuery: findByCode()
        $byCode = self::$almacenQuery->findByCode('W-ALFA');
        self::assertNotNull($byCode);
        self::assertSame($wId2, $byCode['id_almacen']);
    }

    public function testBranchCodeFormatValidation(): void
    {
        // Positive tests
        $id1 = self::$sucursalCmd->create('SUC-01', 'Sucursal Uno', 'Managua');
        self::assertGreaterThan(0, $id1);

        $id2 = self::$sucursalCmd->create('suc_dos', 'Sucursal Dos', 'Leon');
        self::assertGreaterThan(0, $id2);

        $id3 = self::$sucursalCmd->create('S12345678901234567890123456789', 'Sucursal Max', 'Granada'); // 30 chars
        self::assertGreaterThan(0, $id3);

        // Negative tests: spaces, !, @, punctuation, over-length
        $invalidCodes = [
            'SUC 01',
            'SUC CENTRAL',
            'SUC!',
            'SUC@01',
            'SUC#1',
            'SUC.01',
            'SUC/01',
            str_repeat('A', 31),
        ];

        foreach ($invalidCodes as $badCode) {
            try {
                self::$sucursalCmd->create($badCode, 'Nombre Valido', 'Ciudad');
                self::fail("Expected InvalidArgumentException for invalid branch code: '{$badCode}'");
            } catch (InvalidArgumentException $e) {
                self::assertSame('El código de sucursal no es válido.', $e->getMessage());
            }

            $validation = \App\Modules\Inventory\BranchValidator::validateBranch([
                'codigo' => $badCode,
                'nombre' => 'Nombre Valido',
                'ciudad' => 'Ciudad',
            ]);
            self::assertFalse($validation->valid(), "BranchValidator should reject code: '{$badCode}'");
            self::assertArrayHasKey('codigo', $validation->fieldErrors);
        }
    }

    public function testWarehouseCodeFormatValidation(): void
    {
        $branchId = self::$sucursalCmd->create('SUC-WH-TEST', 'Sucursal WH Test', 'Esteli');

        // Positive tests
        $wId1 = self::$almacenCmd->create($branchId, 'ALM-01', 'Almacen Uno');
        self::assertGreaterThan(0, $wId1);

        $wId2 = self::$almacenCmd->create($branchId, 'alm_dos', 'Almacen Dos');
        self::assertGreaterThan(0, $wId2);

        $wId3 = self::$almacenCmd->create($branchId, 'W12345678901234567890123456789', 'Almacen Max'); // 30 chars
        self::assertGreaterThan(0, $wId3);

        // Negative tests
        $invalidCodes = [
            'ALM 01',
            'ALM CENTRAL',
            'ALM!',
            'ALM@01',
            'ALM#1',
            'ALM.01',
            'ALM/01',
            str_repeat('W', 31),
        ];

        foreach ($invalidCodes as $badCode) {
            try {
                self::$almacenCmd->create($branchId, $badCode, 'Nombre Valido', 'bodega');
                self::fail("Expected InvalidArgumentException for invalid warehouse code: '{$badCode}'");
            } catch (InvalidArgumentException $e) {
                self::assertSame('El código de almacén no es válido.', $e->getMessage());
            }

            $validation = \App\Modules\Inventory\WarehouseValidator::validateWarehouse([
                'id_sucursal' => $branchId,
                'codigo'      => $badCode,
                'nombre'      => 'Nombre Valido',
                'tipo'        => 'bodega',
            ]);
            self::assertFalse($validation->valid(), "WarehouseValidator should reject code: '{$badCode}'");
            self::assertArrayHasKey('codigo', $validation->fieldErrors);
        }
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);

        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        self::assertDevDatabaseUntouched($devDb, self::$testConfig);
    }
}
