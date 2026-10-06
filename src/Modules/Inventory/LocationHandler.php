<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use DomainException;

final readonly class LocationHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private LocationQuery $locationQuery,
        private LocationCommand $locationCommand,
        private AlmacenQuery $almacenQuery,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/locations') {
            return $this->browse();
        }

        if ($request->method === 'POST' && $request->path === '/locations') {
            return $this->createLocation($request);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browse(): Response
    {
        $locations = $this->locationQuery->all();
        $warehouses = $this->almacenQuery->findActive();

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.locations', [
            'locations'  => $locations,
            'warehouses' => $warehouses,
            'csrf'       => $this->csrf->token(),
        ]));
    }

    private function createLocation(Request $request): Response
    {
        $validation = LocationValidator::validateLocation($request->body);
        if (!$validation->valid()) {
            return $this->renderWithErrors($validation->fieldErrors, $validation->safeInput);
        }

        $code = $validation->safeInput['codigo'];
        if ($this->locationQuery->findByCode($code) !== null) {
            return $this->renderWithErrors(
                ['codigo' => 'Ya existe una ubicación con ese código.'],
                $validation->safeInput
            );
        }

        $idAlmacen = (int) $validation->safeInput['id_almacen'];
        $desc = ($validation->safeInput['descripcion'] ?? '') !== '' ? $validation->safeInput['descripcion'] : null;

        try {
            $this->locationCommand->create($code, $idAlmacen, $desc);
        } catch (DomainException $e) {
            $field = str_contains($e->getMessage(), 'código') ? 'codigo' : 'id_almacen';
            return $this->renderWithErrors(
                [$field => $e->getMessage()],
                $validation->safeInput
            );
        }

        return $this->mutationSuccess($request, 'Ubicación registrada correctamente.');
    }

    private function mutationSuccess(Request $request, string $message): Response
    {
        $trigger = (string) json_encode(['notification' => ['message' => $message, 'level' => 'success']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $request->isHtmx()
            ? new Response(200, ['HX-Redirect' => '/locations', 'HX-Trigger' => $trigger])
            : Response::redirect('/locations');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $input
     */
    private function renderWithErrors(array $errors, array $input): Response
    {
        return new Response(422, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.locations', [
            'locations'  => $this->locationQuery->all(),
            'warehouses' => $this->almacenQuery->findActive(),
            'csrf'       => $this->csrf->token(),
            'errors'     => $errors,
            'input'      => $input,
        ]));
    }
}
