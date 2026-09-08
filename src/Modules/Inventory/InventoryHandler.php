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
        private ?CountQuery $countQuery = null,
        private ?CountCommand $countCommand = null,
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

        if ($request->method === 'GET' && $request->path === '/inventory/counts') {
            return $this->browseCounts($request);
        }

        if ($request->method === 'POST' && $request->path === '/inventory/counts') {
            return $this->recordCount($request);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browseCounts(Request $request): Response
    {
        $stockParam = $request->query['stock'] ?? null;
        if ($stockParam === '') {
            return Response::redirect('/inventory/counts');
        }

        $positions = $this->stockQuery->listOverview();
        $selectedStock = null;
        $counts = [];

        if (is_string($stockParam)) {
            $stockId = filter_var($stockParam, FILTER_VALIDATE_INT);
            if ($stockId !== false && $stockId > 0) {
                $selectedStock = $this->stockQuery->findById((int) $stockId);
                if ($selectedStock !== null && $this->countQuery !== null) {
                    $counts = $this->countQuery->listByStock((int) $selectedStock['id_stock']);
                }
            }
        }

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.counts', [
            'positions'     => $positions,
            'selectedStock' => $selectedStock,
            'counts'        => $counts,
            'csrf'          => $this->csrf->token(),
        ]));
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

    private function recordCount(Request $request): Response
    {
        $body = $request->body;
        $input = [
            'id_stock'         => trim($body['id_stock'] ?? ''),
            'cantidad_contada' => trim($body['cantidad_contada'] ?? ''),
            'notas'            => isset($body['notas']) ? trim((string) $body['notas']) : '',
        ];

        $errors = [];
        $stockId = filter_var($input['id_stock'], FILTER_VALIDATE_INT);
        $selectedStock = null;
        if ($stockId === false || $stockId <= 0) {
            $errors['id_stock'] = 'Debe seleccionar una existencia válida.';
        } else {
            $selectedStock = $this->stockQuery->findById((int) $stockId);
            if ($selectedStock === null) {
                $errors['id_stock'] = 'La existencia seleccionada no existe.';
            }
        }

        if ($input['cantidad_contada'] === '') {
            $errors['cantidad_contada'] = 'La cantidad contada debe ser mayor o igual a 0 y puede tener hasta 3 decimales.';
        } else {
            $qtyResult = StockValidator::validateQuantity($input['cantidad_contada']);
            if (!$qtyResult->valid()) {
                $errors['cantidad_contada'] = 'La cantidad contada debe ser mayor o igual a 0 y puede tener hasta 3 decimales.';
            }
        }

        if (!empty($errors)) {
            return $this->renderCountsWithErrors($errors, $input, $selectedStock);
        }

        $notas = $input['notas'] !== '' ? $input['notas'] : null;

        if ($this->countCommand !== null) {
            try {
                $this->countCommand->record((int) $selectedStock['id_stock'], $input['cantidad_contada'], $notas);
            } catch (\RuntimeException $e) {
                return $this->renderCountsWithErrors(['general' => 'La existencia seleccionada no existe.'], $input, $selectedStock);
            }
        }

        return $this->countMutationSuccess($request, (int) $selectedStock['id_stock'], 'Conteo registrado correctamente.');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $input
     * @param array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}|null $selectedStock
     */
    private function renderCountsWithErrors(array $errors, array $input, ?array $selectedStock): Response
    {
        $positions = $this->stockQuery->listOverview();
        $counts = [];
        if ($selectedStock !== null && $this->countQuery !== null) {
            $counts = $this->countQuery->listByStock((int) $selectedStock['id_stock']);
        }

        return new Response(422, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.counts', [
            'positions'     => $positions,
            'selectedStock' => $selectedStock,
            'counts'        => $counts,
            'csrf'          => $this->csrf->token(),
            'errors'        => $errors,
            'input'         => $input,
        ]));
    }

    private function countMutationSuccess(Request $request, int $stockId, string $message): Response
    {
        $redirectUrl = '/inventory/counts?stock=' . $stockId;
        $trigger = (string) json_encode(['notification' => ['message' => $message, 'level' => 'success']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $request->isHtmx()
            ? new Response(200, ['HX-Redirect' => $redirectUrl, 'HX-Trigger' => $trigger])
            : Response::redirect($redirectUrl);
    }
}
