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

        $location = (ctype_digit($locInput) || (is_numeric($locInput) && (int) $locInput > 0))
            ? ($this->locationQuery->findById((int) $locInput) ?? $this->locationQuery->findByCode($locInput))
            : $this->locationQuery->findByCode($locInput);

        if ($location === null) {
            $this->writeErr("Error: La ubicación '{$locInput}' no existe." . PHP_EOL);
            return 1;
        }

        $warehouse = (ctype_digit($whInput) || (is_numeric($whInput) && (int) $whInput > 0))
            ? ($this->almacenQuery->findById((int) $whInput) ?? $this->almacenQuery->findByCode($whInput))
            : $this->almacenQuery->findByCode($whInput);

        if ($warehouse === null) {
            $this->writeErr("Error: El almacén '{$whInput}' no existe." . PHP_EOL);
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
