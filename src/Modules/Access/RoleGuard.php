<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\Request;
use App\Foundation\Response;

final readonly class RoleGuard
{
    public function __construct(
        private RouteAccessPolicy $routePolicy,
    ) {
    }

    /**
     * Determines whether the given request is exempt from business role authorization.
     * Public routes and non-business access actions (such as logout) bypass role checks.
     */
    public function isExempt(Request $request): bool
    {
        $path = strtolower($request->path);

        if ($path === '/login' || $path === '/health' || $path === '/logout') {
            return true;
        }

        if (str_starts_with($path, '/assets/')) {
            return true;
        }

        return false;
    }

    /**
     * Evaluates role authorization for the current authenticated principal.
     *
     * Returns null if access is allowed (route is exempt, or principal's role is authorized).
     * Returns HTTP 403 Forbidden if the principal's role is unauthorized for the requested route.
     *
     * @param array{
     *     id_usuario: int,
     *     username: string,
     *     rol: string,
     *     estado: string
     * } $principal The sanitized authenticated principal from AuthGuard
     */
    public function check(Request $request, array $principal): ?Response
    {
        if ($this->isExempt($request)) {
            return null;
        }

        // Only enforce role authorization for defined business routes in RouteAccessPolicy
        if ($this->routePolicy->allowedRolesFor($request->method, $request->path) === null) {
            return null;
        }

        if (!$this->routePolicy->isAllowed($request->method, $request->path, $principal['rol'])) {
            return new Response(403, [], 'Forbidden');
        }

        return null;
    }
}
