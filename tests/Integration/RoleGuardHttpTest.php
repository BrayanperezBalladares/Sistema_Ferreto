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
use ReflectionClass;

final class RoleGuardHttpTest extends TestCase
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
            $roleGuard
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

    private function createCategoryAndProduct(): array
    {
        $tx = new Transaction(self::$testDb);
        $catCmd = new CategoryCommand($tx);
        $prodCmd = new ProductCommand($tx);
        $catId = $catCmd->create('Ferretería');
        $prodId = $prodCmd->register('Martillo de uña', '15.50', $catId);
        return [$catId, $prodId];
    }

    public function testAdministratorPassesRoleGuardOnAllTwelveR1BusinessRoutes(): void
    {
        $userId = $this->createUser('admin_user', 'administrador');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        [$catId, $prodId] = $this->createCategoryAndProduct();

        /** @var list<array{string, string, array<string, mixed>}> $routesToTest */
        $routesToTest = [
            ['GET', '/products', []],
            ['POST', '/categories', ['_csrf' => $token, 'name' => 'Fontanería']],
            ['POST', '/products', ['_csrf' => $token, 'name' => 'Tubo Galvanizado', 'price' => '10.00', 'category_id' => (string) $catId]],
            ['POST', "/products/{$prodId}/price", ['_csrf' => $token, 'price' => '18.75']],
            ['POST', "/products/{$prodId}/deactivate", ['_csrf' => $token]],
            ['POST', "/products/{$prodId}/activate", ['_csrf' => $token]],
            ['GET', '/locations', []],
            ['POST', '/locations', ['_csrf' => $token, 'code' => 'PASILLO-1']],
            ['GET', '/inventory', []],
            ['POST', '/inventory/stock', ['_csrf' => $token, 'product_id' => (string) $prodId, 'location_id' => '1', 'quantity' => '50']],
            ['GET', '/inventory/counts', []],
            ['POST', '/inventory/counts', ['_csrf' => $token, 'product_id' => (string) $prodId, 'counted_quantity' => '10']],
        ];

        foreach ($routesToTest as [$method, $path, $body]) {
            $req = new Request($method, $path, body: $body);
            $res = $this->dispatch($req, session: $session, csrf: $csrf);
            self::assertNotSame(403, $res->status, "Administrator must NOT be denied with 403 on {$method} {$path}.");
        }
    }

    public function testBodegueroRoleAuthorizationMatrix(): void
    {
        $userId = $this->createUser('bodeguero_user', 'bodeguero');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        [$catId, $prodId] = $this->createCategoryAndProduct();

        // 7 Allowed operations for Bodeguero
        $allowed = [
            ['GET', '/products', []],
            ['GET', '/locations', []],
            ['POST', '/locations', ['_csrf' => $token, 'code' => 'BOD-01']],
            ['GET', '/inventory', []],
            ['POST', '/inventory/stock', ['_csrf' => $token, 'product_id' => (string) $prodId, 'location_id' => '1', 'quantity' => '10']],
            ['GET', '/inventory/counts', []],
            ['POST', '/inventory/counts', ['_csrf' => $token, 'product_id' => (string) $prodId, 'counted_quantity' => '5']],
        ];

        foreach ($allowed as [$method, $path, $body]) {
            $res = $this->dispatch(new Request($method, $path, body: $body), session: $session, csrf: $csrf);
            self::assertNotSame(403, $res->status, "Bodeguero must be allowed on {$method} {$path}.");
        }

        // 5 Denied operations for Bodeguero (catalog mutations)
        $denied = [
            ['POST', '/categories', ['_csrf' => $token, 'name' => 'Pintura']],
            ['POST', '/products', ['_csrf' => $token, 'name' => 'Brocha 3in', 'price' => '4.50', 'category_id' => (string) $catId]],
            ['POST', "/products/{$prodId}/price", ['_csrf' => $token, 'price' => '22.00']],
            ['POST', "/products/{$prodId}/deactivate", ['_csrf' => $token]],
            ['POST', "/products/{$prodId}/activate", ['_csrf' => $token]],
        ];

        foreach ($denied as [$method, $path, $body]) {
            $res = $this->dispatch(new Request($method, $path, body: $body), session: $session, csrf: $csrf);
            self::assertSame(403, $res->status, "Bodeguero must be denied with 403 on {$method} {$path}.");
            self::assertSame('Forbidden', $res->body);
            self::assertArrayNotHasKey('Location', $res->headers);
            self::assertArrayNotHasKey('HX-Redirect', $res->headers);
        }
    }

    public function testCajeroRoleAuthorizationMatrix(): void
    {
        $userId = $this->createUser('cajero_user', 'cajero');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        [$catId, $prodId] = $this->createCategoryAndProduct();

        // 1 Allowed operation: GET /products
        $res = $this->dispatch(new Request('GET', '/products'), session: $session, csrf: $csrf);
        self::assertSame(200, $res->status, 'Cajero must be allowed to read products.');

        // 11 Denied operations for Cajero
        $denied = [
            ['POST', '/categories', ['_csrf' => $token, 'name' => 'Construcción']],
            ['POST', '/products', ['_csrf' => $token, 'name' => 'Cemento 50kg', 'price' => '8.00', 'category_id' => (string) $catId]],
            ['POST', "/products/{$prodId}/price", ['_csrf' => $token, 'price' => '19.99']],
            ['POST', "/products/{$prodId}/deactivate", ['_csrf' => $token]],
            ['POST', "/products/{$prodId}/activate", ['_csrf' => $token]],
            ['GET', '/locations', []],
            ['POST', '/locations', ['_csrf' => $token, 'code' => 'CAJA-1']],
            ['GET', '/inventory', []],
            ['POST', '/inventory/stock', ['_csrf' => $token, 'product_id' => (string) $prodId, 'location_id' => '1', 'quantity' => '10']],
            ['GET', '/inventory/counts', []],
            ['POST', '/inventory/counts', ['_csrf' => $token, 'product_id' => (string) $prodId, 'counted_quantity' => '2']],
        ];

        foreach ($denied as [$method, $path, $body]) {
            $res = $this->dispatch(new Request($method, $path, body: $body), session: $session, csrf: $csrf);
            self::assertSame(403, $res->status, "Cajero must receive 403 on {$method} {$path}.");
            self::assertSame('Forbidden', $res->body);
            self::assertArrayNotHasKey('Location', $res->headers);
            self::assertArrayNotHasKey('HX-Redirect', $res->headers);
        }
    }

    public function testComprasRoleAuthorizationMatrix(): void
    {
        $userId = $this->createUser('compras_user', 'compras');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        [$catId, $prodId] = $this->createCategoryAndProduct();

        // 1 Allowed operation: GET /products
        $res = $this->dispatch(new Request('GET', '/products'), session: $session, csrf: $csrf);
        self::assertSame(200, $res->status, 'Compras must be allowed to read products.');

        // 11 Denied operations for Compras
        $denied = [
            ['POST', '/categories', ['_csrf' => $token, 'name' => 'Cerrajería']],
            ['POST', '/products', ['_csrf' => $token, 'name' => 'Cerradura Yale', 'price' => '35.00', 'category_id' => (string) $catId]],
            ['POST', "/products/{$prodId}/price", ['_csrf' => $token, 'price' => '29.99']],
            ['POST', "/products/{$prodId}/deactivate", ['_csrf' => $token]],
            ['POST', "/products/{$prodId}/activate", ['_csrf' => $token]],
            ['GET', '/locations', []],
            ['POST', '/locations', ['_csrf' => $token, 'code' => 'COMPRAS-1']],
            ['GET', '/inventory', []],
            ['POST', '/inventory/stock', ['_csrf' => $token, 'product_id' => (string) $prodId, 'location_id' => '1', 'quantity' => '100']],
            ['GET', '/inventory/counts', []],
            ['POST', '/inventory/counts', ['_csrf' => $token, 'product_id' => (string) $prodId, 'counted_quantity' => '50']],
        ];

        foreach ($denied as [$method, $path, $body]) {
            $res = $this->dispatch(new Request($method, $path, body: $body), session: $session, csrf: $csrf);
            self::assertSame(403, $res->status, "Compras must receive 403 on {$method} {$path}.");
            self::assertSame('Forbidden', $res->body);
            self::assertArrayNotHasKey('Location', $res->headers);
            self::assertArrayNotHasKey('HX-Redirect', $res->headers);
        }
    }

    public function testDynamicProductRoutesAuthorizationForAdminVsNonAdmin(): void
    {
        // 1. Admin test
        $adminId = $this->createUser('admin_dyn', 'administrador');
        $adminSession = new NativeSession(false);
        $adminCsrf = new Csrf($adminSession);
        $adminToken = $adminCsrf->token();
        $this->establishSession($adminId, $adminSession);

        $dynamicPaths = [
            ['POST', '/products/1/price', ['price' => '50.00']],
            ['POST', '/products/15/activate', []],
            ['POST', '/products/99/deactivate', []],
        ];

        foreach ($dynamicPaths as [$method, $path, $body]) {
            $adminRes = $this->dispatch(
                new Request($method, $path, body: array_merge(['_csrf' => $adminToken], $body)),
                session: $adminSession,
                csrf: $adminCsrf
            );
            self::assertNotSame(403, $adminRes->status, "Admin must pass RoleGuard on dynamic route {$path}.");
        }

        // 2. Cajero test in isolated session
        $_SESSION = [];
        $cajeroId = $this->createUser('cajero_dyn', 'cajero');
        $cajeroSession = new NativeSession(false);
        $cajeroCsrf = new Csrf($cajeroSession);
        $cajeroToken = $cajeroCsrf->token();
        $this->establishSession($cajeroId, $cajeroSession);

        foreach ($dynamicPaths as [$method, $path, $body]) {
            $cajeroRes = $this->dispatch(
                new Request($method, $path, body: array_merge(['_csrf' => $cajeroToken], $body)),
                session: $cajeroSession,
                csrf: $cajeroCsrf
            );
            self::assertSame(403, $cajeroRes->status, "Cajero must be denied 403 on dynamic route {$path}.");
            self::assertSame('Forbidden', $cajeroRes->body);
        }
    }

    public function testMidSessionRoleChangeTakesEffectImmediatelyWithoutRelogin(): void
    {
        $userId = $this->createUser('mutable_user', 'administrador');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        // 1. Initial request as administrador: passes RoleGuard
        $res1 = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'Jardinería']),
            session: $session,
            csrf: $csrf
        );
        self::assertNotSame(403, $res1->status, 'Administrator must be allowed on POST /categories.');

        // 2. Persist role change to cajero directly in the database
        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare("UPDATE usuario SET rol = 'cajero' WHERE id_usuario = :id");
        $stmt->execute(['id' => $userId]);

        // 3. Next request with the SAME active session: now denied with 403 without re-login
        $res2 = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'Electricidad']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(403, $res2->status, 'User demoted to cajero must immediately receive 403 on POST /categories.');
        self::assertSame('Forbidden', $res2->body);

        // 4. Promote back to administrador in database
        $stmt = $pdo->prepare("UPDATE usuario SET rol = 'administrador' WHERE id_usuario = :id");
        $stmt->execute(['id' => $userId]);

        // 5. Next request with the SAME active session: allowed again
        $res3 = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'Iluminación']),
            session: $session,
            csrf: $csrf
        );
        self::assertNotSame(403, $res3->status, 'User promoted back to admin must immediately pass RoleGuard.');
    }

    public function testAccountStatusPrecedenceOverRoleGuard(): void
    {
        $userId = $this->createUser('admin_status', 'administrador');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        $pdo = self::$testDb->pdo();

        // 1. Account transitioned to 'bloqueado' in database
        $pdo->prepare("UPDATE usuario SET estado = 'bloqueado' WHERE id_usuario = :id")->execute(['id' => $userId]);

        // Browser request: AuthGuard intercepts -> 303 to /login, NOT 403
        $browserRes = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'Cat1']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(303, $browserRes->status);
        self::assertSame('/login', $browserRes->headers['Location'] ?? '');

        // HTMX request: AuthGuard intercepts -> 200 with HX-Redirect: /login, NOT 403
        $htmxRes = $this->dispatch(
            new Request('POST', '/categories', headers: ['hx-request' => 'true'], body: ['_csrf' => $token, 'name' => 'Cat1']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(200, $htmxRes->status);
        self::assertSame('/login', $htmxRes->headers['HX-Redirect'] ?? '');

        // 2. Account transitioned to 'inactivo' in database
        $pdo->prepare("UPDATE usuario SET estado = 'inactivo' WHERE id_usuario = :id")->execute(['id' => $userId]);

        $browserRes2 = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'Cat2']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(303, $browserRes2->status);
        self::assertSame('/login', $browserRes2->headers['Location'] ?? '');

        $htmxRes2 = $this->dispatch(
            new Request('POST', '/categories', headers: ['hx-request' => 'true'], body: ['_csrf' => $token, 'name' => 'Cat2']),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(200, $htmxRes2->status);
        self::assertSame('/login', $htmxRes2->headers['HX-Redirect'] ?? '');
    }

    public function testInactivityExpirationPrecedenceOverRoleGuard(): void
    {
        $userId = $this->createUser('admin_timeout', 'administrador');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $currentTime = 1700000000;
        $clock = function () use (&$currentTime): int {
            return $currentTime;
        };

        $this->establishSession($userId, $session, clock: $clock);

        // Advance clock past 1800-second administrator inactivity limit
        $currentTime += 1801;

        // Browser request: AuthGuard intercepts -> 303 to /login, NOT 403
        $browserRes = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $token, 'name' => 'TimedOut']),
            session: $session,
            csrf: $csrf,
            clock: $clock
        );
        self::assertSame(303, $browserRes->status);
        self::assertSame('/login', $browserRes->headers['Location'] ?? '');

        // Re-establish session and test HTMX expiration precedence
        $_SESSION = [];
        $session2 = new NativeSession(false);
        $csrf2 = new Csrf($session2);
        $token2 = $csrf2->token();
        $currentTime2 = 1700000000;
        $clock2 = function () use (&$currentTime2): int {
            return $currentTime2;
        };
        $this->establishSession($userId, $session2, clock: $clock2);
        $currentTime2 += 1801;

        $htmxRes = $this->dispatch(
            new Request('POST', '/categories', headers: ['hx-request' => 'true'], body: ['_csrf' => $token2, 'name' => 'TimedOut']),
            session: $session2,
            csrf: $csrf2,
            clock: $clock2
        );
        self::assertSame(200, $htmxRes->status);
        self::assertSame('/login', $htmxRes->headers['HX-Redirect'] ?? '');
    }

    public function testHtmxUnauthorizedRequestReturns403WithoutRedirect(): void
    {
        $userId = $this->createUser('cajero_htmx', 'cajero');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        // Authenticated cajero requesting /inventory via HTMX
        $res = $this->dispatch(
            new Request('GET', '/inventory', headers: ['hx-request' => 'true']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(403, $res->status);
        self::assertSame('Forbidden', $res->body);
        self::assertArrayNotHasKey('HX-Redirect', $res->headers, 'RoleGuard must not return HX-Redirect on 403 role denial.');
        self::assertArrayNotHasKey('Location', $res->headers, 'RoleGuard must not redirect on 403 role denial.');
    }

    public function testUnauthorizedMutationsProduceZeroPersistenceSideEffects(): void
    {
        $bodegueroId = $this->createUser('bod_sideeffect', 'bodeguero');
        $bodSession = new NativeSession(false);
        $bodCsrf = new Csrf($bodSession);
        $bodToken = $bodCsrf->token();
        $this->establishSession($bodegueroId, $bodSession);

        [$catId, $prodId] = $this->createCategoryAndProduct();

        $pdo = self::$testDb->pdo();

        // 1. Bodeguero attempts POST /categories
        $resCat = $this->dispatch(
            new Request('POST', '/categories', body: ['_csrf' => $bodToken, 'name' => 'Ferretería Prohibida']),
            session: $bodSession,
            csrf: $bodCsrf
        );
        self::assertSame(403, $resCat->status);
        $catCount = (int) $pdo->query("SELECT COUNT(*) FROM categoria WHERE nombre = 'Ferretería Prohibida'")->fetchColumn();
        self::assertSame(0, $catCount, 'Category must NOT be created when role is unauthorized.');

        // 2. Bodeguero attempts POST /products
        $resProd = $this->dispatch(
            new Request('POST', '/products', body: ['_csrf' => $bodToken, 'name' => 'Producto Prohibido', 'price' => '12.00', 'category_id' => (string) $catId]),
            session: $bodSession,
            csrf: $bodCsrf
        );
        self::assertSame(403, $resProd->status);
        $prodCount = (int) $pdo->query("SELECT COUNT(*) FROM producto WHERE nombre = 'Producto Prohibido'")->fetchColumn();
        self::assertSame(0, $prodCount, 'Product must NOT be created when role is unauthorized.');

        // 3. Bodeguero attempts POST /products/{id}/price
        $resPrice = $this->dispatch(
            new Request('POST', "/products/{$prodId}/price", body: ['_csrf' => $bodToken, 'price' => '999.99']),
            session: $bodSession,
            csrf: $bodCsrf
        );
        self::assertSame(403, $resPrice->status);
        $price = (string) $pdo->query("SELECT precio_actual FROM producto WHERE id_producto = {$prodId}")->fetchColumn();
        self::assertSame('15.50', $price, 'Product price must NOT be changed when role is unauthorized.');

        // 4. Bodeguero attempts POST /products/{id}/deactivate
        $resDeact = $this->dispatch(
            new Request('POST', "/products/{$prodId}/deactivate", body: ['_csrf' => $bodToken]),
            session: $bodSession,
            csrf: $bodCsrf
        );
        self::assertSame(403, $resDeact->status);
        $active = (int) $pdo->query("SELECT estado_activo FROM producto WHERE id_producto = {$prodId}")->fetchColumn();
        self::assertSame(1, $active, 'Product active state must NOT be changed when role is unauthorized.');

        // 5. Cajero attempts with isolated session
        $_SESSION = [];
        $cajeroId = $this->createUser('caj_sideeffect', 'cajero');
        $cajSession = new NativeSession(false);
        $cajCsrf = new Csrf($cajSession);
        $cajToken = $cajCsrf->token();
        $this->establishSession($cajeroId, $cajSession);

        // Cajero attempts POST /locations
        $resLoc = $this->dispatch(
            new Request('POST', '/locations', body: ['_csrf' => $cajToken, 'code' => 'LOC-PROHIBIDA']),
            session: $cajSession,
            csrf: $cajCsrf
        );
        self::assertSame(403, $resLoc->status);
        $locCount = (int) $pdo->query("SELECT COUNT(*) FROM ubicacion WHERE codigo = 'LOC-PROHIBIDA'")->fetchColumn();
        self::assertSame(0, $locCount, 'Location must NOT be created when role is unauthorized.');

        // Cajero attempts POST /inventory/stock
        $resStock = $this->dispatch(
            new Request('POST', '/inventory/stock', body: ['_csrf' => $cajToken, 'product_id' => (string) $prodId, 'location_id' => '1', 'quantity' => '999']),
            session: $cajSession,
            csrf: $cajCsrf
        );
        self::assertSame(403, $resStock->status);
        $stockCount = (int) $pdo->query('SELECT COUNT(*) FROM inventario_stock')->fetchColumn();
        self::assertSame(0, $stockCount, 'Stock must NOT be recorded when role is unauthorized.');

        // Cajero attempts POST /inventory/counts
        $resCount = $this->dispatch(
            new Request('POST', '/inventory/counts', body: ['_csrf' => $cajToken, 'product_id' => (string) $prodId, 'counted_quantity' => '999']),
            session: $cajSession,
            csrf: $cajCsrf
        );
        self::assertSame(403, $resCount->status);
        $countsTotal = (int) $pdo->query('SELECT COUNT(*) FROM conteo_inventario')->fetchColumn();
        self::assertSame(0, $countsTotal, 'Inventory count must NOT be recorded when role is unauthorized.');
    }

    public function testPublicAndExemptRoutesBypassRoleGuard(): void
    {
        $userId = $this->createUser('cajero_exempt', 'cajero');
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->establishSession($userId, $session);

        // 1. GET /health: accessible anonymously and authenticated
        $resHealth = $this->dispatch(new Request('GET', '/health'), session: $session, csrf: $csrf);
        self::assertSame(200, $resHealth->status);

        // 2. GET /login: authenticated user redirected to /products by AccessHandler, NOT 403
        $resLogin = $this->dispatch(new Request('GET', '/login'), session: $session, csrf: $csrf);
        self::assertSame(303, $resLogin->status);
        self::assertSame('/products', $resLogin->headers['Location'] ?? '');

        // 3. POST /logout: any authenticated role can logout with valid CSRF, NOT 403
        $resLogout = $this->dispatch(
            new Request('POST', '/logout', body: ['_csrf' => $token]),
            session: $session,
            csrf: $csrf
        );
        self::assertSame(303, $resLogout->status);
        self::assertSame('/login', $resLogout->headers['Location'] ?? '');
        self::assertNull($session->get(AuthSession::KEY_USER_ID), 'Session must be destroyed on logout.');
    }

    public function testUnknownRouteReturns404RatherThan403ForAuthenticatedUser(): void
    {
        $userId = $this->createUser('cajero_unknown', 'cajero');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $res = $this->dispatch(new Request('GET', '/definitely-not-a-route'), session: $session);
        self::assertSame(404, $res->status, 'Unknown route must return 404 Not Found, not 403 Forbidden.');
    }

    public function testSanitizedPrincipalDoesNotContainSensitivePersistenceInternals(): void
    {
        $userId = $this->createUser('principal_check', 'cajero');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $query = new UserQuery(self::$testDb);
        $authSession = new AuthSession($session, $query);
        $guard = new AuthGuard($authSession);

        $principal = null;
        $res = $guard->check(new Request('GET', '/products'), $principal);

        self::assertNull($res, 'AuthGuard must allow authenticated user.');
        self::assertNotNull($principal, 'Principal must be populated.');
        self::assertSame([
            'id_usuario' => $userId,
            'username'   => 'principal_check',
            'rol'        => 'cajero',
            'estado'     => 'activo',
        ], $principal);

        self::assertCount(4, $principal, 'Principal must contain exactly 4 sanitized fields.');
        self::assertArrayNotHasKey('password_hash', $principal);
        self::assertArrayNotHasKey('failed_attempt_count', $principal);
        self::assertArrayNotHasKey('failure_window_started_at', $principal);
        self::assertArrayNotHasKey('locked_at', $principal);
        self::assertArrayNotHasKey('created_at', $principal);
        self::assertArrayNotHasKey('updated_at', $principal);
    }

    public function testSingleAuthSessionResolutionPerProtectedRequest(): void
    {
        // 1. Verify RoleGuard has no AuthSession, UserQuery, or Database dependencies
        $refClass = new ReflectionClass(RoleGuard::class);
        $constructor = $refClass->getConstructor();
        self::assertNotNull($constructor);
        $params = $constructor->getParameters();
        self::assertCount(1, $params, 'RoleGuard constructor must take exactly 1 parameter.');
        self::assertSame(RouteAccessPolicy::class, $params[0]->getType()?->getName());

        $properties = $refClass->getProperties();
        self::assertCount(1, $properties, 'RoleGuard must have only 1 property.');
        self::assertSame('routePolicy', $properties[0]->getName());

        // 2. Verify that during Kernel execution, AuthSession resolution occurs exactly once
        $userId = $this->createUser('single_res_user', 'cajero');
        $session = new NativeSession(false);

        $clockCalls = 0;
        $clock = function () use (&$clockCalls): int {
            $clockCalls++;
            return 1700000000;
        };

        $this->establishSession($userId, $session, clock: $clock);
        // Reset call counter after establishSession
        $clockCalls = 0;

        $response = $this->dispatch(new Request('GET', '/products'), session: $session, clock: $clock);
        self::assertSame(200, $response->status);
        self::assertSame(1, $clockCalls, 'AuthSession::user() must be invoked exactly once during protected request dispatch.');
    }

    public function testCheckDeniesAuthenticatedRequestWhenNonExemptRouteHasNoPolicyEntry(): void
    {
        $policy = new RouteAccessPolicy();
        $guard = new RoleGuard($policy);

        $principal = [
            'id_usuario' => 1,
            'username'   => 'admin_user',
            'rol'        => 'administrador',
            'estado'     => 'activo',
        ];

        // Route is non-exempt and has NO entry in RouteAccessPolicy
        $res = $guard->check(new Request('GET', '/unmapped/protected/route'), $principal);

        self::assertNotNull($res, 'RoleGuard must fail closed when a non-exempt route has no RouteAccessPolicy entry.');
        self::assertSame(403, $res->status);
        self::assertSame('Forbidden', $res->body);
    }

    public function testRegisteredNonExemptRouteMissingFromPolicyFailsClosedWith403InKernel(): void
    {
        $userId = $this->createUser('admin_unmapped', 'administrador');
        $session = new NativeSession(false);
        $this->establishSession($userId, $session);

        $actualCsrf = new Csrf($session);
        $actualCsrf->token();

        $userQuery   = new UserQuery(self::$testDb);
        $authSession = new AuthSession($session, $userQuery);
        $routePolicy = new RouteAccessPolicy();

        $routes = [
            ['GET', '/unmapped-registered-route', static fn (): Response => new Response(200, [], 'Unmapped Handler Hit')],
        ];

        $kernel = new Kernel(
            new Router($routes),
            $actualCsrf,
            new ErrorMapper(new Logger(), self::$renderer),
            new AuthGuard($authSession),
            new RoleGuard($routePolicy)
        );

        $res = $kernel->handle(new Request('GET', '/unmapped-registered-route'));
        self::assertSame(403, $res->status, 'Kernel must deny registered non-exempt route missing from RouteAccessPolicy with 403 Forbidden.');
        self::assertSame('Forbidden', $res->body);
    }
}
