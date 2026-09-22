<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;

final readonly class AccessHandler implements Handler
{
    private const string GENERIC_ERROR = 'Credenciales incorrectas o cuenta no autorizada.';

    public function __construct(
        private Renderer $renderer,
        private Authenticator $authenticator,
        private AuthSession $authSession,
        private Csrf $csrf,
        private RouteAccessPolicy $routePolicy,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->path === '/login') {
            if ($request->method === 'GET') {
                return $this->handleGetLogin();
            }
            if ($request->method === 'POST') {
                return $this->handlePostLogin($request);
            }
            return new Response(405, ['Allow' => 'GET, POST']);
        }

        if ($request->path === '/logout') {
            if ($request->method === 'POST') {
                return $this->handlePostLogout($request);
            }
            return new Response(405, ['Allow' => 'POST']);
        }

        return new Response(405, ['Allow' => 'GET, POST']);
    }

    private function handleGetLogin(): Response
    {
        if ($this->authSession->peekUser() !== null) {
            return Response::redirect('/products', 303);
        }

        return $this->renderLogin(200, '', null);
    }

    private function handlePostLogin(Request $request): Response
    {
        if (!$this->csrf->valid($request)) {
            return new Response(403, [], 'Forbidden');
        }

        $username = trim($request->body['username'] ?? '');
        $password = $request->body['password'] ?? '';

        $identity = $this->authenticator->authenticate($username, $password);
        if ($identity === null) {
            return $this->renderLogin(422, $username, self::GENERIC_ERROR);
        }

        $context = $this->authSession->establish($identity);
        if ($context === null) {
            return $this->renderLogin(422, $username, self::GENERIC_ERROR);
        }

        $destination = '/products';
        $target = $this->authSession->pullTargetUrl();
        if ($target !== null) {
            $isValidRelative = str_starts_with($target, '/')
                && !str_starts_with($target, '//')
                && !str_starts_with($target, '/\\')
                && !str_contains($target, '\\')
                && strpbrk($target, "\r\n\t\0") === false;

            if ($isValidRelative) {
                $path = parse_url($target, PHP_URL_PATH);
                if (is_string($path) && $this->routePolicy->canNavigate($path, $context['rol'])) {
                    $destination = $target;
                }
            }
        }

        return Response::redirect($destination, 303);
    }

    private function handlePostLogout(Request $request): Response
    {
        if (!$this->csrf->valid($request)) {
            return new Response(403, [], 'Forbidden');
        }

        if ($this->authSession->peekUser() !== null) {
            $this->authSession->logout();
        }

        return Response::redirect('/login', 303);
    }

    private function renderLogin(int $status, string $username, ?string $error): Response
    {
        return new Response($status, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Vary'          => 'HX-Request',
            'Cache-Control' => 'no-store',
        ], $this->renderer->render('page.login', [
            'csrf'     => $this->csrf->token(),
            'username' => $username,
            'error'    => $error,
        ]));
    }
}
