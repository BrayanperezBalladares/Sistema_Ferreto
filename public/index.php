<?php

declare(strict_types=1);

use App\Foundation\{Config, Csrf, Database, ErrorMapper, HealthHandler, Kernel, Logger, NativeSession, Renderer, Request, Response, Router, Transaction};
use App\Modules\Inventory\{CatalogHandler, CategoryCommand, CategoryQuery, ProductCommand, ProductQuery};

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$request = Request::fromGlobals();
$assets = [
    '/assets/htmx.min.js' => 'text/javascript',
    '/assets/bulma.min.css' => 'text/css',
    '/assets/app.js' => 'text/javascript',
    '/assets/ferreto.css' => 'text/css',
];

if (isset($assets[$request->path]) && $request->method === 'GET') {
    (new Response(200, ['Content-Type' => $assets[$request->path]], (string) file_get_contents(__DIR__ . $request->path)))->emit();
    return;
}

$session = new NativeSession(!in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true));
$csrf = new Csrf($session);
$renderer = new Renderer($root);

$config = Config::fromEnvironment(require $root . '/config/defaults.php');
$database = new Database($config, $config->get('APP_ENV') === 'test');
$tx = new Transaction($database);

$health = new HealthHandler($renderer, $csrf, $session);
$catalog = new CatalogHandler(
    $renderer,
    new ProductQuery($database),
    new CategoryQuery($database),
    new CategoryCommand($tx),
    new ProductCommand($tx),
    $csrf
);

$handlers = [
    'health' => $health->handle(...),
    'catalog' => $catalog->handle(...),
];

/** @var list<array{string, string, string}> $routeConfig */
$routeConfig = require $root . '/config/routes.php';
$routes = array_map(
    static fn (array $route): array => [$route[0], $route[1], $handlers[$route[2]]],
    $routeConfig
);

(new Kernel(new Router($routes), $csrf, new ErrorMapper(new Logger(), $renderer)))->handle($request)->emit();
