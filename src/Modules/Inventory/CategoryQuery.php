<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class CategoryQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}>
     */
    public function all(): array
    {
        $stmt = $this->db->pdo()->query('SELECT id_categoria, nombre, descripcion, created_at, updated_at FROM categoria ORDER BY nombre ASC');
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
     * @return array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id_categoria, nombre, descripcion, created_at, updated_at FROM categoria WHERE id_categoria = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id_categoria, nombre, descripcion, created_at, updated_at FROM categoria WHERE nombre = :nombre');
        $stmt->bindValue(':nombre', trim($name));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}
     */
    private static function map(array $r): array
    {
        return [
            'id_categoria' => isset($r['id_categoria']) && is_numeric($r['id_categoria']) ? (int) $r['id_categoria'] : 0,
            'nombre'       => isset($r['nombre']) && is_string($r['nombre']) ? $r['nombre'] : '',
            'descripcion'  => isset($r['descripcion']) && is_string($r['descripcion']) ? $r['descripcion'] : null,
            'created_at'   => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'   => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
