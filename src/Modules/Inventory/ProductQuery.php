<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class ProductQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT p.id_producto, p.id_categoria, c.nombre AS categoria_nombre, p.nombre, p.descripcion, '
            . 'p.precio_actual, p.estado_activo, p.created_at, p.updated_at '
            . 'FROM producto p LEFT JOIN categoria c ON c.id_categoria = p.id_categoria WHERE p.id_producto = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}>
     */
    public function search(string $query = '', ?int $categoryId = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT p.id_producto, p.id_categoria, c.nombre AS categoria_nombre, p.nombre, p.descripcion, '
            . 'p.precio_actual, p.estado_activo, p.created_at, p.updated_at '
            . 'FROM producto p LEFT JOIN categoria c ON c.id_categoria = p.id_categoria WHERE 1=1';
        $params = [];
        if (($trimmed = trim($query)) !== '') {
            $sql .= ' AND p.nombre LIKE :query';
            $params[':query'] = '%' . $trimmed . '%';
        }
        if ($categoryId !== null) {
            $sql .= ' AND p.id_categoria = :cat';
            $params[':cat'] = $categoryId;
        }
        if ($activeOnly) {
            $sql .= ' AND p.estado_activo = 1';
        }
        $sql .= ' ORDER BY p.nombre ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = self::map($row);
            }
        }
        return $result;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}
     */
    private static function map(array $r): array
    {
        return [
            'id_producto'      => isset($r['id_producto']) && is_numeric($r['id_producto']) ? (int) $r['id_producto'] : 0,
            'id_categoria'     => isset($r['id_categoria']) && is_numeric($r['id_categoria']) ? (int) $r['id_categoria'] : null,
            'categoria_nombre' => isset($r['categoria_nombre']) && is_string($r['categoria_nombre']) ? $r['categoria_nombre'] : null,
            'nombre'           => isset($r['nombre']) && is_string($r['nombre']) ? $r['nombre'] : '',
            'descripcion'      => isset($r['descripcion']) && is_string($r['descripcion']) ? $r['descripcion'] : null,
            'precio_actual'    => isset($r['precio_actual']) && (is_string($r['precio_actual']) || is_numeric($r['precio_actual'])) ? (string) $r['precio_actual'] : '0.00',
            'estado_activo'    => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'       => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'       => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
