<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Csrf;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
use App\Foundation\Session;
use App\Foundation\Transaction;
use App\Modules\Inventory\CatalogHandler;
use App\Modules\Inventory\CategoryCommand;
use App\Modules\Inventory\CategoryQuery;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\ProductQuery;
use PDO;
use PHPUnit\Framework\TestCase;

final class CatalogHttpTest extends TestCase
{
    private static Database $testDb;
    private static Transaction $tx;
    private static CategoryQuery $catQuery;
    private static CategoryCommand $catCmd;
    private static ProductQuery $prodQuery;
    private static ProductCommand $prodCmd;
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
        self::$catQuery = new CategoryQuery(self::$testDb);
        self::$catCmd = new CategoryCommand(self::$tx);
        self::$prodQuery = new ProductQuery(self::$testDb);
        self::$prodCmd = new ProductCommand(self::$tx);
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
        $response = $this->dispatch(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Productos', $response->body);
        self::assertStringContainsString('Ferreterías El Constructor', $response->body);
        self::assertStringContainsString('app-sidebar', $response->body);
        self::assertStringNotContainsString('Product Catalog', $response->body);
        self::assertStringNotContainsString('Sucursales', $response->body);
        self::assertStringNotContainsString('Sincronización', $response->body);
        self::assertStringNotContainsString('Usuarios', $response->body);
        self::assertStringContainsString('id="product-table-container"', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testMatchingSearchReturnsMatchingProducts(): void
    {
        $catId = self::$catCmd->create('Herramientas');
        self::$prodCmd->register('Martillo Galponero', '15.50', $catId);
        self::$prodCmd->register('Taladro Percutor', '89.00', $catId);

        $response = $this->dispatch(new Request('GET', '/products', query: ['q' => 'Martillo'], headers: ['hx-request' => 'true']));
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

        $response = $this->dispatch(new Request('GET', '/products', query: ['q' => 'Desconocido'], headers: ['hx-request' => 'true']));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('No se encontraron productos', $response->body);
        self::assertStringNotContainsString('No products found.', $response->body);
        self::assertStringNotContainsString('Clavo 2in', $response->body);
    }

    public function testHtmxRequestReturnsFragmentInsteadOfLayout(): void
    {
        $catId = self::$catCmd->create('Plomería');
        self::$prodCmd->register('Tubo PVC 1/2', '3.50', $catId);

        $response = $this->dispatch(new Request('GET', '/products', headers: ['hx-request' => 'true']));
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

        $response = $this->dispatch(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("prod-xss")</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;prod-xss&quot;)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('<script>alert("cat-xss")</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;cat-xss&quot;)&lt;/script&gt;', $response->body);
    }

    public function testActiveInactiveAndUnclassifiedPresentation(): void
    {
        $catId = self::$catCmd->create('Electricidad');
        self::$prodCmd->register('Cable 10mm', '4.20', $catId);
        self::$prodCmd->register('Tornillo Roscalata', '0.15', null);
        $p3 = self::$prodCmd->register('Fusible 20A', '1.50', $catId);
        self::$prodCmd->deactivate($p3);

        $response = $this->dispatch(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Cable 10mm', $response->body);
        self::assertStringContainsString('Electricidad', $response->body);
        self::assertStringContainsString('Tornillo Roscalata', $response->body);
        self::assertStringContainsString('Sin clasificar', $response->body);
        self::assertStringNotContainsString('Unclassified', $response->body);
        self::assertStringContainsString('Fusible 20A', $response->body);
        self::assertStringContainsString('Inactivo', $response->body);
        self::assertStringNotContainsString('Inactive', $response->body);
    }

    public function testCategoryCreationSucceeds(): void
    {
        $response = $this->post('/categories', ['nombre' => 'Fijaciones', 'descripcion' => 'Tornillos y anclajes']);
        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location']);

        $saved = self::$catQuery->findByName('Fijaciones');
        self::assertNotNull($saved);
        self::assertSame('Fijaciones', $saved['nombre']);
        self::assertSame('Tornillos y anclajes', $saved['descripcion']);
    }

    public function testDuplicateCategoryReturnsSafeValidationBehavior(): void
    {
        self::$catCmd->create('Fijaciones');
        $response = $this->post('/categories', ['nombre' => 'Fijaciones']);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Category name already exists.', $response->body);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
        self::assertStringNotContainsString('Duplicate entry', $response->body);
    }

    public function testCategoryOptionalDescriptionBehavior(): void
    {
        $response = $this->post('/categories', ['nombre' => 'Sin Descripcion', 'descripcion' => '']);
        self::assertSame(303, $response->status);
        $saved = self::$catQuery->findByName('Sin Descripcion');
        self::assertNotNull($saved);
        self::assertNull($saved['descripcion']);
    }

    public function testProductCreationWithCategory(): void
    {
        $catId = self::$catCmd->create('Pinturas');
        $response = $this->post('/products', [
            'nombre' => 'Esmalte Sintético', 'precio_actual' => '12.50', 'id_categoria' => (string) $catId, 'descripcion' => 'Secado rápido',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location']);

        $results = self::$prodQuery->search('Esmalte Sintético');
        self::assertCount(1, $results);
        self::assertSame('Esmalte Sintético', $results[0]['nombre']);
        self::assertSame('Pinturas', $results[0]['categoria_nombre']);
        self::assertSame('12.50', $results[0]['precio_actual']);
        self::assertSame(1, $results[0]['estado_activo']);
    }

    public function testProductCreationWithoutCategory(): void
    {
        $response = $this->post('/products', ['nombre' => 'Lija al Agua', 'precio_actual' => '1.00', 'id_categoria' => '']);
        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location']);

        $results = self::$prodQuery->search('Lija al Agua');
        self::assertCount(1, $results);
        self::assertNull($results[0]['id_categoria']);
        self::assertNull($results[0]['categoria_nombre']);
    }

    public function testProductStartsActiveByDefault(): void
    {
        $this->post('/products', ['nombre' => 'Producto Activo', 'precio_actual' => '5.00']);
        $results = self::$prodQuery->search('Producto Activo');
        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['estado_activo']);
    }

    public function testValidZeroPriceAccepted(): void
    {
        $response = $this->post('/products', ['nombre' => 'Muestra Gratis', 'precio_actual' => '0.00']);
        self::assertSame(303, $response->status);
        $results = self::$prodQuery->search('Muestra Gratis');
        self::assertCount(1, $results);
        self::assertSame('0.00', $results[0]['precio_actual']);
    }

    public function testNegativePriceRejected(): void
    {
        $response = $this->post('/products', ['nombre' => 'Invalid Negative', 'precio_actual' => '-5.00']);
        self::assertSame(422, $response->status);
        self::assertEmpty(self::$prodQuery->search('Invalid Negative'));
    }

    public function testOverprecisionPriceRejected(): void
    {
        $response = $this->post('/products', ['nombre' => 'Invalid Precision', 'precio_actual' => '10.123']);
        self::assertSame(422, $response->status);
        self::assertEmpty(self::$prodQuery->search('Invalid Precision'));
    }

    public function testMalformedPriceRejected(): void
    {
        $response = $this->post('/products', ['nombre' => 'Invalid Format', 'precio_actual' => 'abc']);
        self::assertSame(422, $response->status);
        self::assertEmpty(self::$prodQuery->search('Invalid Format'));
    }

    public function testCsrfMissingOrInvalidForCategoryCreationProduces403(): void
    {
        $response = $this->post('/categories', ['nombre' => 'No Token'], withCsrf: false);
        self::assertSame(403, $response->status);
    }

    public function testCsrfMissingOrInvalidForProductCreationProduces403(): void
    {
        $response = $this->post('/products', ['nombre' => 'No Token', 'precio_actual' => '1.00'], withCsrf: false);
        self::assertSame(403, $response->status);
    }

    public function testRenderedRedisplayedUserInputIsEscaped(): void
    {
        $malicious = '<script>alert("prod-err")</script>';
        $response = $this->post('/products', ['nombre' => $malicious, 'precio_actual' => 'invalid-price']);
        self::assertSame(422, $response->status);
        self::assertStringNotContainsString($malicious, $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;prod-err&quot;)&lt;/script&gt;', $response->body);
    }

    public function testNoInitialStatusSelectionBehaviorExists(): void
    {
        $response = $this->dispatch(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('name="estado_activo"', $response->body);
        self::assertStringNotContainsString('status dropdown', strtolower($response->body));
    }

    public function testValidPriceUpdate(): void
    {
        $id = self::$prodCmd->register('Disco de Corte', '5.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => '6.50']);
        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location']);

        $updated = self::$prodQuery->findById($id);
        self::assertNotNull($updated);
        self::assertSame('6.50', $updated['precio_actual']);
    }

    public function testZeroPriceUpdateAccepted(): void
    {
        $id = self::$prodCmd->register('Liquidacion', '10.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => '0.00']);
        self::assertSame(303, $response->status);

        $updated = self::$prodQuery->findById($id);
        self::assertNotNull($updated);
        self::assertSame('0.00', $updated['precio_actual']);
    }

    public function testNegativePriceRejectedOnUpdate(): void
    {
        $id = self::$prodCmd->register('Precio Fijo', '15.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => '-2.00']);
        self::assertSame(422, $response->status);

        $product = self::$prodQuery->findById($id);
        self::assertNotNull($product);
        self::assertSame('15.00', $product['precio_actual']);
    }

    public function testOverprecisionPriceRejectedOnUpdate(): void
    {
        $id = self::$prodCmd->register('Precio Preciso', '15.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => '15.123']);
        self::assertSame(422, $response->status);

        $product = self::$prodQuery->findById($id);
        self::assertNotNull($product);
        self::assertSame('15.00', $product['precio_actual']);
    }

    public function testMalformedPriceRejectedOnUpdate(): void
    {
        $id = self::$prodCmd->register('Precio Texto', '15.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => 'bad']);
        self::assertSame(422, $response->status);

        $product = self::$prodQuery->findById($id);
        self::assertNotNull($product);
        self::assertSame('15.00', $product['precio_actual']);
    }

    public function testPriceUpdateCsrfFailureProduces403(): void
    {
        $id = self::$prodCmd->register('Test CSRF', '10.00');
        $response = $this->post("/products/{$id}/price", ['precio_actual' => '12.00'], withCsrf: false);
        self::assertSame(403, $response->status);
    }

    public function testActiveProductCanBeDeactivated(): void
    {
        $id = self::$prodCmd->register('Sierra Circular', '120.00');
        $response = $this->post("/products/{$id}/deactivate", []);
        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location']);

        $deactivated = self::$prodQuery->findById($id);
        self::assertNotNull($deactivated);
        self::assertSame(0, $deactivated['estado_activo']);
    }

    public function testDeactivationCsrfFailureProduces403(): void
    {
        $id = self::$prodCmd->register('Test Deact CSRF', '10.00');
        $response = $this->post("/products/{$id}/deactivate", [], withCsrf: false);
        self::assertSame(403, $response->status);
    }

    public function testProductRemainsInStorageAfterDeactivation(): void
    {
        $id = self::$prodCmd->register('Permanente Historial', '45.00');
        $this->post("/products/{$id}/deactivate", []);

        $stored = self::$prodQuery->findById($id);
        self::assertNotNull($stored);
        self::assertSame('Permanente Historial', $stored['nombre']);
        self::assertSame(0, $stored['estado_activo']);
    }

    public function testNoPhysicalDeleteRouteOrBehavior(): void
    {
        $id = self::$prodCmd->register('No Borrar', '25.00');
        $router = $this->dispatch(new Request('DELETE', "/products/{$id}"));
        self::assertContains($router->status, [404, 405]);

        $exists = self::$prodQuery->findById($id);
        self::assertNotNull($exists);
    }

    public function testNoReactivationRouteOrBehavior(): void
    {
        $id = self::$prodCmd->register('Sin Reactivar', '25.00');
        self::$prodCmd->deactivate($id);
        $response = $this->post("/products/{$id}/activate", []);
        self::assertContains($response->status, [404, 405]);
    }

    public function testInactiveProductDoesNotRenderReactivateAction(): void
    {
        $id = self::$prodCmd->register('Inactivo Visible', '30.00');
        self::$prodCmd->deactivate($id);

        $response = $this->dispatch(new Request('GET', '/products'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Inactivo Visible', $response->body);
        self::assertStringContainsString('Inactivo', $response->body);
        self::assertStringNotContainsString('Inactive', $response->body);
        self::assertStringNotContainsString('activate', strtolower($response->body));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body, bool $withCsrf = true): Response
    {
        $session = new CatalogMemorySession();
        $csrf = new Csrf($session);
        if ($withCsrf) {
            $body['_csrf'] = $csrf->token();
        }
        return $this->dispatch(new Request('POST', $path, body: $body), $csrf);
    }

    private function dispatch(Request $request, ?Csrf $csrf = null): Response
    {
        $session = new CatalogMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        $actualCsrf->token();
        $handler = new CatalogHandler(self::$renderer, self::$prodQuery, self::$catQuery, self::$catCmd, self::$prodCmd, $actualCsrf);
        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'catalog') {
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

    public function testProductionEntrypointServesCatalog(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'putenv("APP_ENV=test"); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/products"; $_SERVER["SERVER_NAME"] = "localhost"; require "public/index.php";';

        $process = proc_open([
            PHP_BINARY,
            '-r',
            $code,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);

        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('Undefined array key "catalog"', $stderr);
        self::assertStringNotContainsString('"level":"error"', $stderr);
        self::assertStringContainsString('Productos', $stdout);
        self::assertStringNotContainsString('Product Catalog', $stdout);
        self::assertStringContainsString('id="product-table-container"', $stdout);
        self::assertStringNotContainsString('Request failed', $stdout);
    }
}

final class CatalogMemorySession implements Session
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): mixed
    {
        $value = $this->get($key);
        unset($this->values[$key]);
        return $value;
    }
}
