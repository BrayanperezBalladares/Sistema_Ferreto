<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\{Config, Csrf, Database, ErrorMapper, Kernel, Logger, MigrationRunner, NativeSession, Renderer, Request, Response, Router, Session, Transaction, ViewContext};
use App\Modules\Access\{AuthGuard, AuthSession, RoleGuard, RouteAccessPolicy, UserCommand, UserQuery, ViewPermissions};
use App\Modules\Inventory\{AlmacenCommand, AlmacenQuery, BranchHandler, CountCommand, LocationCommand, LocationHandler, LocationQuery, ProductCommand, StockCommand, SucursalCommand, SucursalQuery, WarehouseHandler};
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
    private static LocationQuery $locQuery;
    private static LocationCommand $locCmd;
    private static ProductCommand $prodCmd;
    private static StockCommand $stockCmd;
    private static CountCommand $countCmd;
    private static UserQuery $userQuery;
    private static UserCommand $userCmd;
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
        self::$sucQuery  = new SucursalQuery(self::$testDb);
        self::$sucCmd    = new SucursalCommand($tx);
        self::$almQuery  = new AlmacenQuery(self::$testDb);
        self::$almCmd    = new AlmacenCommand($tx);
        self::$locQuery  = new LocationQuery(self::$testDb);
        self::$locCmd    = new LocationCommand($tx);
        self::$prodCmd   = new ProductCommand($tx);
        self::$stockCmd  = new StockCommand($tx);
        self::$countCmd  = new CountCommand($tx);
        self::$userQuery = new UserQuery(self::$testDb);
        self::$userCmd   = new UserCommand($tx);

        $viewContext = new ViewContext();
        $viewContext->set('permissions', new ViewPermissions(new RouteAccessPolicy(), 'administrador'));
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
        $pdo->exec('TRUNCATE TABLE conteo_inventario');
        $pdo->exec('TRUNCATE TABLE inventario_stock');
        $pdo->exec('TRUNCATE TABLE producto');
        $pdo->exec('TRUNCATE TABLE categoria');
        $pdo->exec('TRUNCATE TABLE ubicacion');
        $pdo->exec('TRUNCATE TABLE almacen');
        $pdo->exec('TRUNCATE TABLE sucursal');
        $pdo->exec('TRUNCATE TABLE usuario');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
        }
        $_SESSION = [];
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

    public function testRoleGuardAdministratorAllowedOnAllFacilityRoutes(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $sucId = self::$sucCmd->create('SUC-ADM-T', 'Sucursal Admin Test', 'Granada');
        $sucIdToggle = self::$sucCmd->create('SUC-ADM-TOG', 'Sucursal Admin Toggle', 'Leon');
        $almId = self::$almCmd->create($sucId, 'ALM-ADM-T', 'Almacen Admin Test');
        $almIdToggle = self::$almCmd->create($sucId, 'ALM-ADM-TOG', 'Almacen Admin Toggle');
        self::$locCmd->create('LOC-ADM-T', $almId, 'Ubicacion Admin Test');

        /** @var list<array{string, string, array<string, mixed>}> $routes */
        $routes = [
            ['GET', '/branches', []],
            ['POST', '/branches', ['_csrf' => $token, 'codigo' => 'SUC-ADM-NEW', 'nombre' => 'Sucursal Nueva', 'ciudad' => 'Leon']],
            ['POST', '/branches/toggle-active', ['_csrf' => $token, 'id_sucursal' => (string) $sucIdToggle]],
            ['GET', '/warehouses', []],
            ['POST', '/warehouses', ['_csrf' => $token, 'id_sucursal' => (string) $sucId, 'codigo' => 'ALM-ADM-NEW', 'nombre' => 'Almacen Nuevo', 'tipo' => 'bodega']],
            ['POST', '/warehouses/toggle-active', ['_csrf' => $token, 'id_almacen' => (string) $almIdToggle]],
            ['GET', '/locations', []],
            ['POST', '/locations', ['_csrf' => $token, 'codigo' => 'LOC-ADM-NEW', 'id_almacen' => (string) $almId, 'descripcion' => 'Nueva Ubicacion']],
        ];

        foreach ($routes as [$method, $path, $body]) {
            $req = new Request($method, $path, body: $body);
            $res = $this->dispatchKernel($req, role: 'administrador', session: $session, csrf: $csrf);
            self::assertNotSame(403, $res->status, "Administrator must NOT be denied with 403 on {$method} {$path}.");
            self::assertContains($res->status, [200, 303], "Expected 200 or 303 on {$method} {$path}, got {$res->status}.");
        }
    }

    public function testRoleGuardBodegueroMatrixVerification(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $sucId = self::$sucCmd->create('SUC-BOD-T', 'Sucursal Bod Test', 'Masaya');
        $almId = self::$almCmd->create($sucId, 'ALM-BOD-T', 'Almacen Bod Test');

        // Allowed routes for bodeguero
        $resWh = $this->dispatchKernel(new Request('GET', '/warehouses'), role: 'bodeguero', session: $session, csrf: $csrf);
        self::assertSame(200, $resWh->status, 'Bodeguero must receive 200 on GET /warehouses.');

        $resLoc = $this->dispatchKernel(new Request('GET', '/locations'), role: 'bodeguero', session: $session, csrf: $csrf);
        self::assertSame(200, $resLoc->status, 'Bodeguero must receive 200 on GET /locations.');

        $resPostLoc = $this->dispatchKernel(new Request('POST', '/locations', body: [
            '_csrf'       => $token,
            'codigo'      => 'LOC-BOD-NEW',
            'id_almacen'  => (string) $almId,
            'descripcion' => 'Ubicacion Bodeguero',
        ]), role: 'bodeguero', session: $session, csrf: $csrf);
        self::assertSame(303, $resPostLoc->status, 'Bodeguero must receive 303 on POST /locations.');

        // Denied routes for bodeguero (403 Forbidden)
        /** @var list<array{string, string, array<string, mixed>}> $denied */
        $denied = [
            ['GET', '/branches', []],
            ['POST', '/branches', ['_csrf' => $token, 'codigo' => 'SUC-NO', 'nombre' => 'No', 'ciudad' => 'No']],
            ['POST', '/branches/toggle-active', ['_csrf' => $token, 'id_sucursal' => (string) $sucId]],
            ['POST', '/warehouses', ['_csrf' => $token, 'id_sucursal' => (string) $sucId, 'codigo' => 'ALM-NO', 'nombre' => 'No', 'tipo' => 'bodega']],
            ['POST', '/warehouses/toggle-active', ['_csrf' => $token, 'id_almacen' => (string) $almId]],
        ];

        foreach ($denied as [$method, $path, $body]) {
            $res = $this->dispatchKernel(new Request($method, $path, body: $body), role: 'bodeguero', session: $session, csrf: $csrf);
            self::assertSame(403, $res->status, "Bodeguero must receive 403 on {$method} {$path}.");
            self::assertSame('Forbidden', $res->body);
        }
    }

    public function testRoleGuardCajeroAndComprasForbiddenOnAllBranchAndWarehouseRoutes(): void
    {
        $sucId = self::$sucCmd->create('SUC-CC', 'Sucursal CC', 'Chinandega');
        $almId = self::$almCmd->create($sucId, 'ALM-CC', 'Almacen CC');

        foreach (['cajero', 'compras'] as $role) {
            $session = new NativeSession(false);
            $csrf = new Csrf($session);
            $token = $csrf->token();

            /** @var list<array{string, string, array<string, mixed>}> $facilityRoutes */
            $facilityRoutes = [
                ['GET', '/branches', []],
                ['POST', '/branches', ['_csrf' => $token, 'codigo' => 'SUC-DENIED', 'nombre' => 'D', 'ciudad' => 'D']],
                ['POST', '/branches/toggle-active', ['_csrf' => $token, 'id_sucursal' => (string) $sucId]],
                ['GET', '/warehouses', []],
                ['POST', '/warehouses', ['_csrf' => $token, 'id_sucursal' => (string) $sucId, 'codigo' => 'ALM-DENIED', 'nombre' => 'D', 'tipo' => 'bodega']],
                ['POST', '/warehouses/toggle-active', ['_csrf' => $token, 'id_almacen' => (string) $almId]],
            ];

            foreach ($facilityRoutes as [$method, $path, $body]) {
                $res = $this->dispatchKernel(new Request($method, $path, body: $body), role: $role, session: $session, csrf: $csrf);
                self::assertSame(403, $res->status, "Role '{$role}' must receive 403 on {$method} {$path}.");
                self::assertSame('Forbidden', $res->body);
            }
        }
    }

    public function testStructuralFailClosedRegressionForUnmappedRegisteredRoute(): void
    {
        $session = new NativeSession(false);
        $res = $this->dispatchKernel(
            new Request('GET', '/unmapped-registered-facility-route'),
            role: 'administrador',
            session: $session,
            unmappedRoute: '/unmapped-registered-facility-route'
        );

        self::assertSame(403, $res->status, 'Any registered non-exempt route missing from RouteAccessPolicy must return 403 Forbidden.');
        self::assertSame('Forbidden', $res->body);
    }

    public function testCsrfValidationOnMutationsRejectsMissingOrInvalidTokenWithZeroStateChanges(): void
    {
        $sucId = self::$sucCmd->create('SUC-CSRF-T', 'Sucursal CSRF Test', 'Estelí');
        $almId = self::$almCmd->create($sucId, 'ALM-CSRF-T', 'Almacen CSRF Test');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $validToken = $csrf->token();

        // 1. Valid token succeeds
        $resValid = $this->dispatchKernel(new Request('POST', '/branches', body: [
            '_csrf'     => $validToken,
            'codigo'    => 'SUC-CSRF-OK',
            'nombre'    => 'Sucursal CSRF OK',
            'ciudad'    => 'Rivas',
            'direccion' => '',
            'telefono'  => '',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(303, $resValid->status);
        self::assertNotNull(self::$sucQuery->findByCode('SUC-CSRF-OK'));

        // 2. Missing CSRF token: 403 Forbidden and zero state changes
        $initialBranchCount = count(self::$sucQuery->all());
        $initialWhCount = count(self::$almQuery->all());
        $initialLocCount = count(self::$locQuery->all());

        // POST /branches missing CSRF
        $resNoCsrfBranch = $this->dispatchKernel(new Request('POST', '/branches', body: [
            'codigo' => 'SUC-NO-CSRF',
            'nombre' => 'No CSRF',
            'ciudad' => 'Leon',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resNoCsrfBranch->status);
        self::assertSame('Forbidden', $resNoCsrfBranch->body);
        self::assertCount($initialBranchCount, self::$sucQuery->all(), 'No branch should be created when CSRF is missing.');

        // POST /warehouses missing CSRF
        $resNoCsrfWh = $this->dispatchKernel(new Request('POST', '/warehouses', body: [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-NO-CSRF',
            'nombre'      => 'No CSRF Wh',
            'tipo'        => 'bodega',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resNoCsrfWh->status);
        self::assertSame('Forbidden', $resNoCsrfWh->body);
        self::assertCount($initialWhCount, self::$almQuery->all(), 'No warehouse should be created when CSRF is missing.');

        // POST /locations missing CSRF
        $resNoCsrfLoc = $this->dispatchKernel(new Request('POST', '/locations', body: [
            'id_almacen' => (string) $almId,
            'codigo'     => 'LOC-NO-CSRF',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resNoCsrfLoc->status);
        self::assertSame('Forbidden', $resNoCsrfLoc->body);
        self::assertCount($initialLocCount, self::$locQuery->all(), 'No location should be created when CSRF is missing.');

        // 3. Invalid CSRF token: 403 Forbidden and zero state changes
        $resBadCsrfBranch = $this->dispatchKernel(new Request('POST', '/branches', body: [
            '_csrf'  => 'invalid_csrf_token_value',
            'codigo' => 'SUC-BAD-CSRF',
            'nombre' => 'Bad CSRF',
            'ciudad' => 'Leon',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resBadCsrfBranch->status);
        self::assertSame('Forbidden', $resBadCsrfBranch->body);
        self::assertCount($initialBranchCount, self::$sucQuery->all(), 'No branch should be created when CSRF is invalid.');

        $resBadCsrfWh = $this->dispatchKernel(new Request('POST', '/warehouses', body: [
            '_csrf'       => 'invalid_csrf_token_value',
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-BAD-CSRF',
            'nombre'      => 'Bad CSRF Wh',
            'tipo'        => 'bodega',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resBadCsrfWh->status);
        self::assertSame('Forbidden', $resBadCsrfWh->body);
        self::assertCount($initialWhCount, self::$almQuery->all(), 'No warehouse should be created when CSRF is invalid.');

        $resBadCsrfLoc = $this->dispatchKernel(new Request('POST', '/locations', body: [
            '_csrf'      => 'invalid_csrf_token_value',
            'id_almacen' => (string) $almId,
            'codigo'     => 'LOC-BAD-CSRF',
        ]), role: 'administrador', session: $session, csrf: $csrf);
        self::assertSame(403, $resBadCsrfLoc->status);
        self::assertSame('Forbidden', $resBadCsrfLoc->body);
        self::assertCount($initialLocCount, self::$locQuery->all(), 'No location should be created when CSRF is invalid.');
    }

    public function testLocationParentImmutabilityForgedParentChangeRejectedWithoutSideEffects(): void
    {
        $sucId = self::$sucCmd->create('SUC-PARENT', 'Sucursal Parent', 'Granada');
        $whA   = self::$almCmd->create($sucId, 'ALM-PA', 'Almacen A');
        $whB   = self::$almCmd->create($sucId, 'ALM-PB', 'Almacen B');

        $locId = self::$locCmd->create('LOC-IMM-1', $whA, 'Ubicacion Fija A');

        // Create product, stock, count
        $prodId = self::$prodCmd->register('Disco Corte 4.5', '45.00');
        $stockId = self::$stockCmd->createPosition($prodId, $locId, '150.000');
        $countId = self::$countCmd->record($stockId, '150.000', 'Conteo original');

        // Post to /locations with code of existing location attempting to reparent to whB
        $res = $this->post('/locations', [
            'codigo'      => 'LOC-IMM-1',
            'id_almacen'  => (string) $whB,
            'descripcion' => 'Intento de reparent',
        ]);

        self::assertSame(422, $res->status);
        self::assertStringContainsString('Ya existe una ubicación con ese código.', $res->body);

        // Assert location remains in warehouse A with zero reparenting
        $loc = self::$locQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertSame($locId, $loc['id_ubicacion']);
        self::assertSame('LOC-IMM-1', $loc['codigo']);
        self::assertSame('Ubicacion Fija A', $loc['descripcion']);
        self::assertSame($whA, $loc['id_almacen'], 'Location id_almacen must remain immutable in Warehouse A.');

        // Assert stock position is completely intact
        $stmtStock = self::$testDb->pdo()->prepare('SELECT * FROM inventario_stock WHERE id_stock = :id');
        $stmtStock->execute([':id' => $stockId]);
        $stockRow = $stmtStock->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stockRow);
        self::assertSame($locId, (int) $stockRow['id_ubicacion']);
        self::assertSame('150.000', $stockRow['cantidad']);

        // Assert count record is completely intact
        $stmtCount = self::$testDb->pdo()->prepare('SELECT * FROM conteo_inventario WHERE id_conteo = :id');
        $stmtCount->execute([':id' => $countId]);
        $countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($countRow);
        self::assertSame($stockId, (int) $countRow['id_stock']);
        self::assertSame('150.000', $countRow['cantidad_contada']);
    }

    public function testWarehouseParentImmutabilityForgedParentChangeHasZeroEffect(): void
    {
        $sucA = self::$sucCmd->create('SUC-WA', 'Sucursal A', 'Leon');
        $sucB = self::$sucCmd->create('SUC-WB', 'Sucursal B', 'Managua');
        $whId = self::$almCmd->create($sucA, 'ALM-IMM-WH', 'Almacen Inmutable', 'bodega');

        $locId = self::$locCmd->create('LOC-CHILD', $whId, 'Ubicacion Hija');
        $prodId = self::$prodCmd->register('Lija de agua #100', '1.50');
        $stockId = self::$stockCmd->createPosition($prodId, $locId, '80.000');
        $countId = self::$countCmd->record($stockId, '80.000', 'Conteo inicial');

        // 1. Forged id_sucursal in toggle-active has zero effect
        $resToggle = $this->post('/warehouses/toggle-active', [
            'id_almacen'  => (string) $whId,
            'id_sucursal' => (string) $sucB,
        ]);
        self::assertSame(303, $resToggle->status);

        $whAfterToggle = self::$almQuery->findById($whId);
        self::assertNotNull($whAfterToggle);
        self::assertSame($sucA, $whAfterToggle['id_sucursal'], 'Warehouse id_sucursal must remain branch A.');

        // 2. Forged id_sucursal in POST /warehouses with existing code returns 422
        $resPost = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucB,
            'codigo'      => 'ALM-IMM-WH',
            'nombre'      => 'Almacen Reparent Attempt',
            'tipo'        => 'bodega',
        ]);
        self::assertSame(422, $resPost->status);
        self::assertStringContainsString('Ya existe un almacén con ese código.', $resPost->body);

        $whAfterPost = self::$almQuery->findById($whId);
        self::assertNotNull($whAfterPost);
        self::assertSame($sucA, $whAfterPost['id_sucursal'], 'Warehouse id_sucursal must remain branch A.');
        self::assertSame('Almacen Inmutable', $whAfterPost['nombre']);

        // Verify descendant location, stock, count are completely unaffected
        $loc = self::$locQuery->findById($locId);
        self::assertNotNull($loc);
        self::assertSame($whId, $loc['id_almacen']);

        $stmtStock = self::$testDb->pdo()->prepare('SELECT * FROM inventario_stock WHERE id_stock = :id');
        $stmtStock->execute([':id' => $stockId]);
        $stockRow = $stmtStock->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stockRow);
        self::assertSame($locId, (int) $stockRow['id_ubicacion']);
        self::assertSame('80.000', $stockRow['cantidad']);

        $stmtCount = self::$testDb->pdo()->prepare('SELECT * FROM conteo_inventario WHERE id_conteo = :id');
        $stmtCount->execute([':id' => $countId]);
        $countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($countRow);
        self::assertSame($stockId, (int) $countRow['id_stock']);
    }

    public function testFullPageVsHtmxResponseContractOnMutations(): void
    {
        $sucId = self::$sucCmd->create('SUC-CONTRACT', 'Sucursal Contract', 'Managua');
        $almId = self::$almCmd->create($sucId, 'ALM-CONTRACT', 'Almacen Contract');

        // --- 1. Full-page requests ---
        // A. Branch success -> 303 redirect with Location: /branches
        $resBranchOk = $this->post('/branches', [
            'codigo' => 'SUC-C-OK',
            'nombre' => 'Sucursal Ok',
            'ciudad' => 'Masaya',
        ]);
        self::assertSame(303, $resBranchOk->status);
        self::assertSame('/branches', $resBranchOk->headers['Location'] ?? '');

        // B. Branch validation error -> 422 full-page HTML rendered
        $resBranchFail = $this->post('/branches', [
            'codigo' => '',
            'nombre' => '',
            'ciudad' => '',
        ]);
        self::assertSame(422, $resBranchFail->status);
        self::assertStringContainsString('<!doctype html>', $resBranchFail->body);
        self::assertStringContainsString('El código de la sucursal es obligatorio.', $resBranchFail->body);

        // C. Warehouse success -> 303 redirect with Location: /warehouses
        $resWhOk = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-C-OK',
            'nombre'      => 'Almacen Ok',
            'tipo'        => 'bodega',
        ]);
        self::assertSame(303, $resWhOk->status);
        self::assertSame('/warehouses', $resWhOk->headers['Location'] ?? '');

        // D. Warehouse validation error -> 422 full-page HTML rendered
        $resWhFail = $this->post('/warehouses', [
            'id_sucursal' => '',
            'codigo'      => '',
            'nombre'      => '',
            'tipo'        => '',
        ]);
        self::assertSame(422, $resWhFail->status);
        self::assertStringContainsString('<!doctype html>', $resWhFail->body);
        self::assertStringContainsString('La sucursal es obligatoria.', $resWhFail->body);

        // E. Location success -> 303 redirect with Location: /locations
        $resLocOk = $this->post('/locations', [
            'id_almacen'  => (string) $almId,
            'codigo'      => 'LOC-C-OK',
            'descripcion' => 'Desc Ok',
        ]);
        self::assertSame(303, $resLocOk->status);
        self::assertSame('/locations', $resLocOk->headers['Location'] ?? '');

        // F. Location validation error -> 422 full-page HTML rendered
        $resLocFail = $this->post('/locations', [
            'id_almacen' => '',
            'codigo'     => '',
        ]);
        self::assertSame(422, $resLocFail->status);
        self::assertStringContainsString('<!doctype html>', $resLocFail->body);
        self::assertStringContainsString('El código de la ubicación es obligatorio.', $resLocFail->body);

        // --- 2. HTMX requests (HX-Request: true) ---
        // A. Branch HTMX success -> 200 with HX-Redirect and HX-Trigger
        $resBranchHtmxOk = $this->post('/branches', [
            'codigo' => 'SUC-HX-OK',
            'nombre' => 'Sucursal HX Ok',
            'ciudad' => 'Leon',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(200, $resBranchHtmxOk->status);
        self::assertSame('/branches', $resBranchHtmxOk->headers['HX-Redirect'] ?? '');
        self::assertArrayHasKey('HX-Trigger', $resBranchHtmxOk->headers);
        self::assertStringContainsString('Sucursal registrada correctamente.', $resBranchHtmxOk->headers['HX-Trigger']);

        // B. Branch HTMX validation error -> 422 full-page HTML (no invented fragment!)
        $resBranchHtmxFail = $this->post('/branches', [
            'codigo' => '',
            'nombre' => '',
            'ciudad' => '',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(422, $resBranchHtmxFail->status);
        self::assertStringContainsString('<!doctype html>', $resBranchHtmxFail->body);
        self::assertStringContainsString('El código de la sucursal es obligatorio.', $resBranchHtmxFail->body);

        // C. Warehouse HTMX success -> 200 with HX-Redirect and HX-Trigger
        $resWhHtmxOk = $this->post('/warehouses', [
            'id_sucursal' => (string) $sucId,
            'codigo'      => 'ALM-HX-OK',
            'nombre'      => 'Almacen HX Ok',
            'tipo'        => 'patio',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(200, $resWhHtmxOk->status);
        self::assertSame('/warehouses', $resWhHtmxOk->headers['HX-Redirect'] ?? '');
        self::assertArrayHasKey('HX-Trigger', $resWhHtmxOk->headers);
        self::assertStringContainsString('Almacén registrado correctamente.', $resWhHtmxOk->headers['HX-Trigger']);

        // D. Warehouse HTMX validation error -> 422 full-page HTML
        $resWhHtmxFail = $this->post('/warehouses', [
            'id_sucursal' => '',
            'codigo'      => '',
            'nombre'      => '',
            'tipo'        => '',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(422, $resWhHtmxFail->status);
        self::assertStringContainsString('<!doctype html>', $resWhHtmxFail->body);
        self::assertStringContainsString('La sucursal es obligatoria.', $resWhHtmxFail->body);

        // E. Location HTMX success -> 200 with HX-Redirect and HX-Trigger
        $resLocHtmxOk = $this->post('/locations', [
            'id_almacen'  => (string) $almId,
            'codigo'      => 'LOC-HX-OK',
            'descripcion' => 'Desc HX Ok',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(200, $resLocHtmxOk->status);
        self::assertSame('/locations', $resLocHtmxOk->headers['HX-Redirect'] ?? '');
        self::assertArrayHasKey('HX-Trigger', $resLocHtmxOk->headers);
        self::assertStringContainsString('Ubicación registrada correctamente.', $resLocHtmxOk->headers['HX-Trigger']);

        // F. Location HTMX validation error -> 422 full-page HTML
        $resLocHtmxFail = $this->post('/locations', [
            'id_almacen' => '',
            'codigo'     => '',
        ], headers: ['hx-request' => 'true']);
        self::assertSame(422, $resLocHtmxFail->status);
        self::assertStringContainsString('<!doctype html>', $resLocHtmxFail->body);
        self::assertStringContainsString('El código de la ubicación es obligatorio.', $resLocHtmxFail->body);
    }

    public function testUiAndAccessibilityComplianceAcrossFacilityPages(): void
    {
        $sucId = self::$sucCmd->create(
            'SUC-XSS',
            '<script>alert("b")</script>',
            '"><img src=x onerror=alert(1)>',
            '<p>dir</p>',
            '<b>555-1234</b>'
        );
        $almId = self::$almCmd->create(
            $sucId,
            'ALM-XSS',
            '<script>alert("w")</script>',
            'bodega'
        );
        self::$locCmd->create(
            'LOC-XSS',
            $almId,
            '<script>alert("l")</script>'
        );

        $endpoints = ['/branches', '/warehouses', '/locations'];

        foreach ($endpoints as $ep) {
            $res = $this->dispatch(new Request('GET', $ep));
            self::assertSame(200, $res->status);

            // 1. Viewport meta tag present
            self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $res->body);

            // 2. Responsive table container present
            self::assertStringContainsString('<div class="table-container mb-0">', $res->body);

            // 3. Touch target sizing classes present
            self::assertMatchesRegularExpression('/class="[^"]*(?:btn-primary|btn-secondary|ferreto-btn|button|drawer-close)[^"]*"/i', $res->body);
        }

        // 4. Output escaping verification across facility pages
        $resBranch = $this->dispatch(new Request('GET', '/branches'));
        self::assertStringNotContainsString('<script>alert("b")</script>', $resBranch->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;b&quot;)&lt;/script&gt;', $resBranch->body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $resBranch->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $resBranch->body);

        $resWh = $this->dispatch(new Request('GET', '/warehouses'));
        self::assertStringNotContainsString('<script>alert("w")</script>', $resWh->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;w&quot;)&lt;/script&gt;', $resWh->body);

        $resLoc = $this->dispatch(new Request('GET', '/locations'));
        self::assertStringNotContainsString('<script>alert("l")</script>', $resLoc->body);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;l&quot;)&lt;/script&gt;', $resLoc->body);
    }

    /**
     * Dispatches an authenticated request through the full Kernel pipeline
     * with AuthGuard, RoleGuard, Csrf, ErrorMapper, and ViewContext.
     */
    private function dispatchKernel(
        Request $request,
        string $role = 'administrador',
        ?NativeSession $session = null,
        ?Csrf $csrf = null,
        ?string $unmappedRoute = null
    ): Response {
        $actualSession = $session ?? new NativeSession(false);
        $actualCsrf = $csrf ?? new Csrf($actualSession);
        $actualCsrf->token();

        $username = "user_{$role}";
        $existing = self::$userQuery->findByUsername($username);
        $userId = $existing !== null
            ? (int) $existing['id_usuario']
            : self::$userCmd->create($username, password_hash('Passphrase12345678', PASSWORD_BCRYPT, ['cost' => 10]), $role, 'activo');

        $authSession = new AuthSession($actualSession, self::$userQuery);
        $authSession->establish($userId);

        $routePolicy = new RouteAccessPolicy();
        $authGuard = new AuthGuard($authSession);
        $roleGuard = new RoleGuard($routePolicy);

        $branchHandler = new BranchHandler(self::$renderer, self::$sucQuery, self::$sucCmd, $actualCsrf);
        $warehouseHandler = new WarehouseHandler(self::$renderer, self::$almQuery, self::$almCmd, self::$sucQuery, $actualCsrf);
        $locationHandler = new LocationHandler(self::$renderer, self::$locQuery, self::$locCmd, self::$almQuery, $actualCsrf);

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'branch') {
                $routes[] = [$method, $path, $branchHandler->handle(...)];
            } elseif ($name === 'warehouse') {
                $routes[] = [$method, $path, $warehouseHandler->handle(...)];
            } elseif ($name === 'location') {
                $routes[] = [$method, $path, $locationHandler->handle(...)];
            }
        }

        if ($unmappedRoute !== null) {
            $routes[] = ['GET', $unmappedRoute, static fn (): Response => new Response(200, [], 'Unmapped Handler Hit')];
        }

        $kernel = new Kernel(
            new Router($routes),
            $actualCsrf,
            new ErrorMapper(new Logger(), self::$renderer),
            $authGuard,
            $roleGuard,
            viewContext: new ViewContext(),
            routePolicy: $routePolicy
        );

        return $kernel->handle($request);
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
        $locationHandler = new LocationHandler(self::$renderer, self::$locQuery, self::$locCmd, self::$almQuery, $actualCsrf);

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'branch') {
                $routes[] = [$method, $path, $branchHandler->handle(...)];
            } elseif ($name === 'warehouse') {
                $routes[] = [$method, $path, $warehouseHandler->handle(...)];
            } elseif ($name === 'location') {
                $routes[] = [$method, $path, $locationHandler->handle(...)];
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
