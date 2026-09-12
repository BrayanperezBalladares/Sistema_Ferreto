<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
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
}