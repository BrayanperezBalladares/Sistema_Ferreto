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

final readonly class WarehouseHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private AlmacenQuery $almacenQuery,
        private AlmacenCommand $almacenCommand,
        private SucursalQuery $sucursalQuery,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/warehouses') {
            return $this->browse($request);
        }

        if ($request->method === 'POST' && $request->path === '/warehouses') {
            return $this->createWarehouse($request);
        }

        if ($request->method === 'POST' && $request->path === '/warehouses/toggle-active') {
            return $this->toggleActive($request);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browse(Request $request): Response
    {
        $rawSucursal = $request->query['sucursal'] ?? null;
        $idSucursal = null;
        if ($rawSucursal !== null && $rawSucursal !== '') {
            if (!ctype_digit(trim($rawSucursal)) || (int) trim($rawSucursal) <= 0) {
                return new Response(422, [
                    'Content-Type'  => 'text/html; charset=UTF-8',
                    'Vary'          => 'HX-Request',
                    'Cache-Control' => 'no-store',
                ], $this->renderer->render('page.warehouses', [
                    'warehouses'       => [],
                    'branches'         => $this->sucursalQuery->all(),
                    'activeBranches'   => $this->sucursalQuery->findActive(),
                    'selectedSucursal' => null,
                    'csrf'             => $this->csrf->token(),
                    'errors'           => ['general' => 'Identificador de sucursal no válido.'],
                ]));
            }
            $idSucursal = (int) $rawSucursal;
        }

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.warehouses', [
            'warehouses'       => $this->almacenQuery->all($idSucursal),
            'branches'         => $this->sucursalQuery->all(),
            'activeBranches'   => $this->sucursalQuery->findActive(),
            'selectedSucursal' => $idSucursal,
            'csrf'             => $this->csrf->token(),
        ]));
    }

    private function createWarehouse(Request $request): Response
    {
        $validation = WarehouseValidator::validateWarehouse($request->body);
        if (!$validation->valid()) {
            return $this->renderWithErrors($validation->fieldErrors, $validation->safeInput);
        }

        $idSucursal = (int) $validation->safeInput['id_sucursal'];
        $branch = $this->sucursalQuery->findById($idSucursal);
        if ($branch === null || (int) $branch['estado_activo'] !== 1) {
            return $this->renderWithErrors(
                ['id_sucursal' => 'No se puede crear un almacén en una sucursal inactiva o inexistente.'],
                $validation->safeInput
            );
        }

        $code = $validation->safeInput['codigo'];
        if ($this->almacenQuery->findByCode($code) !== null) {
            return $this->renderWithErrors(
                ['codigo' => 'Ya existe un almacén con ese código.'],
                $validation->safeInput
            );
        }

        $nombre = $validation->safeInput['nombre'];
        $tipo = $validation->safeInput['tipo'];

        try {
            $this->almacenCommand->create($idSucursal, $code, $nombre, $tipo);
        } catch (DomainException $e) {
            $field = str_contains($e->getMessage(), 'código') ? 'codigo' : 'id_sucursal';
            return $this->renderWithErrors(
                [$field => $e->getMessage()],
                $validation->safeInput
            );
        } catch (InvalidArgumentException $e) {
            return $this->renderWithErrors(
                ['nombre' => $e->getMessage()],
                $validation->safeInput
            );
        }

        return $this->mutationSuccess($request, 'Almacén registrado correctamente.');
    }

    private function toggleActive(Request $request): Response
    {
        $rawId = $request->body['id_almacen'] ?? null;
        $isValidId = is_string($rawId) && ctype_digit(trim($rawId)) && (int) trim($rawId) > 0;

        if (!$isValidId) {
            return $this->renderWithErrors(['almacen' => 'Identificador de almacén no válido.'], []);
        }

        $idAlmacen = (int) $rawId;

        $warehouse = $this->almacenQuery->findById($idAlmacen);
        if ($warehouse === null) {
            return $this->renderWithErrors(['almacen' => 'El almacén especificado no existe.'], []);
        }

        try {
            $this->almacenCommand->toggleActive($idAlmacen);
        } catch (DomainException $e) {
            return $this->renderWithErrors(['almacen' => $e->getMessage()], []);
        }

        return $this->mutationSuccess($request, 'Estado del almacén actualizado correctamente.');
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
            ? new Response(200, ['HX-Redirect' => '/warehouses', 'HX-Trigger' => $trigger])
            : Response::redirect('/warehouses');
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
        ], $this->renderer->render('page.warehouses', [
            'warehouses'       => $this->almacenQuery->all(),
            'branches'         => $this->sucursalQuery->all(),
            'activeBranches'   => $this->sucursalQuery->findActive(),
            'selectedSucursal' => null,
            'csrf'             => $this->csrf->token(),
            'errors'           => $errors,
            'input'            => $input,
        ]));
    }
}
