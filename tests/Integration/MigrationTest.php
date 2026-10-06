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

    public function testNewMigrationRecordsCanonicalLfHashFromLfSource(): void
    {
        $sqlLf = "CREATE TABLE _canon_lf (\n  id INT NOT NULL,\n  nombre VARCHAR(50)\n);";
        $expectedHash = hash('sha256', rtrim($sqlLf));

        $dir = $this->createFixtureDir(['0001_canon_lf.up.sql' => $sqlLf]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $pdo = self::$testDb->pdo();
        $stored = $pdo->query("SELECT checksum FROM schema_migrations WHERE identifier = '0001_canon_lf'")->fetchColumn();
        self::assertSame($expectedHash, $stored, 'New migration applied from LF must record canonical LF checksum.');
        self::assertSame('_canon_lf', $pdo->query("SHOW TABLES LIKE '_canon_lf'")->fetchColumn());
    }

    public function testNewMigrationRecordsCanonicalLfHashFromCrlfSource(): void
    {
        $sqlLf   = "CREATE TABLE _canon_crlf (\n  id INT NOT NULL,\n  nombre VARCHAR(50)\n);";
        $sqlCrlf = "CREATE TABLE _canon_crlf (\r\n  id INT NOT NULL,\r\n  nombre VARCHAR(50)\r\n);";
        $expectedHash = hash('sha256', rtrim($sqlLf));

        $dir = $this->createFixtureDir(['0001_canon_crlf.up.sql' => $sqlCrlf]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $pdo = self::$testDb->pdo();
        $stored = $pdo->query("SELECT checksum FROM schema_migrations WHERE identifier = '0001_canon_crlf'")->fetchColumn();
        self::assertSame($expectedHash, $stored, 'New migration applied from CRLF must record the identical canonical LF checksum.');
        self::assertSame('_canon_crlf', $pdo->query("SHOW TABLES LIKE '_canon_crlf'")->fetchColumn());
    }

    public function testRepeatValidationSucceedsWhenFileTogglesBetweenLfAndCrlf(): void
    {
        $sqlLf   = "CREATE TABLE _repeat (\n  id INT NOT NULL\n);";
        $sqlCrlf = "CREATE TABLE _repeat (\r\n  id INT NOT NULL\r\n);";

        $dir = $this->createFixtureDir(['0001_repeat.up.sql' => $sqlLf]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $historyInitial = $this->snapshotHistory();

        // Switch to CRLF on disk: repeated validation must pass
        file_put_contents($dir . '/0001_repeat.up.sql', $sqlCrlf);
        $runner->run($dir);
        self::assertSame($historyInitial, $this->snapshotHistory(), 'CRLF switch must not mutate schema_migrations.');

        // Switch back to LF on disk: repeated validation must pass
        file_put_contents($dir . '/0001_repeat.up.sql', $sqlLf);
        $runner->run($dir);
        self::assertSame($historyInitial, $this->snapshotHistory(), 'LF switch back must not mutate schema_migrations.');

        self::assertSame('_repeat', self::$testDb->pdo()->query("SHOW TABLES LIKE '_repeat'")->fetchColumn());
    }

    public function testLegacyMatrixStoredLfWithLfCheckout(): void
    {
        $sqlLf = "CREATE TABLE _mat_1 (\n  id INT NOT NULL\n);";
        $lfChecksum = hash('sha256', rtrim($sqlLf));

        $dir = $this->createFixtureDir(['0001_mat_1.up.sql' => $sqlLf]);
        $runner = new MigrationRunner(self::$testDb);

        // Pre-populate schema_migrations with legacy LF checksum
        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);
        $stmt = self::$testDb->pdo()->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_mat_1', ':cs' => $lfChecksum]);

        $before = $this->snapshotHistory();
        $runner->run($dir);
        $after = $this->snapshotHistory();

        self::assertSame($before, $after, 'Legacy LF validation with LF checkout must not modify schema_migrations history.');
    }

    public function testLegacyMatrixStoredLfWithCrlfCheckout(): void
    {
        $sqlLf   = "CREATE TABLE _mat_2 (\n  id INT NOT NULL\n);";
        $sqlCrlf = "CREATE TABLE _mat_2 (\r\n  id INT NOT NULL\r\n);";
        $lfChecksum = hash('sha256', rtrim($sqlLf));

        $dir = $this->createFixtureDir(['0001_mat_2.up.sql' => $sqlCrlf]);
        $runner = new MigrationRunner(self::$testDb);

        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);
        $stmt = self::$testDb->pdo()->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_mat_2', ':cs' => $lfChecksum]);

        $before = $this->snapshotHistory();
        $runner->run($dir);
        $after = $this->snapshotHistory();

        self::assertSame($before, $after, 'Legacy LF validation with CRLF checkout must not modify schema_migrations history.');
    }

    public function testLegacyMatrixStoredCrlfWithLfCheckout(): void
    {
        $sqlLf   = "CREATE TABLE _mat_3 (\n  id INT NOT NULL\n);";
        $sqlCrlf = "CREATE TABLE _mat_3 (\r\n  id INT NOT NULL\r\n);";
        $crlfChecksum = hash('sha256', rtrim($sqlCrlf));

        $dir = $this->createFixtureDir(['0001_mat_3.up.sql' => $sqlLf]);
        $runner = new MigrationRunner(self::$testDb);

        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);
        $stmt = self::$testDb->pdo()->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_mat_3', ':cs' => $crlfChecksum]);

        $before = $this->snapshotHistory();
        $runner->run($dir);
        $after = $this->snapshotHistory();

        self::assertSame($before, $after, 'Legacy CRLF validation with LF checkout must not modify schema_migrations history.');
    }

    public function testLegacyMatrixStoredCrlfWithCrlfCheckout(): void
    {
        $sqlCrlf = "CREATE TABLE _mat_4 (\r\n  id INT NOT NULL\r\n);";
        $crlfChecksum = hash('sha256', rtrim($sqlCrlf));

        $dir = $this->createFixtureDir(['0001_mat_4.up.sql' => $sqlCrlf]);
        $runner = new MigrationRunner(self::$testDb);

        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);
        $stmt = self::$testDb->pdo()->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_mat_4', ':cs' => $crlfChecksum]);

        $before = $this->snapshotHistory();
        $runner->run($dir);
        $after = $this->snapshotHistory();

        self::assertSame($before, $after, 'Legacy CRLF validation with CRLF checkout must not modify schema_migrations history.');
    }

    public function testActualHistoricalBaseline0001Through0007Compatibility(): void
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

        // Validate 0001-0007 directly via reflection against repository migration files
        $before = $this->snapshotHistory();
        $refMethod = new \ReflectionMethod(MigrationRunner::class, 'validateChecksums');
        try {
            $refMethod->invoke($runner, $repoMigrations);
            self::assertTrue(true, 'Historical baseline 0001-0007 validated successfully under current working tree files.');
        } catch (RuntimeException $e) {
            self::fail('Historical baseline validation failed: ' . $e->getMessage());
        }
        $after = $this->snapshotHistory();
        self::assertSame($before, $after, 'Baseline 0001-0007 validation must not modify schema_migrations history.');
    }

    public function testActualHistoricalBaselineRejectsAlteredMigrationFile(): void
    {
        $historical = [
            '0001_probe' => 'abe7094f7cd344b1d6abe25e96b627b27afc35370f0f839e6e4413b100b9d7d7',
        ];

        $runner = new MigrationRunner(self::$testDb);
        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);

        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_probe', ':cs' => $historical['0001_probe']]);

        // Create fixture with tampered content for 0001_probe
        $fixtureDir = $this->createFixtureDir([
            '0001_probe.up.sql' => 'CREATE TABLE infrastructure_probe_tampered (id INT);',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_probe');

        $runner->run($fixtureDir);
    }

    public function testSemanticTamperDetectionRejectsChangedTableName(): void
    {
        $baseSql = "CREATE TABLE _t_name (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_t.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        file_put_contents($dir . '/0001_t.up.sql', "CREATE TABLE _t_name_altered (\n  id INT NOT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_t');
        $runner->run($dir);
    }

    public function testSemanticTamperDetectionRejectsChangedColumnName(): void
    {
        $baseSql = "CREATE TABLE _t_col (\n  id_original INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_col.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        file_put_contents($dir . '/0001_col.up.sql', "CREATE TABLE _t_col (\n  id_mutated INT NOT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_col');
        $runner->run($dir);
    }

    public function testSemanticTamperDetectionRejectsChangedConstraint(): void
    {
        $baseSql = "CREATE TABLE _t_cons (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_cons.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        file_put_contents($dir . '/0001_cons.up.sql', "CREATE TABLE _t_cons (\n  id INT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_cons');
        $runner->run($dir);
    }

    public function testSemanticTamperDetectionRejectsChangedSqlKeyword(): void
    {
        $baseSql = "CREATE TABLE _t_kw (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_kw.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        file_put_contents($dir . '/0001_kw.up.sql', "CREATE TABLE _t_kw (\n  id BIGINT NOT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_kw');
        $runner->run($dir);
    }

    public function testWhitespaceTamperDetectionRejectsInternalSpaceChange(): void
    {
        $baseSql = "CREATE TABLE _t_space (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_space.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Double space between TABLE and table name
        file_put_contents($dir . '/0001_space.up.sql', "CREATE TABLE  _t_space (\n  id INT NOT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_space');
        $runner->run($dir);
    }

    public function testWhitespaceTamperDetectionRejectsInternalTabChange(): void
    {
        $baseSql = "CREATE TABLE _t_tab (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_tab.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Tab character instead of space
        file_put_contents($dir . '/0001_tab.up.sql', "CREATE TABLE\t_t_tab (\n  id INT NOT NULL\n);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_tab');
        $runner->run($dir);
    }

    public function testCommentMutationIsChecksumSignificant(): void
    {
        $baseSql = "CREATE TABLE _t_comm (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_comm.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Prepend comment
        file_put_contents($dir . '/0001_comm.up.sql', "-- Meaningful migration comment\n" . $baseSql);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_comm');
        $runner->run($dir);
    }

    public function testBomSignificanceDetectedWhenAdded(): void
    {
        $baseSql = "CREATE TABLE _t_bom (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_bom.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Add UTF-8 BOM
        file_put_contents($dir . '/0001_bom.up.sql', "\xEF\xBB\xBF" . $baseSql);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_bom');
        $runner->run($dir);
    }

    public function testBomSignificanceDetectedWhenRemoved(): void
    {
        $baseSql = "CREATE TABLE _t_nobom (\n  id INT NOT NULL\n);";
        $bomSql  = "\xEF\xBB\xBF" . $baseSql;
        $dir = $this->createFixtureDir(['0001_nobom.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Simulate historical stored checksum that was recorded with UTF-8 BOM
        $bomChecksum = hash('sha256', rtrim($bomSql));
        self::$testDb->pdo()->exec(
            "UPDATE schema_migrations SET checksum = '{$bomChecksum}' WHERE identifier = '0001_nobom'"
        );

        // When file no longer has BOM, checksum drift must be detected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_nobom');
        $runner->run($dir);
    }

    public function testTrailingRtrimBehaviorPreservesEquivalentTrailingWhitespace(): void
    {
        $coreSql = "CREATE TABLE _ws_equiv (\n  id INT NOT NULL\n);";

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
                "Trailing whitespace variant '{$description}' must produce identical canonical hash via rtrim."
            );
        }
    }

    public function testMixedEolLimitationDocumented(): void
    {
        $sql = "CREATE TABLE _lim_mixed (\n  id INT NOT NULL,\n  col2 INT\n);";

        $runner = new MigrationRunner(self::$testDb);
        $canonicalMethod = new \ReflectionMethod(MigrationRunner::class, 'canonicalChecksum');
        $candidatesMethod = new \ReflectionMethod(MigrationRunner::class, 'acceptedChecksumCandidates');

        $canonicalHash = $canonicalMethod->invoke($runner, $sql);

        // Irregular line-by-line mixed EOL (line 1 CRLF, line 2 LF)
        $mixedEolSql = "CREATE TABLE _lim_mixed (\r\n  id INT NOT NULL,\n  col2 INT\n);";
        $mixedCandidates = $candidatesMethod->invoke($runner, $mixedEolSql);

        // The canonical hash of uniform LF matches, but uniform CRLF does not match arbitrary mixed EOL
        self::assertContains($canonicalHash, $mixedCandidates, 'Canonical LF candidate handles mixed CRLF/LF.');
    }

    public function testLoneCrLimitationDocumented(): void
    {
        $sql = "CREATE TABLE _lim_cr (id INT NOT NULL);";

        $runner = new MigrationRunner(self::$testDb);
        $canonicalMethod = new \ReflectionMethod(MigrationRunner::class, 'canonicalChecksum');
        $candidatesMethod = new \ReflectionMethod(MigrationRunner::class, 'acceptedChecksumCandidates');

        $canonicalHash = $canonicalMethod->invoke($runner, $sql);

        // Lone CR (\r without \n) is NOT normalized to \n
        $loneCrSql = "CREATE TABLE _lim_cr\r(id INT NOT NULL);";
        $loneCrCandidates = $candidatesMethod->invoke($runner, $loneCrSql);
        self::assertNotContains(
            $canonicalHash,
            $loneCrCandidates,
            'Lone CR must not be normalized to LF and must not match canonical hash.'
        );
    }

    public function testDriftBeforePendingWorkBehaviorFailStop(): void
    {
        $dir = $this->createFixtureDir([
            '0001_applied.up.sql' => "CREATE TABLE _drift_stop_1 (id INT NOT NULL);",
        ]);

        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Tamper applied migration and add pending migration
        file_put_contents($dir . '/0001_applied.up.sql', "CREATE TABLE _drift_stop_1 (id INT NOT NULL, extra INT);");
        file_put_contents($dir . '/0002_pending.up.sql', "CREATE TABLE _drift_stop_2 (id INT NOT NULL);");

        try {
            $runner->run($dir);
            self::fail('Expected checksum drift exception before running pending migrations.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Checksum drift detected for applied migration: 0001_applied', $e->getMessage());
        }

        // Pending migration table must NEVER exist
        self::assertFalse(
            self::$testDb->pdo()->query("SHOW TABLES LIKE '_drift_stop_2'")->fetchColumn(),
            'Pending migration table _drift_stop_2 must not be created when checksum drift occurs.'
        );
    }

    public function testLoneCrBoundaryTriggersFailStopDriftDetection(): void
    {
        $baseSql = "CREATE TABLE _t_lone_cr (\n  id INT NOT NULL\n);";
        $dir = $this->createFixtureDir(['0001_cr.up.sql' => $baseSql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        // Replace uniform line ending with lone CR (\r without \n)
        file_put_contents($dir . '/0001_cr.up.sql', "CREATE TABLE _t_lone_cr (\r  id INT NOT NULL\r);");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_cr');
        $runner->run($dir);
    }

    public function testMixedEolRawFallbackMatchesWhenPreservedAndFailsWhenNormalized(): void
    {
        // Mixed-EOL content: line 1 CRLF, line 2 LF
        $mixedRawSql = "CREATE TABLE _t_mix (\r\n  id INT NOT NULL,\n  val VARCHAR(30)\n);";
        $rawHash = hash('sha256', rtrim($mixedRawSql));

        $dir = $this->createFixtureDir(['0001_mix.up.sql' => $mixedRawSql]);
        $runner = new MigrationRunner(self::$testDb);

        // Bootstrap schema_migrations and simulate historical record with raw mixed hash
        $emptyDir = $this->createFixtureDir([]);
        $runner->run($emptyDir);
        $stmt = self::$testDb->pdo()->prepare('INSERT INTO schema_migrations (identifier, checksum, applied_at) VALUES (:id, :cs, UTC_TIMESTAMP())');
        $stmt->execute([':id' => '0001_mix', ':cs' => $rawHash]);

        // When raw file matches historical mixed bytes, raw-fallback candidate succeeds without mutating history
        $before = $this->snapshotHistory();
        $runner->run($dir);
        $after = $this->snapshotHistory();
        self::assertSame($before, $after, 'Raw fallback validation must not mutate history rows.');

        // When the mixed-EOL file is normalized to pure LF, candidates no longer match historical raw hash
        $normalizedLf = str_replace("\r\n", "\n", $mixedRawSql);
        file_put_contents($dir . '/0001_mix.up.sql', $normalizedLf);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift detected for applied migration: 0001_mix');
        $runner->run($dir);
    }

    public function testUpMigrationExecutesRawBytesPreservingExactContent(): void
    {
        $multilineComment = "primera linea\r\nsegunda linea";
        $escaped = addslashes($multilineComment);
        $sql = "CREATE TABLE _raw_exec (\n  id INT NOT NULL COMMENT '{$escaped}'\n);";

        $dir = $this->createFixtureDir(['0001_raw_up.up.sql' => $sql]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $pdo = self::$testDb->pdo();
        $row = $pdo->query("SHOW FULL COLUMNS FROM _raw_exec WHERE Field = 'id'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame($multilineComment, $row['Comment'], 'UP migration must execute raw byte payload without mangling.');
    }

    public function testDownMigrationExecutesRawBytesPreservingExactContent(): void
    {
        $upSql = "CREATE TABLE _raw_down (\n  id INT NOT NULL\n);";
        $downSql = "DROP TABLE IF EXISTS _raw_down;";

        $dir = $this->createFixtureDir([
            '0001_raw_down.up.sql'   => $upSql,
            '0001_raw_down.down.sql' => $downSql,
        ]);
        $runner = new MigrationRunner(self::$testDb);
        $runner->run($dir);

        $pdo = self::$testDb->pdo();
        self::assertSame('_raw_down', $pdo->query("SHOW TABLES LIKE '_raw_down'")->fetchColumn());

        // Revert executes raw down migration
        $runner->revert('0001_raw_down', $dir);

        self::assertFalse($pdo->query("SHOW TABLES LIKE '_raw_down'")->fetchColumn());
        self::assertFalse($pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0001_raw_down'")->fetch());
    }

    /** @return list<array<string, mixed>> */
    private function snapshotHistory(): array
    {
        /** @var list<array<string, mixed>> */
        return self::$testDb->pdo()
            ->query('SELECT identifier, checksum, applied_at FROM schema_migrations ORDER BY identifier ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
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
