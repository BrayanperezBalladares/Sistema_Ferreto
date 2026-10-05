<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class AlmacenQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }>
     */
    public function all(?int $idSucursal = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT a.id_almacen, a.id_sucursal, a.codigo, a.nombre, a.tipo, a.estado_activo, a.created_at, a.updated_at, '
            . 's.codigo AS sucursal_codigo, s.nombre AS sucursal_nombre '
            . 'FROM almacen a '
            . 'INNER JOIN sucursal s ON s.id_sucursal = a.id_sucursal';

        $conditions = [];
        $params = [];

        if ($idSucursal !== null) {
            $conditions[] = 'a.id_sucursal = :id_sucursal';
            $params[':id_sucursal'] = $idSucursal;
        }

        if ($activeOnly) {
            $conditions[] = 'a.estado_activo = 1';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.nombre ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $param => $val) {
            $stmt->bindValue($param, $val, PDO::PARAM_INT);
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
     * @return list<array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }>
     */
    public function findAll(?int $idSucursal = null): array
    {
        return $this->all($idSucursal, false);
    }

    /**
     * @return list<array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }>
     */
    public function findActive(?int $idSucursal = null): array
    {
        return $this->all($idSucursal, true);
    }

    /**
     * @return list<array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }>
     */
    public function findByBranch(int $idSucursal, bool $activeOnly = false): array
    {
        return $this->all($idSucursal, $activeOnly);
    }

    /**
     * @return array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id_almacen, a.id_sucursal, a.codigo, a.nombre, a.tipo, a.estado_activo, a.created_at, a.updated_at, '
            . 's.codigo AS sucursal_codigo, s.nombre AS sucursal_nombre '
            . 'FROM almacen a '
            . 'INNER JOIN sucursal s ON s.id_sucursal = a.id_sucursal '
            . 'WHERE a.id_almacen = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id_almacen, a.id_sucursal, a.codigo, a.nombre, a.tipo, a.estado_activo, a.created_at, a.updated_at, '
            . 's.codigo AS sucursal_codigo, s.nombre AS sucursal_nombre '
            . 'FROM almacen a '
            . 'INNER JOIN sucursal s ON s.id_sucursal = a.id_sucursal '
            . 'WHERE a.codigo = :code'
        );
        $stmt->bindValue(':code', trim($code));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{
     *     id_almacen: int,
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     tipo: string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     sucursal_codigo: string,
     *     sucursal_nombre: string
     * }
     */
    private static function map(array $r): array
    {
        return [
            'id_almacen'      => isset($r['id_almacen']) && is_numeric($r['id_almacen']) ? (int) $r['id_almacen'] : 0,
            'id_sucursal'     => isset($r['id_sucursal']) && is_numeric($r['id_sucursal']) ? (int) $r['id_sucursal'] : 0,
            'codigo'          => isset($r['codigo']) && is_string($r['codigo']) ? $r['codigo'] : '',
            'nombre'          => isset($r['nombre']) && is_string($r['nombre']) ? $r['nombre'] : '',
            'tipo'            => isset($r['tipo']) && is_string($r['tipo']) ? $r['tipo'] : 'bodega',
            'estado_activo'   => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'      => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'      => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
            'sucursal_codigo' => isset($r['sucursal_codigo']) && is_string($r['sucursal_codigo']) ? $r['sucursal_codigo'] : '',
            'sucursal_nombre' => isset($r['sucursal_nombre']) && is_string($r['sucursal_nombre']) ? $r['sucursal_nombre'] : '',
        ];
    }
}
