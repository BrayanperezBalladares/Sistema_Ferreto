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
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthenticatedShellTest extends TestCase
{
    use DatabaseIsolationTrait;

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
        foreach (['conteo_inventario', 'inventario_stock', 'ubicacion', 'producto', 'categoria', 'usuario'] as $tbl) {
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

    /**
     * @param (Closure(): int)|null $clock
     */
    private function dispatch(
        Request $request,
        ?NativeSession $session = null,
        ?Csrf $csrf = null,
        ?Closure $clock = null,
        bool $withAuthGuard = true,
        bool $withRoleGuard = true,
    ): Response {
        $actualSession = $session ?? new NativeSession(false);
        $actualCsrf    = $csrf ?? new Csrf($actualSession);
        $actualCsrf->token();

        $tx            = new Transaction(self::$testDb);
        $userQuery     = new UserQuery(self::$testDb);
        $userCommand   = new UserCommand($tx);
        $authenticator = new Authenticator($userQuery, $userCommand);
        $authSession   = new AuthSession($actualSession, $userQuery, clock: $clock);
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

        $authGuard = $withAuthGuard ? new AuthGuard($authSession) : null;
        $roleGuard = $withRoleGuard ? new RoleGuard($routePolicy) : null;

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

    private function createUser(string $username, string $role, string $estado = 'activo', string $password = 'Passphrase12345678'): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $tx = new Transaction(self::$testDb);
        $cmd = new UserCommand($tx);
        return $cmd->create($username, $hash, $role, $estado);
    }

    private function establishSession(int $userId, NativeSession $session, ?Closure $clock = null): void
    {
        $query = new UserQuery(self::$testDb);
        $authSession = new AuthSession($session, $query, clock: $clock);
        $res = $authSession->establish($userId);
        self::assertNotNull($res, 'Precondition: session establishment must succeed.');
    }

    public function testAuthenticatedAdministratorProductsShell(): void
    {
        $userId = $this->createUser('admin_shell', 'administrador', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        // Seed a product
        $cmd = new ProductCommand(self::$tx);
        $cmd->register('Martillo Test', '25.00', null, 'Martillo para pruebas');

        $response = $this->dispatch(new Request('GET', '/products'), session: $session);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('admin_shell', $response->body);
        self::assertStringContainsString('Administrador', $response->body);
        self::assertStringContainsString('badge-role', $response->body);
        self::assertStringContainsString('aria-current="page"', $response->body);
        self::assertStringContainsString('action="/logout"', $response->body);
        self::assertStringContainsString('btn-logout', $response->body);
        self::assertStringContainsString('name="_csrf"', $response->body);
        self::assertStringContainsString('Martillo Test', $response->body);
        self::assertStringContainsString('Catálogo', $response->body);
    }

    public function testAuthenticatedAdministratorAllR1PagesShell(): void
    {
        $userId = $this->createUser('admin_r1', 'administrador', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $pages = ['/products', '/locations', '/inventory', '/inventory/counts'];

        foreach ($pages as $page) {
            $response = $this->dispatch(new Request('GET', $page), session: $session);

            self::assertSame(200, $response->status, "Page {$page} must return 200 for administrator.");
            self::assertStringContainsString('admin_r1', $response->body, "Page {$page} must render username.");
            self::assertStringContainsString('Administrador', $response->body, "Page {$page} must render role label.");
            self::assertStringContainsString('badge-role', $response->body, "Page {$page} must render badge-role.");
            self::assertStringContainsString('app-sidebar', $response->body, "Page {$page} must render app-sidebar.");
            self::assertStringContainsString('sidebar-user', $response->body, "Page {$page} must render sidebar-user.");
            self::assertStringContainsString('topbar-user', $response->body, "Page {$page} must render topbar-user.");
            self::assertStringContainsString('btn-logout', $response->body, "Page {$page} must render logout button.");
            self::assertStringContainsString('action="/logout"', $response->body, "Page {$page} must render logout form.");
        }
    }

    public function testAnonymousLoginIsolation(): void
    {
        $session = new NativeSession(false);
        $response = $this->dispatch(new Request('GET', '/login'), session: $session);

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('app-sidebar', $response->body);
        self::assertStringNotContainsString('sidebar-user', $response->body);
        self::assertStringNotContainsString('topbar-user', $response->body);
        self::assertStringNotContainsString('badge-role', $response->body);
        self::assertStringNotContainsString('action="/logout"', $response->body);
        self::assertStringNotContainsString('btn-logout', $response->body);
        self::assertStringContainsString('action="/login"', $response->body);
    }

    public function testRolePresentationLabels(): void
    {
        $roles = [
            'administrador' => 'Administrador',
            'bodeguero'     => 'Bodeguero',
            'cajero'        => 'Cajero',
            'compras'       => 'Compras',
        ];

        foreach ($roles as $roleKey => $expectedLabel) {
            $username = "user_{$roleKey}";
            $userId = $this->createUser($username, $roleKey, 'activo');
            $session = new NativeSession(false);
            $this->establishSession($userId, $session);

            $response = $this->dispatch(new Request('GET', '/products'), session: $session);

            self::assertSame(200, $response->status, "Role {$roleKey} must access /products.");
            self::assertStringContainsString($username, $response->body);
            self::assertStringContainsString(
                '<span class="badge-role">' . $expectedLabel . '</span>',
                $response->body,
                "Role {$roleKey} must render exact badge label {$expectedLabel}."
            );
        }
    }

    public function testFreshRoleDisplay(): void
    {
        $userId = $this->createUser('dynamic_role_user', 'cajero', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        // 1. Initial request as cajero
        $res1 = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $res1->status);
        self::assertStringContainsString('<span class="badge-role">Cajero</span>', $res1->body);

        // 2. Role is updated directly in TEST_DB to bodeguero
        self::$testDb->pdo()->exec("UPDATE usuario SET rol = 'bodeguero' WHERE id_usuario = {$userId}");

        // 3. Next request reflects the updated authoritative role from DB
        $res2 = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $res2->status);
        self::assertStringContainsString('<span class="badge-role">Bodeguero</span>', $res2->body);
        self::assertStringNotContainsString('<span class="badge-role">Cajero</span>', $res2->body);
    }

    public function testLogoutFormSubmission(): void
    {
        $userId = $this->createUser('logout_tester', 'administrador', 'activo');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $this->establishSession($userId, $session);

        // 1. GET /products to render shell
        $getRes = $this->dispatch(new Request('GET', '/products'), session: $session, csrf: $csrf);
        self::assertSame(200, $getRes->status);

        // Extract CSRF token from logout form
        self::assertMatchesRegularExpression('/<form[^>]+action="\/logout"[^>]*>.*?<input[^>]+name="_csrf"[^>]+value="([^"]+)"/s', $getRes->body);
        preg_match('/<form[^>]+action="\/logout"[^>]*>.*?<input[^>]+name="_csrf"[^>]+value="([^"]+)"/s', $getRes->body, $matches);
        $extractedCsrf = $matches[1];
        self::assertNotEmpty($extractedCsrf);

        // 2. Submit POST /logout
        $logoutRes = $this->dispatch(new Request('POST', '/logout', body: [
            '_csrf' => $extractedCsrf,
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $logoutRes->status);
        self::assertSame('/login', $logoutRes->headers['Location'] ?? '');
        self::assertNull($session->get(AuthSession::KEY_USER_ID), 'Session auth user ID must be null after logout.');

        // 3. Subsequent GET /products redirects to /login because session is destroyed
        $afterLogoutRes = $this->dispatch(new Request('GET', '/products'), session: $session, csrf: $csrf);
        self::assertSame(303, $afterLogoutRes->status);
        self::assertSame('/login', $afterLogoutRes->headers['Location'] ?? '');
    }

    public function testActiveNavigationIndicatorSemantics(): void
    {
        $userId = $this->createUser('nav_semantics_admin', 'administrador', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $testMatrix = [
            '/products' => [
                'expected' => '/products',
                'others'   => ['/locations', '/inventory', '/inventory/counts'],
            ],
            '/locations' => [
                'expected' => '/locations',
                'others'   => ['/products', '/inventory', '/inventory/counts'],
            ],
            '/inventory' => [
                'expected' => '/inventory',
                'others'   => ['/products', '/locations', '/inventory/counts'],
            ],
            '/inventory/counts' => [
                'expected' => '/inventory/counts',
                'others'   => ['/products', '/locations', '/inventory'],
            ],
        ];

        foreach ($testMatrix as $targetPath => $expectation) {
            $response = $this->dispatch(new Request('GET', $targetPath), session: $session);
            self::assertSame(200, $response->status, "Path {$targetPath} must return 200.");

            // Expected link has aria-current="page" and is-active
            $expectedPattern = '/<a\s+href="' . preg_quote($expectation['expected'], '/') . '"\s+class="nav-item\s+is-active"\s+aria-current="page"/';
            self::assertMatchesRegularExpression(
                $expectedPattern,
                $response->body,
                "Path {$targetPath} must mark {$expectation['expected']} as active with aria-current=\"page\"."
            );

            // Other links do NOT have aria-current="page"
            foreach ($expectation['others'] as $other) {
                $otherPattern = '/<a\s+href="' . preg_quote($other, '/') . '"[^>]*aria-current="page"/';
                self::assertDoesNotMatchRegularExpression(
                    $otherPattern,
                    $response->body,
                    "Path {$targetPath} must NOT mark {$other} with aria-current=\"page\"."
                );
            }
        }
    }

    public function testResponsiveStructureAndAccessibility(): void
    {
        $userId = $this->createUser('a11y_user', 'administrador', 'activo');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/products'), session: $session);
        self::assertSame(200, $response->status);

        // 1. Skip link
        self::assertStringContainsString(
            '<a href="#main-content" class="skip-link">Saltar al contenido principal</a>',
            $response->body
        );

        // 2. Main content target
        self::assertStringContainsString('<main class="app-workspace" id="main-content">', $response->body);

        // 3. Navigation toggle with aria-controls
        self::assertStringContainsString('id="nav-toggle"', $response->body);
        self::assertStringContainsString('aria-controls="app-sidebar"', $response->body);
        self::assertStringContainsString('aria-expanded="false"', $response->body);

        // 4. Logout buttons with accessible labels
        self::assertStringContainsString('aria-label="Cerrar sesión"', $response->body);
        self::assertStringContainsString('class="btn-logout"', $response->body);
    }

    public function testPublicHealthPageWithoutAuthDoesNotRenderAuthUser(): void
    {
        $response = $this->dispatch(new Request('GET', '/health'));
        self::assertSame(200, $response->status);

        self::assertStringNotContainsString('topbar-user', $response->body);
        self::assertStringNotContainsString('sidebar-user', $response->body);
        self::assertStringNotContainsString('badge-role', $response->body);
        self::assertStringNotContainsString('btn-logout', $response->body);
        self::assertStringNotContainsString('action="/logout"', $response->body);
    }

    public function testRoleAwareNavigationAndActionSuppression(): void
    {
        // Seed a product so table renders rather than empty state
        $cmd = new ProductCommand(self::$tx);
        $cmd->register('Martillo Test', '25.00', null, 'Martillo para pruebas');

        // 1. Cajero on Products: can view catalog, but no mutation controls and no Inventario section
        $cajeroId = $this->createUser('cajero_ui_test', 'cajero', 'activo');
        $cajeroSession = new NativeSession(false);
        $this->establishSession($cajeroId, $cajeroSession);

        $cajeroRes = $this->dispatch(new Request('GET', '/products'), session: $cajeroSession);
        self::assertSame(200, $cajeroRes->status);
        self::assertStringContainsString('cajero_ui_test', $cajeroRes->body);
        self::assertStringContainsString('Cajero', $cajeroRes->body);
        self::assertStringContainsString('Productos', $cajeroRes->body);
        // Navigation suppression
        self::assertStringNotContainsString('Ubicaciones', $cajeroRes->body);
        self::assertStringNotContainsString('Existencias por ubicación', $cajeroRes->body);
        self::assertStringNotContainsString('Conteos físicos', $cajeroRes->body);
        self::assertStringNotContainsString('<div class="nav-section-label">Inventario</div>', $cajeroRes->body);
        // Action suppression
        self::assertStringNotContainsString('Nueva categoría', $cajeroRes->body);
        self::assertStringNotContainsString('Registrar producto', $cajeroRes->body);
        self::assertStringNotContainsString('<th>Acciones</th>', $cajeroRes->body);
        self::assertStringNotContainsString('Actualizar precio', $cajeroRes->body);
        self::assertStringNotContainsString('modal-category', $cajeroRes->body);
        self::assertStringNotContainsString('modal-product', $cajeroRes->body);

        // 2. Bodeguero on Products: has full navigation, but no catalog mutation controls
        $bodegueroId = $this->createUser('bodeguero_ui_test', 'bodeguero', 'activo');
        $bodegueroSession = new NativeSession(false);
        $this->establishSession($bodegueroId, $bodegueroSession);

        $bodegueroRes = $this->dispatch(new Request('GET', '/products'), session: $bodegueroSession);
        self::assertSame(200, $bodegueroRes->status);
        self::assertStringContainsString('bodeguero_ui_test', $bodegueroRes->body);
        self::assertStringContainsString('Bodeguero', $bodegueroRes->body);
        // Bodeguero has navigation to inventory and locations
        self::assertStringContainsString('Ubicaciones', $bodegueroRes->body);
        self::assertStringContainsString('Existencias por ubicación', $bodegueroRes->body);
        self::assertStringContainsString('Conteos físicos', $bodegueroRes->body);
        self::assertStringContainsString('<div class="nav-section-label">Inventario</div>', $bodegueroRes->body);
        // Bodeguero cannot mutate catalog
        self::assertStringNotContainsString('Nueva categoría', $bodegueroRes->body);
        self::assertStringNotContainsString('Registrar producto', $bodegueroRes->body);
        self::assertStringNotContainsString('<th>Acciones</th>', $bodegueroRes->body);

        // 3. Bodeguero on Locations: sees + Nueva ubicación
        $locationsRes = $this->dispatch(new Request('GET', '/locations'), session: $bodegueroSession);
        self::assertSame(200, $locationsRes->status);
        self::assertStringContainsString('Nueva ubicación', $locationsRes->body);
        self::assertStringContainsString('modal-location', $locationsRes->body);

        // 4. Bodeguero on Inventory: sees + Registrar existencia
        $inventoryRes = $this->dispatch(new Request('GET', '/inventory'), session: $bodegueroSession);
        self::assertSame(200, $inventoryRes->status);
        self::assertStringContainsString('Registrar existencia', $inventoryRes->body);
        self::assertStringContainsString('modal-stock', $inventoryRes->body);

        // 5. Administrador on Products: has full controls
        $adminId = $this->createUser('admin_ui_test', 'administrador', 'activo');
        $adminSession = new NativeSession(false);
        $this->establishSession($adminId, $adminSession);

        $adminRes = $this->dispatch(new Request('GET', '/products'), session: $adminSession);
        self::assertSame(200, $adminRes->status);
        self::assertStringContainsString('Nueva categoría', $adminRes->body);
        self::assertStringContainsString('Registrar producto', $adminRes->body);
        self::assertStringContainsString('<th>Acciones</th>', $adminRes->body);
        self::assertStringContainsString('modal-category', $adminRes->body);
        self::assertStringContainsString('modal-product', $adminRes->body);
        self::assertStringContainsString('modal-price', $adminRes->body);
    }

    public function testMissingViewPermissionsFailsClosed(): void
    {
        $bareRenderer = new Renderer(dirname(__DIR__, 2));

        $renderedProducts = $bareRenderer->render('page.products', [
            'products' => [[
                'id_producto' => 1,
                'id_categoria' => null,
                'categoria_nombre' => null,
                'nombre' => 'Producto Prueba',
                'descripcion' => null,
                'precio_actual' => '10.00',
                'estado_activo' => 1,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]],
            'categories' => [],
            'query' => '',
            'csrf' => 'token123',
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

        $renderedLocations = $bareRenderer->render('page.locations', [
            'locations' => [],
            'csrf' => 'token123',
        ]);
        self::assertStringNotContainsString('Nueva ubicación', $renderedLocations);
        self::assertStringNotContainsString('modal-location', $renderedLocations);
        self::assertStringNotContainsString('<div class="nav-section-label">Inventario</div>', $renderedLocations);
        self::assertStringNotContainsString('href="/locations"', $renderedLocations);
        self::assertStringNotContainsString('href="/inventory"', $renderedLocations);
        self::assertStringNotContainsString('href="/inventory/counts"', $renderedLocations);

        $renderedInventory = $bareRenderer->render('page.inventory', [
            'positions' => [],
            'products' => [],
            'locations' => [],
            'csrf' => 'token123',
        ]);
        self::assertStringNotContainsString('Registrar existencia', $renderedInventory);
        self::assertStringNotContainsString('modal-stock', $renderedInventory);

        $renderedCounts = $bareRenderer->render('page.counts', [
            'stocks' => [],
            'selectedStock' => [
                'id_stock' => 1,
                'producto_nombre' => 'Producto Prueba',
                'ubicacion_codigo' => 'LOC-1',
                'cantidad' => '10.000',
            ],
            'observations' => [],
            'csrf' => 'token123',
        ]);
        self::assertStringNotContainsString('data-modal-open="modal-count"', $renderedCounts);
        self::assertStringNotContainsString('id="modal-count"', $renderedCounts);
    }
}
