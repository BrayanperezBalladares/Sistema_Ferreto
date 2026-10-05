<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Foundation\Database;
use PDO;
use Throwable;

final class MultisiteCliHandler
{
    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly Database $database,
        private readonly LocationQuery $locationQuery,
        private readonly AlmacenQuery $almacenQuery,
        private mixed $stdout = null,
        private mixed $stderr = null,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function handleMapLocation(array $arguments): int
    {
        if (count($arguments) !== 2) {
            $this->writeErr("Usage: php scripts/console.php map-location <location> <warehouse>" . PHP_EOL);
            return 64;
        }

        $locInput = trim($arguments[0]);
        $whInput  = trim($arguments[1]);

        if (str_starts_with($locInput, '-') || str_starts_with($whInput, '-')) {
            $this->writeErr("Usage: php scripts/console.php map-location <location> <warehouse>" . PHP_EOL);
            return 64;
        }

        [$location, $locError] = $this->resolveLocation($locInput);
        if ($locError !== null || $location === null) {
            $this->writeErr(($locError ?? "Error: La ubicación '{$locInput}' no existe.") . PHP_EOL);
            return 1;
        }

        [$warehouse, $whError] = $this->resolveWarehouse($whInput);
        if ($whError !== null || $warehouse === null) {
            $this->writeErr(($whError ?? "Error: El almacén '{$whInput}' no existe.") . PHP_EOL);
            return 1;
        }

        if ($warehouse['estado_activo'] !== 1) {
            $this->writeErr("Error: El almacén '{$warehouse['codigo']}' está inactivo." . PHP_EOL);
            return 1;
        }

        try {
            $stmt = $this->database->pdo()->prepare(
                'UPDATE ubicacion SET id_almacen = :warehouse_id WHERE id_ubicacion = :location_id AND id_almacen IS NULL'
            );
            $stmt->bindValue(':warehouse_id', $warehouse['id_almacen'], PDO::PARAM_INT);
            $stmt->bindValue(':location_id', $location['id_ubicacion'], PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() !== 1) {
                $this->writeErr("Error: La ubicación '{$location['codigo']}' ya tiene un almacén asignado o no se pudo mapear." . PHP_EOL);
                return 1;
            }
        } catch (Throwable $e) {
            $this->writeErr("Error: " . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $this->writeOut("Ubicación '{$location['codigo']}' mapeada exitosamente al almacén '{$warehouse['codigo']}'." . PHP_EOL);
        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    public function handleVerifyLocationsMapped(array $arguments): int
    {
        if (count($arguments) > 0) {
            $this->writeErr("Usage: php scripts/console.php verify-locations-mapped" . PHP_EOL);
            return 64;
        }

        try {
            $stmt = $this->database->pdo()->query(
                'SELECT id_ubicacion, codigo FROM ubicacion WHERE id_almacen IS NULL ORDER BY id_ubicacion ASC'
            );
            if ($stmt === false) {
                $this->writeErr("Error: No se pudo verificar el estado de las ubicaciones." . PHP_EOL);
                return 1;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->writeErr("Error: " . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $unmapped = [];
        foreach ($rows as $row) {
            $id = $row['id_ubicacion'] ?? null;
            $code = $row['codigo'] ?? null;
            if (is_numeric($id) && is_string($code)) {
                $unmapped[] = [
                    'id_ubicacion' => (int) $id,
                    'codigo'       => $code,
                ];
            }
        }

        $count = count($unmapped);
        if ($count > 0) {
            $this->writeErr("Error: Se encontraron {$count} ubicación(es) sin asignar a un almacén:" . PHP_EOL);
            foreach ($unmapped as $r) {
                $this->writeErr("  - ID {$r['id_ubicacion']}: {$r['codigo']}" . PHP_EOL);
            }
            return 1;
        }

        $this->writeOut("Verificación exitosa: Todas las ubicaciones están mapeadas a un almacén." . PHP_EOL);
        return 0;
    }

    /**
     * @return array{
     *     0: array{
     *         id_ubicacion: int,
     *         id_almacen: ?int,
     *         codigo: string,
     *         descripcion: ?string,
     *         estado_activo: int,
     *         created_at: string,
     *         updated_at: string,
     *         almacen_codigo: ?string,
     *         almacen_nombre: ?string,
     *         almacen_tipo: ?string,
     *         almacen_activo: ?int,
     *         id_sucursal: ?int,
     *         sucursal_codigo: ?string,
     *         sucursal_nombre: ?string
     *     }|null,
     *     1: string|null
     * }
     */
    private function resolveLocation(string $input): array
    {
        if (preg_match('/^[1-9][0-9]*$/', $input) === 1) {
            $byPk   = $this->locationQuery->findById((int) $input);
            $byCode = $this->locationQuery->findByCode($input);

            if ($byPk !== null && $byCode !== null) {
                if ($byPk['id_ubicacion'] !== $byCode['id_ubicacion']) {
                    return [null, "Error: El identificador de ubicación '{$input}' es ambiguo: coincide con el ID {$byPk['id_ubicacion']} y el código '{$byCode['codigo']}'."];
                }
                return [$byPk, null];
            }

            if ($byPk !== null) {
                return [$byPk, null];
            }

            if ($byCode !== null) {
                return [$byCode, null];
            }

            return [null, "Error: La ubicación '{$input}' no existe."];
        }

        $byCode = $this->locationQuery->findByCode($input);
        if ($byCode === null) {
            return [null, "Error: La ubicación '{$input}' no existe."];
        }

        return [$byCode, null];
    }

    /**
     * @return array{
     *     0: array{
     *         id_almacen: int,
     *         id_sucursal: int,
     *         codigo: string,
     *         nombre: string,
     *         tipo: string,
     *         estado_activo: int,
     *         created_at: string,
     *         updated_at: string,
     *         sucursal_codigo: string,
     *         sucursal_nombre: string,
     *         total_ubicaciones: int
     *     }|null,
     *     1: string|null
     * }
     */
    private function resolveWarehouse(string $input): array
    {
        if (preg_match('/^[1-9][0-9]*$/', $input) === 1) {
            $byPk   = $this->almacenQuery->findById((int) $input);
            $byCode = $this->almacenQuery->findByCode($input);

            if ($byPk !== null && $byCode !== null) {
                if ($byPk['id_almacen'] !== $byCode['id_almacen']) {
                    return [null, "Error: El identificador de almacén '{$input}' es ambiguo: coincide con el ID {$byPk['id_almacen']} y el código '{$byCode['codigo']}'."];
                }
                return [$byPk, null];
            }

            if ($byPk !== null) {
                return [$byPk, null];
            }

            if ($byCode !== null) {
                return [$byCode, null];
            }

            return [null, "Error: El almacén '{$input}' no existe."];
        }

        $byCode = $this->almacenQuery->findByCode($input);
        if ($byCode === null) {
            return [null, "Error: El almacén '{$input}' no existe."];
        }

        return [$byCode, null];
    }

    private function writeOut(string $message): void
    {
        if (is_resource($this->stdout)) {
            fwrite($this->stdout, $message);
            return;
        }
        if (defined('STDOUT') && is_resource(STDOUT)) {
            fwrite(STDOUT, $message);
        }
    }

    private function writeErr(string $message): void
    {
        if (is_resource($this->stderr)) {
            fwrite($this->stderr, $message);
            return;
        }
        if (defined('STDERR') && is_resource(STDERR)) {
            fwrite(STDERR, $message);
        }
    }
}
