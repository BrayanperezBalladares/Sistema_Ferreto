<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class StockQuery
{
    private const BASE_SELECT = 'SELECT s.id_stock, s.id_producto, p.nombre AS producto_nombre, s.id_ubicacion, '
        . 'u.codigo AS ubicacion_codigo, s.cantidad, s.created_at, s.updated_at '
        . 'FROM inventario_stock s '
        . 'INNER JOIN producto p ON p.id_producto = s.id_producto '
        . 'INNER JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}>
     */
    public function listOverview(): array
    {
        $stmt = $this->db->pdo()->query(self::BASE_SELECT . ' ORDER BY p.nombre ASC, u.codigo ASC');
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
     * @return array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}|null
     */
    public function getPosition(int $idProducto, int $idUbicacion): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE s.id_producto = :prod AND s.id_ubicacion = :loc');
        $stmt->bindValue(':prod', $idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':loc', $idUbicacion, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}|null
     */
    public function findById(int $idStock): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE s.id_stock = :id');
        $stmt->bindValue(':id', $idStock, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}>
     */
    public function listByProduct(int $idProducto): array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE s.id_producto = :prod ORDER BY u.codigo ASC');
        $stmt->bindValue(':prod', $idProducto, PDO::PARAM_INT);
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
     * @return list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}>
     */
    public function listByLocation(int $idUbicacion): array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE s.id_ubicacion = :loc ORDER BY p.nombre ASC');
        $stmt->bindValue(':loc', $idUbicacion, PDO::PARAM_INT);
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
     * @return array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}
     */
    private static function map(array $r): array
    {
        return [
            'id_stock'         => isset($r['id_stock']) && is_numeric($r['id_stock']) ? (int) $r['id_stock'] : 0,
            'id_producto'      => isset($r['id_producto']) && is_numeric($r['id_producto']) ? (int) $r['id_producto'] : 0,
            'producto_nombre'  => isset($r['producto_nombre']) && is_string($r['producto_nombre']) ? $r['producto_nombre'] : '',
            'id_ubicacion'     => isset($r['id_ubicacion']) && is_numeric($r['id_ubicacion']) ? (int) $r['id_ubicacion'] : 0,
            'ubicacion_codigo' => isset($r['ubicacion_codigo']) && is_string($r['ubicacion_codigo']) ? $r['ubicacion_codigo'] : '',
            'cantidad'         => isset($r['cantidad']) && (is_string($r['cantidad']) || is_numeric($r['cantidad'])) ? (string) $r['cantidad'] : '0.000',
            'created_at'       => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'       => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
