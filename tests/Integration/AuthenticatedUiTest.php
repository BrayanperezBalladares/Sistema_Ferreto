<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Csrf;
use App\Foundation\Database;
use App\Foundation\ErrorMapper;
use App\Foundation\HealthHandler;
use App\Foundation\Kernel;
use App\Foundation\Logger;
use App\Foundation\MigrationRunner;
use App\Foundation\NativeSession;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
use App\Foundation\Transaction;
use App\Foundation\ViewContext;
use App\Modules\Access\AccessHandler;
use App\Modules\Access\Authenticator;
use App\Modules\Access\AuthGuard;
use App\Modules\Access\AuthSession;
use App\Modules\Access\RoleGuard;
use App\Modules\Access\RouteAccessPolicy;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use App\Modules\Inventory\AlmacenCommand;
use App\Modules\Inventory\AlmacenQuery;
use App\Modules\Inventory\CatalogHandler;
use App\Modules\Inventory\CategoryCommand;
use App\Modules\Inventory\CategoryQuery;
use App\Modules\Inventory\CountCommand;
use App\Modules\Inventory\CountQuery;
use App\Modules\Inventory\InventoryHandler;
use App\Modules\Inventory\LocationCommand;
use App\Modules\Inventory\LocationHandler;
use App\Modules\Inventory\LocationQuery;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\ProductQuery;
use App\Modules\Inventory\StockCommand;
use App\Modules\Inventory\StockQuery;
use App\Modules\Inventory\SucursalCommand;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthSessionTrait;

/**
 * Dedicated UI integration test suite for Slice G3.
 *
 * Verifies presentation-layer role authorization and control suppression:
 * "What does each authenticated role actually SEE in rendered HTML?"
 */
final class AuthenticatedUiTest extends TestCase
{
    use DatabaseIsolationTrait;
    use AuthSessionTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static ViewContext $viewContext;
    private static Renderer $renderer;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$config      = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb      = new Database(self::$config, useTestDatabase: true);
        self::$devDb       = new Database(self::$config, useTestDatabase: false);
        self::$tx          = new Transaction(self::$testDb);
        self::$viewContext = new ViewContext();
        self::$renderer    = new Renderer(dirname(__DIR__, 2), self::$viewContext);

