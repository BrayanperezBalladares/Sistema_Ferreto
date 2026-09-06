<?php

declare(strict_types=1);

namespace App\Foundation;

use InvalidArgumentException;
use Throwable;

final class Console
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_file($resolved . '/composer.json')) {
            throw new InvalidArgumentException('Project root is invalid.');
        }
        $this->root = $resolved;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if (count($arguments) !== 1) {
            return 64;
        }

        try {
            return match ($arguments[0]) {
                'verify-assets' => $this->verifyAssets(),
                'config' => $this->validateConfig(),
                'serve' => $this->serve(),
                'migrate', 'seed' => $this->notAvailable($arguments[0]),
                default => 64,
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function verifyAssets(): int
    {
        (new AssetVerifier($this->root))->verify();
        return 0;
    }

    private function validateConfig(): int
    {
        Config::fromEnvironment(require $this->root . '/config/defaults.php');
        return 0;
    }

    private function serve(): int
    {
        $this->validateConfig();
        $command = [PHP_BINARY, '-S', '127.0.0.1:8000', '-t', $this->root . '/public'];
        if (is_file($this->root . '/public/index.php')) {
            $command[] = $this->root . '/public/index.php';
        }
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $this->root);
        return is_resource($process) ? proc_close($process) : 1;
    }

    private function notAvailable(string $command): int
    {
        fwrite(STDERR, "Command '{$command}' is reserved for the transactional data slice.\n");
        return 69;
    }
}
