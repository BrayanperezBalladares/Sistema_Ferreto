<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{CatalogHandler, CategoryCommand, CategoryQuery, CountCommand, CountQuery, InventoryHandler, LocationCommand, LocationHandler, LocationQuery, ProductCommand, ProductQuery, StockCommand, StockQuery};
use PHPUnit\Framework\TestCase;

final class ResponsiveHttpTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Database $testDb;
    private static Database $devDb;
    private static Config $config;
    private static Renderer $renderer;
    private static ProductCommand $prodCmd;
    private static ProductQuery $prodQuery;
    private static CategoryCommand $catCmd;
    private static CategoryQuery $catQuery;
    private static LocationCommand $locCmd;
    private static LocationQuery $locQuery;
    private static StockCommand $stockCmd;
    private static StockQuery $stockQuery;
    private static CountCommand $countCmd;
    private static CountQuery $countQuery;

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
        self::$renderer   = new Renderer(dirname(__DIR__, 2));
        self::$catQuery   = new CategoryQuery(self::$testDb);
        self::$catCmd     = new CategoryCommand($tx);
        self::$prodQuery  = new ProductQuery(self::$testDb);
        self::$prodCmd    = new ProductCommand($tx);
        self::$locQuery   = new LocationQuery(self::$testDb);
        self::$locCmd     = new LocationCommand($tx);
        self::$stockQuery = new StockQuery(self::$testDb);
        self::$stockCmd   = new StockCommand($tx);
        self::$countQuery = new CountQuery(self::$testDb);
        self::$countCmd   = new CountCommand($tx);

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
        foreach (['conteo_inventario', 'inventario_stock', 'producto', 'ubicacion', 'categoria'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testNavigationDrawerMarkupAndAccessibleAttributesArePresent(): void
    {
        $response = $this->dispatchCatalog(new Request('GET', '/products'));
        self::assertSame(200, $response->status);

        // Drawer elements
        self::assertStringContainsString('id="app-sidebar"', $response->body);
        self::assertStringContainsString('id="sidebar-backdrop"', $response->body);
        self::assertStringContainsString('data-drawer-close', $response->body);
        self::assertStringContainsString('class="drawer-close"', $response->body);

        // Accessible nav toggle
        self::assertStringContainsString('id="nav-toggle"', $response->body);
        self::assertStringContainsString('aria-label="Abrir menú de navegación"', $response->body);
        self::assertStringContainsString('aria-expanded="false"', $response->body);
        self::assertStringContainsString('aria-controls="app-sidebar"', $response->body);
    }

    public function testProductTableContainerProvidesHorizontalScrolling(): void
    {
        self::$prodCmd->register('Martillo 16oz', '15.00');

        $response = $this->dispatchCatalog(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<div class="table-container mb-0">', $response->body);
        self::assertStringContainsString('<table class="ferreto-table">', $response->body);
    }

    public function testModalProductAndPriceHaveDecimalInputmode(): void
    {
        $response = $this->dispatchCatalog(new Request('GET', '/products'));
        self::assertSame(200, $response->status);

        // Product creation modal price inputmode
        self::assertMatchesRegularExpression('/<input[^>]*inputmode="decimal"[^>]*name="precio_actual"|<input[^>]*name="precio_actual"[^>]*inputmode="decimal"/i', $response->body);

        // Product price update modal price inputmode
        self::assertMatchesRegularExpression('/<input[^>]*inputmode="decimal"[^>]*id="modal-price-input"|<input[^>]*id="modal-price-input"[^>]*inputmode="decimal"/i', $response->body);

        // Responsive columns in product registration modal
        self::assertStringContainsString('is-12-mobile is-half-tablet', $response->body);
    }

    public function testInventoryQuantityHasDecimalInputmodeAndTableContainer(): void
    {
        $pId = self::$prodCmd->register('Tubo PVC 1/2', '4.25');
        $lId = self::$locCmd->create('BOD-A1', 'Bodega A Estante 1');
        self::$stockCmd->createPosition($pId, $lId, '50.000');

        $response = $this->dispatchInventory(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);

        self::assertStringContainsString('<div class="table-container mb-0">', $response->body);
        self::assertMatchesRegularExpression('/<input[^>]*inputmode="decimal"[^>]*name="cantidad"|<input[^>]*name="cantidad"[^>]*inputmode="decimal"/i', $response->body);
    }

    public function testCountsSummaryStacksOnMobileAndInputHasDecimalMode(): void
    {
        $pId = self::$prodCmd->register('Clavos 3in', '2.50');
        $lId = self::$locCmd->create('BOD-B2', 'Bodega B Pasillo 2');
        $sId = self::$stockCmd->createPosition($pId, $lId, '100.000');
        self::$countCmd->record($sId, '95.000', 'Conteo inicial');

        $response = $this->dispatchInventory(new Request('GET', '/inventory/counts', query: ['stock' => (string) $sId]));
        self::assertSame(200, $response->status);

        // Summary columns responsive stacking
        self::assertStringContainsString('class="column is-12-mobile is-4-tablet"', $response->body);

        // Count history table container
        self::assertStringContainsString('<div class="table-container mb-0">', $response->body);

        // Count quantity inputmode
        self::assertMatchesRegularExpression('/<input[^>]*inputmode="decimal"[^>]*id="cantidad_contada"|<input[^>]*id="cantidad_contada"[^>]*inputmode="decimal"/i', $response->body);
    }

    private function dispatchCatalog(Request $request): Response
    {
        $session = new ResponsiveMemorySession();
        $csrf = new Csrf($session);
        $handler = new CatalogHandler(self::$renderer, self::$prodQuery, self::$catQuery, self::$catCmd, self::$prodCmd, $csrf);
        $router = new Router([
            ['GET', '/products', $handler->handle(...)],
        ]);
        return $router->dispatch($request);
    }

    private function dispatchInventory(Request $request): Response
    {
        $session = new ResponsiveMemorySession();
        $csrf = new Csrf($session);
        $handler = new InventoryHandler(
            self::$renderer,
            self::$stockQuery,
            self::$stockCmd,
            self::$prodQuery,
            self::$locQuery,
            $csrf,
            self::$countQuery,
            self::$countCmd
        );
        $router = new Router([
            ['GET', '/inventory', $handler->handle(...)],
            ['GET', '/inventory/counts', $handler->handle(...)],
        ]);
        return $router->dispatch($request);
    }
}

final class ResponsiveMemorySession implements Session
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