        self::assertTestDatabaseIsolated(self::$testDb, self::$config);
        self::recordInitialDevState(self::$devDb);

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
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'almacen', 'sucursal', 'producto', 'categoria', 'usuario'] as $tbl) {
            if (in_array($tbl, $tables, true)) {
                $pdo->exec("TRUNCATE TABLE `{$tbl}`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
        }
        $_SESSION = [];
        self::$viewContext->clear();
    }

    // =========================================================================
    // 1. NAVIGATION VISIBILITY PER ROLE (Sections 4, 5, 6, 7)
    // =========================================================================

    /**
     * @param list<string> $expectedStrings
     * @param list<string> $forbiddenStrings
     */
    #[DataProvider('provideRoleNavigationScenarios')]
    public function testRoleNavigationVisibility(
        string $role,
        array $expectedStrings,
        array $forbiddenStrings
    ): void {
        $userId = $this->createAuthUser(self::$testDb, "user_nav_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $response->status);

        foreach ($expectedStrings as $expected) {
            self::assertStringContainsString($expected, $response->body, "Role '{$role}' must see '{$expected}'.");
        }

        foreach ($forbiddenStrings as $forbidden) {
            self::assertStringNotContainsString($forbidden, $response->body, "Role '{$role}' must NOT see '{$forbidden}'.");
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function provideRoleNavigationScenarios(): array
    {
        return [
            'administrador sees full navigation' => [
                'administrador',
                [
                    'href="/products"',
                    'href="/locations"',
                    'href="/inventory"',
                    'href="/inventory/counts"',
                    'Catálogo',
                    'Inventario',
                    'Productos',
                    'Ubicaciones',
                    'Existencias por ubicación',
                    'Conteos físicos',
                ],
                [],
            ],
            'bodeguero sees full R1 navigation' => [
                'bodeguero',
                [
                    'href="/products"',
                    'href="/locations"',
                    'href="/inventory"',
                    'href="/inventory/counts"',
                    'Catálogo',
                    'Inventario',
                    'Productos',
                    'Ubicaciones',
                    'Existencias por ubicación',
                    'Conteos físicos',
                ],
                [],
            ],
            'cajero sees catalog only with no orphan inventory header' => [
                'cajero',
                [
                    'href="/products"',
                    'Catálogo',
                    'Productos',
                ],
                [
                    'href="/locations"',
                    'href="/inventory"',
                    'href="/inventory/counts"',
                    '<div class="nav-section-label">Inventario</div>',
                    'Ubicaciones',
                    'Existencias por ubicación',
                    'Conteos físicos',
                ],
            ],
            'compras sees catalog only with no orphan inventory header' => [
                'compras',
                [
                    'href="/products"',
                    'Catálogo',
                    'Productos',
                ],
                [
                    'href="/locations"',
                    'href="/inventory"',
                    'href="/inventory/counts"',
                    '<div class="nav-section-label">Inventario</div>',
                    'Ubicaciones',
                    'Existencias por ubicación',
                    'Conteos físicos',
                ],
            ],
        ];
    }

    // =========================================================================
    // 2. PRODUCTS CATALOG ACTIONS & MODALS PER ROLE (Sections 8, 9, 10, 11)
    // =========================================================================

    #[DataProvider('provideRoleCatalogMutationScenarios')]
    public function testRoleProductCatalogActionsAndModals(string $role, bool $canMutate): void
    {
        // Fixture: Category, Active Product, Inactive Product
        $catCmd = new CategoryCommand(self::$tx);
        $catId = $catCmd->create('Ferretería General');

        $prodCmd = new ProductCommand(self::$tx);
        $pActiveId = $prodCmd->register('Martillo Acero', '29.99', $catId);
        $pInactiveId = $prodCmd->register('Clavo Descontinuado', '0.05', $catId);
        $prodCmd->deactivate($pInactiveId);

        $userId = $this->createAuthUser(self::$testDb, "user_prod_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $response->status);

        // All roles must see the product catalog data
        self::assertStringContainsString('Martillo Acero', $response->body);
        self::assertStringContainsString('29.99', $response->body);
        self::assertStringContainsString('Clavo Descontinuado', $response->body);
        self::assertStringContainsString('Ferretería General', $response->body);

        if ($canMutate) {
            // Header buttons
            self::assertStringContainsString('Nueva categoría', $response->body);
            self::assertStringContainsString('Registrar producto', $response->body);
            // Table header
            self::assertStringContainsString('<th>Acciones</th>', $response->body);
            // Row action buttons
            self::assertStringContainsString('Actualizar precio', $response->body);
            self::assertStringContainsString('Desactivar', $response->body);
            self::assertStringContainsString('Activar', $response->body);
            // Modals
            self::assertStringContainsString('id="modal-category"', $response->body);
            self::assertStringContainsString('id="modal-product"', $response->body);
            self::assertStringContainsString('id="modal-price"', $response->body);
            self::assertStringContainsString('id="modal-deactivate"', $response->body);
            self::assertStringContainsString('id="modal-activate"', $response->body);
        } else {
            // Header buttons suppressed
            self::assertStringNotContainsString('Nueva categoría', $response->body);
            self::assertStringNotContainsString('Registrar producto', $response->body);
            // Table header suppressed
            self::assertStringNotContainsString('<th>Acciones</th>', $response->body);
            // Row action buttons suppressed
            self::assertStringNotContainsString('Actualizar precio', $response->body);
            self::assertStringNotContainsString('Desactivar', $response->body);
            self::assertStringNotContainsString('Activar', $response->body);
            // Modals suppressed
            self::assertStringNotContainsString('id="modal-category"', $response->body);
            self::assertStringNotContainsString('id="modal-product"', $response->body);
            self::assertStringNotContainsString('id="modal-price"', $response->body);
            self::assertStringNotContainsString('id="modal-deactivate"', $response->body);
            self::assertStringNotContainsString('id="modal-activate"', $response->body);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function provideRoleCatalogMutationScenarios(): array
    {
        return [
            'administrador has catalog mutation controls' => ['administrador', true],
            'bodeguero has read-only catalog'             => ['bodeguero', false],
            'cajero has read-only catalog'                => ['cajero', false],
            'compras has read-only catalog'               => ['compras', false],
        ];
    }

    // =========================================================================
    // 3. HTMX FRAGMENT ACTION SUPPRESSION (Section 15)
    // =========================================================================

    #[DataProvider('provideRoleCatalogMutationScenarios')]
    public function testProductHtmxFragmentRoleActionSuppression(string $role, bool $canMutate): void
    {
        $catCmd = new CategoryCommand(self::$tx);
        $catId = $catCmd->create('Tornillería');

        $prodCmd = new ProductCommand(self::$tx);
        $prodCmd->register('Tornillo Hexagonal', '0.25', $catId);

        $userId = $this->createAuthUser(self::$testDb, "user_htmx_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        // HTMX search request
        $response = $this->dispatch(
            new Request('GET', '/products', query: ['q' => 'Tornillo'], headers: ['hx-request' => 'true']),
            session: $session
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Tornillo Hexagonal', $response->body);

        if ($canMutate) {
            self::assertStringContainsString('<th>Acciones</th>', $response->body);
            self::assertStringContainsString('Actualizar precio', $response->body);
            self::assertStringContainsString('Desactivar', $response->body);
        } else {
            self::assertStringNotContainsString('<th>Acciones</th>', $response->body);
            self::assertStringNotContainsString('Actualizar precio', $response->body);
            self::assertStringNotContainsString('Desactivar', $response->body);
            self::assertStringNotContainsString('Activar', $response->body);
        }
    }

    // =========================================================================
    // 4. LOCATIONS ACTION & MODAL VISIBILITY (Section 12)
    // =========================================================================

    #[DataProvider('provideAuthorizedOperationalRoles')]
    public function testLocationsActionVisibilityForAuthorizedRoles(string $role): void
    {
        $userId = $this->createAuthUser(self::$testDb, "user_loc_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/locations'), session: $session);
        self::assertSame(200, $response->status);

        self::assertStringContainsString('Nueva ubicación', $response->body);
        self::assertStringContainsString('id="modal-location"', $response->body);
    }

    // =========================================================================
    // 5. INVENTORY ACTION & MODAL VISIBILITY (Section 13)
    // =========================================================================

    #[DataProvider('provideAuthorizedOperationalRoles')]
    public function testInventoryActionVisibilityForAuthorizedRoles(string $role): void
    {
        $userId = $this->createAuthUser(self::$testDb, "user_inv_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/inventory'), session: $session);
        self::assertSame(200, $response->status);

        self::assertStringContainsString('Registrar existencia', $response->body);
        self::assertStringContainsString('id="modal-stock"', $response->body);
    }

    // =========================================================================
    // 6. COUNTS ACTION & MODAL VISIBILITY (Section 14)
    // =========================================================================

    #[DataProvider('provideAuthorizedOperationalRoles')]
    public function testCountsActionVisibilityForAuthorizedRoles(string $role): void
    {
        $prodCmd = new ProductCommand(self::$tx);
        $pId = $prodCmd->register('Pintura Blanca 1G', '45.00');

        $sucursalCmd = new SucursalCommand(self::$tx);
        $bId = $sucursalCmd->create('SUC-UI', 'Sucursal UI', 'Tegucigalpa');
        $almacenCmd = new AlmacenCommand(self::$tx);
        $wId = $almacenCmd->create($bId, 'ALM-UI', 'Almacén UI');

        $locCmd = new LocationCommand(self::$tx);
        $lId = $locCmd->create('BOD-C1', $wId);

        $stockCmd = new StockCommand(self::$tx);
        $sId = $stockCmd->createPosition($pId, $lId, '20.000');

        $userId = $this->createAuthUser(self::$testDb, "user_cnt_{$role}", $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/inventory/counts', query: ['stock' => (string) $sId]), session: $session);
        self::assertSame(200, $response->status);

        self::assertStringContainsString('data-modal-open="modal-count"', $response->body);
        self::assertStringContainsString('id="modal-count"', $response->body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideAuthorizedOperationalRoles(): array
    {
        return [
            'administrador has operational actions' => ['administrador'],
            'bodeguero has operational actions'     => ['bodeguero'],
        ];
    }

    // =========================================================================
    // 7. FRESH ROLE CHANGE & REVERSE ROLE CHANGE (Sections 16, 17)
    // =========================================================================

    public function testFreshRoleChangeImmediatelyReflectsInUi(): void
    {
        // 1. Establish session as administrador
        $userId = $this->createAuthUser(self::$testDb, 'dynamic_user', 'administrador', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $prodCmd = new ProductCommand(self::$tx);
        $prodCmd->register('Sierra Circular', '120.00');

        // 2. Request as administrador: sees admin controls
        $adminRes = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $adminRes->status);
        self::assertStringContainsString('Registrar producto', $adminRes->body);
        self::assertStringContainsString('<th>Acciones</th>', $adminRes->body);
        self::assertStringContainsString('href="/inventory"', $adminRes->body);
        self::assertStringContainsString('<span class="badge-role">Administrador</span>', $adminRes->body);

        // 3. Mutate role directly in database to cajero (no login/logout)
        self::$testDb->pdo()->exec("UPDATE usuario SET rol = 'cajero' WHERE id_usuario = {$userId}");

        // 4. Next request immediately reflects cajero in same session
        $cajeroRes = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $cajeroRes->status);
        self::assertStringNotContainsString('Registrar producto', $cajeroRes->body);
        self::assertStringNotContainsString('<th>Acciones</th>', $cajeroRes->body);
        self::assertStringNotContainsString('href="/inventory"', $cajeroRes->body);
        self::assertStringNotContainsString('<div class="nav-section-label">Inventario</div>', $cajeroRes->body);
        self::assertStringContainsString('<span class="badge-role">Cajero</span>', $cajeroRes->body);

        // 5. Reverse role change: mutate role back to administrador
        self::$testDb->pdo()->exec("UPDATE usuario SET rol = 'administrador' WHERE id_usuario = {$userId}");

        // 6. Next request immediately restores admin controls and full navigation
        $restoredRes = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $restoredRes->status);
        self::assertStringContainsString('Registrar producto', $restoredRes->body);
        self::assertStringContainsString('<th>Acciones</th>', $restoredRes->body);
        self::assertStringContainsString('href="/inventory"', $restoredRes->body);
        self::assertStringContainsString('<span class="badge-role">Administrador</span>', $restoredRes->body);
    }

    // =========================================================================
    // 8. FAIL-CLOSED CONTEXT REGRESSION (Section 18)
    // =========================================================================

    public function testMissingViewPermissionsFailsClosedAcrossAllViews(): void
    {
        $bareRenderer = new Renderer(dirname(__DIR__, 2));

        // Products view without permissions context
        $renderedProducts = $bareRenderer->render('page.products', [
            'products' => [[
                'id_producto' => 1,
                'id_categoria' => null,
                'categoria_nombre' => null,
                'nombre' => 'Producto Sin Permiso',
                'descripcion' => null,
                'precio_actual' => '10.00',
                'estado_activo' => 1,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]],
            'categories' => [],
            'query' => '',
            'csrf' => 'csrf_token',
        ]);

        self::assertStringNotContainsString('Nueva categoría', $renderedProducts);
        self::assertStringNotContainsString('Registrar producto', $renderedProducts);
        self::assertStringNotContainsString('modal-category', $renderedProducts);
        self::assertStringNotContainsString('modal-product', $renderedProducts);
        self::assertStringNotContainsString('modal-price', $renderedProducts);
        self::assertStringNotContainsString('modal-deactivate', $renderedProducts);
        self::assertStringNotContainsString('modal-activate', $renderedProducts);
        self::assertStringNotContainsString('<th>Acciones</th>', $renderedProducts);
        self::assertStringNotContainsString('Actualizar precio', $renderedProducts);

        // Locations view without permissions context
        $renderedLocations = $bareRenderer->render('page.locations', [
            'locations' => [],
            'csrf' => 'csrf_token',
        ]);
        self::assertStringNotContainsString('Nueva ubicación', $renderedLocations);
        self::assertStringNotContainsString('modal-location', $renderedLocations);
        self::assertStringNotContainsString('<div class="nav-section-label">Inventario</div>', $renderedLocations);
        self::assertStringNotContainsString('href="/locations"', $renderedLocations);
        self::assertStringNotContainsString('href="/inventory"', $renderedLocations);
        self::assertStringNotContainsString('href="/inventory/counts"', $renderedLocations);

        // Inventory view without permissions context
        $renderedInventory = $bareRenderer->render('page.inventory', [
            'positions' => [],
            'products' => [],
            'locations' => [],
            'csrf' => 'csrf_token',
        ]);
        self::assertStringNotContainsString('Registrar existencia', $renderedInventory);
        self::assertStringNotContainsString('modal-stock', $renderedInventory);

        // Counts view without permissions context
        $renderedCounts = $bareRenderer->render('page.counts', [
            'stocks' => [],
            'selectedStock' => [
                'id_stock' => 1,
                'producto_nombre' => 'Producto Sin Permiso',
                'ubicacion_codigo' => 'LOC-0',
                'cantidad' => '1.000',
            ],
            'observations' => [],
            'csrf' => 'csrf_token',
        ]);
        self::assertStringNotContainsString('data-modal-open="modal-count"', $renderedCounts);
        self::assertStringNotContainsString('id="modal-count"', $renderedCounts);
    }

    // =========================================================================
    // 9. IDENTITY, LOGOUT & HUMAN-READABLE ROLE LABELS (Sections 19, 20)
    // =========================================================================

    #[DataProvider('provideRoleIdentityScenarios')]
    public function testRoleIdentityAndLogoutPresentForAllRoles(string $role, string $expectedLabel): void
    {
        $username = "ident_{$role}";
        $userId = $this->createAuthUser(self::$testDb, $username, $role, 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $response->status);

        // Username displayed
        self::assertStringContainsString($username, $response->body);

        // Human-readable role label displayed in badge
        self::assertStringContainsString(
            '<span class="badge-role">' . $expectedLabel . '</span>',
            $response->body
        );

        // Logout control always present and accessible
        self::assertStringContainsString('action="/logout"', $response->body);
        self::assertStringContainsString('class="btn-logout"', $response->body);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideRoleIdentityScenarios(): array
    {
        return [
            'administrador identity' => ['administrador', 'Administrador'],
            'bodeguero identity'     => ['bodeguero', 'Bodeguero'],
            'cajero identity'        => ['cajero', 'Cajero'],
            'compras identity'       => ['compras', 'Compras'],
        ];
    }

    // =========================================================================
    // 10. DIRECT URL SECURITY BOUNDARY (Section 21)
    // =========================================================================

    public function testDirectUrlForbiddenReturns403WithoutHtmlLeak(): void
    {
        $userId = $this->createAuthUser(self::$testDb, 'cajero_boundary', 'cajero', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        // Cajero attempts to directly access /inventory
        $response = $this->dispatch(new Request('GET', '/inventory'), session: $session);

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertStringNotContainsString('Existencias por ubicación', $response->body);
    }

    // =========================================================================
    // DISPATCH HELPER (Production-equivalent Kernel pipeline)
    // =========================================================================

    private function dispatch(
        Request $request,
        ?NativeSession $session = null,
        ?Csrf $csrf = null
    ): Response {
        $actualSession = $session ?? new NativeSession(false);
        $actualCsrf    = $csrf ?? new Csrf($actualSession);
        $actualCsrf->token();

        $tx            = new Transaction(self::$testDb);
        $userQuery     = new UserQuery(self::$testDb);
        $userCommand   = new UserCommand($tx);
        $authenticator = new Authenticator($userQuery, $userCommand);
        $authSession   = new AuthSession($actualSession, $userQuery);
        $routePolicy   = new RouteAccessPolicy();
        $accessHandler = new AccessHandler(self::$renderer, $authenticator, $authSession, $actualCsrf, $routePolicy);
        $healthHandler = new HealthHandler(self::$renderer, $actualCsrf, $actualSession);

        $productQuery  = new ProductQuery(self::$testDb);
        $categoryQuery = new CategoryQuery(self::$testDb);
        $locationQuery = new LocationQuery(self::$testDb);
        $stockQuery    = new StockQuery(self::$testDb);

        $catalogHandler = new CatalogHandler(
            self::$renderer,
            $productQuery,
            $categoryQuery,
            new CategoryCommand($tx),
            new ProductCommand($tx),
            $actualCsrf
        );
        $locationHandler = new LocationHandler(
            self::$renderer,
            $locationQuery,
            new LocationCommand($tx),
            new AlmacenQuery(self::$testDb),
            $actualCsrf
        );
        $inventoryHandler = new InventoryHandler(
            self::$renderer,
            $stockQuery,
            new StockCommand($tx),
            $productQuery,
            $locationQuery,
            $actualCsrf,
            new CountQuery(self::$testDb),
            new CountCommand($tx)
        );

        $handlers = [
            'health'    => $healthHandler->handle(...),
            'access'    => $accessHandler->handle(...),
            'catalog'   => $catalogHandler->handle(...),
            'location'  => $locationHandler->handle(...),
            'inventory' => $inventoryHandler->handle(...),
        ];

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = array_map(
            static fn (array $route): array => [$route[0], $route[1], $handlers[$route[2]]],
            $routeConfig
        );

        $authGuard = new AuthGuard($authSession);
        $roleGuard = new RoleGuard($routePolicy);

        $kernel = new Kernel(
            new Router($routes),
            $actualCsrf,
            new ErrorMapper(new Logger(), self::$renderer),
            $authGuard,
            $roleGuard,
            healthPolicy: null,
            viewContext: self::$viewContext,
            routePolicy: $routePolicy,
        );

        return $kernel->handle($request);
    }

    private function establishSession(int $userId, NativeSession $session): void
    {
        $query = new UserQuery(self::$testDb);
        $authSession = new AuthSession($session, $query);
        $res = $authSession->establish($userId);
        self::assertNotNull($res, 'Precondition: session establishment must succeed.');
    }
}
