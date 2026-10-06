<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Csrf;
use App\Foundation\Database;
use App\Foundation\ErrorMapper;
use App\Foundation\HealthAccessPolicy;
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
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthBoundaryTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static Renderer $renderer;

    private ?string $originalEnv = null;
    private ?string $originalServerEnv = null;
    private ?string $originalEnvVar = null;

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
        $this->originalEnv = getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : null;
        $this->originalServerEnv = $_SERVER['APP_ENV'] ?? null;
        $this->originalEnvVar = $_ENV['APP_ENV'] ?? null;

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

    protected function tearDown(): void
    {
        $this->setAppEnv($this->originalEnv);
        if ($this->originalServerEnv !== null) {
            $_SERVER['APP_ENV'] = $this->originalServerEnv;
        } else {
            unset($_SERVER['APP_ENV']);
        }
        if ($this->originalEnvVar !== null) {
            $_ENV['APP_ENV'] = $this->originalEnvVar;
        } else {
            unset($_ENV['APP_ENV']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
        }
        $_SESSION = [];
    }

    private function setAppEnv(?string $env): void
    {
        if ($env === null) {
            putenv('APP_ENV');
            unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
        } else {
            putenv("APP_ENV={$env}");
            $_SERVER['APP_ENV'] = $env;
            $_ENV['APP_ENV'] = $env;
        }
    }

    private function dispatch(
        Request $request,
        ?NativeSession $session = null,
        ?Csrf $csrf = null,
        ?HealthAccessPolicy $healthPolicy = null,
        bool $withGuards = true,
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
        $healthHandler = new HealthHandler(self::$renderer, $actualCsrf, $actualSession, $healthPolicy);

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

        $sucursalQuery = new \App\Modules\Inventory\SucursalQuery(self::$testDb);
        $sucursalCommand = new \App\Modules\Inventory\SucursalCommand($tx);
        $almacenCommand = new \App\Modules\Inventory\AlmacenCommand($tx);
        $branchHandler = new \App\Modules\Inventory\BranchHandler(self::$renderer, $sucursalQuery, $sucursalCommand, $actualCsrf);
        $warehouseHandler = new \App\Modules\Inventory\WarehouseHandler(self::$renderer, new \App\Modules\Inventory\AlmacenQuery(self::$testDb), $almacenCommand, $sucursalQuery, $actualCsrf);

        $handlers = [
            'health'    => $healthHandler->handle(...),
            'access'    => $accessHandler->handle(...),
            'catalog'   => $catalogHandler->handle(...),
            'branch'    => $branchHandler->handle(...),
            'warehouse' => $warehouseHandler->handle(...),
            'location'  => $locationHandler->handle(...),
            'inventory' => $inventoryHandler->handle(...),
        ];

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = array_map(
            static fn (array $route): array => [$route[0], $route[1], $handlers[$route[2]]],
            $routeConfig
        );

        $authGuard = $withGuards ? new AuthGuard($authSession) : null;
        $roleGuard = $withGuards ? new RoleGuard(new RouteAccessPolicy()) : null;
        $kernel = new Kernel(
            new Router($routes),
            $actualCsrf,
            new ErrorMapper(new Logger(), self::$renderer),
            $authGuard,
            $roleGuard,
            $healthPolicy
        );

        return $kernel->handle($request);
    }

    public function testProductionGetHealthReturnsLiveness200(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);

        $response = $this->dispatch(new Request('GET', '/health'), session: $session, csrf: $csrf);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<!doctype html>', $response->body);
        self::assertStringContainsString('Foundation health', $response->body);
        self::assertArrayNotHasKey('Location', $response->headers);
        self::assertNull($session->get('flash'));

        // Full fragment check with HTMX request header
        $htmxResponse = $this->dispatch(
            new Request('GET', '/health', headers: ['hx-request' => 'true']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(200, $htmxResponse->status);
        self::assertStringNotContainsString('<!doctype html>', $htmxResponse->body);
        self::assertStringContainsString('id="health"', $htmxResponse->body);
        self::assertSame('HX-Request', $htmxResponse->headers['Vary'] ?? null);
        self::assertArrayNotHasKey('Location', $htmxResponse->headers);
        self::assertNull($session->get('flash'));
    }

    public function testProductionPostHealthWithValidCsrfAndProbeReturns405MethodNotAllowed(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'production-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testProductionPostHealthWithInvalidCsrfReturns405MethodNotAllowed(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => 'invalid-token', 'probe' => 'production-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testProductionPostHealthWithMissingCsrfReturns405MethodNotAllowed(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['probe' => 'production-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testProductionPostHealthWithInvalidProbeReturns405MethodNotAllowed(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => '']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testProductionHtmxPostHealthReturns405WithoutHtmxHeaders(): void
    {
        $this->setAppEnv('production');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request(
                'POST',
                '/health',
                headers: ['hx-request' => 'true'],
                body: ['_csrf' => $token, 'probe' => 'production-probe']
            ),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertArrayNotHasKey('HX-Redirect', $response->headers);
        self::assertArrayNotHasKey('HX-Trigger', $response->headers);
        self::assertNull($session->get('flash'));
    }

    public function testDevelopmentPostHealthWithValidCsrfSucceeds(): void
    {
        $this->setAppEnv('development');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'dev-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(303, $response->status);
        self::assertSame('/health', $response->headers['Location'] ?? null);
        self::assertSame('Foundation check completed.', $session->get('flash'));

        // HTMX POST in development mode
        $htmxResponse = $this->dispatch(
            new Request(
                'POST',
                '/health',
                headers: ['hx-request' => 'true'],
                body: ['_csrf' => $token, 'probe' => 'dev-probe']
            ),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(200, $htmxResponse->status);
        self::assertSame('/health', $htmxResponse->headers['HX-Redirect'] ?? null);
        self::assertArrayHasKey('HX-Trigger', $htmxResponse->headers);
        self::assertStringContainsString('notification', (string) $htmxResponse->headers['HX-Trigger']);
    }

    public function testDevelopmentPostHealthWithInvalidCsrfReturns403Forbidden(): void
    {
        $this->setAppEnv('development');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => 'bad-token', 'probe' => 'dev-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testTestEnvironmentPostHealthWithValidCsrfSucceeds(): void
    {
        $this->setAppEnv('test');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'test-probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(303, $response->status);
        self::assertSame('/health', $response->headers['Location'] ?? null);
        self::assertSame('Foundation check completed.', $session->get('flash'));
    }

    public function testFailClosedWhenAppEnvIsUnset(): void
    {
        $this->setAppEnv(null);

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testFailClosedWhenAppEnvIsEmpty(): void
    {
        $this->setAppEnv('');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testFailClosedWhenAppEnvIsUnknown(): void
    {
        $this->setAppEnv('staging');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'probe']),
            session: $session,
            csrf: $csrf
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }

    public function testExplicitHealthAccessPolicyInjectionInKernel(): void
    {
        // When ambient environment is 'development', explicit 'production' policy overrides and gates POST
        $this->setAppEnv('development');

        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $prodPolicy = new HealthAccessPolicy('production');
        $response = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'probe']),
            session: $session,
            csrf: $csrf,
            healthPolicy: $prodPolicy
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));

        // When ambient environment is 'production', explicit 'development' policy overrides and permits POST
        $this->setAppEnv('production');

        $devPolicy = new HealthAccessPolicy('development');
        $devResponse = $this->dispatch(
            new Request('POST', '/health', body: ['_csrf' => $token, 'probe' => 'probe']),
            session: $session,
            csrf: $csrf,
            healthPolicy: $devPolicy
        );

        self::assertSame(303, $devResponse->status);
        self::assertSame('/health', $devResponse->headers['Location'] ?? null);
        self::assertSame('Foundation check completed.', $session->get('flash'));
    }

    public function testHealthAccessPolicyUnitBehaviors(): void
    {
        $policy = new HealthAccessPolicy();
        self::assertFalse($policy->allowsDiagnosticPost('production'));
        self::assertTrue($policy->allowsDiagnosticPost('development'));
        self::assertTrue($policy->allowsDiagnosticPost('test'));
        self::assertTrue($policy->allowsDiagnosticPost('  development  '));
        self::assertTrue($policy->allowsDiagnosticPost("test\n"));
        self::assertFalse($policy->allowsDiagnosticPost('TEST'));
        self::assertFalse($policy->allowsDiagnosticPost(''));
        self::assertFalse($policy->allowsDiagnosticPost('   '));
        self::assertFalse($policy->allowsDiagnosticPost('staging'));

        $explicitProd = new HealthAccessPolicy('production');
        self::assertFalse($explicitProd->allowsDiagnosticPost());
        self::assertTrue($explicitProd->allowsDiagnosticPost('development'));

        $explicitDev = new HealthAccessPolicy('development');
        self::assertTrue($explicitDev->allowsDiagnosticPost());
        self::assertFalse($explicitDev->allowsDiagnosticPost('production'));

        // Direct HealthHandler defense-in-depth verification
        $session = new NativeSession(false);
        $csrf = new Csrf($session);
        $handler = new HealthHandler(self::$renderer, $csrf, $session, new HealthAccessPolicy('production'));
        $response = $handler->handle(new Request('POST', '/health', body: ['probe' => 'test']));
        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertNull($session->get('flash'));
    }
}
