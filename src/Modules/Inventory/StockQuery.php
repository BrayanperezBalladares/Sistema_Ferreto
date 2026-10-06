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

    public function getWarehouseStock(int $productId, int $warehouseId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(s.cantidad), 0) AS total '
            . 'FROM inventario_stock s '
            . 'INNER JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion '
            . 'WHERE s.id_producto = :prod AND u.id_almacen = :wh'
        );
        $stmt->bindValue(':prod', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':wh', $warehouseId, PDO::PARAM_INT);
        $stmt->execute();

        $val = $stmt->fetchColumn();
        /** @var numeric-string $num */
        $num = is_numeric($val) ? (string) $val : '0';
        return bcadd($num, '0', 3);
    }

    public function getBranchStock(int $productId, int $branchId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(s.cantidad), 0) AS total '
            . 'FROM inventario_stock s '
            . 'INNER JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion '
            . 'INNER JOIN almacen a ON a.id_almacen = u.id_almacen '
            . 'WHERE s.id_producto = :prod AND a.id_sucursal = :branch'
        );
        $stmt->bindValue(':prod', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':branch', $branchId, PDO::PARAM_INT);
        $stmt->execute();

        $val = $stmt->fetchColumn();
        /** @var numeric-string $num */
        $num = is_numeric($val) ? (string) $val : '0';
        return bcadd($num, '0', 3);
    }

    /**
     * @return list<array{
     *     id_almacen: ?int,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string,
     *     cantidad: string
     * }>
     */
    public function getStockBreakdownByWarehouse(int $productId): array
    {
        $sql = 'SELECT a.id_almacen, a.codigo AS almacen_codigo, a.nombre AS almacen_nombre, '
            . 's_branch.id_sucursal, s_branch.codigo AS sucursal_codigo, s_branch.nombre AS sucursal_nombre, '
            . 'COALESCE(SUM(s.cantidad), 0) AS cantidad '
            . 'FROM inventario_stock s '
            . 'INNER JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion '
            . 'LEFT JOIN almacen a ON a.id_almacen = u.id_almacen '
            . 'LEFT JOIN sucursal s_branch ON s_branch.id_sucursal = a.id_sucursal '
            . 'WHERE s.id_producto = :prod '
            . 'GROUP BY a.id_almacen, a.codigo, a.nombre, s_branch.id_sucursal, s_branch.codigo, s_branch.nombre '
            . 'ORDER BY a.nombre ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':prod', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            /** @var numeric-string $cant */
            $cant = isset($r['cantidad']) && is_numeric($r['cantidad']) ? (string) $r['cantidad'] : '0';
            $result[] = [
                'id_almacen'      => isset($r['id_almacen']) && is_numeric($r['id_almacen']) ? (int) $r['id_almacen'] : null,
                'almacen_codigo'  => isset($r['almacen_codigo']) && is_string($r['almacen_codigo']) ? $r['almacen_codigo'] : null,
                'almacen_nombre'  => isset($r['almacen_nombre']) && is_string($r['almacen_nombre']) ? $r['almacen_nombre'] : null,
                'id_sucursal'     => isset($r['id_sucursal']) && is_numeric($r['id_sucursal']) ? (int) $r['id_sucursal'] : null,
                'sucursal_codigo' => isset($r['sucursal_codigo']) && is_string($r['sucursal_codigo']) ? $r['sucursal_codigo'] : null,
                'sucursal_nombre' => isset($r['sucursal_nombre']) && is_string($r['sucursal_nombre']) ? $r['sucursal_nombre'] : null,
                'cantidad'        => bcadd($cant, '0', 3),
            ];
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
