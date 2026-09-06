<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Csrf;
use App\Foundation\Handler;
use App\Foundation\HealthHandler;
use App\Foundation\Renderer;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
use App\Foundation\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    #[DataProvider('documentationPaths')]
    public function testDocumentationLikePathsAreNeverExecuted(string $path): void
    {
        $marker = sys_get_temp_dir() . '/ferreto-http-' . bin2hex(random_bytes(4));
        $router = new Router([['GET', '/health', static fn (): Response => new Response(200, [], 'ok')]]);

        self::assertSame(404, $router->dispatch(new Request('GET', $path))->status);
        self::assertFileDoesNotExist($marker);
    }

    /** @return iterable<string, array{string}> */
    public static function documentationPaths(): iterable
    {
        yield 'requirements' => ['/requirements.txt'];
        yield 'cmake' => ['/CMakeLists.txt'];
        yield 'markdown' => ['/guide.md'];
        yield 'mdx' => ['/guide.mdx'];
        yield 'shell-like readme' => ['/README.sh'];
    }

    #[DataProvider('unsafePaths')]
    public function testTraversalAndEncodedSeparatorsAreBadRequests(string $path): void
    {
        $router = new Router([]);
        self::assertSame(400, $router->dispatch(new Request('GET', $path))->status);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'traversal' => ['/../config/bootstrap.php'];
        yield 'encoded traversal' => ['/%2e%2e/config'];
        yield 'encoded slash' => ['/health%2fextra'];
        yield 'encoded backslash' => ['/health%5cextra'];
    }

    public function testUnknownAndWrongMethodHaveDifferentSemantics(): void
    {
        $router = new Router([['GET', '/health', static fn (): Response => new Response(200)]]);
        self::assertSame(404, $router->dispatch(new Request('GET', '/unknown'))->status);

        $response = $router->dispatch(new Request('DELETE', '/health'));
        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
    }

    #[DataProvider('unsafeRedirects')]
    public function testUnsafeRedirectsAreRejected(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect($target);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeRedirects(): iterable
    {
        yield 'external' => ['https://example.com'];
        yield 'protocol relative' => ['//example.com'];
        yield 'slash backslash' => ['/\\example.com'];
        yield 'carriage return' => ["/health\rInjected: yes"];
        yield 'line feed' => ["/health\nInjected: yes"];
    }

    public function testBeforeHookCanInterceptOrPassThrough(): void
    {
        $router = new Router([
            ['GET', '/health', static fn (): Response => new Response(200, [], 'health-ok')],
        ]);

        $blocked = $router->dispatch(
            new Request('GET', '/health'),
            static fn (Request $req): ?Response => new Response(403, [], 'blocked')
        );
        self::assertSame(403, $blocked->status);
        self::assertSame('blocked', $blocked->body);

        $passed = $router->dispatch(
            new Request('GET', '/health'),
            static fn (Request $req): ?Response => null
        );
        self::assertSame(200, $passed->status);
        self::assertSame('health-ok', $passed->body);
    }

    public function testHandlerInterfaceCanBeUsedAsRouteTarget(): void
    {
        $handler = new class implements Handler {
            public function handle(Request $request): Response
            {
                return new Response(200, ['X-Custom' => 'ok'], 'from-handler');
            }
        };

        $router = new Router([['GET', '/handled', $handler->handle(...)]]);
        $response = $router->dispatch(new Request('GET', '/handled'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->headers['X-Custom']);
        self::assertSame('from-handler', $response->body);
    }

    public function testFullFragmentAndNoJavaScriptRepresentationsShareTheForm(): void
    {
        [$dispatch] = $this->createHealthApp();
        $full = $dispatch(new Request('GET', '/health'));
        self::assertSame(200, $full->status);
        self::assertStringContainsString('<!doctype html>', $full->body);
        self::assertStringContainsString('method="post" action="/health"', $full->body);
        self::assertStringContainsString('/assets/htmx.min.js', $full->body);

        $fragment = $dispatch(new Request('GET', '/health', headers: ['hx-request' => 'true']));
        self::assertSame(200, $fragment->status);
        self::assertStringNotContainsString('<!doctype html>', $fragment->body);
        self::assertStringContainsString('id="health"', $fragment->body);
        self::assertSame('HX-Request', $fragment->headers['Vary']);
    }

    public function testInvalidInputIsEscapedInMatchingRepresentation(): void
    {
        [$dispatch, $session] = $this->createHealthApp();
        $unsafe = '<script>alert(1)</script>' . str_repeat('x', 80);
        $response = $dispatch(new Request('POST', '/health', body: [
            '_csrf' => (string) $session->get('csrf'),
            'probe' => $unsafe,
        ]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
        self::assertStringNotContainsString('<script>', $response->body);
        self::assertStringContainsString('Use 80 characters or fewer.', $response->body);
    }

    public function testCsrfPreventsActionAndValidSubmissionsUsePrgOrHxHeaders(): void
    {
        [$dispatch, $session] = $this->createHealthApp();
        self::assertSame(403, $dispatch(new Request('POST', '/health', body: ['probe' => 'ok']))->status);
        self::assertNull($session->get('flash'));

        $body = ['_csrf' => (string) $session->get('csrf'), 'probe' => 'ok'];
        $normal = $dispatch(new Request('POST', '/health', body: $body));
        self::assertSame(303, $normal->status);
        self::assertSame('/health', $normal->headers['Location']);

        $htmx = $dispatch(new Request('POST', '/health', body: $body, headers: ['hx-request' => 'true']));
        self::assertSame(200, $htmx->status);
        self::assertSame('/health', $htmx->headers['HX-Redirect']);
        self::assertStringContainsString('notification', $htmx->headers['HX-Trigger']);
    }

    /**
     * @return array{callable(Request): Response, MemorySession, Csrf}
     */
    private function createHealthApp(): array
    {
        $session = new MemorySession();
        $csrf = new Csrf($session);
        $renderer = new Renderer(dirname(__DIR__, 2));
        $health = new HealthHandler($renderer, $csrf, $session);
        $router = new Router([
            ['GET', '/health', $health->handle(...)],
            ['POST', '/health', $health->handle(...)],
        ]);
        $csrf->token();

        $dispatch = static function (Request $request) use ($router, $csrf): Response {
            return $router->dispatch($request, static function (Request $matched) use ($csrf): ?Response {
                if (!in_array($matched->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$csrf->valid($matched)) {
                    return new Response(403, [], 'Forbidden');
                }
                return null;
            });
        };

        return [$dispatch, $session, $csrf];
    }
}

final class MemorySession implements Session
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): mixed
    {
        $value = $this->get($key);
        unset($this->values[$key]);
        return $value;
    }
}
