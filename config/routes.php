<?php

declare(strict_types=1);

return [
    ['GET', '/health', 'health'],
    ['POST', '/health', 'health'],
    ['GET', '/login', 'access'],
    ['POST', '/login', 'access'],
    ['POST', '/logout', 'access'],
    ['GET', '/products', 'catalog'],
    ['POST', '/categories', 'catalog'],
    ['POST', '/products', 'catalog'],
    ['POST', '/products/{id}/price', 'catalog'],
    ['POST', '/products/{id}/deactivate', 'catalog'],
    ['POST', '/products/{id}/activate', 'catalog'],
    ['GET', '/locations', 'location'],
    ['POST', '/locations', 'location'],
    ['GET', '/branches', 'branch'],
    ['POST', '/branches', 'branch'],
    ['POST', '/branches/toggle-active', 'branch'],
    ['GET', '/warehouses', 'warehouse'],
    ['POST', '/warehouses', 'warehouse'],
    ['POST', '/warehouses/toggle-active', 'warehouse'],
    ['GET', '/inventory', 'inventory'],
    ['POST', '/inventory/stock', 'inventory'],
    ['GET', '/inventory/counts', 'inventory'],
    ['POST', '/inventory/counts', 'inventory'],
];
