<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Console;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Access\UserCliHandler;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use PHPUnit\Framework\TestCase;

final class ConsoleUserTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static Database $devDb;
    private static MigrationRunner $runner;
    private static string $migrationsPath;
    private UserCommand $command;
    private UserQuery $query;

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

        $tx = new Transaction(self::$testDb);
        $this->command = new UserCommand($tx);
        $this->query   = new UserQuery(self::$testDb);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testSuccessfulUserCreation(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $handler = new UserCliHandler(
            $this->command,
            fn(): string => 'MiContrasenaSegura123!',
            $out,
            $err,
        );

        $exitCode = $handler->handleCreateUser(['admin_test', 'administrador']);
        self::assertSame(0, $exitCode);

        rewind($out);
        $stdout = stream_get_contents($out);
        self::assertIsString($stdout);
        self::assertStringContainsString("User 'admin_test' created successfully with role 'administrador'.", $stdout);
        self::assertStringNotContainsString('MiContrasenaSegura123!', $stdout);

        $user = $this->query->findByUsername('admin_test');
        self::assertNotNull($user);
        self::assertSame('admin_test', $user['username']);
        self::assertSame('administrador', $user['rol']);
        self::assertSame('activo', $user['estado']);
        self::assertSame(0, $user['failed_attempt_count']);
        self::assertStringNotContainsString('MiContrasenaSegura123!', $user['password_hash']);
    }

    public function testPersistedHashMatchesPasswordAndUsesCost10(): void
    {
        $handler = new UserCliHandler(
            $this->command,
            fn(): string => 'ValidPhraseForCost10!',
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );

        $exitCode = $handler->handleCreateUser(['cost_user', 'cajero']);
        self::assertSame(0, $exitCode);

        $user = $this->query->findByUsername('cost_user');
        self::assertNotNull($user);
        self::assertTrue(password_verify('ValidPhraseForCost10!', $user['password_hash']));
        self::assertFalse(password_verify('WrongPassword12345!', $user['password_hash']));
        // Verify bcrypt identifier and cost 10: $2y$10$
        self::assertStringStartsWith('$2y$10$', $user['password_hash']);
    }

    public function testRoleRejection(): void
    {
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);

        $handler = new UserCliHandler(
            $this->command,
            fn(): string => 'ShouldNotBeEvaluated123!',
            fopen('php://memory', 'w+'),
            $err,
        );

        $exitCode = $handler->handleCreateUser(['bad_role_user', 'gerente']);
        self::assertSame(1, $exitCode);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString("Invalid role 'gerente'", $stderr);

        self::assertNull($this->query->findByUsername('bad_role_user'));
    }

    public function testDuplicateUsernameRejection(): void
    {
        $handler1 = new UserCliHandler(
            $this->command,
            fn(): string => 'InitialPassword123!',
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );
        self::assertSame(0, $handler1->handleCreateUser(['dup_user', 'bodeguero']));

        $original = $this->query->findByUsername('dup_user');
        self::assertNotNull($original);

        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        $handler2 = new UserCliHandler(
            $this->command,
            fn(): string => 'SecondPassword12345!',
            fopen('php://memory', 'w+'),
            $err,
        );

        $exitCode = $handler2->handleCreateUser(['dup_user', 'compras']);
        self::assertSame(1, $exitCode);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString("Username 'dup_user' already exists.", $stderr);
        self::assertStringNotContainsString('SQLSTATE', $stderr);
        self::assertStringNotContainsString('PDOException', $stderr);

        // Ensure original record is unchanged
        $after = $this->query->findByUsername('dup_user');
        self::assertNotNull($after);
        self::assertSame($original['id_usuario'], $after['id_usuario']);
        self::assertSame('bodeguero', $after['rol']);
        self::assertSame($original['password_hash'], $after['password_hash']);
        self::assertSame('activo', $after['estado']);
    }

    public function testMinimumCharacterRule(): void
    {
        // 14 characters: rejected
        $err14 = fopen('php://memory', 'w+');
        self::assertIsResource($err14);
        $handler14 = new UserCliHandler(
            $this->command,
            fn(): string => '12345678901234', // 14 chars
            fopen('php://memory', 'w+'),
            $err14,
        );

        self::assertSame(1, $handler14->handleCreateUser(['short_pwd_user', 'cajero']));
        rewind($err14);
        $stderr14 = stream_get_contents($err14);
        self::assertIsString($stderr14);
        self::assertStringContainsString('Password must have at least 15 Unicode characters', $stderr14);
        self::assertNull($this->query->findByUsername('short_pwd_user'));

        // 15 characters: accepted
        $handler15 = new UserCliHandler(
            $this->command,
            fn(): string => '123456789012345', // 15 chars
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );
        self::assertSame(0, $handler15->handleCreateUser(['valid_15_user', 'cajero']));
        self::assertNotNull($this->query->findByUsername('valid_15_user'));
    }

    public function testByteLimitRule(): void
    {
        // 73 ASCII bytes (> 72 bytes): rejected
        $err73 = fopen('php://memory', 'w+');
        self::assertIsResource($err73);
        $handler73 = new UserCliHandler(
            $this->command,
            fn(): string => str_repeat('a', 73),
            fopen('php://memory', 'w+'),
            $err73,
        );

        self::assertSame(1, $handler73->handleCreateUser(['long_73_user', 'bodeguero']));
        rewind($err73);
        $stderr73 = stream_get_contents($err73);
        self::assertIsString($stderr73);
        self::assertStringContainsString('Password cannot exceed 72 UTF-8 bytes', $stderr73);
        self::assertNull($this->query->findByUsername('long_73_user'));

        // Exactly 72 ASCII bytes: accepted
        $handler72 = new UserCliHandler(
            $this->command,
            fn(): string => str_repeat('b', 72),
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );
        self::assertSame(0, $handler72->handleCreateUser(['exact_72_user', 'bodeguero']));
        self::assertNotNull($this->query->findByUsername('exact_72_user'));
    }

    public function testMultibyteDistinction(): void
    {
        // Multibyte string with 16 Unicode codepoints and 23 UTF-8 bytes: accepted
        $multibytePass = 'ñandú_áéíóú_1234';
        self::assertSame(16, mb_strlen($multibytePass, 'UTF-8'));
        self::assertSame(23, strlen($multibytePass));

        $handlerValid = new UserCliHandler(
            $this->command,
            fn(): string => $multibytePass,
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );
        self::assertSame(0, $handlerValid->handleCreateUser(['multi_valid_user', 'compras']));
        $user = $this->query->findByUsername('multi_valid_user');
        self::assertNotNull($user);
        self::assertTrue(password_verify($multibytePass, $user['password_hash']));

        // Multibyte string with 19 Unicode codepoints (>= 15) but 76 UTF-8 bytes (> 72): rejected
        $overBytePass = str_repeat('🚀', 19);
        self::assertSame(19, mb_strlen($overBytePass, 'UTF-8'));
        self::assertSame(76, strlen($overBytePass));

        $errOver = fopen('php://memory', 'w+');
        self::assertIsResource($errOver);
        $handlerOver = new UserCliHandler(
            $this->command,
            fn(): string => $overBytePass,
            fopen('php://memory', 'w+'),
            $errOver,
        );

        self::assertSame(1, $handlerOver->handleCreateUser(['multi_over_user', 'compras']));
        rewind($errOver);
        $stderrOver = stream_get_contents($errOver);
        self::assertIsString($stderrOver);
        self::assertStringContainsString('Password cannot exceed 72 UTF-8 bytes', $stderrOver);
        self::assertNull($this->query->findByUsername('multi_over_user'));
    }

    public function testSecureReaderFailureFailClosed(): void
    {
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);

        $handler = new UserCliHandler(
            $this->command,
            function (): string {
                throw new \RuntimeException('Secure terminal input failed.');
            },
            fopen('php://memory', 'w+'),
            $err,
        );

        $exitCode = $handler->handleCreateUser(['failed_reader_user', 'administrador']);
        self::assertSame(1, $exitCode);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString('Secure terminal input failed.', $stderr);
        self::assertNull($this->query->findByUsername('failed_reader_user'));
    }

    public function testProductionTerminalReaderFailsClosedInNonInteractiveEnvironment(): void
    {
        // In automated test environment, STDIN is not an interactive TTY
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);

        $handler = new UserCliHandler(
            $this->command,
            null, // Uses default production terminal reader
            fopen('php://memory', 'w+'),
            $err,
        );

        $exitCode = $handler->handleCreateUser(['tty_user', 'administrador']);
        self::assertSame(1, $exitCode);

        rewind($err);
        $stderr = stream_get_contents($err);
        self::assertIsString($stderr);
        self::assertStringContainsString('Secure interactive password input is unavailable.', $stderr);
        self::assertNull($this->query->findByUsername('tty_user'));
    }

    public function testConsoleDispatchWithInjectedHandler(): void
    {
        $handler = new UserCliHandler(
            $this->command,
            fn(): string => 'ConsoleDispatchPass123!',
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );

        $root = dirname(__DIR__, 2);
        $console = new Console($root, $handler);

        $exitCode = $console->run(['create-user', 'dispatched_user', 'cajero']);
        self::assertSame(0, $exitCode);

        $user = $this->query->findByUsername('dispatched_user');
        self::assertNotNull($user);
        self::assertSame('cajero', $user['rol']);
        self::assertSame('activo', $user['estado']);
    }

    public function testConsoleUsageValidation(): void
    {
        $handler = new UserCliHandler(
            $this->command,
            fn(): string => 'NeverCalledPass123!',
            fopen('php://memory', 'w+'),
            fopen('php://memory', 'w+'),
        );

        $root = dirname(__DIR__, 2);
        $console = new Console($root, $handler);

        // Missing arguments
        self::assertSame(64, $console->run(['create-user']));
        self::assertSame(64, $console->run(['create-user', 'only_username']));

        // Extra arguments
        self::assertSame(64, $console->run(['create-user', 'u', 'cajero', 'extra']));

        // Option arguments
        self::assertSame(64, $console->run(['create-user', '--password=123', 'cajero']));
        self::assertSame(64, $console->run(['create-user', 'my_user', '--password=123']));

        // Empty username
        self::assertSame(1, $handler->handleCreateUser(['', 'cajero']));
    }
}
