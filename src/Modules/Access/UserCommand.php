<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\Transaction;
use PDO;

final readonly class UserCommand
{
    public function __construct(private Transaction $tx)
    {
    }

    public function create(string $username, string $passwordHash, string $rol, string $estado = 'creado'): int
    {
        return $this->tx->run(function (PDO $pdo) use ($username, $passwordHash, $rol, $estado): int {
            $stmt = $pdo->prepare(
                'INSERT INTO usuario (username, password_hash, rol, estado, failed_attempt_count, created_at, updated_at) '
                . 'VALUES (:username, :password_hash, :rol, :estado, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->bindValue(':username', trim($username));
            $stmt->bindValue(':password_hash', $passwordHash);
            $stmt->bindValue(':rol', $rol);
            $stmt->bindValue(':estado', $estado);
            $stmt->execute();

            return (int) $pdo->lastInsertId();
        });
    }

    public function recordFailure(string $username): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($username): bool {
            $stmt = $pdo->prepare(
                'UPDATE usuario '
                . 'SET '
                . '    estado = CASE '
                . '        WHEN (failure_window_started_at IS NOT NULL '
                . '              AND failure_window_started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) '
                . '              AND failed_attempt_count >= 5) '
                . '        THEN \'bloqueado\' '
                . '        ELSE estado '
                . '    END, '
                . '    locked_at = CASE '
                . '        WHEN (failure_window_started_at IS NOT NULL '
                . '              AND failure_window_started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) '
                . '              AND failed_attempt_count >= 5) '
                . '        THEN UTC_TIMESTAMP() '
                . '        ELSE locked_at '
                . '    END, '
                . '    failed_attempt_count = CASE '
                . '        WHEN failure_window_started_at IS NULL '
                . '             OR failure_window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) '
                . '        THEN 1 '
                . '        ELSE failed_attempt_count + 1 '
                . '    END, '
                . '    failure_window_started_at = CASE '
                . '        WHEN failure_window_started_at IS NULL '
                . '             OR failure_window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) '
                . '        THEN UTC_TIMESTAMP() '
                . '        ELSE failure_window_started_at '
                . '    END, '
                . '    updated_at = UTC_TIMESTAMP() '
                . 'WHERE username = :username AND estado = \'activo\''
            );
            $stmt->bindValue(':username', trim($username));
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }

    public function resetFailures(int $idUsuario): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($idUsuario): bool {
            $stmt = $pdo->prepare(
                'UPDATE usuario '
                . 'SET failed_attempt_count = 0, '
                . '    failure_window_started_at = NULL, '
                . '    locked_at = NULL, '
                . '    updated_at = UTC_TIMESTAMP() '
                . 'WHERE id_usuario = :id AND estado = \'activo\''
            );
            $stmt->bindValue(':id', $idUsuario, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }

    public function unlock(string $username): bool
    {
        return $this->tx->run(function (PDO $pdo) use ($username): bool {
            $stmt = $pdo->prepare(
                'UPDATE usuario '
                . 'SET estado = \'activo\', '
                . '    failed_attempt_count = 0, '
                . '    failure_window_started_at = NULL, '
                . '    locked_at = NULL, '
                . '    updated_at = UTC_TIMESTAMP() '
                . 'WHERE username = :username AND estado = \'bloqueado\''
            );
            $stmt->bindValue(':username', trim($username));
            $stmt->execute();

            return $stmt->rowCount() > 0;
        });
    }
}
