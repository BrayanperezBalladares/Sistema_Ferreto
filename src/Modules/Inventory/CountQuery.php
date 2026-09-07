<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;

final readonly class CountQuery
{
    private const BASE_SELECT = 'SELECT id_conteo, id_stock, cantidad_sistema, cantidad_contada, diferencia, notas, created_at FROM conteo_inventario';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}|null
     */
    public function findById(int $idConteo): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE id_conteo = :id');
        $stmt->bindValue(':id', $idConteo, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @return list<array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}>
     */
    public function listByStock(int $idStock): array
    {
        $stmt = $this->db->pdo()->prepare(self::BASE_SELECT . ' WHERE id_stock = :stock ORDER BY created_at DESC, id_conteo DESC');
        $stmt->bindValue(':stock', $idStock, PDO::PARAM_INT);
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
     * @return array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}
     */
    private static function map(array $r): array
    {
        return [
            'id_conteo'        => isset($r['id_conteo']) && is_numeric($r['id_conteo']) ? (int) $r['id_conteo'] : 0,
            'id_stock'         => isset($r['id_stock']) && is_numeric($r['id_stock']) ? (int) $r['id_stock'] : 0,
            'cantidad_sistema' => isset($r['cantidad_sistema']) && (is_string($r['cantidad_sistema']) || is_numeric($r['cantidad_sistema'])) ? (string) $r['cantidad_sistema'] : '0.000',
            'cantidad_contada' => isset($r['cantidad_contada']) && (is_string($r['cantidad_contada']) || is_numeric($r['cantidad_contada'])) ? (string) $r['cantidad_contada'] : '0.000',
            'diferencia'       => isset($r['diferencia']) && (is_string($r['diferencia']) || is_numeric($r['diferencia'])) ? (string) $r['diferencia'] : '0.000',
            'notas'            => isset($r['notas']) && is_string($r['notas']) ? $r['notas'] : null,
            'created_at'       => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : '',
        ];
    }
}
