<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{AlmacenCommand, CountCommand, CountQuery, InventoryHandler, LocationCommand, LocationQuery, ProductCommand, ProductQuery, StockCommand, StockQuery, SucursalCommand};
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

final class InventoryHttpTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

    private static Database $testDb;
    private static Database $devDb;
    private static Config $config;
    private static StockQuery $stockQuery;
    private static StockCommand $stockCmd;
    private static ProductQuery $prodQuery;
    private static ProductCommand $prodCmd;
    private static LocationQuery $locQuery;
    private static LocationCommand $locCmd;
    private static Renderer $renderer;

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

        $tx = new Transaction(self::$testDb);
        self::$stockQuery = new StockQuery(self::$testDb);
        self::$stockCmd   = new StockCommand($tx);
        self::$prodQuery  = new ProductQuery(self::$testDb);
        self::$prodCmd    = new ProductCommand($tx);
        self::$locQuery   = new LocationQuery(self::$testDb);
        self::$locCmd     = new LocationCommand($tx);
        $viewContext = new \App\Foundation\ViewContext();
        $viewContext->set('permissions', new \App\Modules\Access\ViewPermissions(new \App\Modules\Access\RouteAccessPolicy(), 'administrador'));
        self::$renderer   = new Renderer(dirname(__DIR__, 2), $viewContext);

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
        foreach (['conteo_inventario', 'inventario_stock', 'producto', 'ubicacion', 'almacen', 'sucursal', 'categoria', 'usuario'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createWarehouseFixture(): int
    {
        $tx = new Transaction(self::$testDb);
        $sucursalCmd = new SucursalCommand($tx);
        $bId = $sucursalCmd->create('SUC-' . uniqid(), 'Sucursal Inv', 'Tegucigalpa');
        $almacenCmd = new AlmacenCommand($tx);
        return $almacenCmd->create($bId, 'ALM-' . uniqid(), 'Almacén Inv');
    }

    public function testInventoryPageReturns200AndRendersCanonicalHtml(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Existencias por ubicación', $response->body);
        self::assertStringContainsString('Consulta las existencias registradas para cada producto y ubicación.', $response->body);
        self::assertStringContainsString('Registrar existencia', $response->body);
        self::assertStringContainsString('Ferreterías El Constructor', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testNavigationContainsActiveInventoryAndInactiveCatalogLinks(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Catálogo', $response->body);
        self::assertStringContainsString('Inventario', $response->body);
        self::assertMatchesRegularExpression('/href="\/products"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/locations"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/inventory"[^>]*class="nav-item\s+is-active"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/inventory\/counts"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertStringContainsString('<span class="topbar-crumb">Inventario</span>', $response->body);
        self::assertStringContainsString('<span class="topbar-current">Existencias por ubicación</span>', $response->body);

        foreach (['Ventas', 'Proveedores', 'Sucursales', 'Reportes', 'Configuración'] as $deadLink) {
            self::assertStringNotContainsString($deadLink, $response->body);
        }
    }

    public function testEmptyStateWhenNoStockPositionsExist(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No hay existencias registradas', $response->body);
        self::assertStringContainsString('Registra una existencia para relacionar un producto con una ubicación.', $response->body);
    }

    public function testStockListingWithData(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Martillo Galponero 16oz', '18.50');
        $lId = self::$locCmd->create('ESTANTE-B1', $wId, 'Estantería B pasillo 1');
        self::$stockCmd->createPosition($pId, $lId, '45.500');

        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<th scope="col" style="width: 40%;">Producto</th>', $response->body);
        self::assertStringContainsString('<th scope="col" style="width: 35%;">Ubicación</th>', $response->body);
        self::assertStringContainsString('<th scope="col" style="width: 25%;" class="has-text-right">Cantidad</th>', $response->body);
        self::assertStringContainsString('Martillo Galponero 16oz', $response->body);
        self::assertStringContainsString('ESTANTE-B1', $response->body);
        self::assertStringContainsString('45.500', $response->body);
        self::assertStringNotContainsString('No hay existencias registradas', $response->body);
    }

    public function testHtmlEscapingInStockListing(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('<script>alert("prod")</script>', '10.00');
        $lId = self::$locCmd->create('<img src=x onerror=alert(1)>', $wId);
        self::$stockCmd->createPosition($pId, $lId, '5.000');

        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("prod")</script>', $response->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;prod&quot;)&lt;/script&gt;', $response->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body);
    }

    public function testNoUnsupportedActionColumnsOrButtonsRendered(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Tornillo Hexagonal 1/4', '0.25');
        $lId = self::$locCmd->create('CAJA-A1', $wId);
        self::$stockCmd->createPosition($pId, $lId, '100.000');

        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        $tableHtml = explode('id="stock-table-container"', $response->body)[1] ?? '';
        self::assertStringNotContainsString('<th scope="col">Acciones</th>', $tableHtml);
        self::assertStringNotContainsString('>Acciones<', $tableHtml);

        foreach (['Editar', 'Ajustar', 'Eliminar', 'Conteos', 'Ver movimientos', 'Transferir', 'Desactivar', 'Activar'] as $unsupported) {
            self::assertStringNotContainsString($unsupported, $tableHtml);
        }
    }

    public function testProductionEntrypointServesInventory(): void
    {
        $adminId = $this->createAuthUser(self::$testDb, 'admin_inventory_entrypoint', 'administrador', 'activo');
        $res = $this->runEntrypointRequest('GET', '/inventory', $adminId);

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringNotContainsString('Undefined array key "inventory"', $res['stderr']);
        self::assertStringNotContainsString('"level":"error"', $res['stderr']);
        self::assertStringContainsString('Existencias por ubicación', $res['stdout']);
        self::assertStringContainsString('id="stock-table-container"', $res['stdout']);
    }

    public function testStockPositionCreationSucceedsWithValidDataAndRedirects(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Taladro Percutor 750W', '85.00');
        $lId = self::$locCmd->create('BOD-A1', $wId, 'Bodega A Estante 1');

        $response = $this->post('/inventory/stock', [
            'id_producto'  => (string) $pId,
            'id_ubicacion' => (string) $lId,
            'cantidad'     => '12.500',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/inventory', $response->headers['Location']);

        $pos = self::$stockQuery->getPosition($pId, $lId);
        self::assertNotNull($pos);
        self::assertSame('12.500', $pos['cantidad']);
        self::assertSame('Taladro Percutor 750W', $pos['producto_nombre']);
        self::assertSame('BOD-A1', $pos['ubicacion_codigo']);
    }

    public function testStockPositionCreationWithZeroAndFractionalQuantities(): void
    {
        $cases = [
            ['0', '0.000'],
            ['0.000', '0.000'],
            ['0.001', '0.001'],
            ['10', '10.000'],
            ['10.5', '10.500'],
            ['10.500', '10.500'],
            ['125.750', '125.750'],
        ];

        $wId = $this->createWarehouseFixture();
        foreach ($cases as $idx => [$inputQty, $expectedQty]) {
            $p = self::$prodCmd->register("Prod Dec {$idx}", '10.00');
            $l = self::$locCmd->create("LOC-D{$idx}", $wId);

            $r = $this->post('/inventory/stock', [
                'id_producto'  => (string) $p,
                'id_ubicacion' => (string) $l,
                'cantidad'     => $inputQty,
            ]);
            self::assertSame(303, $r->status);
            $pos = self::$stockQuery->getPosition($p, $l);
            self::assertNotNull($pos);
            self::assertSame($expectedQty, $pos['cantidad']);
        }
    }

    public function testStockPositionCreationRejectsNegativeMalformedAndOverprecisionQuantities(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Clavos 2 Pulgadas', '5.00');
        $lId = self::$locCmd->create('LOC-C1', $wId);

        foreach (['-1', '-0.5', '10.1234', 'abc'] as $badQty) {
            $r = $this->post('/inventory/stock', [
                'id_producto'  => (string) $pId,
                'id_ubicacion' => (string) $lId,
                'cantidad'     => $badQty,
            ]);
            self::assertSame(422, $r->status);
            self::assertStringContainsString('La cantidad debe ser mayor o igual a 0 y puede tener hasta 3 decimales.', $r->body);
        }

        $rEmpty = $this->post('/inventory/stock', [
            'id_producto'  => (string) $pId,
            'id_ubicacion' => (string) $lId,
            'cantidad'     => '',
        ]);
        self::assertSame(422, $rEmpty->status);
        self::assertStringContainsString('La cantidad es obligatoria.', $rEmpty->body);
    }

    public function testStockPositionCreationRejectsNonexistentOrInvalidProductAndLocation(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Lija al Agua 240', '1.50');
        $lId = self::$locCmd->create('LOC-L1', $wId);

        // Invalid product ID
        $r1 = $this->post('/inventory/stock', [
            'id_producto' => '0', 'id_ubicacion' => (string) $lId, 'cantidad' => '5.000',
        ]);
        self::assertSame(422, $r1->status);
        self::assertStringContainsString('Debe seleccionar un producto válido.', $r1->body);

        // Nonexistent product ID
        $r2 = $this->post('/inventory/stock', [
            'id_producto' => '999999', 'id_ubicacion' => (string) $lId, 'cantidad' => '5.000',
        ]);
        self::assertSame(422, $r2->status);
        self::assertStringContainsString('El producto seleccionado no existe.', $r2->body);

        // Invalid location ID
        $r3 = $this->post('/inventory/stock', [
            'id_producto' => (string) $pId, 'id_ubicacion' => '', 'cantidad' => '5.000',
        ]);
        self::assertSame(422, $r3->status);
        self::assertStringContainsString('Debe seleccionar una ubicación válida.', $r3->body);

        // Nonexistent location ID
        $r4 = $this->post('/inventory/stock', [
            'id_producto' => (string) $pId, 'id_ubicacion' => '888888', 'cantidad' => '5.000',
        ]);
        self::assertSame(422, $r4->status);
        self::assertStringContainsString('La ubicación seleccionada no existe.', $r4->body);
    }

    public function testStockPositionCreationRejectsDuplicateProductLocationPair(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Disco de Corte 4.5', '3.50');
        $lId = self::$locCmd->create('LOC-D1', $wId);
        self::$stockCmd->createPosition($pId, $lId, '10.000');

        $r = $this->post('/inventory/stock', [
            'id_producto'  => (string) $pId,
            'id_ubicacion' => (string) $lId,
            'cantidad'     => '99.000',
        ]);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Ya existe una posición de stock para este producto en esta ubicación.', $r->body);
        self::assertStringContainsString('is-active', $r->body);

        // Confirm existing position is untouched: no overwrite, no UPDATE, no second row
        $pos = self::$stockQuery->getPosition($pId, $lId);
        self::assertNotNull($pos);
        self::assertSame('10.000', $pos['cantidad']);
        self::assertCount(1, self::$stockQuery->listOverview());
    }

    public function testStockPositionCreationRequiresCsrf(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Cinta Aislante', '1.20');
        $lId = self::$locCmd->create('LOC-CA', $wId);

        $response = $this->dispatch(new Request('POST', '/inventory/stock', body: [
            'id_producto'  => (string) $pId,
            'id_ubicacion' => (string) $lId,
            'cantidad'     => '10.000',
        ]));

        self::assertSame(403, $response->status);
    }

    public function testStockPositionCreationSupportsHtmx(): void
    {
        $wId = $this->createWarehouseFixture();
        $pId = self::$prodCmd->register('Destornillador Phillips', '6.00');
        $lId = self::$locCmd->create('LOC-DP', $wId);

        $response = $this->post('/inventory/stock', [
            'id_producto'  => (string) $pId,
            'id_ubicacion' => (string) $lId,
            'cantidad'     => '8.000',
        ], headers: ['hx-request' => 'true']);

        self::assertSame(200, $response->status);
        self::assertSame('/inventory', $response->headers['HX-Redirect']);
        self::assertStringContainsString('Existencia registrada correctamente.', $response->headers['HX-Trigger']);
    }

    public function testModalStockRendersAllExistingProductsAndLocationsIncludingInactiveWithBadge(): void
    {
        $activePId = self::$prodCmd->register('Producto Activo', '10.00');
        $inactivePId = self::$prodCmd->register('Producto Inactivo', '12.00');
        self::$prodCmd->deactivate($inactivePId);

        $wId = $this->createWarehouseFixture();
        $activeLId = self::$locCmd->create('LOC-ACTIVA', $wId);
        $inactiveLId = self::$locCmd->create('LOC-INACT', $wId);
        self::$testDb->pdo()->exec("UPDATE ubicacion SET estado_activo = 0 WHERE id_ubicacion = {$inactiveLId}");

        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="modal-stock"', $response->body);
        self::assertStringContainsString('name="id_producto"', $response->body);
        self::assertStringContainsString('name="id_producto"', $response->body);
        self::assertStringContainsString('name="id_ubicacion"', $response->body);
        self::assertStringContainsString('name="cantidad"', $response->body);

        self::assertStringContainsString('Producto Activo', $response->body);
        self::assertStringContainsString('Producto Inactivo [Inactivo]', $response->body);
        self::assertStringContainsString('LOC-ACTIVA', $response->body);
        self::assertStringContainsString('LOC-INACT [Inactiva]', $response->body);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $body, array $headers = []): Response
    {
        $session = new InventoryMemorySession();
        $csrf = new Csrf($session);
        $body['_csrf'] = $csrf->token();

        return $this->dispatch(new Request('POST', $path, body: $body, headers: $headers), $csrf);
    }

    private function dispatch(Request $request, ?Csrf $csrf = null): Response
    {
        $session = new InventoryMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        $tx = new Transaction(self::$testDb);
        $handler = new InventoryHandler(
            self::$renderer, self::$stockQuery, self::$stockCmd, self::$prodQuery, self::$locQuery, $actualCsrf,
            new CountQuery(self::$testDb)
        );
        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'inventory') {
                $routes[] = [$method, $path, $handler->handle(...)];
            }
        }
        $router = new Router($routes);

        return $router->dispatch($request, static function (Request $matched) use ($actualCsrf): ?Response {
            if (!in_array($matched->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$actualCsrf->valid($matched)) {
                return new Response(403, [], 'Forbidden');
            }
            return null;
        });
    }
}

final class InventoryMemorySession implements Session
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): mixed
    {
        $val = $this->values[$key] ?? null;
        unset($this->values[$key]);
        return $val;
    }
}
