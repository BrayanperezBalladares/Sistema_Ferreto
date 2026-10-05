<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MultisiteMigrationTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static string $migrationsPath;

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
