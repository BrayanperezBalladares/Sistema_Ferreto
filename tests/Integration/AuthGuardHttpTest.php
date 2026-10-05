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
use App\Modules\Access\AccessHandler;
use App\Modules\Access\Authenticator;
use App\Modules\Access\AuthGuard;
use App\Modules\Access\AuthSession;
use App\Modules\Access\RouteAccessPolicy;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
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
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthGuardHttpTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static Renderer $renderer;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$config   = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb   = new Database(self::$config, useTestDatabase: true);
        self::$devDb    = new Database(self::$config, useTestDatabase: false);
        self::$tx       = new Transaction(self::$testDb);
        self::$renderer = new Renderer(dirname(__DIR__, 2));

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
    }

    /**
     * @param (Closure(): int)|null $clock
     */
    private function dispatch(
        Request $request,
        ?NativeSession $session = null,
        ?Csrf $csrf = null,
        ?Closure $clock = null,
        bool $withGuard = true,
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

        $authGuard = $withGuard ? new AuthGuard($authSession) : null;
        $kernel = new Kernel(
            new Router($routes),
            $actualCsrf,
            new ErrorMapper(new Logger(), self::$renderer),
            $authGuard
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

    public function testAnonymousNormalGetToProtectedProductsRedirects303ToLoginWithoutExposingProtectedContent(): void
    {
        $session = new NativeSession(false);
        $response = $this->dispatch(new Request('GET', '/products'), session: $session);

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location'] ?? '');
        self::assertStringNotContainsString('Catálogo de Productos', $response->body);
        self::assertStringNotContainsString('id="product-table-container"', $response->body);
        self::assertStringNotContainsString('app-sidebar', $response->body);
    }

    public function testAnonymousNormalGetToProtectedInventoryRemembersTargetAndLoginRedirectsThere(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        // 1. Unauthenticated request to /inventory
        $response = $this->dispatch(new Request('GET', '/inventory'), session: $session, csrf: $csrf);
        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location'] ?? '');

        // Verify server-side session stored target URL
        self::assertSame('/inventory', $session->get(AuthSession::KEY_TARGET_URL));

        // 2. Provision bodeguero account (authorized for /inventory)
        $this->createUser('bodeguero1', 'bodeguero', 'activo', 'PassphraseBodeguero123');

        // 3. Login with remembered session
        $loginRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $token,
            'username' => 'bodeguero1',
            'password' => 'PassphraseBodeguero123',
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $loginRes->status);
        self::assertSame('/inventory', $loginRes->headers['Location'] ?? '');
        self::assertNull($session->get(AuthSession::KEY_TARGET_URL), 'Target URL must be cleared after login.');
    }

    public function testAnonymousNormalGetTargetFallbackToProductsIfRoleCannotNavigateTarget(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        // 1. Unauthenticated request to /inventory
        $response = $this->dispatch(new Request('GET', '/inventory'), session: $session, csrf: $csrf);
        self::assertSame(303, $response->status);
        self::assertSame('/inventory', $session->get(AuthSession::KEY_TARGET_URL));

        // 2. Provision cajero account (NOT authorized for /inventory in RouteAccessPolicy)
        $this->createUser('cajero1', 'cajero', 'activo', 'PassphraseCajero123');

        // 3. Login with cajero
        $loginRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $token,
            'username' => 'cajero1',
            'password' => 'PassphraseCajero123',
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $loginRes->status);
        self::assertSame('/products', $loginRes->headers['Location'] ?? '', 'Must fallback to /products when role cannot navigate target.');
        self::assertNull($session->get(AuthSession::KEY_TARGET_URL));
    }

    public function testAnonymousMutationDoesNotStoreTargetUrl(): void
    {
        $session = new NativeSession(false);
        $response = $this->dispatch(new Request('POST', '/inventory/stock', body: [
            'id_producto' => '1',
            'id_ubicacion' => '1',
            'pasillo'     => 'A',
            'estante'     => '1',
        ]), session: $session);

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location'] ?? '');
        self::assertNull($session->get(AuthSession::KEY_TARGET_URL), 'Mutations must never be remembered as return targets.');
    }

    public function testAnonymousHtmxGetToProtectedProductsReturns200WithHxRedirectAndEmptyBody(): void
    {
        $session = new NativeSession(false);
        $response = $this->dispatch(
            new Request('GET', '/products', headers: ['hx-request' => 'true']),
            session: $session
        );

        self::assertSame(200, $response->status);
        self::assertSame('/login', $response->headers['HX-Redirect'] ?? '');
        self::assertSame('', $response->body, 'Body must be completely empty.');
        self::assertNull($session->get(AuthSession::KEY_TARGET_URL), 'HTMX requests must not store target URL.');
    }

    public function testIncidentalHtmxGetDoesNotOverwriteNavigableTarget(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        // 1. Initial browser navigation to /inventory/counts
        $navRes = $this->dispatch(new Request('GET', '/inventory/counts'), session: $session, csrf: $csrf);
        self::assertSame(303, $navRes->status);
        self::assertSame('/inventory/counts', $session->get(AuthSession::KEY_TARGET_URL));

        // 2. Incidental background HTMX request
        $htmxRes = $this->dispatch(
            new Request('GET', '/products', headers: ['hx-request' => 'true']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(200, $htmxRes->status);
        self::assertSame('/login', $htmxRes->headers['HX-Redirect'] ?? '');
        self::assertSame('', $htmxRes->body);

        // Target URL must NOT have been overwritten by /products
        self::assertSame('/inventory/counts', $session->get(AuthSession::KEY_TARGET_URL));

        // 3. Login
        $this->createUser('bodeguero_counts', 'bodeguero', 'activo', 'PassphraseCounts123');
        $loginRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $token,
            'username' => 'bodeguero_counts',
            'password' => 'PassphraseCounts123',
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $loginRes->status);
        self::assertSame('/inventory/counts', $loginRes->headers['Location'] ?? '');
        self::assertNull($session->get(AuthSession::KEY_TARGET_URL));
    }

    public function testExpiredSessionNormalGetRedirects303ToLoginAndInvalidatesSession(): void
    {
        $userId = $this->createUser('admin_expire', 'administrador', 'activo');

        $time = 1_000_000;
        $clock = static function () use (&$time): int {
            return $time;
        };

        $session = new NativeSession(false);
        $this->establishSession($userId, $session, $clock);
        self::assertSame($userId, $session->get(AuthSession::KEY_USER_ID));

        // Advance beyond default 1800s timeout
        $time += 1801;

        $response = $this->dispatch(new Request('GET', '/products'), session: $session, clock: $clock);

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location'] ?? '');
        self::assertStringNotContainsString('Productos', $response->body);
        self::assertNull($session->get(AuthSession::KEY_USER_ID), 'Expired session must be invalidated.');
    }

    public function testExpiredSessionHtmxGetReturns200WithHxRedirectAndEmptyBody(): void
    {
        $userId = $this->createUser('cajero_expire', 'cajero', 'activo');

        $time = 2_000_000;
        $clock = static function () use (&$time): int {
            return $time;
        };

        $session = new NativeSession(false);
        $this->establishSession($userId, $session, $clock);
        self::assertSame($userId, $session->get(AuthSession::KEY_USER_ID));

        // Advance beyond 1200s cashier timeout
        $time += 1201;

        $response = $this->dispatch(
            new Request('GET', '/products', headers: ['hx-request' => 'true']),
            session: $session,
            clock: $clock
        );

        self::assertSame(200, $response->status);
        self::assertSame('/login', $response->headers['HX-Redirect'] ?? '');
        self::assertSame('', $response->body);
        self::assertNull($session->get(AuthSession::KEY_USER_ID), 'Expired cashier session must be invalidated.');
    }

    public function testActiveAuthenticatedSessionExecutesBusinessHandlerAndRefreshesActivityTimestamp(): void
    {
        $userId = $this->createUser('admin_active', 'administrador', 'activo');

        $time = 3_000_000;
        $clock = static function () use (&$time): int {
            return $time;
        };

        $session = new NativeSession(false);
        $this->establishSession($userId, $session, $clock);
        self::assertSame(3_000_000, $session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Advance within valid idle window
        $time = 3_000_500;

        $response = $this->dispatch(new Request('GET', '/products'), session: $session, clock: $clock);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Productos', $response->body);
        self::assertStringContainsString('app-sidebar', $response->body);
        self::assertSame(3_000_500, $session->get(AuthSession::KEY_LAST_ACTIVITY), 'Activity timestamp must be refreshed.');
    }

    public function testBlockedMidSessionRedirects303NormalAnd200Htmx(): void
    {
        $userId = $this->createUser('blocked_user', 'bodeguero', 'activo');

        // 1. Normal request after transition to bloqueado
        $session1 = new NativeSession(false);
        $this->establishSession($userId, $session1);

        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'bloqueado' WHERE id_usuario = {$userId}");

        $resNormal = $this->dispatch(new Request('GET', '/locations'), session: $session1);
        self::assertSame(303, $resNormal->status);
        self::assertSame('/login', $resNormal->headers['Location'] ?? '');
        self::assertStringNotContainsString('Ubicaciones', $resNormal->body);
        self::assertNull($session1->get(AuthSession::KEY_USER_ID));

        // 2. HTMX request after transition to bloqueado
        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'activo' WHERE id_usuario = {$userId}");
        $session2 = new NativeSession(false);
        $this->establishSession($userId, $session2);

        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'bloqueado' WHERE id_usuario = {$userId}");

        $resHtmx = $this->dispatch(
            new Request('GET', '/locations', headers: ['hx-request' => 'true']),
            session: $session2
        );
        self::assertSame(200, $resHtmx->status);
        self::assertSame('/login', $resHtmx->headers['HX-Redirect'] ?? '');
        self::assertSame('', $resHtmx->body);
        self::assertNull($session2->get(AuthSession::KEY_USER_ID));
    }

    public function testInactiveMidSessionRedirects303NormalAnd200Htmx(): void
    {
        $userId = $this->createUser('inactive_user', 'bodeguero', 'activo');

        // 1. Normal request after transition to inactivo
        $session1 = new NativeSession(false);
        $this->establishSession($userId, $session1);

        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'inactivo' WHERE id_usuario = {$userId}");

        $resNormal = $this->dispatch(new Request('GET', '/inventory'), session: $session1);
        self::assertSame(303, $resNormal->status);
        self::assertSame('/login', $resNormal->headers['Location'] ?? '');
        self::assertNull($session1->get(AuthSession::KEY_USER_ID));

        // 2. HTMX request after transition to inactivo
        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'activo' WHERE id_usuario = {$userId}");
        $session2 = new NativeSession(false);
        $this->establishSession($userId, $session2);

        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'inactivo' WHERE id_usuario = {$userId}");

        $resHtmx = $this->dispatch(
            new Request('GET', '/inventory', headers: ['hx-request' => 'true']),
            session: $session2
        );
        self::assertSame(200, $resHtmx->status);
        self::assertSame('/login', $resHtmx->headers['HX-Redirect'] ?? '');
        self::assertSame('', $resHtmx->body);
        self::assertNull($session2->get(AuthSession::KEY_USER_ID));
    }

    public function testMissingUserMidSessionRedirects303NormalAnd200Htmx(): void
    {
        $userId = $this->createUser('deleted_user', 'compras', 'activo');

        // 1. Normal request after user deletion
        $session1 = new NativeSession(false);
        $this->establishSession($userId, $session1);

        self::$testDb->pdo()->exec("DELETE FROM usuario WHERE id_usuario = {$userId}");

        $resNormal = $this->dispatch(new Request('GET', '/products'), session: $session1);
        self::assertSame(303, $resNormal->status);
        self::assertSame('/login', $resNormal->headers['Location'] ?? '');
        self::assertNull($session1->get(AuthSession::KEY_USER_ID));

        // 2. HTMX request after user deletion
        $userId2 = $this->createUser('deleted_user2', 'compras', 'activo');
        $session2 = new NativeSession(false);
        $this->establishSession($userId2, $session2);

        self::$testDb->pdo()->exec("DELETE FROM usuario WHERE id_usuario = {$userId2}");

        $resHtmx = $this->dispatch(
            new Request('GET', '/products', headers: ['hx-request' => 'true']),
            session: $session2
        );
        self::assertSame(200, $resHtmx->status);
        self::assertSame('/login', $resHtmx->headers['HX-Redirect'] ?? '');
        self::assertSame('', $resHtmx->body);
        self::assertNull($session2->get(AuthSession::KEY_USER_ID));
    }

    public function testRoleDoesNotDenyInSliceE1Boundary(): void
    {
        // Cajero is not authorized for /inventory in RouteAccessPolicy (Phase E2),
        // but in Slice E1 ONLY, AuthGuard verifies authentication only.
        $userId = $this->createUser('cajero_e1', 'cajero', 'activo');

        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $response = $this->dispatch(new Request('GET', '/inventory'), session: $session);

        self::assertNotSame(403, $response->status, 'E1 AuthGuard must not deny on role basis.');
        self::assertNotSame(303, $response->status, 'Authenticated cajero must not be redirected to /login.');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Existencias por ubicación', $response->body);
    }

    public function testPublicLoginRegressionNotInterceptedByAuthGuard(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        // 1. Anonymous GET /login renders form
        $getRes = $this->dispatch(new Request('GET', '/login'), session: $session, csrf: $csrf);
        self::assertSame(200, $getRes->status);
        self::assertStringContainsString('action="/login"', $getRes->body);

        // 2. Anonymous POST /login with invalid credentials returns 422
        $failRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $token,
            'username' => 'nonexistent',
            'password' => 'WrongPassword12345',
        ]), session: $session, csrf: $csrf);
        self::assertSame(422, $failRes->status);

        // 3. Anonymous POST /login with valid credentials redirects 303
        $this->createUser('valid_login_user', 'administrador', 'activo', 'PassphraseLogin123');
        $successRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $token,
            'username' => 'valid_login_user',
            'password' => 'PassphraseLogin123',
        ]), session: $session, csrf: $csrf);
        self::assertSame(303, $successRes->status);
        self::assertSame('/products', $successRes->headers['Location'] ?? '');

        // 4. Authenticated GET /login redirects 303 to /products without loop
        $authGetRes = $this->dispatch(new Request('GET', '/login'), session: $session, csrf: $csrf);
        self::assertSame(303, $authGetRes->status);
        self::assertSame('/products', $authGetRes->headers['Location'] ?? '');
    }

    public function testPublicHealthRegressionNotInterceptedByAuthGuard(): void
    {
        $getRes = $this->dispatch(new Request('GET', '/health'));
        self::assertSame(200, $getRes->status);
        self::assertStringContainsString('text/html', $getRes->headers['Content-Type'] ?? '');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $postRes = $this->dispatch(new Request('POST', '/health', body: [
            '_csrf' => $csrf->token(),
            'probe' => 'test-probe',
        ]), session: $session, csrf: $csrf);
        self::assertSame(303, $postRes->status);
        self::assertSame('/health', $postRes->headers['Location'] ?? '');
    }

    public function testLogoutRegressionUnauthenticatedAndAuthenticated(): void
    {
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        // 1. Unauthenticated normal POST /logout redirects 303 to /login
        $unauthRes = $this->dispatch(new Request('POST', '/logout'), session: $session, csrf: $csrf);
        self::assertSame(303, $unauthRes->status);
        self::assertSame('/login', $unauthRes->headers['Location'] ?? '');

        // 2. Unauthenticated HTMX POST /logout returns 200 with HX-Redirect
        $unauthHtmxRes = $this->dispatch(
            new Request('POST', '/logout', headers: ['hx-request' => 'true']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(200, $unauthHtmxRes->status);
        self::assertSame('/login', $unauthHtmxRes->headers['HX-Redirect'] ?? '');
        self::assertSame('', $unauthHtmxRes->body);

        // 3. Authenticated POST /logout with INVALID CSRF returns 403 and preserves session
        $userId = $this->createUser('logout_user', 'administrador', 'activo');
        $authSession = new NativeSession(false);
        $authCsrf = new Csrf($authSession);
        $validToken = $authCsrf->token();
        $this->establishSession($userId, $authSession);

        $invalidCsrfRes = $this->dispatch(new Request('POST', '/logout', body: [
            '_csrf' => 'invalid-token',
        ]), session: $authSession, csrf: $authCsrf);
        self::assertSame(403, $invalidCsrfRes->status);
        self::assertSame($userId, $authSession->get(AuthSession::KEY_USER_ID), 'Session must remain intact on invalid CSRF.');

        // 4. Authenticated POST /logout with VALID CSRF redirects 303 to /login and destroys session
        $validLogoutRes = $this->dispatch(new Request('POST', '/logout', body: [
            '_csrf' => $validToken,
        ]), session: $authSession, csrf: $authCsrf);
        self::assertSame(303, $validLogoutRes->status);
        self::assertSame('/login', $validLogoutRes->headers['Location'] ?? '');
        self::assertNull($authSession->get(AuthSession::KEY_USER_ID), 'Session must be destroyed after valid logout.');
    }

    public function testAllR1BusinessRoutesAreProtectedWhenUnauthenticated(): void
    {
        $routes = [
            ['GET', '/products'],
            ['POST', '/categories'],
            ['POST', '/products'],
            ['POST', '/products/1/price'],
            ['POST', '/products/1/deactivate'],
            ['POST', '/products/1/activate'],
            ['GET', '/locations'],
            ['POST', '/locations'],
            ['GET', '/inventory'],
            ['POST', '/inventory/stock'],
            ['GET', '/inventory/counts'],
            ['POST', '/inventory/counts'],
        ];

        foreach ($routes as [$method, $path]) {
            // Normal request: 303 to /login
            $normalRes = $this->dispatch(new Request($method, $path));
            self::assertSame(303, $normalRes->status, "Route {$method} {$path} must respond 303 for normal unauthenticated requests.");
            self::assertSame('/login', $normalRes->headers['Location'] ?? '', "Route {$method} {$path} must redirect to /login.");

            // HTMX request: 200 with HX-Redirect and empty body
            $htmxRes = $this->dispatch(new Request($method, $path, headers: ['hx-request' => 'true']));
            self::assertSame(200, $htmxRes->status, "Route {$method} {$path} must respond 200 for unauthenticated HTMX requests.");
            self::assertSame('/login', $htmxRes->headers['HX-Redirect'] ?? '', "Route {$method} {$path} must specify HX-Redirect: /login.");
            self::assertSame('', $htmxRes->body, "Route {$method} {$path} must return empty body for unauthenticated HTMX requests.");
        }
    }

    public function testKernelWithoutAuthGuardPreservesBackwardCompatibility(): void
    {
        // When Kernel is instantiated with authGuard = null, unauthenticated GET /products succeeds
        $response = $this->dispatch(new Request('GET', '/products'), withGuard: false);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Productos', $response->body);
    }

    public function testNoSensitiveInformationLoggedOrDisclosed(): void
    {
        $session = new NativeSession(false);
        $response = $this->dispatch(new Request('GET', '/products'), session: $session);

        self::assertSame(303, $response->status);
        self::assertStringNotContainsString('password', $response->body);
        self::assertStringNotContainsString('hash', $response->body);
        self::assertStringNotContainsString('session', $response->body);
        self::assertStringNotContainsString('csrf', $response->body);
    }
}
