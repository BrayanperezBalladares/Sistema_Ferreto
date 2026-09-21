<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Modules\Access\UserCliHandler;
use App\Modules\Access\UserCommand;
use InvalidArgumentException;
use Throwable;

final class Console
{
    private readonly string $root;

    public function __construct(
        string $root,
        private readonly ?UserCliHandler $userCliHandler = null,
    ) {
        $resolved = realpath($root);
        if ($resolved === false || !is_file($resolved . '/composer.json')) {
            throw new InvalidArgumentException('Project root is invalid.');
        }
        $this->root = $resolved;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if (count($arguments) < 1) {
            return 64;
        }

        $command = $arguments[0];

        try {
            return match ($command) {
                'verify-assets' => count($arguments) === 1 ? $this->verifyAssets() : 64,
                'config'        => count($arguments) === 1 ? $this->validateConfig() : 64,
                'serve'         => count($arguments) === 1 ? $this->serve() : 64,
                'migrate'       => count($arguments) === 1 ? $this->runMigrate() : 64,
                'seed'          => count($arguments) === 1 ? $this->runSeed() : 64,
                'create-user'   => $this->runCreateUser(array_slice($arguments, 1)),
                default => 64,
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** @param list<string> $args */
    private function runCreateUser(array $args): int
    {
        if ($this->userCliHandler !== null) {
            return $this->userCliHandler->handleCreateUser($args);
        }

        $config  = Config::fromEnvironment(require $this->root . '/config/defaults.php');
        $db      = new Database($config);
        $tx      = new Transaction($db);
        $command = new UserCommand($tx);
        $handler = new UserCliHandler($command);

        return $handler->handleCreateUser($args);
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

    private function runMigrate(): int
    {
        $config = Config::fromEnvironment(require $this->root . '/config/defaults.php');
        $db     = new Database($config);
        (new MigrationRunner($db))->run($this->root . '/database/migrations');
        return 0;
    }

    private function runSeed(): int
    {
        $config = Config::fromEnvironment(require $this->root . '/config/defaults.php');
        $db     = new Database($config);
        (new SeedRunner($db, $config->get('APP_ENV')))->run($this->root . '/database/seeds');
        return 0;
    }
}
