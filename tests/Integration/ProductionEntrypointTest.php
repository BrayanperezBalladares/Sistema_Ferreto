<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

final class ProductionEntrypointTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$config = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb = new Database(self::$config, useTestDatabase: true);
        self::$devDb  = new Database(self::$config, useTestDatabase: false);

        self::assertTestDatabaseIsolated(self::$testDb, self::$config);
        self::recordInitialDevState(self::$devDb);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$config);
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'producto', 'categoria', 'usuario'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE `{$tbl}`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testAnonymousProtectedRoutesRedirectToLogin(): void
    {
        $protectedRoutes = ['/products', '/locations', '/inventory', '/inventory/counts', '/branches', '/warehouses'];

        foreach ($protectedRoutes as $route) {
            $res = $this->runEntrypointRequest('GET', $route);
            self::assertSame(303, $res['status'], "Route {$route} must redirect anonymous access with 303.");
            self::assertSame(0, $res['exitCode']);
        }
    }

    public function testAuthenticatedAdminGetProductsRendersShellWithIdentity(): void
    {
        $username = 'admin_entrypoint';
        $adminId = $this->createAuthUser(self::$testDb, $username, 'administrador', 'activo');

        $res = $this->runEntrypointRequest('GET', '/products', $adminId);

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringContainsString($username, $res['stdout']);
        self::assertStringContainsString('Administrador', $res['stdout']);
        self::assertStringContainsString('action="/logout"', $res['stdout']);
        self::assertStringContainsString('Productos', $res['stdout']);
    }

    public function testAuthenticatedCajeroPermittedCatalogDeniedInventory(): void
    {
        $cajeroId = $this->createAuthUser(self::$testDb, 'cajero_entrypoint', 'cajero', 'activo');

        $catalogRes = $this->runEntrypointRequest('GET', '/products', $cajeroId);
        self::assertSame(200, $catalogRes['status']);
        self::assertSame(0, $catalogRes['exitCode']);
        self::assertStringContainsString('Cajero', $catalogRes['stdout']);
        self::assertStringContainsString('Productos', $catalogRes['stdout']);

        $inventoryRes = $this->runEntrypointRequest('GET', '/inventory', $cajeroId);
        self::assertSame(403, $inventoryRes['status']);
        self::assertSame(0, $inventoryRes['exitCode']);
        self::assertStringContainsString('Forbidden', $inventoryRes['stdout']);
    }

    public function testAnonymousGetLoginServesLoginPage(): void
    {
        $res = $this->runEntrypointRequest('GET', '/login');

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringContainsString('Iniciar sesión', $res['stdout']);
    }

    public function testPublicHealthProbeAllowedWithoutAuth(): void
    {
        $res = $this->runEntrypointRequest('GET', '/health');

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringContainsString('Foundation health', $res['stdout']);
    }

    public function testAuthenticatedAdminCanAccessBranchesAndWarehouses(): void
    {
        $adminId = $this->createAuthUser(self::$testDb, 'admin_facility_ep', 'administrador', 'activo');

        $branchesRes = $this->runEntrypointRequest('GET', '/branches', $adminId);
        self::assertSame(200, $branchesRes['status']);
        self::assertSame(0, $branchesRes['exitCode']);
        self::assertStringContainsString('Sucursales', $branchesRes['stdout']);

        $warehousesRes = $this->runEntrypointRequest('GET', '/warehouses', $adminId);
        self::assertSame(200, $warehousesRes['status']);
        self::assertSame(0, $warehousesRes['exitCode']);
        self::assertStringContainsString('Almacenes', $warehousesRes['stdout']);
    }

    public function testAuthenticatedBodegueroCanAccessWarehousesDeniedBranches(): void
    {
        $bodegueroId = $this->createAuthUser(self::$testDb, 'bod_facility_ep', 'bodeguero', 'activo');

        $warehousesRes = $this->runEntrypointRequest('GET', '/warehouses', $bodegueroId);
        self::assertSame(200, $warehousesRes['status']);
        self::assertSame(0, $warehousesRes['exitCode']);
        self::assertStringContainsString('Almacenes', $warehousesRes['stdout']);

        $branchesRes = $this->runEntrypointRequest('GET', '/branches', $bodegueroId);
        self::assertSame(403, $branchesRes['status']);
        self::assertSame(0, $branchesRes['exitCode']);
        self::assertStringContainsString('Forbidden', $branchesRes['stdout']);
    }
}
