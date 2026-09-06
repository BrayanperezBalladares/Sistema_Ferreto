<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Foundation\Handler;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\Router;
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
}
