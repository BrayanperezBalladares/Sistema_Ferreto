<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;

final readonly class CatalogHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private ProductQuery $productQuery,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET') {
            $q = $request->query['q'] ?? '';
            $products = $this->productQuery->search($q);

            $template = $request->isHtmx() ? 'fragment.product_table' : 'page.products';

            return new Response(200, [
                'Content-Type'  => 'text/html; charset=UTF-8',
                'Vary'          => 'HX-Request',
                'Cache-Control' => 'no-store',
            ], $this->renderer->render($template, [
                'products' => $products,
                'query'    => $q,
            ]));
        }

        return new Response(405, ['Allow' => 'GET']);
    }
}
