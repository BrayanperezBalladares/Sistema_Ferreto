<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class ErrorMapper
{
    public function __construct(private Logger $logger, private Renderer $renderer)
    {
    }

    public function map(\Throwable $exception, string $route): Response
    {
        $id = bin2hex(random_bytes(8));
        $this->logger->error($id, $route, $exception);

        try {
            $body = $this->renderer->render('error', ['correlationId' => $id]);
        } catch (\Throwable) {
            $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Request failed</title></head>'
                . '<body><main><h1>Request failed</h1><p>Reference: '
                . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p></main></body></html>';
        }

        return new Response(500, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
