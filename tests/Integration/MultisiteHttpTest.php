<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, MigrationRunner, Renderer, Request, Response, Router, Session, Transaction};
use App\Modules\Inventory\{AlmacenCommand, AlmacenQuery, BranchHandler, SucursalCommand, SucursalQuery, WarehouseHandler};
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

final class MultisiteHttpTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

    private static Database $testDb;
    private static Database $devDb;
    private static Config $config;
    private static SucursalQuery $sucQuery;
    private static SucursalCommand $sucCmd;
    private static AlmacenQuery $almQuery;
    private static AlmacenCommand $almCmd;
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
        self::$sucQuery = new SucursalQuery(self::$testDb);
        self::$sucCmd   = new SucursalCommand($tx);
        self::$almQuery = new AlmacenQuery(self::$testDb);
        self::$almCmd   = new AlmacenCommand($tx);

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

    public function testBranchesPageReturns200AndRendersCanonicalHtml(): void
    {
        $response = $this->dispatch(new Request('GET', '/branches'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Sucursales', $response->body);
        self::assertStringContainsString('Gestiona las sucursales comerciales de Ferreterías El Constructor.', $response->body);
        self::assertStringContainsString('Nueva sucursal', $response->body);
        self::assertStringContainsString('No hay sucursales registradas', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testBranchesListingWithData(): void
    {
        self::$sucCmd->create('SUC-01', 'Sucursal Central', 'Managua', 'Km 5 Carretera Norte', '+505 2222-1111');

        $response = $this->dispatch(new Request('GET', '/branches'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('SUC-01', $response->body);
        self::assertStringContainsString('Sucursal Central', $response->body);
        self::assertStringContainsString('Managua', $response->body);
        self::assertStringContainsString('Km 5 Carretera Norte', $response->body);
        self::assertStringContainsString('+505 2222-1111', $response->body);
        self::assertStringContainsString('<span class="badge-status badge-active">Activo</span>', $response->body);
        self::assertStringNotContainsString('No hay sucursales registradas', $response->body);
    }

    public function testBranchRegistrationValidationErrorsReturn422(): void
    {
        $response = $this->post('/branches', [
            'codigo'    => '',
            'nombre'    => '',
            'ciudad'    => '',
            'direccion' => '',
            'telefono'  => '',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('El código de la sucursal es obligatorio.', $response->body);
        self::assertStringContainsString('El nombre de la sucursal es obligatorio.', $response->body);
        self::assertStringContainsString('La ciudad es obligatoria.', $response->body);
        self::assertCount(0, self::$sucQuery->all());
    }

    public function testBranchRegistrationDuplicateCodeReturns422(): void
    {
        self::$sucCmd->create('SUC-DUP', 'Sucursal Existente', 'Granada');

        $response = $this->post('/branches', [
            'codigo'    => 'SUC-DUP',
            'nombre'    => 'Otra Sucursal',
            'ciudad'    => 'Masaya',
            'direccion' => '',
            'telefono'  => '',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Ya existe una sucursal con ese código.', $response->body);
        self::assertCount(1, self::$sucQuery->all());
    }

    public function testBranchRegistrationSuccessfulRedirect303AndHtmx(): void
    {
        // 1. Normal browser POST: returns HTTP 303 with Location
        $resNormal = $this->post('/branches', [
            'codigo'    => 'SUC-NORM',
            'nombre'    => 'Sucursal Normal',
            'ciudad'    => 'Chinandega',
            'direccion' => 'Calle Central',
            'telefono'  => '2345-6789',
        ]);
        self::assertSame(303, $resNormal->status);
        self::assertSame('/branches', $resNormal->headers['Location'] ?? '');
        self::assertNotNull(self::$sucQuery->findByCode('SUC-NORM'));

        // 2. HTMX POST: returns HTTP 200 with HX-Redirect and HX-Trigger
        $resHtmx = $this->post('/branches', [
            'codigo'    => 'SUC-HTMX',
            'nombre'    => 'Sucursal HTMX',
            'ciudad'    => 'Leon',
            'direccion' => '',
            'telefono'  => '',
        ], headers: ['hx-request' => 'true']);

        self::assertSame(200, $resHtmx->status);
        self::assertSame('/branches', $resHtmx->headers['HX-Redirect'] ?? '');
        self::assertArrayHasKey('HX-Trigger', $resHtmx->headers);
        self::assertStringContainsString('Sucursal registrada correctamente.', $resHtmx->headers['HX-Trigger']);
        self::assertNotNull(self::$sucQuery->findByCode('SUC-HTMX'));
    }

    public function testBranchToggleActiveStatus(): void
    {
        $id = self::$sucCmd->create('SUC-TOG', 'Sucursal Toggle', 'Rivas');
        $branch = self::$sucQuery->findById($id);
        self::assertNotNull($branch);
        self::assertSame(1, $branch['estado_activo']);

        // Toggle to inactive: returns 303
        $res1 = $this->post('/branches/toggle-active', ['id_sucursal' => (string) $id]);
        self::assertSame(303, $res1->status);
        self::assertSame('/branches', $res1->headers['Location'] ?? '');

        $branchAfter1 = self::$sucQuery->findById($id);
        self::assertNotNull($branchAfter1);
        self::assertSame(0, $branchAfter1['estado_activo']);

        // Toggle back to active via HTMX: returns 200 with HX-Redirect
        $res2 = $this->post('/branches/toggle-active', ['id_sucursal' => (string) $id], headers: ['hx-request' => 'true']);
        self::assertSame(200, $res2->status);
        self::assertSame('/branches', $res2->headers['HX-Redirect'] ?? '');

        $branchAfter2 = self::$sucQuery->findById($id);
        self::assertNotNull($branchAfter2);
        self::assertSame(1, $branchAfter2['estado_activo']);

        // Invalid ID returns 422
        $resInvalid = $this->post('/branches/toggle-active', ['id_sucursal' => '99999']);
        self::assertSame(422, $resInvalid->status);
    }

    public function testWarehousesPageReturns200AndRendersCanonicalHtml(): void
    {
        $response = $this->dispatch(new Request('GET', '/warehouses'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Almacenes', $response->body);
        self::assertStringContainsString('Gestiona los almacenes y áreas de almacenamiento por sucursal.', $response->body);
        self::assertStringContainsString('Nuevo almacén', $response->body);
        self::assertStringContainsString('No hay almacenes registrados', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('HX-Request', $response->headers['Vary']);
    }

    public function testWarehousesListingAndFilteringByBranch(): void
    {
        $s1 = self::$sucCmd->create('SUC-A', 'Sucursal A', 'Managua');
        $s2 = self::$sucCmd->create('SUC-B', 'Sucursal B', 'Leon');

        self::$almCmd->create($s1, 'ALM-A1', 'Bodega Alfa', 'bodega');
        self::$almCmd->create($s2, 'ALM-B1', 'Mostrador Beta', 'mostrador');

        // All warehouses
        $resAll = $this->dispatch(new Request('GET', '/warehouses'));
        self::assertSame(200, $resAll->status);
        self::assertStringContainsString('ALM-A1', $resAll->body);
        self::assertStringContainsString('Bodega Alfa', $resAll->body);
        self::assertStringContainsString('ALM-B1', $resAll->body);
        self::assertStringContainsString('Mostrador Beta', $resAll->body);

        // Filter by branch A
        $resFilterA = $this->dispatch(new Request('GET', '/warehouses', query: ['sucursal' => (string) $s1]));
        self::assertSame(200, $resFilterA->status);
        self::assertStringContainsString('ALM-A1', $resFilterA->body);
        self::assertStringNotContainsString('ALM-B1', $resFilterA->body);
    }

    public function testWarehouseRegistrationValidationErrorsReturn422(): void
    {
        $response = $this->post('/warehouses', [
            'id_sucursal' => '',
            'codigo'      => '',
            'nombre'      => '',
            'tipo'        => '',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('La sucursal es obligatoria.', $response->body);
        self::assertStringContainsString('El código del almacén es obligatorio.', $response->body);
        self::assertStringContainsString('El nombre del almacén es obligatorio.', $response->body);
        self::assertStringContainsString('Tipo de almacén no válido.', $response->body);
        self::assertCount(0, self::$almQuery->all());
    }

    public function testWarehouseRegistrationFailsOnInactiveOrNonexistentBranchReturn422(): void
    {
        $sucId = self::$sucCmd->create('SUC-INACT', 'Sucursal Inactiva', 'Estelí');
        self::$sucCmd->toggleActive($sucId); // Inactive now

        $response = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-FAIL',
            'nombre'      => 'Almacen Fallido',
            'tipo'        => 'bodega',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('No se puede crear un almacén en una sucursal inactiva o inexistente.', $response->body);
        self::assertCount(0, self::$almQuery->all());
    }

    public function testWarehouseRegistrationDuplicateCodeReturns422(): void
    {
        $sucId = self::$sucCmd->create('SUC-ACT', 'Sucursal Activa', 'Matagalpa');
        self::$almCmd->create($sucId, 'ALM-DUP', 'Almacen Existente', 'bodega');

        $response = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-DUP',
            'nombre'      => 'Otro Almacen',
            'tipo'        => 'patio',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Ya existe un almacén con ese código.', $response->body);
        self::assertCount(1, self::$almQuery->all());
    }

    public function testWarehouseRegistrationSuccessfulRedirect303AndHtmx(): void
    {
        $sucId = self::$sucCmd->create('SUC-WH-OK', 'Sucursal WH OK', 'Boaco');

        // 1. Normal POST
        $resNormal = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-NORM',
            'nombre'      => 'Almacen Normal',
            'tipo'        => 'mostrador',
        ]);
        self::assertSame(303, $resNormal->status);
        self::assertSame('/warehouses', $resNormal->headers['Location'] ?? '');
        self::assertNotNull(self::$almQuery->findByCode('ALM-NORM'));

        // 2. HTMX POST
        $resHtmx = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-HTMX',
            'nombre'      => 'Almacen HTMX',
            'tipo'        => 'patio',
        ], headers: ['hx-request' => 'true']);

        self::assertSame(200, $resHtmx->status);
        self::assertSame('/warehouses', $resHtmx->headers['HX-Redirect'] ?? '');
        self::assertArrayHasKey('HX-Trigger', $resHtmx->headers);
        self::assertStringContainsString('Almacén registrado correctamente.', $resHtmx->headers['HX-Trigger']);
        self::assertNotNull(self::$almQuery->findByCode('ALM-HTMX'));
    }

    public function testWarehouseToggleActiveAndReactivationGuardUnderInactiveBranch(): void
    {
        $sucId = self::$sucCmd->create('SUC-GUARD', 'Sucursal Guard', 'Juigalpa');
        $almId = self::$almCmd->create($sucId, 'ALM-GUARD', 'Almacen Guard', 'bodega');

        // Deactivate warehouse: succeeds
        $resDeact = $this->post('/warehouses/toggle-active', ['id_almacen' => (string) $almId]);
        self::assertSame(303, $resDeact->status);
        $wh = self::$almQuery->findById($almId);
        self::assertNotNull($wh);
        self::assertSame(0, $wh['estado_activo']);

        // Deactivate parent branch
        self::$sucCmd->toggleActive($sucId);
        $branch = self::$sucQuery->findById($sucId);
        self::assertNotNull($branch);
        self::assertSame(0, $branch['estado_activo']);

        // Attempt reactivation under inactive parent branch: caught DomainException -> 422
        $resReactivate = $this->post('/warehouses/toggle-active', ['id_almacen' => (string) $almId]);
        self::assertSame(422, $resReactivate->status);
        self::assertStringContainsString('No se puede reactivar un almacén cuya sucursal está inactiva.', $resReactivate->body);

        $whStillInactive = self::$almQuery->findById($almId);
        self::assertNotNull($whStillInactive);
        self::assertSame(0, $whStillInactive['estado_activo']);
    }

    public function testWarehouseForgedParentBranchHasZeroEffect(): void
    {
        $suc1 = self::$sucCmd->create('SUC-ORIG', 'Sucursal Original', 'Jinotepe');
        $suc2 = self::$sucCmd->create('SUC-FORGED', 'Sucursal Forjada', 'Diriamba');
        $almId = self::$almCmd->create($suc1, 'ALM-IMMUT', 'Almacen Inmutable', 'bodega');

        // Post toggle-active with forged id_sucursal pointing to suc2
        $res = $this->post('/warehouses/toggle-active', [
            'id_almacen'  => (string) $almId,
            'id_sucursal' => (string) $suc2,
        ]);
        self::assertSame(303, $res->status);

        $wh = self::$almQuery->findById($almId);
        self::assertNotNull($wh);
        self::assertSame($suc1, $wh['id_sucursal'], 'Parent branch id_sucursal MUST remain immutable and unreprented.');
    }

    public function testHtmlEscapingInBranchesAndWarehouses(): void
    {
        $sId = self::$sucCmd->create('SUC-<XSS>', '<script>alert("suc")</script>', '<img src=x onerror=alert(1)>', '<p>dir</p>', '<b>tel</b>');
        self::$almCmd->create($sId, 'ALM-<XSS>', '<script>alert("alm")</script>', 'bodega');

        $resBranch = $this->dispatch(new Request('GET', '/branches'));
        self::assertSame(200, $resBranch->status);
        self::assertStringNotContainsString('<script>alert("suc")</script>', $resBranch->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;suc&quot;)&lt;/script&gt;', $resBranch->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $resBranch->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $resBranch->body);

        $resWarehouse = $this->dispatch(new Request('GET', '/warehouses'));
        self::assertSame(200, $resWarehouse->status);
        self::assertStringNotContainsString('<script>alert("alm")</script>', $resWarehouse->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;alm&quot;)&lt;/script&gt;', $resWarehouse->body);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?Csrf $csrf = null, array $headers = []): Response
    {
        $session = new MultisiteMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);
        if (!isset($body['_csrf'])) {
            $body['_csrf'] = $actualCsrf->token();
        }
        return $this->dispatch(new Request('POST', $path, headers: $headers, body: $body), $actualCsrf);
    }

    private function dispatch(Request $request, ?Csrf $csrf = null): Response
    {
        $session = new MultisiteMemorySession();
        $actualCsrf = $csrf ?? new Csrf($session);

        $branchHandler = new BranchHandler(self::$renderer, self::$sucQuery, self::$sucCmd, $actualCsrf);
        $warehouseHandler = new WarehouseHandler(self::$renderer, self::$almQuery, self::$almCmd, self::$sucQuery, $actualCsrf);

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'branch') {
                $routes[] = [$method, $path, $branchHandler->handle(...)];
            } elseif ($name === 'warehouse') {
                $routes[] = [$method, $path, $warehouseHandler->handle(...)];
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

final class MultisiteMemorySession implements Session
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
