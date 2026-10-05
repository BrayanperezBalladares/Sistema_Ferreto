<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use DomainException;
use InvalidArgumentException;

final readonly class BranchHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private SucursalQuery $sucursalQuery,
        private SucursalCommand $sucursalCommand,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/branches') {
            return $this->browse();
        }

        if ($request->method === 'POST' && $request->path === '/branches') {
            return $this->createBranch($request);
        }

        if ($request->method === 'POST' && $request->path === '/branches/toggle-active') {
            return $this->toggleActive($request);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browse(): Response
    {
        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.branches', [
            'branches' => $this->sucursalQuery->all(),
            'csrf'     => $this->csrf->token(),
        ]));
    }

    private function createBranch(Request $request): Response
    {
        $validation = BranchValidator::validateBranch($request->body);
        if (!$validation->valid()) {
            return $this->renderWithErrors($validation->fieldErrors, $validation->safeInput);
        }

        $code = $validation->safeInput['codigo'];
        if ($this->sucursalQuery->findByCode($code) !== null) {
            return $this->renderWithErrors(
                ['codigo' => 'Ya existe una sucursal con ese código.'],
                $validation->safeInput
            );
        }

        $nombre = $validation->safeInput['nombre'];
        $ciudad = $validation->safeInput['ciudad'];
        $direccion = $validation->safeInput['direccion'] !== '' ? $validation->safeInput['direccion'] : null;
        $telefono = $validation->safeInput['telefono'] !== '' ? $validation->safeInput['telefono'] : null;

        try {
            $this->sucursalCommand->create($code, $nombre, $ciudad, $direccion, $telefono);
        } catch (DomainException $e) {
            return $this->renderWithErrors(
                ['codigo' => 'Ya existe una sucursal con ese código.'],
                $validation->safeInput
            );
        } catch (InvalidArgumentException $e) {
            return $this->renderWithErrors(
                ['nombre' => $e->getMessage()],
                $validation->safeInput
            );
        }

        return $this->mutationSuccess($request, 'Sucursal registrada correctamente.');
    }

    private function toggleActive(Request $request): Response
    {
        $rawId = $request->body['id_sucursal'] ?? null;
        $isValidId = is_string($rawId) && ctype_digit(trim($rawId)) && (int) trim($rawId) > 0;

        if (!$isValidId) {
            return $this->renderWithErrors(['general' => 'Identificador de sucursal no válido.'], []);
        }

        $idSucursal = (int) $rawId;

        $branch = $this->sucursalQuery->findById($idSucursal);
        if ($branch === null) {
            return $this->renderWithErrors(['general' => 'La sucursal especificada no existe.'], []);
        }

        try {
            $this->sucursalCommand->toggleActive($idSucursal);
        } catch (DomainException $e) {
            return $this->renderWithErrors(['general' => $e->getMessage()], []);
        }

        return $this->mutationSuccess($request, 'Estado de la sucursal actualizado correctamente.');
    }

    private function mutationSuccess(Request $request, string $message): Response
    {
        $trigger = (string) json_encode([
            'notification' => [
                'message' => $message,
                'level'   => 'success',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $request->isHtmx()
            ? new Response(200, ['HX-Redirect' => '/branches', 'HX-Trigger' => $trigger])
            : Response::redirect('/branches');
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
        ], $this->renderer->render('page.branches', [
            'branches' => $this->sucursalQuery->all(),
            'csrf'     => $this->csrf->token(),
            'errors'   => $errors,
            'input'    => $input,
        ]));
    }
}
