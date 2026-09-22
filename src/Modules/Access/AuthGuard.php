<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\Request;
use App\Foundation\Response;

final readonly class AuthGuard
{
    public function __construct(
        private AuthSession $authSession,
    ) {
    }

    /**
     * Determines whether the given request targets a publicly accessible route.
     * Public routes bypass authentication enforcement.
     */
    public function isPublicRoute(Request $request): bool
    {
        $path = strtolower($request->path);

        if ($path === '/login' || $path === '/health') {
            return true;
        }

        if (str_starts_with($path, '/assets/')) {
            return true;
        }

        return false;
    }

    /**
     * Evaluates authentication for the current request.
     *
     * Returns null if access is allowed (public route or valid authenticated principal).
     * Returns a Response when access is denied:
     * - Standard browser request: 303 See Other redirecting to /login.
     * - HTMX request (HX-Request: true): HTTP 200 with HX-Redirect: /login and empty body.
     */
    public function check(Request $request): ?Response
    {
        if ($this->isPublicRoute($request)) {
            return null;
        }

        // Must invoke user() on protected requests to enforce persistent revalidation,
        // role-aware inactivity expiration, and activity timestamp refresh.
        $user = $this->authSession->user();
        if ($user !== null) {
            return null;
        }

        // Remember safe navigable GET targets for post-login redirection.
        // Incidental HTMX requests and mutations (POST) are never stored as return targets.
        if (!$request->isHtmx() && $request->method === 'GET' && $this->isNavigablePath($request->path)) {
            $this->authSession->setTargetUrl($request->path);
        }

        if ($request->isHtmx()) {
            return new Response(200, ['HX-Redirect' => '/login'], '');
        }

        return Response::redirect('/login', 303);
    }

    /**
     * Validates that a path is a safe internal relative path.
     */
    private function isNavigablePath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_starts_with($path, '/\\')
            && !str_contains($path, '\\')
            && strpbrk($path, "\r\n\t\0") === false;
    }
}
