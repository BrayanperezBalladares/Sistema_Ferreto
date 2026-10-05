<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class LocationQuery
{
    private const BASE_SELECT = 'SELECT u.id_ubicacion, u.id_almacen, u.codigo, u.descripcion, u.estado_activo, u.created_at, u.updated_at, '
        . 'a.codigo AS almacen_codigo, a.nombre AS almacen_nombre, a.tipo AS almacen_tipo, a.estado_activo AS almacen_activo, '
        . 's.id_sucursal, s.codigo AS sucursal_codigo, s.nombre AS sucursal_nombre '
        . 'FROM ubicacion u '
        . 'LEFT JOIN almacen a ON a.id_almacen = u.id_almacen '
        . 'LEFT JOIN sucursal s ON s.id_sucursal = a.id_sucursal';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }>
     */
    public function all(?int $idAlmacen = null, bool $activeOnly = false): array
    {
        $conditions = [];
        $params = [];

        if ($idAlmacen !== null) {
            $conditions[] = 'u.id_almacen = :id_almacen';
            $params[':id_almacen'] = $idAlmacen;
        }

        if ($activeOnly) {
            $conditions[] = 'u.estado_activo = 1';
        }

        $sql = self::BASE_SELECT;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY u.codigo ASC';

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
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }>
     */
    public function findAll(?int $idAlmacen = null): array
    {
        return $this->all($idAlmacen, false);
    }

    /**
     * @return list<array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }>
     */
    public function findActive(?int $idAlmacen = null): array
    {
        return $this->all($idAlmacen, true);
    }

    /**
     * @return list<array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }>
     */
    public function findByWarehouse(int $idAlmacen, bool $activeOnly = false): array
    {
        return $this->all($idAlmacen, $activeOnly);
    }

    /**
     * @return array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE u.id_ubicacion = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE u.codigo = :code');
        $stmt->bindValue(':code', trim($code));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{
     *     id_ubicacion: int,
     *     id_almacen: ?int,
     *     codigo: string,
     *     descripcion: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     almacen_codigo: ?string,
     *     almacen_nombre: ?string,
     *     almacen_tipo: ?string,
     *     almacen_activo: ?int,
     *     id_sucursal: ?int,
     *     sucursal_codigo: ?string,
     *     sucursal_nombre: ?string
     * }
     */
    private static function map(array $r): array
    {
        return [
            'id_ubicacion'    => isset($r['id_ubicacion']) && is_numeric($r['id_ubicacion']) ? (int) $r['id_ubicacion'] : 0,
            'id_almacen'      => isset($r['id_almacen']) && is_numeric($r['id_almacen']) ? (int) $r['id_almacen'] : null,
            'codigo'          => isset($r['codigo']) && is_string($r['codigo']) ? $r['codigo'] : '',
            'descripcion'     => isset($r['descripcion']) && is_string($r['descripcion']) ? $r['descripcion'] : null,
            'estado_activo'   => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'      => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'      => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
            'almacen_codigo'  => isset($r['almacen_codigo']) && is_string($r['almacen_codigo']) ? $r['almacen_codigo'] : null,
            'almacen_nombre'  => isset($r['almacen_nombre']) && is_string($r['almacen_nombre']) ? $r['almacen_nombre'] : null,
            'almacen_tipo'    => isset($r['almacen_tipo']) && is_string($r['almacen_tipo']) ? $r['almacen_tipo'] : null,
            'almacen_activo'  => isset($r['almacen_activo']) && is_numeric($r['almacen_activo']) ? (int) $r['almacen_activo'] : null,
            'id_sucursal'     => isset($r['id_sucursal']) && is_numeric($r['id_sucursal']) ? (int) $r['id_sucursal'] : null,
            'sucursal_codigo' => isset($r['sucursal_codigo']) && is_string($r['sucursal_codigo']) ? $r['sucursal_codigo'] : null,
            'sucursal_nombre' => isset($r['sucursal_nombre']) && is_string($r['sucursal_nombre']) ? $r['sucursal_nombre'] : null,
        ];
    }
}
