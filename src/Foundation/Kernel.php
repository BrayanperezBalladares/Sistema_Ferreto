<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class Kernel
{
    public function __construct(private Router $router, private Csrf $csrf, private ErrorMapper $errors)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request, function (Request $matched): ?Response {
                if (!in_array($matched->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->csrf->valid($matched)) {
                    return new Response(403, [], 'Forbidden');
                }
                return null;
            });
        } catch (\Throwable $exception) {
            return $this->errors->map($exception, $request->path);
        }
    }
}
