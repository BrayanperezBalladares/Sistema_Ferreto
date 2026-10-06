<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MigrationTest extends TestCase
{
    use DatabaseIsolationTrait;

    private static Config $testConfig;
    private static Database $testDb;
    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);
        self::recordInitialDevState(new Database(self::$testConfig, useTestDatabase: false));
    }

    protected function setUp(): void
    {
        $this->dropAllTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();
        foreach ($this->tempDirs as $dir) {
            $files = glob($dir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testIsolationGuardEnforced(): void
    {
        $selected = self::$testDb->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsString($selected);
        self::assertStringEndsWith('_test', $selected);
    }

    public function testRepositoryMigrationLifecycle(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $path   = dirname(__DIR__, 2) . '/database/migrations';

        $runner->run($path);

        $pdo   = self::$testDb->pdo();
        $probe = $pdo->query("SHOW TABLES LIKE 'infrastructure_probe'")->fetchColumn();
        self::assertSame('infrastructure_probe', $probe);

        $row = $pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0001_probe'")->fetch();
        self::assertIsArray($row);
        self::assertNotEmpty($row['checksum']);
        self::assertNotEmpty($row['applied_at']);

        // Idempotent re-run: does not re-apply or error
        $runner->run($path);

        // Compensating reversal removes table and history
        $runner->revert('0001_probe', $path);
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'infrastructure_probe'")->fetchColumn());
        self::assertFalse($pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0001_probe'")->fetch());
    }

    public function testDeterministicOrdering(): void
    {
        $dir = $this->createFixtureDir([
            '0002_second.up.sql' => 'CREATE TABLE _ord_b (id INT NOT NULL)',
            '0001_first.up.sql'  => 'CREATE TABLE _ord_a (id INT NOT NULL)',
        ]);

        (new MigrationRunner(self::$testDb))->run($dir);

        $history = self::$testDb->pdo()
            ->query('SELECT identifier FROM schema_migrations ORDER BY applied_at ASC, identifier ASC')
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['0001_first', '0002_second'], $history);
    }

    public function testFailStopOnInvalidSqlWithNoFalseHistory(): void
    {
        $dir = $this->createFixtureDir([
            '0001_ok.up.sql'    => 'CREATE TABLE _fs_ok (id INT NOT NULL)',
            '0002_bad.up.sql'   => 'THIS IS NOT VALID SQL',
            '0003_after.up.sql' => 'CREATE TABLE _fs_after (id INT NOT NULL)',
        ]);

        try {
            (new MigrationRunner(self::$testDb))->run($dir);
            self::fail('Expected migration execution to throw on invalid SQL.');
        } catch (Throwable) {
            // Expected
        }

        $pdo = self::$testDb->pdo();
        self::assertSame('_fs_ok', $pdo->query("SHOW TABLES LIKE '_fs_ok'")->fetchColumn());
        self::assertFalse($pdo->query("SHOW TABLES LIKE '_fs_after'")->fetchColumn());

        $history = $pdo->query('SELECT identifier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['0001_ok'], $history);

        $lockConn = new Database(self::$testConfig, useTestDatabase: true);
        $lockName = 'ferreto_migrations_lock_' . self::$testConfig->get('TEST_DB_NAME');
        $acquired = $lockConn->pdo()->query("SELECT GET_LOCK('{$lockName}', 0)")->fetchColumn();
        self::assertSame(1, (int) $acquired, 'Runner must release its advisory lock in finally on failure.');
        $lockConn->pdo()->query("SELECT RELEASE_LOCK('{$lockName}')");
    }

    public function testChecksumDriftRejectionBeforePendingWork(): void
    {
        $dir = $this->createFixtureDir([
            '0001_base.up.sql' => 'CREATE TABLE _drift_base (id INT NOT NULL)',
        ]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Alter applied migration and add pending migration
        file_put_contents($dir . '/0001_base.up.sql', 'CREATE TABLE _drift_base (id INT NOT NULL, extra INT)');
        file_put_contents($dir . '/0002_pend.up.sql', 'CREATE TABLE _drift_pend (id INT NOT NULL)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_base');

        try {
            $runner->run($dir);
        } finally {
            self::assertFalse(
                self::$testDb->pdo()->query("SHOW TABLES LIKE '_drift_pend'")->fetchColumn(),
                'Pending migration must not execute when checksum drift is detected.'
            );
        }
    }

    public function testFailedDownMigrationPreservesHistory(): void
    {
        $dir = $this->createFixtureDir([
            '0001_test.up.sql'   => 'CREATE TABLE _fdm (id INT NOT NULL)',
            '0001_test.down.sql' => 'DROP TABLE non_existent_table_that_will_fail',
        ]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        try {
            $runner->revert('0001_test', $dir);
            self::fail('Expected revert to throw on invalid down statement.');
        } catch (Throwable) {
            // Expected
        }

        $history = self::$testDb->pdo()
            ->query("SELECT identifier FROM schema_migrations WHERE identifier = '0001_test'")
            ->fetchColumn();
        self::assertSame('0001_test', $history);
    }

    public function testAdvisoryLockContentionAndRelease(): void
    {
        $lockConn = new Database(self::$testConfig, useTestDatabase: true);
        $lockName = 'ferreto_migrations_lock_' . self::$testConfig->get('TEST_DB_NAME');

        $acquired = $lockConn->pdo()->query("SELECT GET_LOCK('{$lockName}', 5)")->fetchColumn();
        self::assertSame(1, (int) $acquired);

        $dir = $this->createFixtureDir([
            '0001_locked.up.sql' => 'CREATE TABLE _lck (id INT NOT NULL)',
        ]);

        try {
            (new MigrationRunner(self::$testDb, lockTimeout: 0))->run($dir);
            self::fail('Expected runner to fail when lock is held by another connection.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Could not acquire migration lock', $e->getMessage());
        } finally {
            $lockConn->pdo()->query("SELECT RELEASE_LOCK('{$lockName}')");
        }

        // Lock released: runner succeeds
        (new MigrationRunner(self::$testDb, lockTimeout: 2))->run($dir);
        self::assertSame('_lck', self::$testDb->pdo()->query("SHOW TABLES LIKE '_lck'")->fetchColumn());
    }

    public function testMultiStatementIsRejected(): void
    {
        $dir = $this->createFixtureDir([
            '0001_multi.up.sql' => "CREATE TABLE _m1 (id INT);\nCREATE TABLE _m2 (id INT);",
        ]);

        try {
            (new MigrationRunner(self::$testDb))->run($dir);
            self::fail('Expected multi-statement execution to fail.');
        } catch (Throwable) {
            // Expected: ATTR_MULTI_STATEMENTS is false
        }

        self::assertFalse(self::$testDb->pdo()->query("SHOW TABLES LIKE '_m2'")->fetchColumn());
    }

    public function testDevelopmentDatabaseUntouched(): void
    {
        self::assertTestDatabaseIsolated(self::$testDb, self::$testConfig);

        $devDb = new Database(self::$testConfig, useTestDatabase: false);
        self::assertDevDatabaseUntouched($devDb, self::$testConfig);

        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertFalse(in_array('_m1', $tables, true));
        self::assertFalse(in_array('_m2', $tables, true));
    }

    public function testCanonicalizationAcrossLfAndCrlfSource(): void
    {
        $sqlLf   = "CREATE TABLE _canon (\n  id INT NOT NULL,\n  nombre VARCHAR(50)\n);";
        $sqlCrlf = "CREATE TABLE _canon (\r\n  id INT NOT NULL,\r\n  nombre VARCHAR(50)\r\n);";

        $expectedHash = hash('sha256', rtrim($sqlLf));

        // A. LF source produces canonical LF hash
        $dirLf = $this->createFixtureDir(['0001_canon.up.sql' => $sqlLf]);
        $runnerLf = new MigrationRunner(self::$testDb);
        $runnerLf->run($dirLf);

        $pdo = self::$testDb->pdo();
        $storedLf = $pdo->query("SELECT checksum FROM schema_migrations WHERE identifier = '0001_canon'")->fetchColumn();
        self::assertSame($expectedHash, $storedLf, 'New migration applied from LF must record canonical LF checksum.');

        // Clean up test table and history
        $this->dropAllTestTables();

        // B. CRLF source produces the exact same canonical LF hash
        $dirCrlf = $this->createFixtureDir(['0001_canon.up.sql' => $sqlCrlf]);
        $runnerCrlf = new MigrationRunner(self::$testDb);
        $runnerCrlf->run($dirCrlf);

        $storedCrlf = $pdo->query("SELECT checksum FROM schema_migrations WHERE identifier = '0001_canon'")->fetchColumn();
        self::assertSame($expectedHash, $storedCrlf, 'New migration applied from CRLF must record the identical canonical LF checksum.');

        // D. Repeat validation succeeds regardless of whether file is in LF or CRLF form
        file_put_contents($dirCrlf . '/0001_canon.up.sql', $sqlLf);
        $runnerCrlf->run($dirCrlf); // Should not throw

        file_put_contents($dirCrlf . '/0001_canon.up.sql', $sqlCrlf);
        $runnerCrlf->run($dirCrlf); // Should not throw
    }

    public function testHistoricalLfAndCrlfMatrix(): void
    {
        $sqlLf   = "CREATE TABLE _matrix (\n  id INT NOT NULL\n);";
        $sqlCrlf = "CREATE TABLE _matrix (\r\n  id INT NOT NULL\r\n);";

        $lfChecksum   = hash('sha256', rtrim($sqlLf));
        $crlfChecksum = hash('sha256', rtrim($sqlCrlf));
        self::assertNotSame($lfChecksum, $crlfChecksum, 'Precondition: LF and CRLF raw hashes must differ.');

        $matrix = [
            'legacy LF checksum + LF checkout'     => [$lfChecksum, $sqlLf],
            'legacy LF checksum + CRLF checkout'   => [$lfChecksum, $sqlCrlf],
            'legacy CRLF checksum + LF checkout'   => [$crlfChecksum, $sqlLf],
            'legacy CRLF checksum + CRLF checkout' => [$crlfChecksum, $sqlCrlf],
        ];

        foreach ($matrix as $description => [$storedChecksum, $fileContent]) {
            $this->dropAllTestTables();

            $dir = $this->createFixtureDir(['0001_matrix.up.sql' => $fileContent]);
            $runner = new MigrationRunner(self::$testDb);

            // Bootstrap schema_migrations and insert legacy record
            $emptyDir = $this->createFixtureDir([]);
            $runner->run($emptyDir);

            $stmt = self::$testDb->pdo()->prepare(
                'INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())'
            );
            $stmt->execute([':id' => '0001_matrix', ':cs' => $storedChecksum]);

            // Execute runner: must accept the legacy checksum without throwing checksum drift
            try {
                $runner->run($dir);
                self::assertTrue(true, "Validation passed for: {$description}");
            } catch (RuntimeException $e) {
                self::fail("Validation failed for {$description}: " . $e->getMessage());
            }
        }
    }

    public function testActualHistoricalBaselineCompatibility(): void
    {
        $historical = [
            '0001_probe'                    => 'abe7094f7cd344b1d6abe25e96b627b27afc35370f0f839e6e4413b100b9d7d7', // CRLF
            '0002_create_categoria'         => '49635f0f1f547cf929163947ef567984728a05d45d57177b359fe143af09f2f4', // CRLF
            '0003_create_producto'          => '8878a7521973c50ec6ae17e95a298e5d00b32830007d2f9a8144ffd8451d7934', // CRLF
            '0004_create_ubicacion'         => 'bc6ceeee82b24c391844e3f859e1832314e877bff75e86b9ccfd7fe3041152c7', // CRLF
            '0005_create_inventario_stock'  => '3886dc3194d6fe94ed7ff5d11e64b2b3a868cf6f612f6f0a306b08d760463180', // CRLF
            '0006_create_conteo_inventario' => 'ac53c4e7c2e87deed7ad17080ad0e3a89ce2d1c7f9daf76043f5e4b33b6a4931', // CRLF
            '0007_create_usuario'           => 'a77f1320fb002094bf7f66934dcf2ef5730b7217ef3ca0d33d551a5be2edb514', // LF
        ];

        $runner = new MigrationRunner(self::$testDb);
        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir); // bootstraps schema_migrations

        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        foreach ($historical as $id => $cs) {
            $stmt->execute([':id' => $id, ':cs' => $cs]);
        }

        $repoMigrations = dirname(__DIR__, 2) . '/database/migrations';

        // Validate 0001-0007 directly via reflection without needing to execute pending DDL
        $refMethod = new \ReflectionMethod(MigrationRunner::class, 'validateChecksums');
        try {
            $refMethod->invoke($runner, $repoMigrations);
            self::assertTrue(true, 'Historical baseline 0001-0007 validated successfully under current working tree files.');
        } catch (RuntimeException $e) {
            self::fail('Historical baseline validation failed: ' . $e->getMessage());
        }
    }

    public function testTamperDetectionEnforcedForSemanticModifications(): void
    {
        $baseSql = "CREATE TABLE _tamper (\n  id INT NOT NULL,\n  codigo VARCHAR(30)\n);";
        $dir = $this->createFixtureDir(['0001_tamper.up.sql' => $baseSql]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $tamperCases = [
            'changed table name'      => "CREATE TABLE _tamper_alt (\n  id INT NOT NULL,\n  codigo VARCHAR(30)\n);",
            'changed column name'     => "CREATE TABLE _tamper (\n  ident INT NOT NULL,\n  codigo VARCHAR(30)\n);",
            'changed constraint'      => "CREATE TABLE _tamper (\n  id INT NULL,\n  codigo VARCHAR(30)\n);",
            'changed keyword'         => "CREATE TABLE _tamper (\n  id BIGINT NOT NULL,\n  codigo VARCHAR(30)\n);",
            'changed internal space'  => "CREATE TABLE  _tamper (\n  id INT NOT NULL,\n  codigo VARCHAR(30)\n);",
            'changed internal tab'    => "CREATE TABLE\t_tamper (\n  id INT NOT NULL,\n  codigo VARCHAR(30)\n);",
            'added BOM'               => "\xEF\xBB\xBF" . $baseSql,
            'comment modification'    => "-- Comment added\n" . $baseSql,
        ];

        foreach ($tamperCases as $description => $tamperedSql) {
            file_put_contents($dir . '/0001_tamper.up.sql', $tamperedSql);

            try {
                $runner->run($dir);
                self::fail("Expected tamper detection for {$description}, but validation passed.");
            } catch (RuntimeException $e) {
                self::assertStringContainsString(
                    'Checksum drift detected for applied migration: 0001_tamper',
                    $e->getMessage(),
                    "Tamper detection must trigger for: {$description}"
                );
            }
        }
    }

    public function testTrailingWhitespaceContractPreserved(): void
    {
        $coreSql = "CREATE TABLE _ws (\n  id INT NOT NULL\n);";

        $variants = [
            'single LF'                => $coreSql . "\n",
            'single CRLF'              => $coreSql . "\r\n",
            'multiple trailing LF'     => $coreSql . "\n\n\n",
            'multiple trailing CRLF'   => $coreSql . "\r\n\r\n",
            'trailing spaces'          => $coreSql . "   ",
            'trailing tabs'            => $coreSql . "\t\t",
            'trailing mixed space EOL' => $coreSql . "  \r\n\n\t",
        ];

        $refMethod = new \ReflectionMethod(MigrationRunner::class, 'canonicalChecksum');
        $runner = new MigrationRunner(self::$testDb);

        $expectedHash = $refMethod->invoke($runner, $coreSql);

        foreach ($variants as $description => $variantSql) {
            $computed = $refMethod->invoke($runner, $variantSql);
            self::assertSame(
                $expectedHash,
                $computed,
                "Trailing whitespace variant '{$description}' must produce the identical canonical hash via rtrim."
            );
        }
    }

    public function testMixedEolAndLoneCrLimitationsDocumented(): void
    {
        $sql = "CREATE TABLE _limits (id INT NOT NULL);";

        $runner = new MigrationRunner(self::$testDb);
        $canonicalMethod = new \ReflectionMethod(MigrationRunner::class, 'canonicalChecksum');
        $candidatesMethod = new \ReflectionMethod(MigrationRunner::class, 'acceptedChecksumCandidates');

        $canonicalHash = $canonicalMethod->invoke($runner, $sql);

        // 1. Lone CR (\r without \n) is NOT normalized to \n
        $loneCrSql = "CREATE TABLE _limits\r(id INT NOT NULL);";
        $loneCrCandidates = $candidatesMethod->invoke($runner, $loneCrSql);
        self::assertNotContains(
            $canonicalHash,
            $loneCrCandidates,
            'Lone CR must not be normalized to LF and must not match canonical hash.'
        );

        // 2. UTF-8 BOM is NOT stripped
        $bomSql = "\xEF\xBB\xBF" . $sql;
        $bomCandidates = $candidatesMethod->invoke($runner, $bomSql);
        self::assertNotContains(
            $canonicalHash,
            $bomCandidates,
            'UTF-8 BOM must not be stripped and must not match non-BOM canonical hash.'
        );
    }

    /** @param array<string, string> $files */
    private function createFixtureDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/ferreto_mig_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
        return $dir;
    }

    private function dropAllTestTables(): void
    {
        $pdo    = self::$testDb->pdo();
        $stmt   = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($tables !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $t) {
                if (is_string($t)) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
