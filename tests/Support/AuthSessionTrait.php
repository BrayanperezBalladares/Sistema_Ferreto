<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Foundation\Database;
use App\Foundation\Transaction;
use App\Modules\Access\UserCommand;
use PHPUnit\Framework\Assert;

trait AuthSessionTrait
{
    public function createAuthUser(
        Database $db,
        string $username,
        string $role = 'administrador',
        string $estado = 'activo',
        string $password = 'Passphrase12345678'
    ): int {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $tx = new Transaction($db);
        $cmd = new UserCommand($tx);

        return $cmd->create($username, $hash, $role, $estado);
    }

    /**
     * @return array{status: int, stdout: string, stderr: string, exitCode: int}
     */
    public function runEntrypointRequest(
        string $method,
        string $path,
        ?int $userId = null,
        string $sessionId = 'test_sess_entrypoint'
    ): array {
        $root = dirname(__DIR__, 2);
        $statusFile = tempnam(sys_get_temp_dir(), 'sf_status_');
        if ($statusFile === false) {
            Assert::fail('Failed to allocate temporary status file.');
        }

        $cleanSessionId = (string) preg_replace('/[^a-zA-Z0-9,-]/', '-', $sessionId);
        if ($cleanSessionId === '') {
            $cleanSessionId = 'test-sess-entrypoint';
        }

        $sessionBootstrap = '';
        if ($userId !== null) {
            $sessIdExport = var_export($cleanSessionId, true);
            $userIdInt = (int) $userId;
            $sessionBootstrap = <<<PHP
\$sessName = session_name() ?: 'PHPSESSID';
\$_COOKIE[\$sessName] = {$sessIdExport};
session_id({$sessIdExport});
session_start();
\$_SESSION['auth_user_id'] = {$userIdInt};
\$_SESSION['auth_last_activity'] = time();
session_write_close();
PHP;
        }

        $methodExport = var_export(strtoupper($method), true);
        $pathExport = var_export($path, true);
        $statusFileExport = var_export($statusFile, true);

        $code = <<<PHP
putenv('APP_ENV=test');
\$_ENV['APP_ENV'] = 'test';
\$_SERVER['APP_ENV'] = 'test';
\$_SERVER['REQUEST_METHOD'] = {$methodExport};
\$_SERVER['REQUEST_URI'] = {$pathExport};
\$_SERVER['SERVER_NAME'] = 'localhost';

\$parsedQuery = parse_url({$pathExport}, PHP_URL_QUERY);
if (is_string(\$parsedQuery) && \$parsedQuery !== '') {
    parse_str(\$parsedQuery, \$_GET);
}

register_shutdown_function(static function (): void {
    \$code = http_response_code();
    @file_put_contents({$statusFileExport}, (string) (\$code !== false ? \$code : 200));
});

{$sessionBootstrap}

require 'public/index.php';
PHP;

        $process = proc_open([
            PHP_BINARY,
            '-r',
            $code,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);

        if (!is_resource($process)) {
            @unlink($statusFile);
            Assert::fail('Failed to launch entrypoint process via proc_open.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($process);

        $status = 200;
        if (file_exists($statusFile)) {
            $rawStatus = (string) file_get_contents($statusFile);
            if ($rawStatus !== '') {
                $status = (int) $rawStatus;
            }
            @unlink($statusFile);
        }

        return [
            'status' => $status,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
        ];
    }
}
