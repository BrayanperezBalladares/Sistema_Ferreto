<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\CategoryCommand;
use App\Modules\Inventory\CategoryQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;
    private static CategoryQuery $catQuery;
    private static CategoryCommand $catCmd;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME');
        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail('Isolation guard: TEST_DB_NAME must end in _test.');
        }

        self::$testConfig = Config::fromEnvironment(require dirname(__DIR__, 2) . '/config/defaults.php');
        self::$testDb     = new Database(self::$testConfig, useTestDatabase: true);
        $tx               = new Transaction(self::$testDb);

        self::$catQuery   = new CategoryQuery(self::$testDb);
        self::$catCmd     = new CategoryCommand($tx);

        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE IF EXISTS producto');
        $pdo->exec('DROP TABLE IF EXISTS categoria');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        (new MigrationRunner(self::$testDb))->run(dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function setUp(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE categoria');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = self::$testDb->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE IF EXISTS producto');
        $pdo->exec('DROP TABLE IF EXISTS categoria');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testCategoryCreationAndRetrieval(): void
    {
        $id = self::$catCmd->create('Herramientas', 'Herramientas de mano');
        self::assertGreaterThan(0, $id);
        $cat = self::$catQuery->findById($id);
        self::assertIsArray($cat);
        self::assertSame('Herramientas', $cat['nombre']);
        self::assertSame('Herramientas de mano', $cat['descripcion']);
    }

    public function testCategoryCreationWithOptionalDescription(): void
    {
        $id = self::$catCmd->create('Plomería', null);
        self::assertGreaterThan(0, $id);
        $cat = self::$catQuery->findByName('Plomería');
        self::assertIsArray($cat);
        self::assertNull($cat['descripcion']);
    }

    public function testDuplicateCategoryRejection(): void
    {
        self::$catCmd->create('Fijaciones');
        $this->expectException(PDOException::class);
        self::$catCmd->create('Fijaciones');
    }

    public function testMigrationReversalAndReRun(): void
    {
        $runner = new MigrationRunner(self::$testDb);
        $migrationsPath = dirname(__DIR__, 2) . '/database/migrations';

        if (file_exists($migrationsPath . '/0003_create_producto.up.sql')) {
            $applied = self::$testDb->pdo()->query("SELECT 1 FROM schema_migrations WHERE identifier = '0003_create_producto'")->fetch();
            if ($applied !== false) {
                $runner->revert('0003_create_producto', $migrationsPath);
            }
        }

        $runner->revert('0002_create_categoria', $migrationsPath);

        $tables = self::$testDb->pdo()->query("SHOW TABLES LIKE 'categoria'")->fetchAll();
        self::assertCount(0, $tables);

        $runner->run($migrationsPath);
        $tablesAfter = self::$testDb->pdo()->query("SHOW TABLES LIKE 'categoria'")->fetchAll();
        self::assertCount(1, $tablesAfter);
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        $devDb  = new Database(self::$testConfig, useTestDatabase: false);
        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }
}
