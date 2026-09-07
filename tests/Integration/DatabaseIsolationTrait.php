<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Config;
use App\Foundation\Database;
use PDO;
use PHPUnit\Framework\Assert;

trait DatabaseIsolationTrait
{
    /** @var array<class-string, array{tables: list<string>, checksums: array<string, array{checksum: string|int|null, count: int}>}> */
    protected static array $initialDevState = [];

    /**
     * @return array{tables: list<string>, checksums: array<string, array{checksum: string|int|null, count: int}>}
     */
    protected static function captureDevState(Database $devDb): array
    {
        $pdo = $devDb->pdo();
        /** @var list<string> $tables */
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);

        $checksums = [];
        foreach ($tables as $table) {
            /** @var array{Table: string, Checksum: string|int|null}|false $chk */
            $chk = $pdo->query("CHECKSUM TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $checksums[$table] = [
                'checksum' => $chk !== false ? ($chk['Checksum'] ?? null) : null,
                'count' => $count,
            ];
        }

        return [
            'tables' => $tables,
            'checksums' => $checksums,
        ];
    }

    protected static function recordInitialDevState(Database $devDb): void
    {
        self::$initialDevState[static::class] = self::captureDevState($devDb);
    }

    protected static function assertTestDatabaseIsolated(Database $testDb, Config $config): void
    {
        /** @var array{db: string, usr: string, port: string|int} $identity */
        $identity = $testDb->pdo()->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr, @@port AS port')->fetch(PDO::FETCH_ASSOC);

        $testDbName = (string) $config->get('TEST_DB_NAME');
        $devDbName  = (string) $config->get('DB_NAME');
        $testUser   = (string) $config->get('TEST_DB_USER');
        $testPort   = (int) $config->get('TEST_DB_PORT');

        Assert::assertSame($testDbName, $identity['db'], 'Test connection must target TEST_DB_NAME.');
        Assert::assertNotSame($devDbName, $identity['db'], 'Test connection must never target DB_NAME.');
        Assert::assertStringEndsWith('_test', (string) $identity['db'], 'Test connection DB must end in _test.');
        Assert::assertSame($testUser . '@127.0.0.1', $identity['usr'], 'Test connection must use TEST_DB_USER.');
        Assert::assertSame($testPort, (int) $identity['port'], 'Test connection must use TEST_DB_PORT.');
    }

    protected static function assertDevDatabaseUntouched(Database $devDb, Config $config): void
    {
        Assert::assertArrayHasKey(
            static::class,
            self::$initialDevState,
            'Initial dev state must be captured in setUpBeforeClass via recordInitialDevState.'
        );

        /** @var array{db: string, usr: string, port: string|int} $identity */
        $identity = $devDb->pdo()->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr, @@port AS port')->fetch(PDO::FETCH_ASSOC);

        $devDbName = (string) $config->get('DB_NAME');
        $devUser   = (string) $config->get('DB_USER');
        $devPort   = (int) $config->get('DB_PORT');

        Assert::assertSame($devDbName, $identity['db'], 'Dev connection must target DB_NAME.');
        Assert::assertFalse(str_ends_with((string) $identity['db'], '_test'), 'Dev DB name must not end in _test.');
        Assert::assertSame($devUser . '@127.0.0.1', $identity['usr'], 'Dev connection must use DB_USER.');
        Assert::assertSame($devPort, (int) $identity['port'], 'Dev connection must use DB_PORT.');

        $currentState = self::captureDevState($devDb);
        Assert::assertSame(
            self::$initialDevState[static::class],
            $currentState,
            'Development database tables, row counts, and checksums must remain identical before and after test execution.'
        );
    }
}
