<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Modules\Access\AuthGuard;

final readonly class Kernel
{
    public function __construct(
        private Router $router,
        private Csrf $csrf,
        private ErrorMapper $errors,
        private ?AuthGuard $authGuard = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request, function (Request $matched): ?Response {
                if ($this->authGuard !== null) {
                    $authResponse = $this->authGuard->check($matched);
                    if ($authResponse !== null) {
                        return $authResponse;
                    }
                }

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
