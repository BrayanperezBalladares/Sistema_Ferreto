<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Modules\Access\AuthGuard;
use App\Modules\Access\RoleGuard;

final readonly class Kernel
{
    private HealthAccessPolicy $healthPolicy;

    public function __construct(
        private Router $router,
        private Csrf $csrf,
        private ErrorMapper $errors,
        private ?AuthGuard $authGuard = null,
        private ?RoleGuard $roleGuard = null,
        ?HealthAccessPolicy $healthPolicy = null,
    ) {
        $this->healthPolicy = $healthPolicy ?? new HealthAccessPolicy();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request, function (Request $matched): ?Response {
                if ($matched->method === 'POST' && $matched->path === '/health' && !$this->healthPolicy->allowsDiagnosticPost()) {
                    return new Response(405, ['Allow' => 'GET'], 'Method Not Allowed');
                }

                $principal = null;
                if ($this->authGuard !== null) {
                    $authResponse = $this->authGuard->check($matched, $principal);
                    if ($authResponse !== null) {
                        return $authResponse;
                    }
                }

                if ($this->roleGuard !== null && $principal !== null) {
                    $roleResponse = $this->roleGuard->check($matched, $principal);
                    if ($roleResponse !== null) {
                        return $roleResponse;
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
