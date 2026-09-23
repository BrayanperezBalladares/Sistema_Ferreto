<?php

declare(strict_types=1);

namespace App\Modules\Access;

/**
 * Presentation-layer permissions helper derived strictly from RouteAccessPolicy.
 *
 * Provides view templates with role-aware capability checks without exposing
 * persistence internals, session state, or raw role conditional matrices.
 */
final readonly class ViewPermissions
{
    public function __construct(
        private RouteAccessPolicy $policy,
        private ?string $role,
    ) {
    }

    /**
     * Determines whether the current role may execute the specified HTTP method and path.
     */
    public function can(string $method, string $path): bool
    {
        if ($this->role === null) {
            return false;
        }

        return $this->policy->isAllowed($method, $path, $this->role);
    }

    /**
     * Determines whether the current role may navigate to the specified GET destination.
     */
    public function canNavigate(string $path): bool
    {
        return $this->can('GET', $path);
    }

    /**
     * Invocable shorthand: $can($method, $path).
     */
    public function __invoke(string $method, string $path): bool
    {
        return $this->can($method, $path);
    }
}
