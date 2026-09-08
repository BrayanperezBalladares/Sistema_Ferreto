<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{InventoryHandler, LocationCommand, LocationQuery, ProductCommand, ProductQuery, StockCommand, StockQuery};
use PDO;
use PHPUnit\Framework\TestCase;

final class InventoryHttpTest extends TestCase
{
    use DatabaseIsolationTrait;

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
        self::$renderer   = new Renderer(dirname(__DIR__, 2));

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
        self::assertStringContainsString('<span class="topbar-crumb">Inventario</span>', $response->body);
        self::assertStringContainsString('<span class="topbar-current">Existencias por ubicación</span>', $response->body);

        foreach (['Conteos', 'Ventas', 'Proveedores', 'Sucursales', 'Reportes', 'Configuración'] as $deadLink) {
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
        $pId = self::$prodCmd->register('Martillo Galponero 16oz', '18.50');
        $lId = self::$locCmd->create('ESTANTE-B1', 'Estantería B pasillo 1');
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
        $pId = self::$prodCmd->register('<script>alert("prod")</script>', '10.00');
        $lId = self::$locCmd->create('<img src=x onerror=alert(1)>');
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
        $pId = self::$prodCmd->register('Tornillo Hexagonal 1/4', '0.25');
        $lId = self::$locCmd->create('CAJA-A1');
        self::$stockCmd->createPosition($pId, $lId, '100.000');

        $response = $this->dispatch(new Request('GET', '/inventory'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<th scope="col">Acciones</th>', $response->body);
        self::assertStringNotContainsString('>Acciones<', $response->body);

        foreach (['Editar', 'Ajustar', 'Eliminar', 'Conteos', 'Ver movimientos', 'Transferir', 'Desactivar', 'Activar'] as $unsupported) {
            self::assertStringNotContainsString($unsupported, $response->body);
        }
    }

    public function testProductionEntrypointServesInventory(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'putenv("APP_ENV=test"); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/inventory"; $_SERVER["SERVER_NAME"] = "localhost"; require "public/index.php";';
        $proc = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $p) { fclose($p); }
        $exitCode = proc_close($proc);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('Undefined array key "inventory"', $stderr);
        self::assertStringNotContainsString('"level":"error"', $stderr);
        self::assertStringContainsString('Existencias por ubicación', $stdout);
        self::assertStringContainsString('id="stock-table-container"', $stdout);
    }

    private function dispatch(Request $request, ?Csrf $csrf = null): Response
    {
        $session = new InventoryMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        $handler = new InventoryHandler(
            self::$renderer, self::$stockQuery, self::$prodQuery, self::$locQuery, $actualCsrf
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
