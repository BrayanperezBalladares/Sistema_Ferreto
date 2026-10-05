<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{AlmacenCommand, CountCommand, CountQuery, InventoryHandler, LocationCommand, ProductCommand, StockCommand, StockQuery, SucursalCommand};
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

final class CountHttpTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

    private static Database $testDb, $devDb;
    private static Config $config;
    private static StockQuery $stockQuery;
    private static StockCommand $stockCmd;
    private static ProductCommand $prodCmd;
    private static LocationCommand $locCmd;
    private static CountQuery $countQuery;
    private static CountCommand $countCmd;
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
        self::$prodCmd    = new ProductCommand($tx);
        self::$locCmd     = new LocationCommand($tx);
        self::$countQuery = new CountQuery(self::$testDb);
        self::$countCmd   = new CountCommand($tx);
        $viewContext = new \App\Foundation\ViewContext();
        $viewContext->set('permissions', new \App\Modules\Access\ViewPermissions(new \App\Modules\Access\RouteAccessPolicy(), 'administrador'));
        self::$renderer   = new Renderer(dirname(__DIR__, 2), $viewContext);

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    public static function tearDownAfterClass(): void { self::assertDevDatabaseUntouched(self::$devDb, self::$config); }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['conteo_inventario', 'inventario_stock', 'producto', 'ubicacion', 'almacen', 'sucursal', 'categoria', 'usuario'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createStock(string $prod = 'Bombilo', string $loc = 'CENTRAL', string $qty = '2000.000'): int
    {
        $tx = new Transaction(self::$testDb);
        $sucursalCmd = new SucursalCommand($tx);
        $bId = $sucursalCmd->create('SUC-' . uniqid(), 'Sucursal Cnt', 'Tegucigalpa');
        $almacenCmd = new AlmacenCommand($tx);
        $wId = $almacenCmd->create($bId, 'ALM-' . uniqid(), 'Almacén Cnt');

        return self::$stockCmd->createPosition(self::$prodCmd->register($prod, '10.00'), self::$locCmd->create($loc, $wId), $qty);
    }

    public function testCountsPageReturns200AndRendersCanonicalHtmlAndActiveNavigation(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory/counts'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Conteos físicos', $response->body);
        self::assertStringContainsString('Registra observaciones físicas del inventario sin modificar las existencias registradas en el sistema.', $response->body);
        self::assertStringContainsString('Selecciona una existencia', $response->body);
        self::assertStringNotContainsString('id="selected-stock-summary"', $response->body);
        self::assertMatchesRegularExpression('/href="\/inventory\/counts"[^>]*class="nav-item\s+is-active"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/inventory"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertStringContainsString('<span class="topbar-crumb">Inventario</span>', $response->body);
        self::assertStringContainsString('<span class="topbar-current">Conteos físicos</span>', $response->body);

        foreach (['Ventas', 'Proveedores', 'Sucursales', 'Reportes', 'Configuración'] as $deadLink) {
            self::assertStringNotContainsString($deadLink, $response->body);
        }
    }

    public function testStockPositionSelectorPopulatesExistingPositions(): void
    {
        $sId = $this->createStock('Martillo Galponero 16oz', 'PASILLO-B2', '150.000');

        $response = $this->dispatch(new Request('GET', '/inventory/counts'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="stock-selector-container"', $response->body);
        self::assertStringContainsString("value=\"{$sId}\"", $response->body);
        self::assertStringContainsString('Martillo Galponero 16oz — PASILLO-B2', $response->body);
        self::assertStringContainsString('(Actual: 150.000)', $response->body);
    }

    public function testSelectedPositionRendersSummaryAndCountHistoryWithExactVariance(): void
    {
        $sId = $this->createStock('Bombilo LED 9W', 'CENTRAL', '2000.000');

        $resEmpty = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => (string) $sId]));
        self::assertSame(200, $resEmpty->status);
        self::assertStringContainsString('id="selected-stock-summary"', $resEmpty->body);
        self::assertStringContainsString('Bombilo LED 9W', $resEmpty->body);
        self::assertStringContainsString('CENTRAL', $resEmpty->body);
        self::assertStringContainsString('2000.000', $resEmpty->body);
        self::assertStringContainsString('No hay conteos registrados para esta existencia.', $resEmpty->body);
        self::assertStringContainsString('Registrar conteo', $resEmpty->body);

        self::$countCmd->record($sId, '1996.000', 'Observación inicial de bodega');
        self::$countCmd->record($sId, '2004.000', 'Reconteo con pallet adicional');
        self::$countCmd->record($sId, '2000.000', 'Auditoría exacta');

        $response = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => (string) $sId]));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="count-history-container"', $response->body);
        self::assertStringContainsString('-4.000', $response->body);
        self::assertStringContainsString('has-text-danger', $response->body);
        self::assertStringContainsString('+4.000', $response->body);
        self::assertStringContainsString('has-text-success', $response->body);
        self::assertStringContainsString('0.000', $response->body);
        self::assertStringContainsString('has-text-grey', $response->body);

        $historyHtml = explode('id="count-history-container"', $response->body)[1] ?? '';
        self::assertStringNotContainsString('<th scope="col">Acciones</th>', $historyHtml);
        foreach (['Editar', 'Eliminar', 'Conciliar', 'Aplicar', 'Ajustar', 'Corregir', 'Reemplazar'] as $unsupported) {
            self::assertStringNotContainsString($unsupported, $historyHtml);
        }
    }

    public function testNonexistentStockIdFallsBackSafely(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => '999999']));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Selecciona una existencia', $response->body);
        self::assertStringNotContainsString('id="selected-stock-summary"', $response->body);
    }

    public function testEmptyStockQueryParamNormalizesToCanonicalCountsRoute(): void
    {
        $response = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => '']));
        self::assertSame(303, $response->status);
        self::assertSame('/inventory/counts', $response->headers['Location'] ?? '');
    }

    public function testHtmlEscapingInCountsView(): void
    {
        $sId = $this->createStock('<script>alert("prod")</script>', '<img src=x onerror=alert(1)>', '5.000');
        self::$countCmd->record($sId, '4.000', '<b onmouseover=alert(2)>danger note</b>');

        $response = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => (string) $sId]));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("prod")</script>', $response->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body);
        self::assertStringNotContainsString('<b onmouseover=alert(2)>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;prod&quot;)&lt;/script&gt;', $response->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body);
        self::assertStringContainsString('&lt;b onmouseover=alert(2)&gt;danger note&lt;/b&gt;', $response->body);
    }

    public function testProductionEntrypointServesCounts(): void
    {
        $adminId = $this->createAuthUser(self::$testDb, 'admin_count_entrypoint', 'administrador', 'activo');
        $res = $this->runEntrypointRequest('GET', '/inventory/counts', $adminId);

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringNotContainsString('Undefined array key "inventory"', $res['stderr']);
        self::assertStringNotContainsString('"level":"error"', $res['stderr']);
        self::assertStringContainsString('Conteos físicos', $res['stdout']);
        self::assertStringContainsString('id="stock-selector-container"', $res['stdout']);
    }

    public function testCountRegistrationSucceedsAndEnforcesStockNonMutationInvariant(): void
    {
        $sId = $this->createStock('Bombilo LED 9W', 'CENTRAL', '2000.000');

        $response = $this->post('/inventory/counts', [
            'id_stock'         => (string) $sId,
            'cantidad_contada' => '1996.000',
            'notas'            => 'Conteo físico verificado por auditoría',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/inventory/counts?stock=' . $sId, $response->headers['Location'] ?? '');

        // Persisted count verification
        $counts = self::$countQuery->listByStock($sId);
        self::assertCount(1, $counts);
        self::assertSame('2000.000', $counts[0]['cantidad_sistema']);
        self::assertSame('1996.000', $counts[0]['cantidad_contada']);
        self::assertSame('-4.000', $counts[0]['diferencia']);
        self::assertSame('Conteo físico verificado por auditoría', $counts[0]['notas']);

        // CRITICAL INVARIANT: inventario_stock.cantidad MUST REMAIN 2000.000!
        $stockRow = self::$stockQuery->findById($sId);
        self::assertNotNull($stockRow);
        self::assertSame('2000.000', $stockRow['cantidad']);
    }

    public function testSecondCountAppendsRowAndLeavesFirstCountAndStockUntouched(): void
    {
        $sId = $this->createStock('Tornillo Hexagonal', 'ESTANTE-1', '500.000');

        $this->post('/inventory/counts', ['id_stock' => (string) $sId, 'cantidad_contada' => '490.000']);
        $this->post('/inventory/counts', ['id_stock' => (string) $sId, 'cantidad_contada' => '505.500']);

        $counts = self::$countQuery->listByStock($sId);
        self::assertCount(2, $counts);

        // Most recent first
        self::assertSame('505.500', $counts[0]['cantidad_contada']);
        self::assertSame('5.500', $counts[0]['diferencia']);

        // First count untouched
        self::assertSame('490.000', $counts[1]['cantidad_contada']);
        self::assertSame('-10.000', $counts[1]['diferencia']);

        // Stock quantity untouched
        $stock = self::$stockQuery->findById($sId);
        self::assertNotNull($stock);
        self::assertSame('500.000', $stock['cantidad']);
    }

    public function testCountRegistrationRejectsNegativeMalformedAndOverprecisionQuantities(): void
    {
        $sId = $this->createStock();

        foreach (['-1', '-0.001', '10.1234', 'abc', ''] as $invalidQty) {
            $response = $this->post('/inventory/counts', [
                'id_stock'         => (string) $sId,
                'cantidad_contada' => $invalidQty,
            ]);
            self::assertSame(422, $response->status);
            self::assertStringContainsString('La cantidad contada debe ser mayor o igual a 0 y puede tener hasta 3 decimales.', $response->body);
        }

        self::assertCount(0, self::$countQuery->listByStock($sId));
    }

    public function testCountRegistrationRejectsMissingOrNonexistentStock(): void
    {
        $resMissing = $this->post('/inventory/counts', ['id_stock' => '', 'cantidad_contada' => '10.000']);
        self::assertSame(422, $resMissing->status);
        self::assertStringContainsString('Debe seleccionar una existencia válida.', $resMissing->body);

        $resNonexistent = $this->post('/inventory/counts', ['id_stock' => '999999', 'cantidad_contada' => '10.000']);
        self::assertSame(422, $resNonexistent->status);
        self::assertStringContainsString('La existencia seleccionada no existe.', $resNonexistent->body);
    }

    public function testCountRegistrationRequiresCsrf(): void
    {
        $sId = $this->createStock();
        $response = $this->dispatch(new Request('POST', '/inventory/counts', body: ['id_stock' => (string) $sId, 'cantidad_contada' => '10.000']));
        self::assertSame(403, $response->status);
    }

    public function testClientSuppliedSystemQuantityAndDifferenceCannotOverrideCalculations(): void
    {
        $sId = $this->createStock('Candado Bronce', 'VITRINA-1', '100.000');

        $this->post('/inventory/counts', [
            'id_stock'         => (string) $sId,
            'cantidad_contada' => '95.000',
            'cantidad_sistema' => '999999.000', // Malicious fake value
            'diferencia'       => '888888.000', // Malicious fake value
        ]);

        $counts = self::$countQuery->listByStock($sId);
        self::assertCount(1, $counts);
        self::assertSame('100.000', $counts[0]['cantidad_sistema']);
        self::assertSame('-5.000', $counts[0]['diferencia']);
    }

    public function testCountRegistrationSupportsHtmx(): void
    {
        $sId = $this->createStock();
        $response = $this->post('/inventory/counts', [
            'id_stock'         => (string) $sId,
            'cantidad_contada' => '2000.000',
        ], ['hx-request' => 'true']);

        self::assertSame(200, $response->status);
        self::assertSame('/inventory/counts?stock=' . $sId, $response->headers['HX-Redirect'] ?? '');
        self::assertStringContainsString('Conteo registrado correctamente.', $response->headers['HX-Trigger'] ?? '');
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $body, array $headers = []): Response
    {
        $session = new CountMemorySession();
        $csrf = new Csrf($session);
        $body['_csrf'] = $csrf->token();

        return $this->dispatchWithCsrf(new Request('POST', $path, body: $body, headers: $headers), $csrf);
    }

    private function dispatch(Request $request): Response
    {
        return $this->dispatchWithCsrf($request, null);
    }

    private function dispatchWithCsrf(Request $request, ?Csrf $csrf): Response
    {
        $session = new CountMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        $handler = new InventoryHandler(
            self::$renderer, self::$stockQuery, self::$stockCmd,
            new \App\Modules\Inventory\ProductQuery(self::$testDb),
            new \App\Modules\Inventory\LocationQuery(self::$testDb),
            $actualCsrf, self::$countQuery, self::$countCmd
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

final class CountMemorySession implements Session
{
    private array $values = [];
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): mixed { $v = $this->values[$key] ?? null; unset($this->values[$key]); return $v; }
}