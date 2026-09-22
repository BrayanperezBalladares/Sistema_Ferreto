<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Access\Authenticator;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
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
        $this->query         = new UserQuery(self::$testDb);
        $this->command       = new UserCommand($tx);
        $this->authenticator = new Authenticator($this->query, $this->command);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testSuccessfulAuthenticationReturnsIdentityAndPreservesActivo(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('valid_user', $hash, 'administrador', 'activo');

        $result = $this->authenticator->authenticate('valid_user', $password);

        self::assertIsArray($result);
        self::assertSame($id, $result['id_usuario']);
        self::assertSame('valid_user', $result['username']);
        self::assertSame('administrador', $result['rol']);
        self::assertArrayNotHasKey('password_hash', $result);
        self::assertArrayNotHasKey('failed_attempt_count', $result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('activo', $row['estado']);
        self::assertSame(0, $row['failed_attempt_count']);
        self::assertNull($row['failure_window_started_at']);
        self::assertNull($row['locked_at']);
    }

    public function testWrongPasswordOnActiveAccountRecordsFailureAndStartsWindow(): void
    {
        $password = 'ValidPassword15Chars';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('failed_user', $hash, 'cajero', 'activo');

        $result = $this->authenticator->authenticate('failed_user', 'IncorrectPassword');

        self::assertNull($result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('activo', $row['estado']);
        self::assertSame(1, $row['failed_attempt_count']);
        self::assertNotNull($row['failure_window_started_at']);
        self::assertNull($row['locked_at']);
    }

    public function testFiveFailedAttemptsWithinWindowKeepAccountActivo(): void
    {
        $password = 'ValidPassword15Chars';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('five_fail_user', $hash, 'bodeguero', 'activo');

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->authenticator->authenticate('five_fail_user', 'WrongPass' . $i);
            self::assertNull($result);
        }

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('activo', $row['estado']);
        self::assertSame(5, $row['failed_attempt_count']);
        self::assertNotNull($row['failure_window_started_at']);
        self::assertNull($row['locked_at']);
    }

    public function testSixthFailedAttemptWithinWindowTransitionsAccountToBloqueado(): void
    {
        $password = 'ValidPassword15Chars';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('six_fail_user', $hash, 'compras', 'activo');

        for ($i = 1; $i <= 6; $i++) {
            $result = $this->authenticator->authenticate('six_fail_user', 'WrongPass' . $i);
            self::assertNull($result);
        }

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('bloqueado', $row['estado']);
        self::assertSame(6, $row['failed_attempt_count']);
        self::assertNotNull($row['failure_window_started_at']);
        self::assertNotNull($row['locked_at']);

        // Subsequent attempt even with correct password must fail and remain bloqueado
        $subsequent = $this->authenticator->authenticate('six_fail_user', $password);
        self::assertNull($subsequent);

        $rowAfter = $this->query->findById($id);
        self::assertNotNull($rowAfter);
        self::assertSame('bloqueado', $rowAfter['estado']);
        self::assertSame(6, $rowAfter['failed_attempt_count']);
    }

    public function testExpiredWindowResetsCounterToOneOnSubsequentFailure(): void
    {
        $password = 'ValidPassword15Chars';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('expired_window_user', $hash, 'cajero', 'activo');

        // Simulate 4 failures 15 minutes ago (expired window)
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "UPDATE usuario SET failed_attempt_count = 4, "
            . "failure_window_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) "
            . "WHERE id_usuario = " . $id
        );

        $result = $this->authenticator->authenticate('expired_window_user', 'WrongPassword');
        self::assertNull($result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('activo', $row['estado']);
        self::assertSame(1, $row['failed_attempt_count']);
        self::assertNotNull($row['failure_window_started_at']);
        self::assertNull($row['locked_at']);
    }

    public function testSuccessfulAuthenticationResetsExistingFailuresAndClearsWindow(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('reset_fail_user', $hash, 'cajero', 'activo');

        // Execute 3 failures
        for ($i = 1; $i <= 3; $i++) {
            $this->authenticator->authenticate('reset_fail_user', 'WrongPass' . $i);
        }

        $rowBefore = $this->query->findById($id);
        self::assertNotNull($rowBefore);
        self::assertSame(3, $rowBefore['failed_attempt_count']);
        self::assertNotNull($rowBefore['failure_window_started_at']);

        // Now authenticate with correct credentials
        $result = $this->authenticator->authenticate('reset_fail_user', $password);
        self::assertIsArray($result);
        self::assertSame($id, $result['id_usuario']);

        $rowAfter = $this->query->findById($id);
        self::assertNotNull($rowAfter);
        self::assertSame('activo', $rowAfter['estado']);
        self::assertSame(0, $rowAfter['failed_attempt_count']);
        self::assertNull($rowAfter['failure_window_started_at']);
        self::assertNull($rowAfter['locked_at']);
    }

    public function testBloqueadoAccountWithCorrectPasswordFailsAndDoesNotUnlock(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('locked_user', $hash, 'bodeguero', 'bloqueado');

        $result = $this->authenticator->authenticate('locked_user', $password);
        self::assertNull($result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('bloqueado', $row['estado']);
        self::assertSame(0, $row['failed_attempt_count']);
        self::assertNull($row['locked_at']);
    }

    public function testCreadoAccountWithCorrectPasswordFailsAndRemainsCreado(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('created_user', $hash, 'cajero', 'creado');

        $result = $this->authenticator->authenticate('created_user', $password);
        self::assertNull($result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('creado', $row['estado']);
        self::assertSame(0, $row['failed_attempt_count']);
    }

    public function testInactivoAccountWithCorrectPasswordFailsAndRemainsInactivo(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('inactive_user', $hash, 'compras', 'inactivo');

        $result = $this->authenticator->authenticate('inactive_user', $password);
        self::assertNull($result);

        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('inactivo', $row['estado']);
        self::assertSame(0, $row['failed_attempt_count']);
    }

    public function testPasswordExceeding72BytesFailsWithoutBcryptTruncationOrMutation(): void
    {
        // 73 ASCII bytes
        $over72Ascii = str_repeat('a', 73);
        $password    = 'ValidPassword15Chars';
        $hash        = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id          = $this->command->create('limit_user', $hash, 'administrador', 'activo');

        $result = $this->authenticator->authenticate('limit_user', $over72Ascii);
        self::assertNull($result);

        // Account must remain completely untouched (no failed attempt recorded for >72 bytes pre-validation rejection)
        $row = $this->query->findById($id);
        self::assertNotNull($row);
        self::assertSame('activo', $row['estado']);
        self::assertSame(0, $row['failed_attempt_count']);

        // Multibyte case: 25 characters of '€' (3 bytes each = 75 UTF-8 bytes)
        $multibyteOver72 = str_repeat('€', 25);
        self::assertSame(25, mb_strlen($multibyteOver72, 'UTF-8'));
        self::assertSame(75, strlen($multibyteOver72));

        $resultMb = $this->authenticator->authenticate('limit_user', $multibyteOver72);
        self::assertNull($resultMb);

        $rowMb = $this->query->findById($id);
        self::assertNotNull($rowMb);
        self::assertSame(0, $rowMb['failed_attempt_count']);

        // Exactly 72 bytes should be accepted if matching
        $exact72 = str_repeat('k', 72);
        self::assertSame(72, strlen($exact72));
        $exact72Hash = password_hash($exact72, PASSWORD_BCRYPT, ['cost' => 10]);
        $exactId     = $this->command->create('exact72_user', $exact72Hash, 'administrador', 'activo');

        $exactResult = $this->authenticator->authenticate('exact72_user', $exact72);
        self::assertIsArray($exactResult);
        self::assertSame($exactId, $exactResult['id_usuario']);
    }

    public function testNonexistentUsernameReturnsNullAndExecutesTimingPathWithoutSideEffects(): void
    {
        $countBefore = $this->countUsers();

        $result = $this->authenticator->authenticate('nonexistent_user', 'AnyPassword123');
        self::assertNull($result);

        $countAfter = $this->countUsers();
        self::assertSame($countBefore, $countAfter);
    }

    public function testEmptyOrWhitespaceUsernameReturnsNullWithoutSideEffects(): void
    {
        $countBefore = $this->countUsers();

        self::assertNull($this->authenticator->authenticate('', 'SomePassword123'));
        self::assertNull($this->authenticator->authenticate('   ', 'SomePassword123'));

        $countAfter = $this->countUsers();
        self::assertSame($countBefore, $countAfter);
    }

    public function testUsernameNormalizationTrimsLeadingAndTrailingWhitespace(): void
    {
        $password = 'CorrectHorseBatteryStaple15';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id       = $this->command->create('whitespace_user', $hash, 'cajero', 'activo');

        $result = $this->authenticator->authenticate('   whitespace_user   ', $password);

        self::assertIsArray($result);
        self::assertSame($id, $result['id_usuario']);
        self::assertSame('whitespace_user', $result['username']);
    }

    private function countUsers(): int
    {
        $stmt = self::$testDb->pdo()->query('SELECT COUNT(*) FROM usuario');
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return (int) $stmt->fetchColumn();
    }
}
