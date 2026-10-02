<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Csrf;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\NativeSession;
use App\Foundation\Request;
use App\Foundation\Transaction;
use App\Modules\Access\Authenticator;
use App\Modules\Access\AuthSession;
use App\Modules\Access\UserCliHandler;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use PHPUnit\Framework\TestCase;

final class SensitiveLoggingTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static Database $devDb;
    private static MigrationRunner $runner;
    private static string $migrationsPath;
    private UserQuery $query;
    private UserCommand $command;
    private Authenticator $authenticator;
    private string $logFile;
    private string|false $previousErrorLog;

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

        $tx                  = new Transaction(self::$testDb);
        $this->query         = new UserQuery(self::$testDb);
        $this->command       = new UserCommand($tx);
        $this->authenticator = new Authenticator($this->query, $this->command);

        // Configure test-scoped error_log capture
        $tmp = tempnam(sys_get_temp_dir(), 'ferreto_sensitive_log_');
        self::assertIsString($tmp);
        $this->logFile          = $tmp;
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if (is_string($this->previousErrorLog)) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testPlaintextPasswordIsNotLoggedDuringAuthentication(): void
    {
        $validPass   = 'VALID_SECRET_PASSPHRASE_15';
        $invalidPass = 'WRONG_SECRET_PASSPHRASE_15';
        $over72Pass  = 'OVER72_SECRET_' . str_repeat('X', 65);

        $hash = password_hash($validPass, PASSWORD_BCRYPT, ['cost' => 10]);
        $this->command->create('secret_user', $hash, 'administrador', 'activo');

        // Execute successful, wrong-password, and >72-byte authentication paths
        $this->authenticator->authenticate('secret_user', $validPass);
        $this->authenticator->authenticate('secret_user', $invalidPass);
        $this->authenticator->authenticate('secret_user', $over72Pass);
        $this->authenticator->authenticate('nonexistent_user', $invalidPass);

        $logContents = (string) file_get_contents($this->logFile);

        self::assertStringNotContainsString($validPass, $logContents, 'Valid plaintext password must never appear in logs.');
        self::assertStringNotContainsString($invalidPass, $logContents, 'Invalid plaintext password must never appear in logs.');
        self::assertStringNotContainsString($over72Pass, $logContents, 'Over-72-byte password must never appear in logs.');
    }

    public function testPlaintextPasswordIsNotLoggedDuringCliUserCreation(): void
    {
        $cliSecret = 'SECRET_CLI_PASSPHRASE_15';
        $outStream = fopen('php://memory', 'w+');
        $errStream = fopen('php://memory', 'w+');
        self::assertIsResource($outStream);
        self::assertIsResource($errStream);

        $cli = new UserCliHandler(
            $this->command,
            static fn (): string => $cliSecret,
            $outStream,
            $errStream
        );

        $exitCode = $cli->handleCreateUser(['cli_logged_user', 'cajero']);
        self::assertSame(0, $exitCode);

        $logContents = (string) file_get_contents($this->logFile);
        rewind($outStream);
        rewind($errStream);
        $stdout = (string) stream_get_contents($outStream);
        $stderr = (string) stream_get_contents($errStream);
        fclose($outStream);
        fclose($errStream);

        self::assertStringNotContainsString($cliSecret, $logContents, 'CLI plaintext password must never appear in error_log.');
        self::assertStringNotContainsString($cliSecret, $stdout, 'CLI plaintext password must never appear in stdout.');
        self::assertStringNotContainsString($cliSecret, $stderr, 'CLI plaintext password must never appear in stderr.');
    }

    public function testPasswordHashIsNotLogged(): void
    {
        $rawHash = '$2y$10$e9f16z8F1EJWUuYNJozkxuGq4SBl1Q3p.FljewY.3DWzf6IZrUC9a';
        $userId  = $this->command->create('hash_user', $rawHash, 'bodeguero', 'activo');

        // Query user and authenticate
        $this->query->findById($userId);
        $this->query->findByUsername('hash_user');
        $this->authenticator->authenticate('hash_user', 'SomePassword123');

        $logContents = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString($rawHash, $logContents, 'Password hash must never appear in application logs.');
    }

    public function testSessionIdentifierIsNotLogged(): void
    {
        $hash   = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $userId = $this->command->create('sess_user', $hash, 'compras', 'activo');

        $session     = new NativeSession(false);
        $authSession = new AuthSession($session, $this->query);

        $authSession->establish($userId);
        $sessionId = session_id();
        self::assertIsString($sessionId);
        self::assertNotSame('', $sessionId);

        $authSession->user();
        $authSession->logout();

        $logContents = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString($sessionId, $logContents, 'Session ID must never appear in application logs.');
    }

    public function testCsrfTokenIsNotLogged(): void
    {
        $session = new NativeSession(false);
        $csrf    = new Csrf($session);

        $token = $csrf->token();
        self::assertNotSame('', $token);

        // Validate both matching and non-matching requests
        $validReq   = new Request('POST', '/login', [], ['_csrf' => $token]);
        $invalidReq = new Request('POST', '/login', [], ['_csrf' => 'invalid_csrf_sentinel']);

        $csrf->valid($validReq);
        $csrf->valid($invalidReq);

        $logContents = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString($token, $logContents, 'CSRF token must never appear in application logs.');
        self::assertStringNotContainsString('invalid_csrf_sentinel', $logContents, 'Sent CSRF candidate must never appear in application logs.');
    }

    public function testErrorPathsDoNotLogCredentialsOrExceptionsWithSecrets(): void
    {
        $secretCandidate = 'WRONG_ATTEMPT_SECRET_SENTINEL';
        $hash            = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->command->create('error_probe_user', $hash, 'cajero', 'bloqueado');

        // Blocked user login attempt with secret candidate
        $this->authenticator->authenticate('error_probe_user', $secretCandidate);

        // Missing user login attempt with secret candidate
        $this->authenticator->authenticate('nonexistent_probe', $secretCandidate);

        $logContents = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString($secretCandidate, $logContents, 'Failed attempt candidate must not leak in error paths.');
    }

    public function testStaticSourceAuditProhibitsSecretLogging(): void
    {
        $accessFiles = glob(dirname(__DIR__, 2) . '/src/Modules/Access/*.php');
        self::assertIsArray($accessFiles);
        self::assertNotEmpty($accessFiles);

        $filesToAudit = array_merge(
            $accessFiles,
            [dirname(__DIR__, 2) . '/src/Foundation/NativeSession.php']
        );

        foreach ($filesToAudit as $filePath) {
            $content = (string) file_get_contents($filePath);
            self::assertStringNotContainsString(
                'error_log',
                $content,
                sprintf('Direct error_log call found in %s.', basename($filePath))
            );
            self::assertStringNotContainsString(
                'var_dump',
                $content,
                sprintf('Debug var_dump found in %s.', basename($filePath))
            );
            self::assertStringNotContainsString(
                'print_r',
                $content,
                sprintf('Debug print_r found in %s.', basename($filePath))
            );
        }
    }
}
