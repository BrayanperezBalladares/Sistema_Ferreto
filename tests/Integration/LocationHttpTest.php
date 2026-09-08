<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{LocationCommand, LocationHandler, LocationQuery};
use PDO;
use PHPUnit\Framework\TestCase;

final class LocationHttpTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Database $testDb;
    private static Database $devDb;
    private static Config $config;
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
        self::$locQuery = new LocationQuery(self::$testDb);
        self::$locCmd = new LocationCommand($tx);
        self::$renderer = new Renderer(dirname(__DIR__, 2));

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
        $pdo->exec('TRUNCATE TABLE ubicacion');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testLocationsPageReturns200AndRendersCanonicalHtml(): void
    {
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Ubicaciones', $response->body);
        self::assertStringContainsString('Gestiona los espacios físicos donde se mantiene el inventario.', $response->body);
        self::assertStringContainsString('Nueva ubicación', $response->body);
        self::assertStringContainsString('Ferreterías El Constructor', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testNavigationContainsActiveLocationsAndInactiveProducts(): void
    {
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Catálogo', $response->body);
        self::assertMatchesRegularExpression('/href="\/products"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/locations"[^>]*class="nav-item\s+is-active"/i', $response->body);
        self::assertStringContainsString('<span class="topbar-crumb">Catálogo</span>', $response->body);
        self::assertStringContainsString('<span class="topbar-current">Ubicaciones</span>', $response->body);

        self::assertMatchesRegularExpression('/href="\/inventory"[^>]*class="nav-item\s*"/i', $response->body);
        self::assertMatchesRegularExpression('/href="\/inventory\/counts"[^>]*class="nav-item\s*"/i', $response->body);

        foreach (['Ventas', 'Proveedores', 'Sucursales', 'Reportes', 'Configuración'] as $deadLink) {
            self::assertStringNotContainsString($deadLink, $response->body);
        }
    }

    public function testEmptyStateWhenNoLocationsExist(): void
    {
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No hay ubicaciones registradas', $response->body);
        self::assertStringContainsString('Registra una ubicación para comenzar a organizar físicamente el inventario.', $response->body);
    }

    public function testLocationsListingWithData(): void
    {
        self::$locCmd->create('ESTANTE-A1', 'Estantería metálica pasillo 1');
        self::$locCmd->create('PASILLO-B2', null);

        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<th scope="col" style="width: 30%;">Código</th>', $response->body);
        self::assertStringContainsString('<th scope="col" style="width: 50%;">Descripción</th>', $response->body);
        self::assertStringContainsString('<th scope="col" style="width: 20%;" class="has-text-centered">Estado</th>', $response->body);
        self::assertStringContainsString('ESTANTE-A1', $response->body);
        self::assertStringContainsString('Estantería metálica pasillo 1', $response->body);
        self::assertStringContainsString('PASILLO-B2', $response->body);
        self::assertStringContainsString('Sin descripción', $response->body);
        self::assertStringContainsString('<span class="badge-status badge-active">Activo</span>', $response->body);
        self::assertStringNotContainsString('No hay ubicaciones registradas', $response->body);
    }

    public function testHtmlEscapingInLocationCodeAndDescription(): void
    {
        self::$locCmd->create('<script>alert("xss")</script>', '<img src=x onerror=alert(1)>');
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("xss")</script>', $response->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $response->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body);
    }

    public function testNoUnsupportedCrudActionsRendered(): void
    {
        self::$locCmd->create('LOC-01', 'Test Location');
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<th scope="col">Acciones</th>', $response->body);
        self::assertStringNotContainsString('>Acciones<', $response->body);

        foreach (['Editar', 'Eliminar', 'Desactivar', 'Activar', 'Restaurar', 'Reactivar', 'Asignar producto', 'Ajustar existencia', 'Registrar conteo'] as $unsupported) {
            self::assertStringNotContainsString($unsupported, $response->body);
        }
    }

    public function testLocationCreationSucceedsAndRedirects(): void
    {
        $response = $this->post('/locations', [
            'codigo'      => 'BODEGA-01',
            'descripcion' => 'Bodega central de almacenamiento',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/locations', $response->headers['Location']);

        $loc = self::$locQuery->findByCode('BODEGA-01');
        self::assertIsArray($loc);
        self::assertSame('BODEGA-01', $loc['codigo']);
        self::assertSame('Bodega central de almacenamiento', $loc['descripcion']);
        self::assertSame(1, (int) $loc['estado_activo']);
    }

    public function testLocationCreationWithoutOptionalDescriptionWorks(): void
    {
        $response = $this->post('/locations', [
            'codigo'      => 'ZONA-CARGA',
            'descripcion' => '',
        ]);
        self::assertSame(303, $response->status);

        $loc = self::$locQuery->findByCode('ZONA-CARGA');
        self::assertIsArray($loc);
        self::assertNull($loc['descripcion']);
    }

    public function testLocationCreationDuplicateCodeRejectedWith422(): void
    {
        self::$locCmd->create('PASILLO-01');

        $response = $this->post('/locations', [
            'codigo'      => 'PASILLO-01',
            'descripcion' => 'Intento duplicado',
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Ya existe una ubicación con ese código.', $response->body);
        self::assertStringContainsString('is-active', $response->body);
    }

    public function testLocationCreationEmptyCodeRejectedWith422(): void
    {
        $response = $this->post('/locations', [
            'codigo' => '   ',
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('El código de la ubicación es obligatorio.', $response->body);
    }

    public function testLocationCreationCodeExceedingFiftyCharsRejectedWith422(): void
    {
        $longCode = str_repeat('A', 51);
        $response = $this->post('/locations', [
            'codigo' => $longCode,
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('El código de la ubicación no debe exceder los 50 caracteres.', $response->body);
    }

    public function testLocationCreationRequiresCsrfToken(): void
    {
        $response = $this->dispatch(new Request('POST', '/locations', body: [
            'codigo' => 'UNAUTHORIZED',
            '_csrf'  => 'invalid-token-12345',
        ]));
        self::assertSame(403, $response->status);
        self::assertNull(self::$locQuery->findByCode('UNAUTHORIZED'));
    }

    public function testHtmxLocationCreationReturnsHxRedirectAndTrigger(): void
    {
        $response = $this->post('/locations', [
            'codigo' => 'HTMX-LOC',
        ], headers: ['hx-request' => 'true']);

        self::assertSame(200, $response->status);
        self::assertSame('/locations', $response->headers['HX-Redirect']);
        self::assertStringContainsString('Ubicación registrada correctamente.', $response->headers['HX-Trigger']);
    }

    public function testLocationCreationAcceptsLowercaseAndMixedCaseCode(): void
    {
        $response = $this->post('/locations', [
            'codigo'      => 'pasillo-norte-01',
            'descripcion' => 'Ubicación con código en minúsculas',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/locations', $response->headers['Location']);

        $loc = self::$locQuery->findByCode('pasillo-norte-01');
        self::assertIsArray($loc);
        self::assertSame('pasillo-norte-01', $loc['codigo']);
        self::assertSame('Ubicación con código en minúsculas', $loc['descripcion']);
        self::assertSame(1, (int) $loc['estado_activo']);
    }

    public function testProductionEntrypointServesLocations(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'putenv("APP_ENV=test"); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/locations"; $_SERVER["SERVER_NAME"] = "localhost"; require "public/index.php";';
        $proc = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $p) { fclose($p); }
        $exitCode = proc_close($proc);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('Undefined array key "location"', $stderr);
        self::assertStringNotContainsString('"level":"error"', $stderr);
        self::assertStringContainsString('Ubicaciones', $stdout);
        self::assertStringContainsString('id="location-table-container"', $stdout);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?Csrf $csrf = null, array $headers = []): Response
    {
        $session = new LocationMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        if (!isset($body['_csrf'])) {
            $body['_csrf'] = $actualCsrf->token();
        }
        return $this->dispatch(new Request('POST', $path, headers: $headers, body: $body), $actualCsrf);
    }

    private function dispatch(Request $request, ?Csrf $csrf = null): Response
    {
        $session = new LocationMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        $handler = new LocationHandler(self::$renderer, self::$locQuery, self::$locCmd, $actualCsrf);
        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'location') {
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

final class LocationMemorySession implements Session
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
