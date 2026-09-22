<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\NativeSession;
use App\Foundation\Transaction;
use App\Modules\Access\AuthSession;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use PHPUnit\Framework\TestCase;

final class SessionLifecycleTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static Database $devDb;
    private static MigrationRunner $runner;
    private static string $migrationsPath;
    private UserQuery $query;
    private UserCommand $command;
    private NativeSession $session;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig     = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb         = new Database(self::$testConfig, useTestDatabase: true);
        self::$devDb          = new Database(self::$testConfig, useTestDatabase: false);
        self::$runner         = new MigrationRunner(self::$testDb);
        self::$migrationsPath = dirname(__DIR__, 2) . '/database/migrations';

        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);
        self::recordInitialDevState(self::$devDb);

        self::$runner->run(self::$migrationsPath);
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('DELETE FROM usuario');

        $_SESSION = [];
        $this->session = new NativeSession(false);
        $tx            = new Transaction(self::$testDb);
        $this->query   = new UserQuery(self::$testDb);
        $this->command = new UserCommand($tx);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testSessionEstablishmentAndFixationProtection(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('alice', $hash, 'administrador', 'activo');

        $preSessionId = session_id();
        $authSession  = new AuthSession($this->session, $this->query);

        $context = $authSession->establish($userId);
        $postSessionId = session_id();

        self::assertIsArray($context);
        self::assertSame($userId, $context['id_usuario']);
        self::assertSame('alice', $context['username']);
        self::assertSame('administrador', $context['rol']);
        self::assertSame('activo', $context['estado']);

        self::assertNotSame('', $preSessionId);
        self::assertNotSame($preSessionId, $postSessionId);
        self::assertSame($userId, $this->session->get(AuthSession::KEY_USER_ID));
        self::assertSame('alice', $this->session->get(AuthSession::KEY_USERNAME));
        self::assertIsInt($this->session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Revalidation through user() returns fresh context
        $user = $authSession->user();
        self::assertNotNull($user);
        self::assertSame($userId, $user['id_usuario']);
        self::assertTrue($authSession->isAuthenticated());
    }

    public function testEstablishmentRefusedIfAccountNotActivoAtEstablishmentTime(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('bob', $hash, 'cajero', 'bloqueado');

        $authSession = new AuthSession($this->session, $this->query);
        $result      = $authSession->establish($userId);

        self::assertNull($result);
        self::assertNull($this->session->get(AuthSession::KEY_USER_ID));
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testCurrentUserRevalidationReturnsFreshContextAndRefreshesActivity(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('charlie', $hash, 'bodeguero', 'activo');

        $currentTime = 1000000;
        $authSession = new AuthSession(
            $this->session,
            $this->query,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );

        $authSession->establish($userId);
        self::assertSame(1000000, $this->session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Advance clock by 100 seconds
        $currentTime = 1000100;
        $context = $authSession->user();

        self::assertNotNull($context);
        self::assertSame('charlie', $context['username']);
        self::assertSame(1000100, $this->session->get(AuthSession::KEY_LAST_ACTIVITY));
    }

    public function testRoleChangeTakesEffectImmediatelyWithoutRelogin(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('david', $hash, 'compras', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);

        $before = $authSession->user();
        self::assertNotNull($before);
        self::assertSame('compras', $before['rol']);

        // Update role in DB
        self::$testDb->pdo()->exec("UPDATE usuario SET rol = 'bodeguero' WHERE id_usuario = {$userId}");

        $after = $authSession->user();
        self::assertNotNull($after);
        self::assertSame('bodeguero', $after['rol']);
    }

    public function testBlockedAccountDuringSessionInvalidatesSessionImmediately(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('eva', $hash, 'cajero', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);
        self::assertTrue($authSession->isAuthenticated());

        // Account is locked in DB
        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'bloqueado' WHERE id_usuario = {$userId}");

        $result = $authSession->user();
        self::assertNull($result);
        self::assertFalse($authSession->isAuthenticated());
        self::assertNull($this->session->get(AuthSession::KEY_USER_ID));

        // Account remains bloqueado in DB
        $row = $this->query->findById($userId);
        self::assertNotNull($row);
        self::assertSame('bloqueado', $row['estado']);
    }

    public function testInactiveAccountDuringSessionInvalidatesSessionImmediately(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('frank', $hash, 'administrador', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);

        // Account administratively deactivated
        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'inactivo' WHERE id_usuario = {$userId}");

        self::assertNull($authSession->user());
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testCreatedStateAccountDuringSessionInvalidatesSession(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('grace', $hash, 'bodeguero', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);

        self::$testDb->pdo()->exec("UPDATE usuario SET estado = 'creado' WHERE id_usuario = {$userId}");

        self::assertNull($authSession->user());
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testDeletedUserAccountDuringSessionInvalidatesSession(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('heidi', $hash, 'compras', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);

        self::$testDb->pdo()->exec("DELETE FROM usuario WHERE id_usuario = {$userId}");

        self::assertNull($authSession->user());
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testCajeroInactivityTimeoutAtTwentyMinutesBoundary(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('cajero_user', $hash, 'cajero', 'activo');

        $currentTime = 1000000;
        $authSession = new AuthSession(
            $this->session,
            $this->query,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );
        $authSession->establish($userId);

        // At exactly 1200 seconds (20 minutes): session remains valid
        $currentTime = 1000000 + 1200;
        $valid = $authSession->user();
        self::assertNotNull($valid);
        self::assertSame(1001200, $this->session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Advance to 1201 seconds after last activity: session expired
        $currentTime = 1001200 + 1201;
        $expired = $authSession->user();
        self::assertNull($expired);
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testNonCajeroDefaultInactivityTimeoutAtThirtyMinutesBoundary(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('admin_user', $hash, 'administrador', 'activo');

        $currentTime = 1000000;
        $authSession = new AuthSession(
            $this->session,
            $this->query,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );
        $authSession->establish($userId);

        // At exactly 1800 seconds (30 minutes): session remains valid
        $currentTime = 1000000 + 1800;
        $valid = $authSession->user();
        self::assertNotNull($valid);
        self::assertSame(1001800, $this->session->get(AuthSession::KEY_LAST_ACTIVITY));

        // Advance to 1801 seconds after last activity: session expired
        $currentTime = 1001800 + 1801;
        $expired = $authSession->user();
        self::assertNull($expired);
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testConfiguredNonCajeroTimeoutRespectsSettingWhileCajeroRemainsFixed(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $adminId  = $this->command->create('custom_admin', $hash, 'administrador', 'activo');
        $cajeroId = $this->command->create('custom_cajero', $hash, 'cajero', 'activo');

        $currentTime = 1000000;
        // Configure non-cajero timeout to 600 seconds (10 minutes)
        $authSession = new AuthSession(
            $this->session,
            $this->query,
            nonCajeroTimeout: 600,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );

        // 1. Admin with 600s timeout: expires at 601s
        $authSession->establish($adminId);
        $currentTime = 1000601;
        self::assertNull($authSession->user());

        // 2. Cajero: at 601s, cajero must still be active because cajero timeout is fixed at 1200s
        $sessionCajero     = new NativeSession(false);
        $authSessionCajero = new AuthSession(
            $sessionCajero,
            $this->query,
            nonCajeroTimeout: 600,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );
        $currentTime = 1000000;
        $authSessionCajero->establish($cajeroId);

        $currentTime = 1000601;
        $cajeroContext = $authSessionCajero->user();
        self::assertNotNull($cajeroContext);
        self::assertSame('cajero', $cajeroContext['rol']);
    }

    public function testRoleChangeFromNonCajeroToCajeroEnforcesStricterTimeout(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('role_shift_user', $hash, 'compras', 'activo');

        $currentTime = 1000000;
        $authSession = new AuthSession(
            $this->session,
            $this->query,
            clock: function () use (&$currentTime): int {
                return $currentTime;
            },
        );
        $authSession->establish($userId);

        // 1300 seconds elapse: valid for compras (<= 1800), but > 1200
        $currentTime = 1001300;

        // Change role to cajero in DB before resolution
        self::$testDb->pdo()->exec("UPDATE usuario SET rol = 'cajero' WHERE id_usuario = {$userId}");

        // Next resolution sees current role = cajero and enforces 1200s limit -> expired!
        self::assertNull($authSession->user());
        self::assertFalse($authSession->isAuthenticated());
    }

    public function testTargetUrlStorageRetrievalAndClear(): void
    {
        $authSession = new AuthSession($this->session, $this->query);

        self::assertNull($authSession->getTargetUrl());

        $authSession->setTargetUrl('/inventory');
        self::assertSame('/inventory', $authSession->getTargetUrl());

        $pulled = $authSession->pullTargetUrl();
        self::assertSame('/inventory', $pulled);
        self::assertNull($authSession->getTargetUrl());
    }

    public function testLogoutDestroysAuthenticatedSession(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('logout_user', $hash, 'administrador', 'activo');

        $authSession = new AuthSession($this->session, $this->query);
        $authSession->establish($userId);
        self::assertTrue($authSession->isAuthenticated());

        $authSession->logout();

        self::assertFalse($authSession->isAuthenticated());
        self::assertNull($authSession->user());
    }
}
