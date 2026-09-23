<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Modules\Access\AuthGuard;
use App\Modules\Access\RoleGuard;
use App\Modules\Access\RouteAccessPolicy;
use App\Modules\Access\ViewPermissions;

final readonly class Kernel
{
    private HealthAccessPolicy $healthPolicy;
    private RouteAccessPolicy $routePolicy;

    public function __construct(
        private Router $router,
        private Csrf $csrf,
        private ErrorMapper $errors,
        private ?AuthGuard $authGuard = null,
        private ?RoleGuard $roleGuard = null,
        ?HealthAccessPolicy $healthPolicy = null,
        private ?ViewContext $viewContext = null,
        ?RouteAccessPolicy $routePolicy = null,
    ) {
        $this->healthPolicy = $healthPolicy ?? new HealthAccessPolicy();
        $this->routePolicy = $routePolicy ?? new RouteAccessPolicy();
    }

    public function handle(Request $request): Response
    {
        $this->viewContext?->clear();

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

                if ($this->viewContext !== null) {
                    if ($principal !== null) {
                        $this->viewContext->set('user', [
                            'username' => $principal['username'],
                            'rol' => $principal['rol'],
                        ]);
                        $this->viewContext->set('csrf', $this->csrf->token());
                        $this->viewContext->set('permissions', new ViewPermissions($this->routePolicy, $principal['rol']));
                    } else {
                        $this->viewContext->set('permissions', new ViewPermissions($this->routePolicy, null));
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
