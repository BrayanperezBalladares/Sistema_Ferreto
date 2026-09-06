<?php

declare(strict_types=1);

namespace App\Foundation;

final class Router
{
    /**
     * @var list<array{string, string, callable(Request): Response}>
     */
    private array $routes = [];

    /**
     * @param list<array{string, string, callable(Request): Response}> $routes
     */
    public function __construct(array $routes)
    {
        foreach ($routes as [$method, $path, $handler]) {
            foreach ($this->routes as [$knownMethod, $knownPath]) {
                if ($method === $knownMethod && $path === $knownPath) {
                    throw new \InvalidArgumentException('Duplicate route registration.');
                }
            }
            $this->routes[] = [$method, $path, $handler];
        }
    }

    /**
     * @param (callable(Request): ?Response)|null $before
     */
    public function dispatch(Request $request, ?callable $before = null): Response
    {
        $lowerPath = strtolower($request->path);
        if (
            str_contains($lowerPath, '..')
            || str_contains($lowerPath, '%2e')
            || str_contains($lowerPath, '%2f')
            || str_contains($lowerPath, '%5c')
            || str_contains($lowerPath, '\\')
        ) {
            return new Response(400);
        }

        $allowed = [];
        foreach ($this->routes as [$method, $path, $handler]) {
            if ($path !== $request->path) {
                continue;
            }
            $allowed[] = $method;
            if ($method === $request->method) {
                if ($before !== null) {
                    $earlyResponse = $before($request);
                    if ($earlyResponse !== null) {
                        return $earlyResponse;
                    }
                }

                return $handler($request);
            }
        }

        return $allowed === []
            ? new Response(404)
            : new Response(405, ['Allow' => implode(', ', $allowed)]);
    }
}
