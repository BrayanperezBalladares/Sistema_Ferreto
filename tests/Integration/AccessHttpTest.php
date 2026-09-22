<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Csrf;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\NativeSession;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
use App\Foundation\Transaction;
use App\Modules\Access\AccessHandler;
use App\Modules\Access\Authenticator;
use App\Modules\Access\AuthSession;
use App\Modules\Access\RouteAccessPolicy;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use Closure;
use PHPUnit\Framework\TestCase;

final class AccessHttpTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $config;
    private static Database $testDb;
    private static Database $devDb;
    private static Transaction $tx;
    private static UserQuery $userQuery;
    private static UserCommand $userCmd;
    private static Renderer $renderer;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$config    = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb    = new Database(self::$config, useTestDatabase: true);
        self::$devDb     = new Database(self::$config, useTestDatabase: false);
        self::$tx        = new Transaction(self::$testDb);
        self::$userQuery = new UserQuery(self::$testDb);
        self::$userCmd   = new UserCommand(self::$tx);
        self::$renderer  = new Renderer(dirname(__DIR__, 2));

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
        $pdo->exec('DELETE FROM usuario');

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
        ?Closure $clock = null
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

        /** @var list<array{string, string, string}> $routeConfig */
        $routeConfig = require dirname(__DIR__, 2) . '/config/routes.php';
        $routes = [];
        foreach ($routeConfig as [$method, $path, $name]) {
            if ($name === 'access') {
                $routes[] = [$method, $path, $accessHandler->handle(...)];
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

    public function testAnonymousGetLoginRendersFormWithCsrfAndAccessibleInputs(): void
    {
        $session = new NativeSession(false);
        $csrf    = new Csrf($session);
        $token   = $csrf->token();

        $response = $this->dispatch(new Request('GET', '/login'), session: $session, csrf: $csrf);

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/html', $response->headers['Content-Type'] ?? '');
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? '');

        // Form action="/login" method="post"
        self::assertMatchesRegularExpression('/<form[^>]+method="post"[^>]+action="\/login"|<form[^>]+action="\/login"[^>]+method="post"/i', $response->body);

        // Username input with autocomplete="username"
        self::assertMatchesRegularExpression('/<input[^>]*id="username"[^>]*autocomplete="username"/i', $response->body);

        // Password input with autocomplete="current-password" and NO value attribute
        self::assertMatchesRegularExpression('/<input[^>]*id="password"[^>]*autocomplete="current-password"/i', $response->body);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*id="password"[^>]*value=/i', $response->body);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*name="password"[^>]*value=/i', $response->body);

        // Submit button with login-submit class
        self::assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*class="[^"]*login-submit/i', $response->body);

        // CSRF hidden input with token
        self::assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="_csrf"[^>]*value="' . preg_quote($token, '/') . '"/i', $response->body);
    }

    public function testAuthenticatedGetLoginRedirectsToProductsWithoutTouchingInactivity(): void
    {
        $hash   = password_hash('Passphrase2026!', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = self::$userCmd->create('alice_auth', $hash, 'cajero', 'activo');

        $session   = new NativeSession(false);
        $clockTime = 1000000;
        $clock     = static fn (): int => $clockTime;

        $authSession = new AuthSession($session, self::$userQuery, clock: $clock);
        $context     = $authSession->establish($userId);
        self::assertNotNull($context);
        self::assertSame(1000000, $session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Advance clock by 300 seconds
        $clockTime = 1000300;

        $response = $this->dispatch(new Request('GET', '/login'), session: $session, clock: $clock);

        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location'] ?? null);
        self::assertSame(1000000, $session->get(AuthSession::KEY_LAST_ACTIVITY));
    }

    public function testSuccessfulLoginEstablishesSessionAndRedirectsToProductsByDefault(): void
    {
        $password = 'CorrectPassphrase123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userId   = self::$userCmd->create('bob_login', $hash, 'cajero', 'activo');

        $session  = new NativeSession(false);
        $csrf     = new Csrf($session);
        $response = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $csrf->token(),
            'username' => 'bob_login',
            'password' => $password,
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $response->status);
        self::assertSame('/products', $response->headers['Location'] ?? null);
        self::assertSame($userId, $session->get(AuthSession::KEY_USER_ID));
        self::assertSame('bob_login', $session->get(AuthSession::KEY_USERNAME));
        self::assertIsInt($session->get(AuthSession::KEY_LAST_ACTIVITY));
        self::assertStringNotContainsString($password, $response->body);
        self::assertStringNotContainsString($hash, $response->body);
    }

    public function testSessionFixationRegeneratesSessionIdOnSuccessfulLogin(): void
    {
        $password = 'SecurePassphrase123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        self::$userCmd->create('fixation_user', $hash, 'cajero', 'activo');

        $session      = new NativeSession(false);
        $csrf         = new Csrf($session);
        $preSessionId = session_id();

        $response = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $csrf->token(),
            'username' => 'fixation_user',
            'password' => $password,
        ]), session: $session, csrf: $csrf);

        $postSessionId = session_id();

        self::assertSame(303, $response->status);
        self::assertNotEmpty($preSessionId);
        self::assertNotEmpty($postSessionId);
        self::assertNotSame($preSessionId, $postSessionId);
    }

    public function testInvalidCredentialMatrixReturns422WithGenericMessage(): void
    {
        $correctPassword = 'StandardPassphrase123';
        $hash            = password_hash($correctPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        self::$userCmd->create('active_user', $hash, 'cajero', 'activo');
        self::$userCmd->create('creado_user', $hash, 'cajero', 'creado');
        self::$userCmd->create('bloqueado_user', $hash, 'cajero', 'bloqueado');
        self::$userCmd->create('inactivo_user', $hash, 'cajero', 'inactivo');

        /** @var list<array{string, string, string}> $scenarios */
        $scenarios = [
            'missing username'                     => ['', $correctPassword, ''],
            'wrong password on active user'        => ['active_user', 'WrongPassword999', 'active_user'],
            'creado account with correct password' => ['creado_user', $correctPassword, 'creado_user'],
            'bloqueado account with correct password' => ['bloqueado_user', $correctPassword, 'bloqueado_user'],
            'inactivo account with correct password'  => ['inactivo_user', $correctPassword, 'inactivo_user'],
            '>72 bytes password'                   => ['active_user', str_repeat('A', 73), 'active_user'],
        ];

        foreach ($scenarios as $name => [$username, $password, $expectedValue]) {
            $session = new NativeSession(false);
            $csrf    = new Csrf($session);
            $token   = $csrf->token();

            $response = $this->dispatch(new Request('POST', '/login', body: [
                '_csrf'    => $token,
                'username' => $username,
                'password' => $password,
            ]), session: $session, csrf: $csrf);

            self::assertSame(422, $response->status, "Scenario '{$name}' must return HTTP 422.");
            self::assertStringContainsString(
                'Credenciales incorrectas o cuenta no autorizada.',
                $response->body,
                "Scenario '{$name}' must return exact generic error copy."
            );
            self::assertStringNotContainsString(
                $password,
                $response->body,
                "Scenario '{$name}' must not leak password in response body."
            );
            self::assertMatchesRegularExpression(
                '/<input[^>]*id="username"[^>]*value="' . preg_quote($expectedValue, '/') . '"/i',
                $response->body,
                "Scenario '{$name}' must preserve entered username."
            );
            self::assertMatchesRegularExpression(
                '/<input[^>]*type="hidden"[^>]*name="_csrf"[^>]*value="' . preg_quote($token, '/') . '"/i',
                $response->body,
                "Scenario '{$name}' must provide usable CSRF token."
            );
        }
    }

    public function testLockoutThroughHttpIntegrationAfterSixthFailedAttempt(): void
    {
        $password = 'CorrectPassword123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userId   = self::$userCmd->create('victim_user', $hash, 'cajero', 'activo');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $session  = new NativeSession(false);
            $csrf     = new Csrf($session);
            $response = $this->dispatch(new Request('POST', '/login', body: [
                '_csrf'    => $csrf->token(),
                'username' => 'victim_user',
                'password' => 'WrongPassword',
            ]), session: $session, csrf: $csrf);

            self::assertSame(422, $response->status);
            $user = self::$userQuery->findById($userId);
            self::assertNotNull($user);
            self::assertSame('activo', $user['estado']);
            self::assertSame($attempt, (int) $user['failed_attempt_count']);
            self::assertNull($user['locked_at']);
        }

        // 6th failed attempt locks out the account
        $session   = new NativeSession(false);
        $csrf      = new Csrf($session);
        $response6 = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $csrf->token(),
            'username' => 'victim_user',
            'password' => 'WrongPassword',
        ]), session: $session, csrf: $csrf);

        self::assertSame(422, $response6->status);
        $user6 = self::$userQuery->findById($userId);
        self::assertNotNull($user6);
        self::assertSame('bloqueado', $user6['estado']);
        self::assertSame(6, (int) $user6['failed_attempt_count']);
        self::assertNotNull($user6['locked_at']);
    }

    public function testLoginCsrfEnforcedBeforeAuthentication(): void
    {
        $password = 'CorrectPassword123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userId   = self::$userCmd->create('csrf_user', $hash, 'cajero', 'activo');

        // Missing CSRF token
        $session = new NativeSession(false);
        $csrf    = new Csrf($session);
        $csrf->token();

        $responseMissing = $this->dispatch(new Request('POST', '/login', body: [
            'username' => 'csrf_user',
            'password' => 'wrong',
        ]), session: $session, csrf: $csrf);

        self::assertSame(403, $responseMissing->status);
        $userAfterMissing = self::$userQuery->findById($userId);
        self::assertNotNull($userAfterMissing);
        self::assertSame(0, (int) $userAfterMissing['failed_attempt_count']);

        // Invalid CSRF token
        $responseInvalid = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => 'invalid_csrf_token_string',
            'username' => 'csrf_user',
            'password' => 'wrong',
        ]), session: $session, csrf: $csrf);

        self::assertSame(403, $responseInvalid->status);
        $userAfterInvalid = self::$userQuery->findById($userId);
        self::assertNotNull($userAfterInvalid);
        self::assertSame(0, (int) $userAfterInvalid['failed_attempt_count']);
    }

    public function testSafeReturnDestinationMatrixAndOneTimeConsumption(): void
    {
        $password = 'ValidPassword123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        self::$userCmd->create('user_cajero', $hash, 'cajero', 'activo');
        self::$userCmd->create('user_bodeguero', $hash, 'bodeguero', 'activo');
        self::$userCmd->create('user_admin', $hash, 'administrador', 'activo');

        /** @var list<array{string, string, string}> $matrix */
        $matrix = [
            ['/products', 'user_cajero', '/products'],
            ['/inventory', 'user_cajero', '/products'],
            ['/inventory', 'user_bodeguero', '/inventory'],
            ['/locations', 'user_admin', '/locations'],
            ['https://example.com', 'user_admin', '/products'],
            ['//example.com/products', 'user_admin', '/products'],
            ['/unknown', 'user_admin', '/products'],
            ['/inventory/stock', 'user_admin', '/products'],
        ];

        foreach ($matrix as [$targetUrl, $username, $expectedDestination]) {
            $session     = new NativeSession(false);
            $authSession = new AuthSession($session, self::$userQuery);
            $authSession->setTargetUrl($targetUrl);
            self::assertSame($targetUrl, $authSession->getTargetUrl());

            $csrf     = new Csrf($session);
            $response = $this->dispatch(new Request('POST', '/login', body: [
                '_csrf'    => $csrf->token(),
                'username' => $username,
                'password' => $password,
            ]), session: $session, csrf: $csrf);

            self::assertSame(303, $response->status);
            self::assertSame($expectedDestination, $response->headers['Location'] ?? null);
            self::assertNull($authSession->getTargetUrl(), "Target URL '{$targetUrl}' must be consumed.");
        }
    }

    public function testLogoutSuccessDestroysSessionAndRedirectsToLogin(): void
    {
        $password = 'ValidPassword123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userId   = self::$userCmd->create('logout_user', $hash, 'cajero', 'activo');

        $session     = new NativeSession(false);
        $authSession = new AuthSession($session, self::$userQuery);
        $context     = $authSession->establish($userId);
        self::assertNotNull($context);
        self::assertNotNull($authSession->peekUser());

        $csrf     = new Csrf($session);
        $response = $this->dispatch(new Request('POST', '/logout', body: [
            '_csrf' => $csrf->token(),
        ]), session: $session, csrf: $csrf);

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location'] ?? null);
        self::assertNull($authSession->peekUser());
        self::assertNull($session->get(AuthSession::KEY_USER_ID));
    }

    public function testLogoutWithInvalidCsrfPreservesSession(): void
    {
        $password = 'ValidPassword123';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userId   = self::$userCmd->create('csrf_logout_user', $hash, 'cajero', 'activo');

        $session     = new NativeSession(false);
        $authSession = new AuthSession($session, self::$userQuery);
        $context     = $authSession->establish($userId);
        self::assertNotNull($context);
        self::assertNotNull($authSession->peekUser());

        $csrf     = new Csrf($session);
        $response = $this->dispatch(new Request('POST', '/logout', body: [
            '_csrf' => 'invalid_csrf_token',
        ]), session: $session, csrf: $csrf);

        self::assertSame(403, $response->status);
        self::assertNotNull($authSession->peekUser());
        self::assertSame($userId, $authSession->peekUser()['id_usuario']);
        self::assertSame($userId, $session->get(AuthSession::KEY_USER_ID));
    }

    public function testNoPlaintextPasswordOrHashLeakedInResponses(): void
    {
        $plaintext = 'SecretP@ssword#2026LeakedCheck';
        $hash      = password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => 10]);
        self::$userCmd->create('leak_user', $hash, 'cajero', 'activo');

        // 1. GET /login
        $getRes = $this->dispatch(new Request('GET', '/login'));
        self::assertStringNotContainsString($plaintext, $getRes->body);
        self::assertStringNotContainsString($hash, $getRes->body);

        // 2. Failed POST /login
        $session1 = new NativeSession(false);
        $csrf1    = new Csrf($session1);
        $failRes  = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $csrf1->token(),
            'username' => 'leak_user',
            'password' => 'Wrong' . $plaintext,
        ]), session: $session1, csrf: $csrf1);
        self::assertSame(422, $failRes->status);
        self::assertStringNotContainsString('Wrong' . $plaintext, $failRes->body);
        self::assertStringNotContainsString($plaintext, $failRes->body);
        self::assertStringNotContainsString($hash, $failRes->body);
        foreach ($failRes->headers as $headerValue) {
            self::assertStringNotContainsString($plaintext, $headerValue);
            self::assertStringNotContainsString($hash, $headerValue);
        }

        // 3. Successful POST /login
        $session2   = new NativeSession(false);
        $csrf2      = new Csrf($session2);
        $successRes = $this->dispatch(new Request('POST', '/login', body: [
            '_csrf'    => $csrf2->token(),
            'username' => 'leak_user',
            'password' => $plaintext,
        ]), session: $session2, csrf: $csrf2);
        self::assertSame(303, $successRes->status);
        self::assertStringNotContainsString($plaintext, $successRes->body);
        self::assertStringNotContainsString($hash, $successRes->body);
        foreach ($successRes->headers as $headerValue) {
            self::assertStringNotContainsString($plaintext, $headerValue);
            self::assertStringNotContainsString($hash, $headerValue);
        }
    }

    public function testUnsupportedMethodsOnLoginAndLogoutReturn405(): void
    {
        $putLogin = $this->dispatch(new Request('PUT', '/login'));
        self::assertSame(405, $putLogin->status);
        self::assertSame('GET, POST', $putLogin->headers['Allow'] ?? '');

        $deleteLogin = $this->dispatch(new Request('DELETE', '/login'));
        self::assertSame(405, $deleteLogin->status);
        self::assertSame('GET, POST', $deleteLogin->headers['Allow'] ?? '');

        $getLogout = $this->dispatch(new Request('GET', '/logout'));
        self::assertSame(405, $getLogout->status);
        self::assertSame('POST', $getLogout->headers['Allow'] ?? '');

        $deleteLogout = $this->dispatch(new Request('DELETE', '/logout'));
        self::assertSame(405, $deleteLogout->status);
        self::assertSame('POST', $deleteLogout->headers['Allow'] ?? '');
    }
}
