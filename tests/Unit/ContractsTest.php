<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Foundation\Config;
use App\Foundation\Database;
use App\Foundation\HealthQuery;
use App\Foundation\Transaction;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit contract tests for Slice 3A.
 *
 * These tests verify structural contracts and argument-level safety without
 * requiring a live database connection. All mutation proofs are delegated to
 * DatabaseTest (integration, test DB only).
 */
final class ContractsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Database class contracts
    // -------------------------------------------------------------------------

    public function testDatabaseClassExists(): void
    {
        self::assertTrue(class_exists(Database::class));
    }

    public function testDatabaseIsNotInstantiableWithoutConfig(): void
    {
        $ref = new ReflectionClass(Database::class);
        // Constructor must require a Config argument — no zero-arg construction.
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        self::assertGreaterThan(0, $ctor->getNumberOfRequiredParameters());
    }

    public function testDatabaseExposesPdoMethod(): void
    {
        $ref = new ReflectionClass(Database::class);
        self::assertTrue($ref->hasMethod('pdo'));
    }

    // -------------------------------------------------------------------------
    // Transaction class contracts
    // -------------------------------------------------------------------------

    public function testTransactionClassExists(): void
    {
        self::assertTrue(class_exists(Transaction::class));
    }

    public function testTransactionExposesRunMethod(): void
    {
        $ref = new ReflectionClass(Transaction::class);
        self::assertTrue($ref->hasMethod('run'));
    }

    public function testTransactionRunAcceptsCallable(): void
    {
        $ref    = new ReflectionClass(Transaction::class);
        $method = $ref->getMethod('run');
        $params = $method->getParameters();
        self::assertCount(1, $params);
        $type = $params[0]->getType();
        self::assertNotNull($type);
        self::assertSame('Closure', (string) $type);
    }

    // -------------------------------------------------------------------------
    // HealthQuery class contracts
    // -------------------------------------------------------------------------

    public function testHealthQueryClassExists(): void
    {
        self::assertTrue(class_exists(HealthQuery::class));
    }

    public function testHealthQueryExposesPingMethod(): void
    {
        $ref = new ReflectionClass(HealthQuery::class);
        self::assertTrue($ref->hasMethod('ping'));
    }

    public function testHealthQueryIsNotInstantiableWithoutDatabase(): void
    {
        $ref  = new ReflectionClass(HealthQuery::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        self::assertGreaterThan(0, $ctor->getNumberOfRequiredParameters());
    }

    // -------------------------------------------------------------------------
    // Config isolation-guard contract (TEST_DB_NAME suffix)
    // -------------------------------------------------------------------------

    public function testConfigRejectsTestDbNameWithoutTestSuffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $env = [
            'APP_ENV'         => 'development',
            'DB_HOST'         => '127.0.0.1',
            'DB_PORT'         => '3309',
            'DB_NAME'         => 'sistema_ferreto',
            'DB_USER'         => 'ferreto_app',
            'DB_PASSWORD'     => 'secret',
            'TEST_DB_HOST'    => '127.0.0.1',
            'TEST_DB_PORT'    => '3309',
            'TEST_DB_NAME'    => 'sistema_ferreto',   // missing _test suffix — MUST be rejected
            'TEST_DB_USER'    => 'ferreto_test',
            'TEST_DB_PASSWORD'=> 'secret',
        ];

        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
        }

        try {
            Config::fromEnvironment([
                'APP_ENV'      => ['development', 'test', 'production'],
                'DB_PORT'      => [1, 65535],
                'TEST_DB_PORT' => [1, 65535],
            ]);
        } finally {
            // Restore: unset injected env vars so they don't bleed into other tests.
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }
    }

    public function testConfigAcceptsTestDbNameWithTestSuffix(): void
    {
        $env = [
            'APP_ENV'         => 'development',
            'DB_HOST'         => '127.0.0.1',
            'DB_PORT'         => '3309',
            'DB_NAME'         => 'sistema_ferreto',
            'DB_USER'         => 'ferreto_app',
            'DB_PASSWORD'     => 'secret',
            'TEST_DB_HOST'    => '127.0.0.1',
            'TEST_DB_PORT'    => '3309',
            'TEST_DB_NAME'    => 'sistema_ferreto_test', // valid
            'TEST_DB_USER'    => 'ferreto_test',
            'TEST_DB_PASSWORD'=> 'secret',
        ];

        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
        }

        try {
            $config = Config::fromEnvironment([
                'APP_ENV'      => ['development', 'test', 'production'],
                'DB_PORT'      => [1, 65535],
                'TEST_DB_PORT' => [1, 65535],
            ]);
            self::assertSame('sistema_ferreto_test', $config->get('TEST_DB_NAME'));
        } finally {
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }
    }

    // -------------------------------------------------------------------------
    // No generic repository contract (structural)
    // -------------------------------------------------------------------------

    public function testNoGenericRepositoryClassExists(): void
    {
        // The design forbids a generic repository abstraction.
        self::assertFalse(
            class_exists('App\\Foundation\\Repository'),
            'A generic Repository class must not exist; module-owned queries/commands only.'
        );
    }

    public function testNoOrmClassExists(): void
    {
        self::assertFalse(
            class_exists('App\\Foundation\\Model'),
            'A generic ORM Model class must not exist.'
        );
    }
}
