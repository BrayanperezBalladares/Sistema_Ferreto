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
use PHPUnit\Framework\TestCase;

final class LockoutConcurrencyTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    private static Database $devDb;
    private static MigrationRunner $runner;
    private static string $migrationsPath;
    private UserQuery $query;
    private UserCommand $command;

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

        $tx            = new Transaction(self::$testDb);
        $this->query   = new UserQuery(self::$testDb);
        $this->command = new UserCommand($tx);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testSimultaneousFailedAuthenticationsReachSixthFailureAndLockAccount(): void
    {
        $hash = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->command->create('conc_user_lock', $hash, 'cajero', 'activo');

        // Pre-seed with 4 failures in active window
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "UPDATE usuario SET failed_attempt_count = 4, failure_window_started_at = UTC_TIMESTAMP() "
            . "WHERE username = 'conc_user_lock'"
        );

        $results = $this->runConcurrentAuthentications('conc_user_lock', 'WrongPassword1', 'WrongPassword2');

        self::assertCount(2, $results);
        self::assertSame('FAILED', $results[0]);
        self::assertSame('FAILED', $results[1]);

        $user = $this->query->findByUsername('conc_user_lock');
        self::assertNotNull($user);
        self::assertSame(6, $user['failed_attempt_count'], 'Counter must reach exactly 6 without lost increments.');
        self::assertSame('bloqueado', $user['estado'], '6th failure must lock the account.');
        self::assertNotNull($user['locked_at']);
    }

    public function testSimultaneousFailedAuthenticationsAccumulateWithoutLostIncrementsAtLowerCounts(): void
    {
        $hash = password_hash('ValidPassphrase15', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->command->create('conc_user_accum', $hash, 'bodeguero', 'activo');

        // Pre-seed with 1 failure in active window
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "UPDATE usuario SET failed_attempt_count = 1, failure_window_started_at = UTC_TIMESTAMP() "
            . "WHERE username = 'conc_user_accum'"
        );

        $results = $this->runConcurrentAuthentications('conc_user_accum', 'WrongPasswordA', 'WrongPasswordB');

        self::assertCount(2, $results);
        self::assertSame('FAILED', $results[0]);
        self::assertSame('FAILED', $results[1]);

        $user = $this->query->findByUsername('conc_user_accum');
        self::assertNotNull($user);
        self::assertSame(3, $user['failed_attempt_count'], 'Counter must reach exactly 3 (1 initial + 2 concurrent).');
        self::assertSame('activo', $user['estado'], 'Account must remain activo below threshold.');
        self::assertNull($user['locked_at']);
    }

    /**
     * Executes two parallel authentication attempts through independent PHP child processes
     * synchronized via stdin barrier to guarantee concurrency overlap against MariaDB.
     *
     * @return list<string> Process outputs (FAILED or SUCCESS)
     */
    private function runConcurrentAuthentications(string $username, string $passwordA, string $passwordB): array
    {
        $workerTemplate = '<?php
            require %s;
            $config = App\Foundation\Config::fromEnvironment(require %s);
            $db = new App\Foundation\Database($config, useTestDatabase: true);
            $tx = new App\Foundation\Transaction($db);
            $query = new App\Modules\Access\UserQuery($db);
            $cmd = new App\Modules\Access\UserCommand($tx);
            $auth = new App\Modules\Access\Authenticator($query, $cmd);

            // Barrier: wait for parent signal to ensure concurrent execution
            fgets(STDIN);
            $result = $auth->authenticate($argv[1], $argv[2]);
            echo ($result === null ? "FAILED" : "SUCCESS") . "\n";
        ';

        $workerCode = sprintf(
            $workerTemplate,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export(dirname(__DIR__, 2) . '/config/defaults.php', true)
        );

        $scriptPath = sys_get_temp_dir() . '/conc_worker_' . uniqid('', true) . '.php';
        file_put_contents($scriptPath, $workerCode);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $p1 = proc_open([PHP_BINARY, $scriptPath, $username, $passwordA], $desc, $pipes1);
            $p2 = proc_open([PHP_BINARY, $scriptPath, $username, $passwordB], $desc, $pipes2);

            self::assertIsResource($p1);
            self::assertIsResource($p2);

            // Small pause to allow both child processes to bootstrap and wait on fgets(STDIN)
            usleep(50000);

            // Release both child processes simultaneously
            fwrite($pipes1[0], "GO\n");
            fflush($pipes1[0]);
            fwrite($pipes2[0], "GO\n");
            fflush($pipes2[0]);

            $out1 = stream_get_contents($pipes1[1]);
            $out2 = stream_get_contents($pipes2[1]);

            fclose($pipes1[0]);
            fclose($pipes1[1]);
            fclose($pipes1[2]);
            proc_close($p1);

            fclose($pipes2[0]);
            fclose($pipes2[1]);
            fclose($pipes2[2]);
            proc_close($p2);

            return [
                trim((string) $out1),
                trim((string) $out2),
            ];
        } finally {
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }
        }
    }
}
