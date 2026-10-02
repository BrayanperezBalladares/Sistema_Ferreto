<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Access\RouteAccessPolicy;
use PHPUnit\Framework\TestCase;

final class RouteAccessPolicyTest extends TestCase
{
    private RouteAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new RouteAccessPolicy();
    }

    public function testAdministradorIsAllowedOnAllTwelveRegisteredOperations(): void
    {
        $operations = [
            ['GET', '/products'],
            ['POST', '/categories'],
            ['POST', '/products'],
            ['POST', '/products/10/price'],
            ['POST', '/products/25/deactivate'],
            ['POST', '/products/30/activate'],
            ['GET', '/locations'],
            ['POST', '/locations'],
            ['GET', '/inventory'],
            ['POST', '/inventory/stock'],
            ['GET', '/inventory/counts'],
            ['POST', '/inventory/counts'],
        ];

        self::assertCount(12, $operations);

        foreach ($operations as [$method, $path]) {
            self::assertTrue(
                $this->policy->isAllowed($method, $path, 'administrador'),
                "administrador should be allowed on {$method} {$path}"
            );
        }
    }

    public function testBodegueroAllowedAndDeniedOperations(): void
    {
        $allowed = [
            ['GET', '/products'],
            ['GET', '/locations'],
            ['POST', '/locations'],
            ['GET', '/inventory'],
            ['POST', '/inventory/stock'],
            ['GET', '/inventory/counts'],
            ['POST', '/inventory/counts'],
        ];

        $denied = [
            ['POST', '/categories'],
            ['POST', '/products'],
            ['POST', '/products/1/price'],
            ['POST', '/products/1/deactivate'],
            ['POST', '/products/1/activate'],
        ];

        foreach ($allowed as [$method, $path]) {
            self::assertTrue(
                $this->policy->isAllowed($method, $path, 'bodeguero'),
                "bodeguero should be allowed on {$method} {$path}"
            );
        }

        foreach ($denied as [$method, $path]) {
            self::assertFalse(
                $this->policy->isAllowed($method, $path, 'bodeguero'),
                "bodeguero should be denied on {$method} {$path}"
            );
        }
    }

    public function testCajeroAllowedAndDeniedOperations(): void
    {
        self::assertTrue(
            $this->policy->isAllowed('GET', '/products', 'cajero'),
            'cajero should be allowed on GET /products'
        );

        $denied = [
            ['POST', '/categories'],
            ['POST', '/products'],
            ['POST', '/products/5/price'],
            ['POST', '/products/5/deactivate'],
            ['POST', '/products/5/activate'],
            ['GET', '/locations'],
            ['POST', '/locations'],
            ['GET', '/inventory'],
            ['POST', '/inventory/stock'],
            ['GET', '/inventory/counts'],
            ['POST', '/inventory/counts'],
        ];

        foreach ($denied as [$method, $path]) {
            self::assertFalse(
                $this->policy->isAllowed($method, $path, 'cajero'),
                "cajero should be denied on {$method} {$path}"
            );
        }
    }

    public function testComprasAllowedAndDeniedOperations(): void
    {
        self::assertTrue(
            $this->policy->isAllowed('GET', '/products', 'compras'),
            'compras should be allowed on GET /products'
        );

        $denied = [
            ['POST', '/categories'],
            ['POST', '/products'],
            ['POST', '/products/99/price'],
            ['POST', '/products/99/deactivate'],
            ['POST', '/products/99/activate'],
            ['GET', '/locations'],
            ['POST', '/locations'],
            ['GET', '/inventory'],
            ['POST', '/inventory/stock'],
            ['GET', '/inventory/counts'],
            ['POST', '/inventory/counts'],
        ];

        foreach ($denied as [$method, $path]) {
            self::assertFalse(
                $this->policy->isAllowed($method, $path, 'compras'),
                "compras should be denied on {$method} {$path}"
            );
        }
    }

    public function testUnknownRoutesFailClosedForEveryRole(): void
    {
        $roles = ['administrador', 'bodeguero', 'cajero', 'compras'];

        foreach ($roles as $role) {
            self::assertFalse($this->policy->isAllowed('GET', '/unknown', $role));
            self::assertFalse($this->policy->isAllowed('POST', '/unknown', $role));
            self::assertFalse($this->policy->isAllowed('DELETE', '/products', $role));
        }

        self::assertNull($this->policy->allowedRolesFor('GET', '/unknown'));
        self::assertNull($this->policy->allowedRolesFor('POST', '/unknown'));
        self::assertNull($this->policy->allowedRolesFor('DELETE', '/products'));
    }

    public function testNonExistentPostInventoryFailsClosed(): void
    {
        $roles = ['administrador', 'bodeguero', 'cajero', 'compras'];

        foreach ($roles as $role) {
            self::assertFalse(
                $this->policy->isAllowed('POST', '/inventory', $role),
                "POST /inventory does not exist in routes and must fail closed for {$role}"
            );
        }

        self::assertNull($this->policy->allowedRolesFor('POST', '/inventory'));
    }

    public function testWrongHttpMethodIsDeniedEvenForAdmin(): void
    {
        self::assertFalse(
            $this->policy->isAllowed('GET', '/categories', 'administrador'),
            'GET /categories must be denied because /categories only exists for POST'
        );
        self::assertNull($this->policy->allowedRolesFor('GET', '/categories'));
    }

    public function testDynamicProductRoutesMatchVariousIds(): void
    {
        self::assertTrue($this->policy->isAllowed('POST', '/products/1/price', 'administrador'));
        self::assertTrue($this->policy->isAllowed('POST', '/products/42/deactivate', 'administrador'));
        self::assertTrue($this->policy->isAllowed('POST', '/products/999/activate', 'administrador'));

        self::assertSame(['administrador'], $this->policy->allowedRolesFor('POST', '/products/1/price'));
        self::assertSame(['administrador'], $this->policy->allowedRolesFor('POST', '/products/42/deactivate'));
        self::assertSame(['administrador'], $this->policy->allowedRolesFor('POST', '/products/999/activate'));

        // Bodeguero denied on dynamic product mutations
        self::assertFalse($this->policy->isAllowed('POST', '/products/1/price', 'bodeguero'));
        self::assertFalse($this->policy->isAllowed('POST', '/products/42/deactivate', 'bodeguero'));
        self::assertFalse($this->policy->isAllowed('POST', '/products/999/activate', 'bodeguero'));
    }

    public function testCanNavigateMethod(): void
    {
        // Cajero can navigate products but not inventory
        self::assertTrue($this->policy->canNavigate('/products', 'cajero'));
        self::assertFalse($this->policy->canNavigate('/inventory', 'cajero'));

        // Bodeguero can navigate inventory
        self::assertTrue($this->policy->canNavigate('/inventory', 'bodeguero'));

        // Administrador can navigate locations
        self::assertTrue($this->policy->canNavigate('/locations', 'administrador'));

        // Mutation-only paths cannot be navigated
        self::assertFalse($this->policy->canNavigate('/inventory/stock', 'administrador'));
        self::assertFalse($this->policy->canNavigate('POST /inventory/stock', 'administrador'));
        self::assertFalse($this->policy->canNavigate('/categories', 'administrador'));

        // Unknown destination cannot be navigated
        self::assertFalse($this->policy->canNavigate('/unknown', 'administrador'));
        self::assertFalse($this->policy->canNavigate('/unknown', 'cajero'));
    }
}
