<?php

declare(strict_types=1);

return [
    ['GET', '/health', 'health'],
    ['POST', '/health', 'health'],
    ['GET', '/products', 'catalog'],
    ['POST', '/categories', 'catalog'],
    ['POST', '/products', 'catalog'],
];
