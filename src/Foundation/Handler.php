<?php

declare(strict_types=1);

namespace App\Foundation;

interface Handler
{
    public function handle(Request $request): Response;
}
