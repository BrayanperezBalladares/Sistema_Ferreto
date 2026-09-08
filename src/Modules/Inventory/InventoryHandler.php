<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use PDOException;

final readonly class InventoryHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private StockQuery $stockQuery,
        private StockCommand $stockCommand,
        private ProductQuery $productQuery,
        private LocationQuery $locationQuery,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/inventory') {
            return $this->browse();
        }

        if ($request->method === 'POST' && $request->path === '/inventory/stock') {
            return $this->createStockPosition($request);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browse(): Response
    {
        $positions = $this->stockQuery->listOverview();
        $products = $this->productQuery->search();
        $locations = $this->locationQuery->all();

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.inventory', [
            'positions' => $positions,
            'products'  => $products,
            'locations' => $locations,
            'csrf'      => $this->csrf->token(),
        ]));
    }

    private function createStockPosition(Request $request): Response
    {
        $body = $request->body;
        $input = [
            'id_producto'  => trim($body['id_producto'] ?? ''),
            'id_ubicacion' => trim($body['id_ubicacion'] ?? ''),
            'cantidad'     => trim($body['cantidad'] ?? ''),
        ];

        $errors = [];

        $prodId = filter_var($input['id_producto'], FILTER_VALIDATE_INT);
        if ($prodId === false || $prodId <= 0) {
            $errors['id_producto'] = 'Debe seleccionar un producto válido.';
        } elseif ($this->productQuery->findById((int) $prodId) === null) {
            $errors['id_producto'] = 'El producto seleccionado no existe.';
        }

        $locId = filter_var($input['id_ubicacion'], FILTER_VALIDATE_INT);
        if ($locId === false || $locId <= 0) {
            $errors['id_ubicacion'] = 'Debe seleccionar una ubicación válida.';
        } elseif ($this->locationQuery->findById((int) $locId) === null) {
            $errors['id_ubicacion'] = 'La ubicación seleccionada no existe.';
        }

        if ($input['cantidad'] === '') {
            $errors['cantidad'] = 'La cantidad es obligatoria.';
        } else {
            $qtyResult = StockValidator::validateQuantity($input['cantidad']);
            if (!$qtyResult->valid()) {
                $errors['cantidad'] = 'La cantidad debe ser mayor o igual a 0 y puede tener hasta 3 decimales.';
            }
        }

        if (!empty($errors)) {
            return $this->renderWithErrors($errors, $input);
        }

        $prodId = (int) $prodId;
        $locId = (int) $locId;
        $cantidad = $input['cantidad'];

        if ($this->stockQuery->getPosition($prodId, $locId) !== null) {
            return $this->renderWithErrors([
                'general' => 'Ya existe una posición de stock para este producto en esta ubicación.',
            ], $input);
        }

        try {
            $this->stockCommand->createPosition($prodId, $locId, $cantidad);
        } catch (PDOException $e) {
            $driverCode = isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062 && str_contains($e->getMessage(), 'uq_stock_producto_ubicacion')) {
                return $this->renderWithErrors([
                    'general' => 'Ya existe una posición de stock para este producto en esta ubicación.',
                ], $input);
            }
            throw $e;
        }

        return $this->mutationSuccess($request, 'Existencia registrada correctamente.');
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
        ], $this->renderer->render('page.inventory', [
            'positions' => $this->stockQuery->listOverview(),
            'products'  => $this->productQuery->search(),
            'locations' => $this->locationQuery->all(),
            'csrf'      => $this->csrf->token(),
            'errors'    => $errors,
            'input'     => $input,
        ]));
    }

    private function mutationSuccess(Request $request, string $message): Response
    {
        $trigger = (string) json_encode(['notification' => ['message' => $message, 'level' => 'success']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $request->isHtmx()
            ? new Response(200, ['HX-Redirect' => '/inventory', 'HX-Trigger' => $trigger])
            : Response::redirect('/inventory');
    }
}
