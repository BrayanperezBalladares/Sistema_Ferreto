<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
use App\Foundation\Transaction;
use App\Modules\Inventory\CatalogHandler;
use App\Modules\Inventory\CategoryCommand;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\ProductQuery;
use PDO;
use PHPUnit\Framework\TestCase;

final class CatalogHttpTest extends TestCase
{
    private static Database $testDb;
    private static Transaction $tx;
    private static CategoryCommand $catCmd;
    private static ProductCommand $prodCmd;
    private static ProductQuery $prodQuery;
    private static Renderer $renderer;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        $config = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb = new Database($config, useTestDatabase: true);
        self::$tx = new Transaction(self::$testDb);
        self::$catCmd = new CategoryCommand(self::$tx);
        self::$prodCmd = new ProductCommand(self::$tx);
        self::$prodQuery = new ProductQuery(self::$testDb);
        self::$renderer = new Renderer(dirname(__DIR__, 2));

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'producto', 'categoria'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE {$tbl}");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testNormalCatalogNavigationReturnsFullHtml(): void
    {
        $router = $this->createRouter();
        $response = $router->dispatch(new Request('GET', '/products'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Product Catalog', $response->body);
        self::assertStringContainsString('id="product-table-container"', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testMatchingSearchReturnsMatchingProducts(): void
    {
        $catId = self::$catCmd->create('Herramientas');
        self::$prodCmd->register('Martillo Galponero', '15.50', $catId);
        self::$prodCmd->register('Taladro Percutor', '89.00', $catId);

        $router = $this->createRouter();
        $response = $router->dispatch(new Request(
            'GET',
            '/products',
            query: ['q' => 'Martillo'],
            headers: ['hx-request' => 'true']
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Martillo Galponero', $response->body);
        self::assertStringContainsString('Herramientas', $response->body);
        self::assertStringNotContainsString('Taladro Percutor', $response->body);
    }

    public function testNonmatchingSearchReturnsEmptyStateFragment(): void
    {
        $catId = self::$catCmd->create('Ferretería');
        self::$prodCmd->register('Clavo 2in', '0.05', $catId);

        $router = $this->createRouter();
        $response = $router->dispatch(new Request(
            'GET',
            '/products',
            query: ['q' => 'Desconocido'],
            headers: ['hx-request' => 'true']
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('No products found.', $response->body);
        self::assertStringNotContainsString('Clavo 2in', $response->body);
    }

    public function testHtmxRequestReturnsFragmentInsteadOfLayout(): void
    {
        $catId = self::$catCmd->create('Plomería');
        self::$prodCmd->register('Tubo PVC 1/2', '3.50', $catId);

        $router = $this->createRouter();
        $response = $router->dispatch(new Request(
            'GET',
            '/products',
            headers: ['hx-request' => 'true']
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('id="product-table-container"', $response->body);
        self::assertStringContainsString('Tubo PVC 1/2', $response->body);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testRenderedProductAndCategoryContentIsSafelyEscaped(): void
    {
        $catId = self::$catCmd->create('<script>alert("cat-xss")</script>');
        self::$prodCmd->register('<script>alert("prod-xss")</script>', '12.50', $catId);

        $router = $this->createRouter();
        $response = $router->dispatch(new Request('GET', '/products'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("prod-xss")</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;prod-xss&quot;)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('<script>alert("cat-xss")</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;cat-xss&quot;)&lt;/script&gt;', $response->body);
    }

    public function testActiveInactiveAndUnclassifiedPresentation(): void
    {
        $catId = self::$catCmd->create('Electricidad');
        $p1 = self::$prodCmd->register('Cable 10mm', '4.20', $catId);
        $p2 = self::$prodCmd->register('Tornillo Roscalata', '0.15', null);
        $p3 = self::$prodCmd->register('Fusible 20A', '1.50', $catId);
        self::$prodCmd->deactivate($p3);

        $router = $this->createRouter();
        $response = $router->dispatch(new Request('GET', '/products'));

        self::assertSame(200, $response->status);
        // Active classified
        self::assertStringContainsString('Cable 10mm', $response->body);
        self::assertStringContainsString('Electricidad', $response->body);
        // Active unclassified
        self::assertStringContainsString('Tornillo Roscalata', $response->body);
        self::assertStringContainsString('Unclassified', $response->body);
        // Inactive classified
        self::assertStringContainsString('Fusible 20A', $response->body);
        self::assertStringContainsString('Inactive', $response->body);
    }

    private function createRouter(): Router
    {
        $handler = new CatalogHandler(self::$renderer, self::$prodQuery);
        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'catalog') {
                $routes[] = [$method, $path, $handler->handle(...)];
            }
        }

        return new Router($routes);
    }
}
