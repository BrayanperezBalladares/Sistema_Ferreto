<?php

declare(strict_types=1);

return [
    ['GET', '/health', 'health'],
    ['POST', '/health', 'health'],
    ['GET', '/products', 'catalog'],
    ['POST', '/categories', 'catalog'],
    ['POST', '/products', 'catalog'],
    ['POST', '/products/{id}/price', 'catalog'],
    ['POST', '/products/{id}/deactivate', 'catalog'],
    ['POST', '/products/{id}/activate', 'catalog'],
    ['GET', '/locations', 'location'],
    ['POST', '/locations', 'location'],
    ['GET', '/inventory', 'inventory'],
];
