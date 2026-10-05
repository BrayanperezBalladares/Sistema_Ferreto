<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\AlmacenCommand;
use App\Modules\Inventory\AlmacenQuery;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\LocationQuery;
use App\Modules\Inventory\MultisiteCliHandler;
use App\Modules\Inventory\SucursalCommand;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class MultisiteMigrationTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static string $migrationsPath;

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
        self::$migrationsPath = dirname(__DIR__, 2) . '/database/migrations';
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

    public function testFreshDatabaseReleaseOneExecution(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();

        // Verify sucursal table structure
        self::assertSame('sucursal', $pdo->query("SHOW TABLES LIKE 'sucursal'")->fetchColumn());
        $sucursalCols = $pdo->query('SHOW COLUMNS FROM sucursal')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('id_sucursal', $sucursalCols);
        self::assertContains('codigo', $sucursalCols);
        self::assertContains('nombre', $sucursalCols);
        self::assertContains('ciudad', $sucursalCols);
        self::assertContains('direccion', $sucursalCols);
        self::assertContains('telefono', $sucursalCols);
        self::assertContains('estado_activo', $sucursalCols);

        // Verify almacen table structure
        self::assertSame('almacen', $pdo->query("SHOW TABLES LIKE 'almacen'")->fetchColumn());
        $almacenCols = $pdo->query('SHOW COLUMNS FROM almacen')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('id_almacen', $almacenCols);
        self::assertContains('id_sucursal', $almacenCols);
        self::assertContains('codigo', $almacenCols);
        self::assertContains('nombre', $almacenCols);
        self::assertContains('tipo', $almacenCols);
        self::assertContains('estado_activo', $almacenCols);

        // Verify ubicacion table has nullable id_almacen
        $ubicacionCols = $pdo->query('SHOW COLUMNS FROM ubicacion')->fetchAll(PDO::FETCH_ASSOC);
        $idAlmacenCol = null;
        foreach ($ubicacionCols as $col) {
            if ($col['Field'] === 'id_almacen') {
                $idAlmacenCol = $col;
                break;
            }
        }
        self::assertNotNull($idAlmacenCol);
        self::assertSame('YES', $idAlmacenCol['Null'], 'In Release 1, ubicacion.id_almacen must be nullable.');

        // Verify migration history recorded
        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('0008_create_sucursal', $history);
        self::assertContains('0009_create_almacen', $history);
        self::assertContains('0010_add_ubicacion_almacen_nullable', $history);
        self::assertNotContains('0011_enforce_ubicacion_almacen_not_null', $history, '0011 must NOT exist in Release 1.');
    }

    public function testPopulatedDatabaseUpgradePreservesExistingData(): void
    {
        $runner = new MigrationRunner(self::$testDb);

        // Run migrations up to 0007 using a temp directory or subset
        // Let's create an isolated populated state before 0008-0010
        // We can run 0001 to 0007 first
        $tempDir = sys_get_temp_dir() . '/ferreto_pop_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0777, true);

        for ($i = 1; $i <= 7; $i++) {
            $prefix = sprintf('%04d', $i);
            $files = glob(self::$migrationsPath . "/{$prefix}_*.sql") ?: [];
            foreach ($files as $f) {
                copy($f, $tempDir . '/' . basename($f));
            }
        }

        $runner->run($tempDir);

        $pdo = self::$testDb->pdo();
        // Insert product, location, stock, count matching development scenario
        $pdo->exec("INSERT INTO producto (id_producto, nombre, precio_actual) VALUES (2, 'Martillo Galponero', 15.50)");
        $pdo->exec("INSERT INTO ubicacion (id_ubicacion, codigo, descripcion, estado_activo) VALUES (1, 'CENTRAL', 'Bodega Central', 1)");
        $pdo->exec("INSERT INTO ubicacion (id_ubicacion, codigo, descripcion, estado_activo) VALUES (2, 'bod-a2', 'Bodega calle 2', 1)");
        $pdo->exec("INSERT INTO inventario_stock (id_stock, id_producto, id_ubicacion, cantidad) VALUES (1, 2, 1, 2000.000)");
        $pdo->exec("INSERT INTO inventario_stock (id_stock, id_producto, id_ubicacion, cantidad) VALUES (2, 2, 2, 300.000)");
        $pdo->exec("INSERT INTO conteo_inventario (id_conteo, id_stock, cantidad_sistema, cantidad_contada, diferencia, created_at) VALUES (1, 2, 300.000, 250.000, -50.000, UTC_TIMESTAMP())");

        // Now upgrade by running the full migrations directory (running 0008, 0009, 0010)
        $runner->run(self::$migrationsPath);

        // Verify existing locations survived with id_almacen IS NULL
        $locations = $pdo->query('SELECT * FROM ubicacion ORDER BY id_ubicacion ASC')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $locations);

        self::assertSame(1, (int) $locations[0]['id_ubicacion']);
        self::assertSame('CENTRAL', $locations[0]['codigo']);
        self::assertSame('Bodega Central', $locations[0]['descripcion']);
        self::assertNull($locations[0]['id_almacen']);

        self::assertSame(2, (int) $locations[1]['id_ubicacion']);
        self::assertSame('bod-a2', $locations[1]['codigo']);
        self::assertSame('Bodega calle 2', $locations[1]['descripcion']);
        self::assertNull($locations[1]['id_almacen']);

        // Verify stock positions preserved
        $stocks = $pdo->query('SELECT * FROM inventario_stock ORDER BY id_stock ASC')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $stocks);
        self::assertSame(1, (int) $stocks[0]['id_stock']);
        self::assertSame(2, (int) $stocks[0]['id_producto']);
        self::assertSame(1, (int) $stocks[0]['id_ubicacion']);
        self::assertSame('2000.000', $stocks[0]['cantidad']);

        self::assertSame(2, (int) $stocks[1]['id_stock']);
        self::assertSame(2, (int) $stocks[1]['id_producto']);
        self::assertSame(2, (int) $stocks[1]['id_ubicacion']);
        self::assertSame('300.000', $stocks[1]['cantidad']);

        // Verify count history preserved
        $counts = $pdo->query('SELECT * FROM conteo_inventario')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $counts);
        self::assertSame(1, (int) $counts[0]['id_conteo']);
        self::assertSame(2, (int) $counts[0]['id_stock']);
        self::assertSame('300.000', $counts[0]['cantidad_sistema']);
        self::assertSame('250.000', $counts[0]['cantidad_contada']);
        self::assertSame('-50.000', $counts[0]['diferencia']);

        // Cleanup temp dir
        $files = glob($tempDir . '/*') ?: [];
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($tempDir);
    }

    public function testRepeatMigrationIsIdempotent(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        // Second execution should do nothing and not throw
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();
        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('0008_create_sucursal', $history);
        self::assertContains('0009_create_almacen', $history);
        self::assertContains('0010_add_ubicacion_almacen_nullable', $history);
    }

    public function testReversalDependencyOrdering(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();

        // Reverting in correct dependency order
        $runner->revert('0010_add_ubicacion_almacen_nullable', self::$migrationsPath);
        $ubicacionCols = $pdo->query('SHOW COLUMNS FROM ubicacion')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('id_almacen', $ubicacionCols);

        $runner->revert('0009_create_almacen', self::$migrationsPath);
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'almacen'")->fetchColumn());

        $runner->revert('0008_create_sucursal', self::$migrationsPath);
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'sucursal'")->fetchColumn());

        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('0008_create_sucursal', $history);
        self::assertNotContains('0009_create_almacen', $history);
        self::assertNotContains('0010_add_ubicacion_almacen_nullable', $history);

        // Re-applying works seamlessly
        $runner->run(self::$migrationsPath);
        self::assertSame('sucursal', $pdo->query("SHOW TABLES LIKE 'sucursal'")->fetchColumn());
        self::assertSame('almacen', $pdo->query("SHOW TABLES LIKE 'almacen'")->fetchColumn());
    }

    public function testPartialMigrationFailureAndRetryBehavior(): void
    {
        $tempDir = $this->createFixtureDir([]);
        for ($i = 1; $i <= 8; $i++) {
            $prefix = sprintf('%04d', $i);
            $files = glob(self::$migrationsPath . "/{$prefix}_*.sql") ?: [];
            foreach ($files as $f) {
                copy($f, $tempDir . '/' . basename($f));
            }
        }
        file_put_contents($tempDir . '/0009_create_almacen.up.sql', 'THIS IS NOT VALID SQL FOR ALMACEN;');

        $runner = new MigrationRunner(self::$testDb);
        $threw = false;
        try {
            $runner->run($tempDir);
        } catch (Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'MigrationRunner must throw on invalid migration SQL.');

        $pdo = self::$testDb->pdo();
        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('0008_create_sucursal', $history);
        self::assertNotContains('0009_create_almacen', $history, 'Failed migration 0009 must not be recorded.');
        self::assertSame('sucursal', $pdo->query("SHOW TABLES LIKE 'sucursal'")->fetchColumn());
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'almacen'")->fetchColumn());

        // Fix 0009 and add 0010
        $files9 = glob(self::$migrationsPath . '/0009_*.sql') ?: [];
        foreach ($files9 as $f) {
            copy($f, $tempDir . '/' . basename($f));
        }
        $files10 = glob(self::$migrationsPath . '/0010_*.sql') ?: [];
        foreach ($files10 as $f) {
            copy($f, $tempDir . '/' . basename($f));
        }

        // Retry migration runner: succeeds without throwing
        $runner->run($tempDir);

        $newHistory = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('0009_create_almacen', $newHistory);
        self::assertContains('0010_add_ubicacion_almacen_nullable', $newHistory);
        self::assertSame('almacen', $pdo->query("SHOW TABLES LIKE 'almacen'")->fetchColumn());
        $ubicacionCols = $pdo->query('SHOW COLUMNS FROM ubicacion')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('id_almacen', $ubicacionCols);
    }

    public function testMixedMappedAndUnmappedAdaptState(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $tx = new Transaction(self::$testDb);
        $sucCmd = new SucursalCommand($tx);
        $almCmd = new AlmacenCommand($tx);
        $locCmd = new LocationCommand($tx);
        $locQuery = new LocationQuery(self::$testDb);

        $sucId = $sucCmd->create('SUC-ADAPT', 'Sucursal Adapt', 'Granada');
        $almId = $almCmd->create($sucId, 'ALM-ADAPT', 'Almacen Adapt', 'bodega');

        // Unmapped legacy location: id_almacen is NULL
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "INSERT INTO ubicacion (codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) "
            . "VALUES ('LOC-LEGACY', 'Ubicacion Antigua', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $legacyId = (int) $pdo->lastInsertId();

        // Mapped location: id_almacen is $almId
        $mappedId = $locCmd->create('LOC-MODERN', $almId, 'Ubicacion Nueva');

        // Query all locations
        $all = $locQuery->all();
        self::assertCount(2, $all);

        $unmappedRow = null;
        $mappedRow = null;
        foreach ($all as $loc) {
            if ($loc['codigo'] === 'LOC-LEGACY') {
                $unmappedRow = $loc;
            } elseif ($loc['codigo'] === 'LOC-MODERN') {
                $mappedRow = $loc;
            }
        }

        self::assertNotNull($unmappedRow);
        self::assertSame($legacyId, $unmappedRow['id_ubicacion']);
        self::assertNull($unmappedRow['id_almacen']);
        self::assertNull($unmappedRow['almacen_codigo']);
        self::assertNull($unmappedRow['almacen_nombre']);
        self::assertNull($unmappedRow['sucursal_codigo']);
        self::assertNull($unmappedRow['sucursal_nombre']);

        self::assertNotNull($mappedRow);
        self::assertSame($mappedId, $mappedRow['id_ubicacion']);
        self::assertSame($almId, $mappedRow['id_almacen']);
        self::assertSame('ALM-ADAPT', $mappedRow['almacen_codigo']);
        self::assertSame('Almacen Adapt', $mappedRow['almacen_nombre']);
        self::assertSame('SUC-ADAPT', $mappedRow['sucursal_codigo']);
        self::assertSame('Sucursal Adapt', $mappedRow['sucursal_nombre']);

        // Test findById nullable safety
        $foundLegacy = $locQuery->findById($legacyId);
        self::assertNotNull($foundLegacy);
        self::assertNull($foundLegacy['id_almacen']);
        self::assertNull($foundLegacy['almacen_codigo']);

        $foundMapped = $locQuery->findById($mappedId);
        self::assertNotNull($foundMapped);
        self::assertSame($almId, $foundMapped['id_almacen']);
        self::assertSame('ALM-ADAPT', $foundMapped['almacen_codigo']);
    }

    public function testVerifyLocationsMappedFailsWhenUnmappedRowsExist(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "INSERT INTO ubicacion (codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) "
            . "VALUES ('LOC-UNMAP-1', 'Legacy 1', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = new MultisiteCliHandler(
            self::$testDb,
            new LocationQuery(self::$testDb),
            new AlmacenQuery(self::$testDb),
            $out,
            $err
        );

        $exitCode = $handler->handleVerifyLocationsMapped([]);
        self::assertSame(1, $exitCode);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString('Error: Se encontraron 1 ubicación(es) sin asignar a un almacén:', $stderr);
        self::assertStringContainsString('LOC-UNMAP-1', $stderr);
    }

    public function testVerifyLocationsMappedSucceedsWhenAllRowsAreMapped(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $tx = new Transaction(self::$testDb);
        $sucCmd = new SucursalCommand($tx);
        $almCmd = new AlmacenCommand($tx);
        $locCmd = new LocationCommand($tx);

        $sucId = $sucCmd->create('SUC-MAP-ALL', 'Sucursal All', 'Managua');
        $almId = $almCmd->create($sucId, 'ALM-MAP-ALL', 'Almacen All');
        $locCmd->create('LOC-MAP-1', $almId, 'Ubicacion 1');
        $locCmd->create('LOC-MAP-2', $almId, 'Ubicacion 2');

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = new MultisiteCliHandler(
            self::$testDb,
            new LocationQuery(self::$testDb),
            new AlmacenQuery(self::$testDb),
            $out,
            $err
        );

        $exitCode = $handler->handleVerifyLocationsMapped([]);
        self::assertSame(0, $exitCode);

        rewind($out);
        $stdout = stream_get_contents($out);
        self::assertIsString($stdout);
        self::assertStringContainsString('Verificación exitosa: Todas las ubicaciones están mapeadas a un almacén.', $stdout);
    }

    public function testMigrationResetAndHistoryConsistency(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();
        /** @var list<string> $baselineTables */
        $baselineTables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);

        /** @var array<string, list<array<string, mixed>>> $baselineColumns */
        $baselineColumns = [];
        foreach ($baselineTables as $tbl) {
            if ($tbl !== 'schema_migrations') {
                /** @var list<array<string, mixed>> $cols */
                $cols = $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                $baselineColumns[$tbl] = $cols;
            }
        }

        // Revert all migrations in dependency order
        $downOrder = [
            '0010_add_ubicacion_almacen_nullable',
            '0009_create_almacen',
            '0008_create_sucursal',
            '0007_create_usuario',
            '0006_create_conteo_inventario',
            '0005_create_inventario_stock',
            '0004_create_ubicacion',
            '0003_create_producto',
            '0002_create_categoria',
            '0001_probe',
        ];
        foreach ($downOrder as $id) {
            $runner->revert($id, self::$migrationsPath);
        }

        // schema_migrations must be empty
        $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        self::assertSame(0, $count);

        // Only schema_migrations should remain
        $remainingTables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['schema_migrations'], $remainingTables);

        // Re-run all migrations
        $runner->run(self::$migrationsPath);

        /** @var list<string> $reappliedTables */
        $reappliedTables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame($baselineTables, $reappliedTables);

        foreach ($baselineTables as $tbl) {
            if ($tbl !== 'schema_migrations') {
                /** @var list<array<string, mixed>> $cols */
                $cols = $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                self::assertSame($baselineColumns[$tbl], $cols, "Table {$tbl} schema must match baseline after reset.");
            }
        }
    }

    public function testReleaseTwoContractCheckFailsWithUnmappedRowsAndSucceedsWhenMapped(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $runner->run(self::$migrationsPath);

        $pdo = self::$testDb->pdo();
        $tx = new Transaction(self::$testDb);
        $sucCmd = new SucursalCommand($tx);
        $almCmd = new AlmacenCommand($tx);

        $sucId = $sucCmd->create('SUC-C2', 'Sucursal Contract', 'Managua');
        $almId = $almCmd->create($sucId, 'ALM-C2', 'Almacen Contract');

        // Insert unmapped location with id_almacen IS NULL
        $pdo->exec(
            "INSERT INTO ubicacion (codigo, descripcion, estado_activo, id_almacen, created_at, updated_at) "
            . "VALUES ('LOC-C2-UNMAP', 'Unmapped Contract', 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        // In-memory test execution: ALTER TABLE to NOT NULL must fail due to unmapped row
        $threw = false;
        try {
            $pdo->exec('ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL');
        } catch (\PDOException $e) {
            $threw = true;
            self::assertNotEmpty($e->getMessage());
        }
        self::assertTrue($threw, 'Release 2 contract DDL must fail when unmapped rows (NULL) exist.');

        // Verify column remains nullable
        $colBefore = $pdo->query("SHOW COLUMNS FROM ubicacion WHERE Field = 'id_almacen'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($colBefore);
        self::assertSame('YES', $colBefore['Null']);

        // Now map the location to $almId
        $pdo->exec("UPDATE ubicacion SET id_almacen = {$almId} WHERE id_almacen IS NULL");

        // Release 2 contract DDL now succeeds
        $pdo->exec('ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL');

        // Verify column is now NOT NULL
        $colAfter = $pdo->query("SHOW COLUMNS FROM ubicacion WHERE Field = 'id_almacen'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($colAfter);
        self::assertSame('NO', $colAfter['Null'], 'Column id_almacen must be NOT NULL after successful Release 2 contract execution.');

        // Revert in-memory for clean state
        $pdo->exec('ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL');
        $colRevert = $pdo->query("SHOW COLUMNS FROM ubicacion WHERE Field = 'id_almacen'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($colRevert);
        self::assertSame('YES', $colRevert['Null']);
    }

    /** @param array<string, string> $files */
    private function createFixtureDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/ferreto_msmig_' . bin2hex(random_bytes(6));
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
