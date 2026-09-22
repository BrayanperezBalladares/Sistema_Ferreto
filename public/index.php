<?php

declare(strict_types=1);

use App\Foundation\{Config, Csrf, Database, ErrorMapper, HealthHandler, Kernel, Logger, NativeSession, Renderer, Request, Response, Router, Transaction};
use App\Modules\Access\{AccessHandler, Authenticator, AuthSession, RouteAccessPolicy, UserCommand, UserQuery};
use App\Modules\Inventory\{CatalogHandler, CategoryCommand, CategoryQuery, CountCommand, CountQuery, InventoryHandler, LocationCommand, LocationHandler, LocationQuery, ProductCommand, ProductQuery, StockCommand, StockQuery};

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

$productQuery = new ProductQuery($database);
$categoryQuery = new CategoryQuery($database);
$locationQuery = new LocationQuery($database);
$stockQuery = new StockQuery($database);

$userQuery = new UserQuery($database);
$userCommand = new UserCommand($tx);
$authenticator = new Authenticator($userQuery, $userCommand);
$authSession = new AuthSession($session, $userQuery);
$routePolicy = new RouteAccessPolicy();
$access = new AccessHandler($renderer, $authenticator, $authSession, $csrf, $routePolicy);

$health = new HealthHandler($renderer, $csrf, $session);
$catalog = new CatalogHandler(
    $renderer,
    $productQuery,
    $categoryQuery,
    new CategoryCommand($tx),
    new ProductCommand($tx),
    $csrf
);
$location = new LocationHandler(
    $renderer,
    $locationQuery,
    new LocationCommand($tx),
    $csrf
);
$inventory = new InventoryHandler(
    $renderer,
    $stockQuery,
    new StockCommand($tx),
    $productQuery,
    $locationQuery,
    $csrf,
    new CountQuery($database),
    new CountCommand($tx)
);

$handlers = [
    'health' => $health->handle(...),
    'access' => $access->handle(...),
    'catalog' => $catalog->handle(...),
    'location' => $location->handle(...),
    'inventory' => $inventory->handle(...),
];

/** @var list<array{string, string, string}> $routeConfig */
$routeConfig = require $root . '/config/routes.php';
$routes = array_map(
    static fn (array $route): array => [$route[0], $route[1], $handlers[$route[2]]],
    $routeConfig
);

(new Kernel(new Router($routes), $csrf, new ErrorMapper(new Logger(), $renderer)))->handle($request)->emit();
