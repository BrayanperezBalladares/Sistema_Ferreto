<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class SucursalQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT s.id_sucursal, s.codigo, s.nombre, s.ciudad, s.direccion, s.telefono, s.estado_activo, s.created_at, s.updated_at, '
            . 'COUNT(a.id_almacen) AS total_almacenes '
            . 'FROM sucursal s '
            . 'LEFT JOIN almacen a ON a.id_sucursal = s.id_sucursal';
        if ($activeOnly) {
            $sql .= ' WHERE s.estado_activo = 1';
        }
        $sql .= ' GROUP BY s.id_sucursal ORDER BY s.nombre ASC';

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
     * @return list<array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }>
     */
    public function findAll(): array
    {
        return $this->all(false);
    }

    /**
     * @return list<array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }>
     */
    public function findActive(): array
    {
        return $this->all(true);
    }

    /**
     * @return array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id_sucursal, s.codigo, s.nombre, s.ciudad, s.direccion, s.telefono, s.estado_activo, s.created_at, s.updated_at, '
            . 'COUNT(a.id_almacen) AS total_almacenes '
            . 'FROM sucursal s '
            . 'LEFT JOIN almacen a ON a.id_sucursal = s.id_sucursal '
            . 'WHERE s.id_sucursal = :id '
            . 'GROUP BY s.id_sucursal'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id_sucursal, s.codigo, s.nombre, s.ciudad, s.direccion, s.telefono, s.estado_activo, s.created_at, s.updated_at, '
            . 'COUNT(a.id_almacen) AS total_almacenes '
            . 'FROM sucursal s '
            . 'LEFT JOIN almacen a ON a.id_sucursal = s.id_sucursal '
            . 'WHERE s.codigo = :code '
            . 'GROUP BY s.id_sucursal'
        );
        $stmt->bindValue(':code', trim($code));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<mixed, mixed> $r
     * @return array{
     *     id_sucursal: int,
     *     codigo: string,
     *     nombre: string,
     *     ciudad: string,
     *     direccion: ?string,
     *     telefono: ?string,
     *     estado_activo: int,
     *     created_at: string,
     *     updated_at: string,
     *     total_almacenes: int
     * }
     */
    private static function map(array $r): array
    {
        return [
            'id_sucursal'     => isset($r['id_sucursal']) && is_numeric($r['id_sucursal']) ? (int) $r['id_sucursal'] : 0,
            'codigo'          => isset($r['codigo']) && is_string($r['codigo']) ? $r['codigo'] : '',
            'nombre'          => isset($r['nombre']) && is_string($r['nombre']) ? $r['nombre'] : '',
            'ciudad'          => isset($r['ciudad']) && is_string($r['ciudad']) ? $r['ciudad'] : '',
            'direccion'       => isset($r['direccion']) && is_string($r['direccion']) ? $r['direccion'] : null,
            'telefono'        => isset($r['telefono']) && is_string($r['telefono']) ? $r['telefono'] : null,
            'estado_activo'   => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'      => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'      => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
            'total_almacenes' => isset($r['total_almacenes']) && is_numeric($r['total_almacenes']) ? (int) $r['total_almacenes'] : 0,
        ];
    }
}
