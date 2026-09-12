<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\Database;
use PDO;

final readonly class UserQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     id_usuario: int,
     *     username: string,
     *     password_hash: string,
     *     rol: string,
     *     estado: string,
     *     failed_attempt_count: int,
     *     failure_window_started_at: ?string,
     *     locked_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id_usuario, username, password_hash, rol, estado, '
            . 'failed_attempt_count, failure_window_started_at, locked_at, created_at, updated_at '
            . 'FROM usuario WHERE id_usuario = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{
     *     id_usuario: int,
     *     username: string,
     *     password_hash: string,
     *     rol: string,
     *     estado: string,
     *     failed_attempt_count: int,
     *     failure_window_started_at: ?string,
     *     locked_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id_usuario, username, password_hash, rol, estado, '
            . 'failed_attempt_count, failure_window_started_at, locked_at, created_at, updated_at '
            . 'FROM usuario WHERE username = :username'
        );
        $stmt->bindValue(':username', trim($username));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{
     *     id_usuario: int,
     *     username: string,
     *     password_hash: string,
     *     rol: string,
     *     estado: string,
     *     failed_attempt_count: int,
     *     failure_window_started_at: ?string,
     *     locked_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    private static function map(array $r): array
    {
        return [
            'id_usuario'                => isset($r['id_usuario']) && is_numeric($r['id_usuario']) ? (int) $r['id_usuario'] : 0,
            'username'                  => isset($r['username']) && is_string($r['username']) ? $r['username'] : '',
            'password_hash'             => isset($r['password_hash']) && is_string($r['password_hash']) ? $r['password_hash'] : '',
            'rol'                       => isset($r['rol']) && is_string($r['rol']) ? $r['rol'] : '',
            'estado'                    => isset($r['estado']) && is_string($r['estado']) ? $r['estado'] : '',
            'failed_attempt_count'      => isset($r['failed_attempt_count']) && is_numeric($r['failed_attempt_count']) ? (int) $r['failed_attempt_count'] : 0,
            'failure_window_started_at' => isset($r['failure_window_started_at']) && is_string($r['failure_window_started_at']) ? $r['failure_window_started_at'] : null,
            'locked_at'                 => isset($r['locked_at']) && is_string($r['locked_at']) ? $r['locked_at'] : null,
            'created_at'                => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'                => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
