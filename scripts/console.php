<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, 'is_string')) : [];
exit((new App\Foundation\Console(dirname(__DIR__)))->run(array_slice($arguments, 1)));
