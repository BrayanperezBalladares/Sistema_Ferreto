<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Access\UserCommand;
use App\Modules\Access\UserQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class UserPersistenceTest extends TestCase
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

        // Run migrations up to 0007 on test database
        self::$runner->run(self::$migrationsPath);
    }

    protected function setUp(): void
    {
        // Clean usuario test rows between tests without dropping tables
        $pdo = self::$testDb->pdo();
        $pdo->exec('DELETE FROM usuario');

        $tx = new Transaction(self::$testDb);
        $this->query   = new UserQuery(self::$testDb);
        $this->command = new UserCommand($tx);
    }

    public static function tearDownAfterClass(): void
    {
        self::assertDevDatabaseUntouched(self::$devDb, self::$testConfig);
    }

    public function testExistingR1SchemaPreserved(): void
    {
        $pdo = self::$testDb->pdo();
        $r1Tables = [
            'infrastructure_probe',
            'categoria',
            'producto',
            'ubicacion',
            'inventario_stock',
            'conteo_inventario',
        ];

        foreach ($r1Tables as $table) {
            $found = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
            self::assertSame($table, $found, "Existing table {$table} must be preserved.");
        }
    }

    public function testUsuarioTableAndColumnsExist(): void
    {
        $pdo = self::$testDb->pdo();
        $table = $pdo->query("SHOW TABLES LIKE 'usuario'")->fetchColumn();
        self::assertSame('usuario', $table);

        $stmt = $pdo->query('SHOW COLUMNS FROM usuario');
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['Field']] = $row;
        }

        $expectedFields = [
            'id_usuario',
            'username',
            'password_hash',
            'rol',
            'estado',
            'failed_attempt_count',
            'failure_window_started_at',
            'locked_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedFields as $field) {
            self::assertArrayHasKey($field, $columns, "Column {$field} must exist on usuario table.");
        }

        // Nullability checks
        self::assertSame('NO', $columns['id_usuario']['Null']);
        self::assertSame('NO', $columns['username']['Null']);
        self::assertSame('NO', $columns['password_hash']['Null']);
        self::assertSame('NO', $columns['rol']['Null']);
        self::assertSame('NO', $columns['estado']['Null']);
        self::assertSame('NO', $columns['failed_attempt_count']['Null']);
        self::assertSame('YES', $columns['failure_window_started_at']['Null']);
        self::assertSame('YES', $columns['locked_at']['Null']);
        self::assertSame('NO', $columns['created_at']['Null']);
        self::assertSame('NO', $columns['updated_at']['Null']);

        // Default values
        self::assertSame('creado', $columns['estado']['Default']);
        self::assertSame('0', (string) $columns['failed_attempt_count']['Default']);
    }

    public function testUsernameUniquenessEnforced(): void
    {
        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol) VALUES (:u, :p, :r)'
        );
        $stmt->execute([
            ':u' => 'unique_user',
            ':p' => '$2y$10$hashedpasswordsample123456789012345678901234567890123456',
            ':r' => 'cajero',
        ]);

        self::expectException(PDOException::class);
        // Attempt duplicate username
        $stmt->execute([
            ':u' => 'unique_user',
            ':p' => '$2y$10$hashedpasswordsample123456789012345678901234567890123456',
            ':r' => 'administrador',
        ]);
    }

    public function testUsernameNotNullEnforced(): void
    {
        $pdo = self::$testDb->pdo();
        self::expectException(PDOException::class);
        $pdo->exec(
            "INSERT INTO usuario (username, password_hash, rol) VALUES (NULL, 'hash', 'cajero')"
        );
    }

    public function testRoleCheckConstraintAllowsSupportedRoles(): void
    {
        $pdo = self::$testDb->pdo();
        $roles = ['administrador', 'cajero', 'bodeguero', 'compras'];

        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol) VALUES (:u, :p, :r)'
        );

        foreach ($roles as $index => $rol) {
            $stmt->execute([
                ':u' => "user_role_{$index}",
                ':p' => 'dummy_hash',
                ':r' => $rol,
            ]);
            $inserted = $pdo->query("SELECT rol FROM usuario WHERE username = 'user_role_{$index}'")->fetchColumn();
            self::assertSame($rol, $inserted);
        }
    }

    public function testRoleCheckConstraintRejectsUnsupportedRole(): void
    {
        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol) VALUES (:u, :p, :r)'
        );

        self::expectException(PDOException::class);
        $stmt->execute([
            ':u' => 'invalid_role_user',
            ':p' => 'dummy_hash',
            ':r' => 'gerente',
        ]);
    }

    public function testEstadoCheckConstraintAllowsSupportedStates(): void
    {
        $pdo = self::$testDb->pdo();
        $states = ['creado', 'activo', 'bloqueado', 'inactivo'];

        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol, estado) VALUES (:u, :p, :r, :e)'
        );

        foreach ($states as $index => $estado) {
            $stmt->execute([
                ':u' => "user_state_{$index}",
                ':p' => 'dummy_hash',
                ':r' => 'bodeguero',
                ':e' => $estado,
            ]);
            $inserted = $pdo->query("SELECT estado FROM usuario WHERE username = 'user_state_{$index}'")->fetchColumn();
            self::assertSame($estado, $inserted);
        }
    }

    public function testEstadoCheckConstraintRejectsUnsupportedState(): void
    {
        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol, estado) VALUES (:u, :p, :r, :e)'
        );

        self::expectException(PDOException::class);
        $stmt->execute([
            ':u' => 'invalid_state_user',
            ':p' => 'dummy_hash',
            ':r' => 'cajero',
            ':e' => 'suspendido',
        ]);
    }

    public function testFailedAttemptCountDefaultAndNonNegative(): void
    {
        $pdo = self::$testDb->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol) VALUES (:u, :p, :r)'
        );
        $stmt->execute([
            ':u' => 'count_user',
            ':p' => 'dummy_hash',
            ':r' => 'compras',
        ]);

        $row = $pdo->query("SELECT estado, failed_attempt_count, failure_window_started_at, locked_at FROM usuario WHERE username = 'count_user'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('creado', $row['estado'], 'Default estado must be creado.');
        self::assertSame(0, (int) $row['failed_attempt_count'], 'Default failed_attempt_count must be 0.');
        self::assertNull($row['failure_window_started_at'], 'failure_window_started_at must be nullable.');
        self::assertNull($row['locked_at'], 'locked_at must be nullable.');

        // Verify negative failed_attempt_count is rejected
        self::expectException(PDOException::class);
        $pdo->exec("INSERT INTO usuario (username, password_hash, rol, failed_attempt_count) VALUES ('neg_user', 'hash', 'cajero', -1)");
    }

    public function testReversibilityViaDownMigration(): void
    {
        $pdo = self::$testDb->pdo();

        // Verify usuario table is present before revert
        self::assertSame('usuario', $pdo->query("SHOW TABLES LIKE 'usuario'")->fetchColumn());

        // Revert migration 0007
        self::$runner->revert('0007_create_usuario', self::$migrationsPath);

        // Verify usuario is removed
        self::assertFalse($pdo->query("SHOW TABLES LIKE 'usuario'")->fetchColumn());
        self::assertFalse($pdo->query("SELECT * FROM schema_migrations WHERE identifier = '0007_create_usuario'")->fetch());

        // Verify all R1 tables remain intact
        $r1Tables = [
            'infrastructure_probe',
            'categoria',
            'producto',
            'ubicacion',
            'inventario_stock',
            'conteo_inventario',
        ];
        foreach ($r1Tables as $table) {
            self::assertSame($table, $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn());
        }

        // Re-apply migration 0007 cleanly
        self::$runner->run(self::$migrationsPath);
        self::assertSame('usuario', $pdo->query("SHOW TABLES LIKE 'usuario'")->fetchColumn());
        self::assertNotEmpty($pdo->query("SELECT checksum FROM schema_migrations WHERE identifier = '0007_create_usuario'")->fetchColumn());
    }

    public function testUserQueryFindByIdAndFindByUsername(): void
    {
        $id = $this->command->create('alice', '$2y$10$hashforexamplealice1234567890123456789012345678901234', 'cajero', 'activo');
        self::assertGreaterThan(0, $id);

        $byName = $this->query->findByUsername('alice');
        self::assertNotNull($byName);
        self::assertSame($id, $byName['id_usuario']);
        self::assertSame('alice', $byName['username']);
        self::assertSame('$2y$10$hashforexamplealice1234567890123456789012345678901234', $byName['password_hash']);
        self::assertSame('cajero', $byName['rol']);
        self::assertSame('activo', $byName['estado']);
        self::assertSame(0, $byName['failed_attempt_count']);
        self::assertNull($byName['failure_window_started_at']);
        self::assertNull($byName['locked_at']);
        self::assertNotEmpty($byName['created_at']);
        self::assertNotEmpty($byName['updated_at']);

        $byId = $this->query->findById($id);
        self::assertNotNull($byId);
        self::assertSame($byName, $byId);

        self::assertNull($this->query->findByUsername('nonexistent_user'));
        self::assertNull($this->query->findById(999999));
    }

    public function testUserCommandCreateDefaultsAndExplicitState(): void
    {
        $id1 = $this->command->create('bob', 'hash_bob', 'bodeguero');
        $row1 = $this->query->findById($id1);
        self::assertNotNull($row1);
        self::assertSame('creado', $row1['estado'], 'Default state must be creado.');
        self::assertSame('bodeguero', $row1['rol']);
        self::assertSame(0, $row1['failed_attempt_count']);

        $id2 = $this->command->create('charlie', 'hash_charlie', 'administrador', 'activo');
        $row2 = $this->query->findById($id2);
        self::assertNotNull($row2);
        self::assertSame('activo', $row2['estado'], 'Explicit state must be activo.');
        self::assertSame('administrador', $row2['rol']);

        self::expectException(PDOException::class);
        $this->command->create('charlie', 'hash_duplicate', 'cajero', 'activo');
    }

    public function testRecordFailureFirstAttemptInitializesFixedWindow(): void
    {
        $this->command->create('david', 'hash_david', 'cajero', 'activo');

        $result = $this->command->recordFailure('david');
        self::assertTrue($result);

        $user = $this->query->findByUsername('david');
        self::assertNotNull($user);
        self::assertSame(1, $user['failed_attempt_count']);
        self::assertSame('activo', $user['estado']);
        self::assertNotNull($user['failure_window_started_at']);
        self::assertNull($user['locked_at']);
    }

    public function testRecordFailureUpToFiveAttemptsKeepsActive(): void
    {
        $this->command->create('elena', 'hash_elena', 'bodeguero', 'activo');

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->command->recordFailure('elena');
            self::assertTrue($result);

            $user = $this->query->findByUsername('elena');
            self::assertNotNull($user);
            self::assertSame($i, $user['failed_attempt_count']);
            self::assertSame('activo', $user['estado']);
            self::assertNull($user['locked_at']);
        }
    }

    public function testRecordFailureSixthAttemptLocksAccount(): void
    {
        $this->command->create('frank', 'hash_frank', 'compras', 'activo');

        for ($i = 1; $i <= 5; $i++) {
            $this->command->recordFailure('frank');
        }

        // 6th attempt within active window triggers lockout
        $result6 = $this->command->recordFailure('frank');
        self::assertTrue($result6);

        $user = $this->query->findByUsername('frank');
        self::assertNotNull($user);
        self::assertSame(6, $user['failed_attempt_count']);
        self::assertSame('bloqueado', $user['estado']);
        self::assertNotNull($user['locked_at']);

        // Subsequent failure attempts on a bloqueado account return false and do not modify state
        $result7 = $this->command->recordFailure('frank');
        self::assertFalse($result7);

        $userAfter = $this->query->findByUsername('frank');
        self::assertNotNull($userAfter);
        self::assertSame(6, $userAfter['failed_attempt_count']);
        self::assertSame('bloqueado', $userAfter['estado']);
    }

    public function testRecordFailureExpiredWindowResetsCounter(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "INSERT INTO usuario (username, password_hash, rol, estado, failed_attempt_count, failure_window_started_at) "
            . "VALUES ('grace', 'hash_grace', 'cajero', 'activo', 5, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))"
        );

        $result = $this->command->recordFailure('grace');
        self::assertTrue($result);

        $user = $this->query->findByUsername('grace');
        self::assertNotNull($user);
        self::assertSame(1, $user['failed_attempt_count'], 'Expired window must reset failed_attempt_count to 1.');
        self::assertSame('activo', $user['estado'], 'Account must remain activo.');
        self::assertNotNull($user['failure_window_started_at']);
        self::assertNull($user['locked_at']);
    }

    public function testRecordFailureIgnoresNonActiveAccounts(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec("INSERT INTO usuario (username, password_hash, rol, estado) VALUES ('user_creado', 'hash', 'cajero', 'creado')");
        $pdo->exec("INSERT INTO usuario (username, password_hash, rol, estado) VALUES ('user_inactivo', 'hash', 'cajero', 'inactivo')");
        $pdo->exec("INSERT INTO usuario (username, password_hash, rol, estado) VALUES ('user_bloqueado', 'hash', 'cajero', 'bloqueado')");

        self::assertFalse($this->command->recordFailure('user_creado'));
        self::assertFalse($this->command->recordFailure('user_inactivo'));
        self::assertFalse($this->command->recordFailure('user_bloqueado'));
        self::assertFalse($this->command->recordFailure('user_nonexistent'));

        $creado = $this->query->findByUsername('user_creado');
        self::assertNotNull($creado);
        self::assertSame(0, $creado['failed_attempt_count']);

        $inactivo = $this->query->findByUsername('user_inactivo');
        self::assertNotNull($inactivo);
        self::assertSame(0, $inactivo['failed_attempt_count']);
    }

    public function testResetFailuresForActiveUser(): void
    {
        $idHector = $this->command->create('hector', 'hash_hector', 'cajero', 'activo');
        $this->command->recordFailure('hector');
        $this->command->recordFailure('hector');

        $before = $this->query->findById($idHector);
        self::assertNotNull($before);
        self::assertSame(2, $before['failed_attempt_count']);
        self::assertNotNull($before['failure_window_started_at']);

        $res = $this->command->resetFailures($idHector);
        self::assertTrue($res);

        $after = $this->query->findById($idHector);
        self::assertNotNull($after);
        self::assertSame(0, $after['failed_attempt_count']);
        self::assertNull($after['failure_window_started_at']);
        self::assertNull($after['locked_at']);
        self::assertSame('activo', $after['estado']);

        // Cannot reset failures on a blocked user (prevent implicit unlock)
        $idIsabel = $this->command->create('isabel', 'hash_isabel', 'cajero', 'bloqueado');
        self::assertFalse($this->command->resetFailures($idIsabel));
        $isabel = $this->query->findById($idIsabel);
        self::assertNotNull($isabel);
        self::assertSame('bloqueado', $isabel['estado']);
    }

    public function testUnlockTransitionsBloqueadoToActivo(): void
    {
        $idJulia = $this->command->create('julia', 'hash_julia', 'compras', 'activo');
        for ($i = 0; $i < 6; $i++) {
            $this->command->recordFailure('julia');
        }

        $blocked = $this->query->findById($idJulia);
        self::assertNotNull($blocked);
        self::assertSame('bloqueado', $blocked['estado']);
        self::assertSame(6, $blocked['failed_attempt_count']);
        self::assertNotNull($blocked['locked_at']);

        $unlocked = $this->command->unlock('julia');
        self::assertTrue($unlocked);

        $active = $this->query->findById($idJulia);
        self::assertNotNull($active);
        self::assertSame('activo', $active['estado']);
        self::assertSame(0, $active['failed_attempt_count']);
        self::assertNull($active['failure_window_started_at']);
        self::assertNull($active['locked_at']);

        // Second unlock is a no-op because account is already activo
        self::assertFalse($this->command->unlock('julia'));

        // Unlock on inactivo, creado, or nonexistent accounts returns false
        $this->command->create('kevin', 'hash_kevin', 'bodeguero', 'inactivo');
        self::assertFalse($this->command->unlock('kevin'));
        $kevin = $this->query->findByUsername('kevin');
        self::assertNotNull($kevin);
        self::assertSame('inactivo', $kevin['estado']);

        $this->command->create('laura', 'hash_laura', 'bodeguero', 'creado');
        self::assertFalse($this->command->unlock('laura'));
        $laura = $this->query->findByUsername('laura');
        self::assertNotNull($laura);
        self::assertSame('creado', $laura['estado']);

        self::assertFalse($this->command->unlock('nonexistent_user'));
    }

    public function testConcurrentRecordFailureLocksWithoutLostIncrements(): void
    {
        $this->command->create('conc_user', 'dummy_hash', 'cajero', 'activo');

        // Pre-seed with 4 failures in active window
        $pdo = self::$testDb->pdo();
        $pdo->exec(
            "UPDATE usuario SET failed_attempt_count = 4, failure_window_started_at = UTC_TIMESTAMP() "
            . "WHERE username = 'conc_user'"
        );

        $workerCode = sprintf(
            '<?php
            require %s;
            $config = App\Foundation\Config::fromEnvironment(require %s);
            $db = new App\Foundation\Database($config, useTestDatabase: true);
            $tx = new App\Foundation\Transaction($db);
            $cmd = new App\Modules\Access\UserCommand($tx);
            $cmd->recordFailure("conc_user");
            ',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export(dirname(__DIR__, 2) . '/config/defaults.php', true)
        );

        $childScript = sys_get_temp_dir() . '/child_worker_' . uniqid() . '.php';
        file_put_contents($childScript, $workerCode);

        try {
            $p1 = proc_open([PHP_BINARY, $childScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
            $p2 = proc_open([PHP_BINARY, $childScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

            if (is_resource($p1)) {
                fclose($pipes1[0]);
                fclose($pipes1[1]);
                fclose($pipes1[2]);
                proc_close($p1);
            }
            if (is_resource($p2)) {
                fclose($pipes2[0]);
                fclose($pipes2[1]);
                fclose($pipes2[2]);
                proc_close($p2);
            }

            $user = $this->query->findByUsername('conc_user');
            self::assertNotNull($user);
            self::assertSame('bloqueado', $user['estado'], 'Concurrent failures reaching attempt 6 must lock account.');
            self::assertSame(6, $user['failed_attempt_count'], 'Counter must reach exactly 6 without lost increments.');
            self::assertNotNull($user['locked_at']);
        } finally {
            if (file_exists($childScript)) {
                unlink($childScript);
            }
        }
    }
}
