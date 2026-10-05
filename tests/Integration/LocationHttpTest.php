<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{AlmacenCommand, AlmacenQuery, LocationCommand, LocationHandler, LocationQuery, SucursalCommand};
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

final class LocationHttpTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

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
        $viewContext = new \App\Foundation\ViewContext();
        $viewContext->set('permissions', new \App\Modules\Access\ViewPermissions(new \App\Modules\Access\RouteAccessPolicy(), 'administrador'));
        self::$renderer = new Renderer(dirname(__DIR__, 2), $viewContext);

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
        $pdo->exec('TRUNCATE TABLE almacen');
        $pdo->exec('TRUNCATE TABLE sucursal');
        $pdo->exec('TRUNCATE TABLE usuario');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createWarehouseFixture(string $code = 'ALM-LOC'): int
    {
        $tx = new Transaction(self::$testDb);
        $sucCmd = new SucursalCommand($tx);
        $almCmd = new AlmacenCommand($tx);
        $sucId = $sucCmd->create('SUC-' . uniqid(), 'Sucursal Loc Test', 'Tegucigalpa');
        return $almCmd->create($sucId, $code . '-' . uniqid(), 'Almacen Loc Test');
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
        $wId = $this->createWarehouseFixture();
        self::$locCmd->create('ESTANTE-A1', $wId, 'Estantería metálica pasillo 1');
        self::$locCmd->create('PASILLO-B2', $wId, null);

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
        $wId = $this->createWarehouseFixture();
        self::$locCmd->create('<script>alert("xss")</script>', $wId, '<img src=x onerror=alert(1)>');
        $response = $this->dispatch(new Request('GET', '/locations'));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert("xss")</script>', $response->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $response->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body);
    }

    public function testNoUnsupportedCrudActionsRendered(): void
    {
        $wId = $this->createWarehouseFixture();
        self::$locCmd->create('LOC-01', $wId, 'Test Location');
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
        $wId = $this->createWarehouseFixture();
        $response = $this->post('/locations', [
            'id_almacen'  => (string) $wId,
            'codigo'      => 'BODEGA-01',
            'descripcion' => 'Bodega central de almacenamiento',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/locations', $response->headers['Location']);

        $loc = self::$locQuery->findByCode('BODEGA-01');
        self::assertIsArray($loc);
        self::assertSame('BODEGA-01', $loc['codigo']);
        self::assertSame('Bodega central de almacenamiento', $loc['descripcion']);
        self::assertSame($wId, (int) $loc['id_almacen']);
        self::assertSame(1, (int) $loc['estado_activo']);
    }

    public function testLocationCreationWithoutOptionalDescriptionWorks(): void
    {
        $wId = $this->createWarehouseFixture();
        $response = $this->post('/locations', [
            'id_almacen'  => (string) $wId,
            'codigo'      => 'ZONA-CARGA',
            'descripcion' => '',
        ]);
        self::assertSame(303, $response->status);

        $loc = self::$locQuery->findByCode('ZONA-CARGA');
        self::assertIsArray($loc);
        self::assertNull($loc['descripcion']);
        self::assertSame($wId, (int) $loc['id_almacen']);
    }

    public function testLocationCreationDuplicateCodeRejectedWith422(): void
    {
        $wId = $this->createWarehouseFixture();
        self::$locCmd->create('PASILLO-01', $wId);

        $response = $this->post('/locations', [
            'id_almacen'  => (string) $wId,
            'codigo'      => 'PASILLO-01',
            'descripcion' => 'Intento duplicado',
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Ya existe una ubicación con ese código.', $response->body);
        self::assertStringContainsString('is-active', $response->body);
    }

    public function testLocationCreationEmptyCodeRejectedWith422(): void
    {
        $wId = $this->createWarehouseFixture();
        $response = $this->post('/locations', [
            'id_almacen' => (string) $wId,
            'codigo'     => '   ',
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('El código de la ubicación es obligatorio.', $response->body);
    }

    public function testLocationCreationCodeExceedingFiftyCharsRejectedWith422(): void
    {
        $wId = $this->createWarehouseFixture();
        $longCode = str_repeat('A', 51);
        $response = $this->post('/locations', [
            'id_almacen' => (string) $wId,
            'codigo'     => $longCode,
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('El código de la ubicación no debe exceder los 50 caracteres.', $response->body);
    }

    public function testLocationCreationMissingWarehouseRejectedWith422(): void
    {
        $response = $this->post('/locations', [
            'codigo' => 'LOC-NO-WAREHOUSE',
        ]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('El almacén es obligatorio.', $response->body);
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
        $wId = $this->createWarehouseFixture();
        $response = $this->post('/locations', [
            'id_almacen' => (string) $wId,
            'codigo'     => 'HTMX-LOC',
        ], headers: ['hx-request' => 'true']);

        self::assertSame(200, $response->status);
        self::assertSame('/locations', $response->headers['HX-Redirect']);
        self::assertStringContainsString('Ubicación registrada correctamente.', $response->headers['HX-Trigger']);
    }

    public function testLocationCreationAcceptsLowercaseAndMixedCaseCode(): void
    {
        $wId = $this->createWarehouseFixture();
        $response = $this->post('/locations', [
            'id_almacen'  => (string) $wId,
            'codigo'      => 'pasillo-norte-01',
            'descripcion' => 'Ubicación con código en minúsculas',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/locations', $response->headers['Location']);

        $loc = self::$locQuery->findByCode('pasillo-norte-01');
        self::assertIsArray($loc);
        self::assertSame('pasillo-norte-01', $loc['codigo']);
        self::assertSame('Ubicación con código en minúsculas', $loc['descripcion']);
        self::assertSame($wId, (int) $loc['id_almacen']);
        self::assertSame(1, (int) $loc['estado_activo']);
    }

    public function testProductionEntrypointServesLocations(): void
    {
        $adminId = $this->createAuthUser(self::$testDb, 'admin_location_entrypoint', 'administrador', 'activo');
        $res = $this->runEntrypointRequest('GET', '/locations', $adminId);

        self::assertSame(200, $res['status']);
        self::assertSame(0, $res['exitCode']);
        self::assertStringNotContainsString('Undefined array key "location"', $res['stderr']);
        self::assertStringNotContainsString('"level":"error"', $res['stderr']);
        self::assertStringContainsString('Ubicaciones', $res['stdout']);
        self::assertStringContainsString('id="location-table-container"', $res['stdout']);
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
        $almacenQuery = new AlmacenQuery(self::$testDb);
        $handler = new LocationHandler(self::$renderer, self::$locQuery, self::$locCmd, $almacenQuery, $actualCsrf);
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
