<?php

declare(strict_types=1);

namespace App\Modules\Access;

use Closure;
use PDOException;
use RuntimeException;
use Throwable;

final class UserCliHandler
{
    private const array SUPPORTED_ROLES = [
        'administrador',
        'cajero',
        'bodeguero',
        'compras',
    ];

    /**
     * @param (Closure(): string)|null $passwordReader
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly UserCommand $command,
        private readonly ?Closure $passwordReader = null,
        private mixed $stdout = null,
        private mixed $stderr = null,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function handleCreateUser(array $arguments): int
    {
        if (count($arguments) !== 2) {
            $this->writeErr("Usage: php scripts/console.php create-user <username> <rol>" . PHP_EOL);
            return 64;
        }

        $username = trim($arguments[0]);
        $rol      = trim($arguments[1]);

        if (str_starts_with($username, '--') || str_starts_with($rol, '--')) {
            $this->writeErr("Error: Options are not supported. Passwords must be entered interactively." . PHP_EOL);
            return 64;
        }

        if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
            $this->writeErr("Error: Username must be between 1 and 50 characters." . PHP_EOL);
            return 1;
        }

        if (!in_array($rol, self::SUPPORTED_ROLES, true)) {
            $this->writeErr(sprintf(
                "Error: Invalid role '%s'. Supported roles: %s." . PHP_EOL,
                $rol,
                implode(', ', self::SUPPORTED_ROLES)
            ));
            return 1;
        }

        try {
            $password = $this->readPassword();
        } catch (Throwable $e) {
            $this->writeErr("Error: " . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $charCount = mb_strlen($password, 'UTF-8');
        $byteCount = strlen($password);

        if ($charCount < 15) {
            $this->writeErr("Error: Password must have at least 15 Unicode characters." . PHP_EOL);
            return 1;
        }

        if ($byteCount > 72) {
            $this->writeErr("Error: Password cannot exceed 72 UTF-8 bytes." . PHP_EOL);
            return 1;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        try {
            $this->command->create($username, $passwordHash, $rol, 'activo');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062) {
                $this->writeErr(sprintf("Error: Username '%s' already exists." . PHP_EOL, $username));
                return 1;
            }
            $this->writeErr("Error: Account creation failed." . PHP_EOL);
            return 1;
        } catch (Throwable) {
            $this->writeErr("Error: Account creation failed." . PHP_EOL);
            return 1;
        }

        $this->writeOut(sprintf("User '%s' created successfully with role '%s'." . PHP_EOL, $username, $rol));
        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    public function handleUnlockUser(array $arguments): int
    {
        if (count($arguments) !== 1) {
            $this->writeErr("Usage: php scripts/console.php unlock-user <username>" . PHP_EOL);
            return 64;
        }

        $username = trim($arguments[0]);

        if (str_starts_with($username, '--')) {
            $this->writeErr("Error: Options are not supported." . PHP_EOL);
            return 64;
        }

        if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
            $this->writeErr("Error: Username must be between 1 and 50 characters." . PHP_EOL);
            return 1;
        }

        try {
            $unlocked = $this->command->unlock($username);
        } catch (Throwable) {
            $this->writeErr("Error: Failed to unlock user." . PHP_EOL);
            return 1;
        }

        if (!$unlocked) {
            $this->writeErr(sprintf("Error: User '%s' could not be unlocked. Account must exist and be blocked." . PHP_EOL, $username));
            return 1;
        }

        $this->writeOut(sprintf("User '%s' unlocked successfully." . PHP_EOL, $username));
        return 0;
    }

    private function readPassword(): string
    {
        if ($this->passwordReader !== null) {
            return ($this->passwordReader)();
        }

        return $this->readPasswordFromTerminal();
    }

    private function readPasswordFromTerminal(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || !stream_isatty(STDIN)) {
            throw new RuntimeException('Secure interactive password input is unavailable.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $psCmd = 'powershell.exe -NoProfile -Command "$p = Read-Host -Prompt \'Password\' -AsSecureString; if ($p -ne $null) { [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($p)) }"';
            $descriptors = [
                0 => STDIN,
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($psCmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Secure interactive password input is unavailable.');
            }
            $password = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0 || $password === false) {
                throw new RuntimeException('Secure interactive password input is unavailable.');
            }
            return rtrim($password, "\r\n");
        }

        // POSIX
        $sttyMode = shell_exec('stty -g 2>/dev/null');
        if ($sttyMode === null || $sttyMode === false) {
            throw new RuntimeException('Secure interactive password input is unavailable.');
        }

        shell_exec('stty -echo');
        try {
            $this->writeOut('Password: ');
            $line = fgets(STDIN);
            if ($line === false) {
                throw new RuntimeException('Failed to read password input.');
            }
            return rtrim($line, "\r\n");
        } finally {
            shell_exec('stty ' . escapeshellarg(trim($sttyMode)));
            $this->writeOut(PHP_EOL);
        }
    }

    private function writeOut(string $message): void
    {
        if (is_resource($this->stdout)) {
            fwrite($this->stdout, $message);
            return;
        }
        if (defined('STDOUT') && is_resource(STDOUT)) {
            fwrite(STDOUT, $message);
        }
    }

    private function writeErr(string $message): void
    {
        if (is_resource($this->stderr)) {
            fwrite($this->stderr, $message);
            return;
        }
        if (defined('STDERR') && is_resource(STDERR)) {
            fwrite(STDERR, $message);
        }
    }
}
