<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;

final readonly class CatalogHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private ProductQuery $productQuery,
        private CategoryQuery $categoryQuery,
        private CategoryCommand $categoryCommand,
        private ProductCommand $productCommand,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/products') {
            return $this->browse($request);
        }

        if ($request->method === 'POST') {
            if ($request->path === '/categories') {
                return $this->createCategory($request);
            }
            if ($request->path === '/products') {
                return $this->createProduct($request);
            }
            if (preg_match('#^/products/(\d+)/price$#', $request->path, $matches) === 1) {
                return $this->updatePrice($request, (int) $matches[1]);
            }
            if (preg_match('#^/products/(\d+)/deactivate$#', $request->path, $matches) === 1) {
                return $this->deactivate($request, (int) $matches[1]);
            }
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function browse(Request $request): Response
    {
        $q = $request->query['q'] ?? '';
        $products = $this->productQuery->search($q);
        $categories = $this->categoryQuery->all();

        $template = $request->isHtmx() ? 'fragment.product_table' : 'page.products';

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render($template, [
            'products'   => $products,
            'categories' => $categories,
            'query'      => $q,
            'csrf'       => $this->csrf->token(),
        ]));
    }

    private function createCategory(Request $request): Response
    {
        $validation = CatalogValidator::validateCategory($request->body);
        if (!$validation->valid()) {
            return $this->renderWithErrors($request, $validation->fieldErrors, $validation->safeInput);
        }

        $name = $validation->safeInput['nombre'];
        if ($this->categoryQuery->findByName($name) !== null) {
            return $this->renderWithErrors($request, ['nombre' => 'Category name already exists.'], $validation->safeInput);
        }

        $desc = ($validation->safeInput['descripcion'] ?? '') !== '' ? $validation->safeInput['descripcion'] : null;
        $this->categoryCommand->create($name, $desc);

        return $this->mutationSuccess($request, 'Category created successfully.');
    }

    private function createProduct(Request $request): Response
    {
        $validation = CatalogValidator::validateProduct($request->body);
        if (!$validation->valid()) {
            return $this->renderWithErrors($request, $validation->fieldErrors, $validation->safeInput);
        }

        $catId = isset($validation->safeInput['id_categoria']) ? (int) $validation->safeInput['id_categoria'] : null;
        if ($catId !== null && $this->categoryQuery->findById($catId) === null) {
            return $this->renderWithErrors($request, ['id_categoria' => 'Selected category does not exist.'], $validation->safeInput);
        }

        $desc = ($validation->safeInput['descripcion'] ?? '') !== '' ? $validation->safeInput['descripcion'] : null;
        $this->productCommand->register($validation->safeInput['nombre'], $validation->safeInput['precio_actual'], $catId, $desc);

        return $this->mutationSuccess($request, 'Product registered successfully.');
    }

    private function updatePrice(Request $request, int $id): Response
    {
        if ($this->productQuery->findById($id) === null) {
            return new Response(404);
        }

        $price = is_string($request->body['precio_actual'] ?? null) ? $request->body['precio_actual'] : '';
        $validation = CatalogValidator::validatePrice($price);
        if (!$validation->valid()) {
            return $this->renderWithErrors($request, $validation->fieldErrors, [
                'id_producto'   => (string) $id,
                'precio_actual' => $price,
            ]);
        }

        $this->productCommand->updatePrice($id, $validation->safeInput['precio_actual']);

        return $this->mutationSuccess($request, 'Price updated successfully.');
    }

    private function deactivate(Request $request, int $id): Response
    {
        if ($this->productQuery->findById($id) === null) {
            return new Response(404);
        }

        $this->productCommand->deactivate($id);

        return $this->mutationSuccess($request, 'Product deactivated successfully.');
    }

    private function mutationSuccess(Request $request, string $message): Response
    {
        $trigger = (string) json_encode(['notification' => ['message' => $message, 'level' => 'success']], JSON_THROW_ON_ERROR);
        return $request->isHtmx()
            ? new Response(200, ['HX-Redirect' => '/products', 'HX-Trigger' => $trigger])
            : Response::redirect('/products');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $input
     */
    private function renderWithErrors(Request $request, array $errors, array $input): Response
    {
        $template = $request->isHtmx() ? 'fragment.product_table' : 'page.products';

        return new Response(422, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render($template, [
            'products'   => $this->productQuery->search(),
            'categories' => $this->categoryQuery->all(),
            'query'      => '',
            'csrf'       => $this->csrf->token(),
            'errors'     => $this->localizeErrors($errors),
            'input'      => $input,
        ]));
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function localizeErrors(array $errors): array
    {
        $localized = [];
        foreach ($errors as $field => $msg) {
            $localized[$field] = match ($msg) {
                'Category name already exists.' => 'Ya existe una categoría con ese nombre.',
                'Selected category does not exist.' => 'La categoría seleccionada no existe.',
                'Product name is required.' => 'El nombre del producto es obligatorio.',
                'Product name must not exceed 150 characters.' => 'El nombre del producto no debe exceder los 150 caracteres.',
                'Category name is required.' => 'El nombre de la categoría es obligatorio.',
                'Category name must not exceed 100 characters.' => 'El nombre de la categoría no debe exceder los 100 caracteres.',
                'Price must be a valid non-negative decimal with at most 2 fractional digits.' => 'El precio debe ser mayor o igual a 0 y puede tener hasta 2 decimales.',
                'Category ID must be a positive integer.' => 'La categoría seleccionada no es válida.',
                default => $msg,
            };
        }
        return $localized;
    }
}
