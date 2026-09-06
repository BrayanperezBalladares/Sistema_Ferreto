<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    (new App\Foundation\AssetVerifier(dirname(__DIR__)))->verify();
    fwrite(STDOUT, "Locked dependencies and local assets verified.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Setup verification failed: {$exception->getMessage()}\n");
    exit(1);
}
