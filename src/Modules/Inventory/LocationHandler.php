<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;

final readonly class LocationHandler implements Handler
{
    public function __construct(
        private Renderer $renderer,
        private LocationQuery $locationQuery,
        private Csrf $csrf,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/locations') {
            return $this->browse();
        }

        return new Response(405, ['Allow' => 'GET']);
    }

    private function browse(): Response
    {
        $locations = $this->locationQuery->all();

        return new Response(200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.locations', [
            'locations' => $locations,
            'csrf'      => $this->csrf->token(),
        ]));
    }
}
