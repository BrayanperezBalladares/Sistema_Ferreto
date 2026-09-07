<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class LocationQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT id_ubicacion, codigo, descripcion, estado_activo, created_at, updated_at FROM ubicacion';
        if ($activeOnly) {
            $sql .= ' WHERE estado_activo = 1';
        }
        $sql .= ' ORDER BY codigo ASC';

        $stmt = $this->db->pdo()->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = self::map($row);
            }
        }
        return $result;
    }

    /**
     * @return array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id_ubicacion, codigo, descripcion, estado_activo, created_at, updated_at FROM ubicacion WHERE id_ubicacion = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id_ubicacion, codigo, descripcion, estado_activo, created_at, updated_at FROM ubicacion WHERE codigo = :code');
        $stmt->bindValue(':code', trim($code));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}
     */
    private static function map(array $r): array
    {
        return [
            'id_ubicacion'  => isset($r['id_ubicacion']) && is_numeric($r['id_ubicacion']) ? (int) $r['id_ubicacion'] : 0,
            'codigo'        => isset($r['codigo']) && is_string($r['codigo']) ? $r['codigo'] : '',
            'descripcion'   => isset($r['descripcion']) && is_string($r['descripcion']) ? $r['descripcion'] : null,
            'estado_activo' => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'    => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'    => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
