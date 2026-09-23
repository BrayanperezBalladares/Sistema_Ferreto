<?php

declare(strict_types=1);

namespace App\Foundation;

final readonly class HealthHandler implements Handler
{
    private HealthAccessPolicy $healthPolicy;

    public function __construct(
        private Renderer $renderer,
        private Csrf $csrf,
        private Session $session,
        ?HealthAccessPolicy $healthPolicy = null,
    ) {
        $this->healthPolicy = $healthPolicy ?? new HealthAccessPolicy();
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'GET') {
            return $this->html($request, new ValidationResult([], []), $this->session->remove('flash'));
        }

        if (!$this->healthPolicy->allowsDiagnosticPost()) {
            return new Response(405, ['Allow' => 'GET'], 'Method Not Allowed');
        }

        $input = trim($request->body['probe'] ?? '');
        $errors = $input === ''
            ? ['probe' => 'Enter a probe value.']
            : (mb_strlen($input) > 80 ? ['probe' => 'Use 80 characters or fewer.'] : []);

        $result = new ValidationResult(['probe' => mb_substr($input, 0, 80)], $errors);
        if (!$result->valid()) {
            return $this->html($request, $result, null, 422);
        }

        $this->session->set('flash', 'Foundation check completed.');

        return $request->isHtmx()
            ? new Response(200, [
                'HX-Redirect' => '/health',
                'HX-Trigger' => json_encode(
                    ['notification' => ['message' => 'Foundation check completed.', 'level' => 'success']],
                    JSON_THROW_ON_ERROR
                ),
            ])
            : Response::redirect('/health');
    }

    private function html(Request $request, ValidationResult $result, mixed $message, int $status = 200): Response
    {
        $name = $request->isHtmx() ? 'fragment.health' : 'page.health';

        return new Response($status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render($name, [
            'result' => $result,
            'csrf' => $this->csrf->token(),
            'message' => $message,
        ]));
    }
}
