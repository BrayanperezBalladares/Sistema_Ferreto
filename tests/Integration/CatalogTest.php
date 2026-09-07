<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\MigrationRunner;
use App\Foundation\Transaction;
use App\Modules\Inventory\CatalogValidator;
use App\Modules\Inventory\CategoryCommand;
use App\Modules\Inventory\CategoryQuery;
use App\Modules\Inventory\ProductCommand;
use App\Modules\Inventory\ProductQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private static Config $testConfig;
    private static Database $testDb;
    private static CategoryQuery $catQuery;
    private static CategoryCommand $catCmd;
    private static ProductQuery $prodQuery;
    private static ProductCommand $prodCmd;

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
        self::$prodQuery  = new ProductQuery(self::$testDb);
        self::$prodCmd    = new ProductCommand($tx);

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
        $pdo->exec('TRUNCATE TABLE producto');
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

        $runner->revert('0003_create_producto', $migrationsPath);
        $runner->revert('0002_create_categoria', $migrationsPath);

        $tables = self::$testDb->pdo()->query("SHOW TABLES LIKE 'categoria'")->fetchAll();
        self::assertCount(0, $tables);

        $runner->run($migrationsPath);
        $tablesAfter = self::$testDb->pdo()->query("SHOW TABLES LIKE 'categoria'")->fetchAll();
        self::assertCount(1, $tablesAfter);
    }

    public function testProductRegistrationWithCategory(): void
    {
        $catId = self::$catCmd->create('Pinturas');
        $prodId = self::$prodCmd->register('Esmalte Sintético', '125.50', $catId, 'Color blanco 1L');
        $prod = self::$prodQuery->findById($prodId);
        self::assertIsArray($prod);
        self::assertSame('Esmalte Sintético', $prod['nombre']);
        self::assertSame('125.50', $prod['precio_actual']);
        self::assertSame($catId, (int) $prod['id_categoria']);
        self::assertSame('Pinturas', $prod['categoria_nombre']);
        self::assertSame(1, (int) $prod['estado_activo']);
    }

    public function testProductRegistrationWithoutCategory(): void
    {
        $prodId = self::$prodCmd->register('Clavo Genérico', '15.00', null);
        $prod = self::$prodQuery->findById($prodId);
        self::assertIsArray($prod);
        self::assertNull($prod['id_categoria']);
        self::assertNull($prod['categoria_nombre']);
        self::assertSame('15.00', $prod['precio_actual']);
    }

    public function testNegativePriceRejectedByDatabaseConstraint(): void
    {
        $this->expectException(PDOException::class);
        self::$prodCmd->register('Tornillo', '-5.00');
    }

    public function testPriceValidationFormatAndNonNegativity(): void
    {
        self::assertTrue(CatalogValidator::validatePrice('0')->valid());
        self::assertTrue(CatalogValidator::validatePrice('10.50')->valid());
        self::assertTrue(CatalogValidator::validatePrice('100')->valid());
        self::assertFalse(CatalogValidator::validatePrice('-10.50')->valid());
        self::assertFalse(CatalogValidator::validatePrice('10.555')->valid());
        self::assertFalse(CatalogValidator::validatePrice('abc')->valid());
        self::assertFalse(CatalogValidator::validatePrice('')->valid());
    }

    public function testUpdateCurrentSellingPrice(): void
    {
        $prodId = self::$prodCmd->register('Cinta Métrica', '45.00');
        self::assertTrue(self::$prodCmd->updatePrice($prodId, '49.99'));
        $prod = self::$prodQuery->findById($prodId);
        self::assertIsArray($prod);
        self::assertSame('49.99', $prod['precio_actual']);
    }

    public function testProductDeactivation(): void
    {
        $prodId = self::$prodCmd->register('Nivel Torpedo', '60.00');
        self::assertTrue(self::$prodCmd->deactivate($prodId));
        $prod = self::$prodQuery->findById($prodId);
        self::assertIsArray($prod);
        self::assertSame(0, (int) $prod['estado_activo']);
    }

    public function testReferentialIntegrityRestrictsCategoryDeletionWithProducts(): void
    {
        $catId = self::$catCmd->create('Electricidad');
        self::$prodCmd->register('Cable 2.5mm', '80.00', $catId);
        $this->expectException(PDOException::class);
        self::$testDb->pdo()->exec("DELETE FROM categoria WHERE id_categoria = {$catId}");
    }

    public function testCatalogSearchByNameAndCategory(): void
    {
        $catA = self::$catCmd->create('Ferretería');
        $catB = self::$catCmd->create('Plomería');
        self::$prodCmd->register('Martillo de uña', '75.00', $catA);
        self::$prodCmd->register('Martillo demoledor', '450.00', $catA);
        self::$prodCmd->register('Tubo PVC 1/2', '25.00', $catB);

        self::assertCount(2, self::$prodQuery->search('martillo'));
        $plomeria = self::$prodQuery->search('', $catB);
        self::assertCount(1, $plomeria);
        self::assertSame('Tubo PVC 1/2', $plomeria[0]['nombre']);
        self::assertSame([], self::$prodQuery->search('Inexistente'));
    }

    public function testDevelopmentDatabaseRemainsUntouched(): void
    {
        $devDb  = new Database(self::$testConfig, useTestDatabase: false);
        $tables = $devDb->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }
}
