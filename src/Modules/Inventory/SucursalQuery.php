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
     *     updated_at: string
     * }>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT id_sucursal, codigo, nombre, ciudad, direccion, telefono, estado_activo, created_at, updated_at FROM sucursal';
        if ($activeOnly) {
            $sql .= ' WHERE estado_activo = 1';
        }
        $sql .= ' ORDER BY nombre ASC';

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
     *     updated_at: string
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
     *     updated_at: string
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
     *     updated_at: string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id_sucursal, codigo, nombre, ciudad, direccion, telefono, estado_activo, created_at, updated_at FROM sucursal WHERE id_sucursal = :id'
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
     *     updated_at: string
     * }|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id_sucursal, codigo, nombre, ciudad, direccion, telefono, estado_activo, created_at, updated_at FROM sucursal WHERE codigo = :code'
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
     *     updated_at: string
     * }
     */
    private static function map(array $r): array
    {
        return [
            'id_sucursal'   => isset($r['id_sucursal']) && is_numeric($r['id_sucursal']) ? (int) $r['id_sucursal'] : 0,
            'codigo'        => isset($r['codigo']) && is_string($r['codigo']) ? $r['codigo'] : '',
            'nombre'        => isset($r['nombre']) && is_string($r['nombre']) ? $r['nombre'] : '',
            'ciudad'        => isset($r['ciudad']) && is_string($r['ciudad']) ? $r['ciudad'] : '',
            'direccion'     => isset($r['direccion']) && is_string($r['direccion']) ? $r['direccion'] : null,
            'telefono'      => isset($r['telefono']) && is_string($r['telefono']) ? $r['telefono'] : null,
            'estado_activo' => isset($r['estado_activo']) && is_numeric($r['estado_activo']) ? (int) $r['estado_activo'] : 1,
            'created_at'    => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
            'updated_at'    => isset($r['updated_at']) && is_string($r['updated_at']) ? $r['updated_at'] : '',
        ];
    }
}
