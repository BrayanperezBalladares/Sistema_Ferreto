<?php

declare(strict_types=1);

namespace App\Modules\Access;

/**
 * Canonical route authorization policy for R1 operations.
 *
 * This class provides the single authoritative mapping between HTTP methods, route path
 * patterns, and allowed roles based on actual `config/routes.php` and `design.md`.
 *
 * IMPORTANT ARCHITECTURAL NOTE:
 * Future RoleGuard (Phase E2) and UI navigation/control-rendering logic (Phase G)
 * MUST consume this policy directly rather than recreating or duplicating the authorization matrix.
 */
final class RouteAccessPolicy
{
    /**
     * Canonical authorization matrix for R1 business operations.
     *
     * @var list<array{0: string, 1: string, 2: list<string>}>
     */
    private const array MATRIX = [
        ['GET', '/products', ['administrador', 'bodeguero', 'cajero', 'compras']],
        ['POST', '/categories', ['administrador']],
        ['POST', '/products', ['administrador']],
        ['POST', '/products/{id}/price', ['administrador']],
        ['POST', '/products/{id}/deactivate', ['administrador']],
        ['POST', '/products/{id}/activate', ['administrador']],
        ['GET', '/locations', ['administrador', 'bodeguero']],
        ['POST', '/locations', ['administrador', 'bodeguero']],
        ['GET', '/inventory', ['administrador', 'bodeguero']],
        ['POST', '/inventory/stock', ['administrador', 'bodeguero']],
        ['GET', '/inventory/counts', ['administrador', 'bodeguero']],
        ['POST', '/inventory/counts', ['administrador', 'bodeguero']],
    ];

    /**
     * Determines whether the given role is allowed to access the specified route method and path.
     * Unknown methods or paths fail closed and return false.
     *
     * @param string $method HTTP method (e.g., 'GET', 'POST')
     * @param string $path Request path (e.g., '/products/1/price')
     * @param string $role User role (e.g., 'administrador', 'bodeguero', 'cajero', 'compras')
     */
    public function isAllowed(string $method, string $path, string $role): bool
    {
        $allowedRoles = $this->allowedRolesFor($method, $path);
        if ($allowedRoles === null) {
            return false;
        }

        return in_array($role, $allowedRoles, true);
    }

    /**
     * Strictly derives from isAllowed('GET', $path, $role).
     * Must only permit appropriate GET navigation destinations.
     *
     * @param string $path Navigation destination path
     * @param string $role User role
     */
    public function canNavigate(string $path, string $role): bool
    {
        return $this->isAllowed('GET', $path, $role);
    }

    /**
     * Resolves the list of allowed roles for a given HTTP method and path pattern.
     * Unknown methods or paths fail closed and return null.
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @return list<string>|null
     */
    public function allowedRolesFor(string $method, string $path): ?array
    {
        $normalizedMethod = strtoupper(trim($method));
        foreach (self::MATRIX as [$routeMethod, $routePath, $roles]) {
            if ($routeMethod !== $normalizedMethod) {
                continue;
            }
            if ($this->matchPath($routePath, $path)) {
                return $roles;
            }
        }

        return null;
    }

    /**
     * Deterministic route pattern matching consistent with Router.
     * Exact match first if no '{', otherwise matches parameterized segments like '{id}'.
     */
    private function matchPath(string $routePath, string $requestPath): bool
    {
        if ($routePath === $requestPath) {
            return true;
        }
        if (!str_contains($routePath, '{')) {
            return false;
        }
        $pattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $routePath) . '$#';
        return preg_match($pattern, $requestPath) === 1;
    }
}
