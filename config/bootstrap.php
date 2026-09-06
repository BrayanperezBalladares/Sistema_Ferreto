<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

return App\Foundation\Config::fromEnvironment(
    require __DIR__ . '/defaults.php',
);
