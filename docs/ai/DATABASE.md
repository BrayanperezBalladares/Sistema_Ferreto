# Database Architecture — Sistema Ferreto

> **Audience**: AI Coding Agents & Database Engineers
> **Engine**: MariaDB 10.4.19+ | Storage Engine: InnoDB | Collation: `utf8mb4_unicode_ci`

---

## 1. Migration History & Schema Definition

Database schema changes are strictly versioned, deterministic, and executed sequentially from `database/migrations/`:

| Migration File | Primary Table | Purpose / Architectural Invariants |
|---|---|---|
| `0001_probe.up.sql` | `infrastructure_probe` | Diagnostic connectivity table used to verify database connectivity. |
| `0002_create_categoria.up.sql` | `categoria` | Catalog categorization. Uniqueness on `nombre` (`uk_categoria_nombre`). Self-healing default fallback. |
| `0003_create_producto.up.sql` | `producto` | Core product catalog. `precio_actual` uses `DECIMAL(12,2)`. Foreign key to `categoria(id_categoria)`. No SKU, barcode, or unique product-name constraint. |
| `0004_create_ubicacion.up.sql` | `ubicacion` | Physical warehouse locations. Uniqueness on `codigo` (`uk_ubicacion_codigo`). `estado_activo` flag. |
| `0005_create_inventario_stock.up.sql` | `inventario_stock` | Product-to-location mapping. `cantidad` uses `DECIMAL(12,3)`. Composite uniqueness on `(id_producto, id_ubicacion)`. |
| `0006_create_conteo_inventario.up.sql` | `conteo_inventario` | Observational physical inventory audits. Stores `cantidad_sistema` and `cantidad_contada` (`DECIMAL(12,3)`), and persisted variance `diferencia` calculated during insert as `(:qty - s.cantidad)` by `CountCommand`. |
| `0007_create_usuario.up.sql` | `usuario` | User accounts, credentials, and roles. Password hash (bcrypt cost 10), failed login attempts, lockout state (`bloqueado`), and timestamps. |

### Technical Tables
- **`schema_migrations`**: Bootstrapped automatically by `App\Foundation\MigrationRunner` if not present. Tracks applied migration identifiers, sha256 checksums, and execution timestamps (`identifier VARCHAR(255) PRIMARY KEY`, `checksum CHAR(64) NOT NULL`, `applied_at DATETIME NOT NULL`).
- **`infrastructure_probe`**: Created by `0001_probe.up.sql` to verify database connectivity and isolation (`id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `created_at DATETIME NOT NULL`).

---

## 2. Entity-Relationship Model (ASCII Diagram)

```
       +-----------------------+
       |       categoria       |
       +-----------------------+
       | PK  id_categoria      |
       |     nombre (UNIQUE)   |
       |     descripcion       |
       |     created_at        |
       |     updated_at        |
       +-----------+-----------+
                   | 1
                   |
                   | N
       +-----------v-----------+                       +------------------------+
       |       producto        |                       |       ubicacion        |
       +-----------------------+                       +------------------------+
       | PK  id_producto       |                       | PK  id_ubicacion       |
       | FK  id_categoria      |                       |     codigo (UNIQUE)    |
       |     nombre            |                       |     descripcion        |
       |     descripcion       |                       |     estado_activo      |
       |     precio_actual     |                       |     created_at         |
       |     estado_activo     |                       |     updated_at         |
       |     created_at        |                       +-----------+------------+
       |     updated_at        |                                   | 1
       +-----------+-----------+                                   |
                   | 1                                             |
                   |                                               |
                   +-----------------------+  +--------------------+
                                           |  |
                                           |N |N
                               +-----------v--v-----------+
                               |     inventario_stock     |
                               +--------------------------+
                               | PK  id_stock             |
                               | FK  id_producto          |
                               | FK  id_ubicacion         |
                               |     cantidad (DEC(12,3)) |
                               |     UNIQUE(prod, ubic)   |
                               |     created_at           |
                               |     updated_at           |
                               +------------+-------------+
                                            | 1
                                            |
                                            | N
                               +------------v-------------+
                               |     conteo_inventario    |
                               +--------------------------+
                               | PK  id_conteo            |
                               | FK  id_stock             |
                               |     cantidad_sistema     |
                               |     cantidad_contada     |
                               |     diferencia           |
                               |     notas                |
                               |     created_at           |
                               +--------------------------+

       +-------------------------------+
       |            usuario            |
       +-------------------------------+
       | PK  id_usuario                |
       |     username (UNIQUE)         |
       |     password_hash             |
       |     rol (VARCHAR(30))         |  --> CHECK ('administrador', 'cajero', 'bodeguero', 'compras')
       |     estado (VARCHAR(20))      |  --> CHECK ('creado', 'activo', 'bloqueado', 'inactivo')
       |     failed_attempt_count      |
       |     failure_window_started_at |
       |     locked_at                 |
       |     created_at                |
       |     updated_at                |
       +-------------------------------+
```

---

## 3. Decimal Precision Constraints & Float Prohibition

Hardware store items involve discrete units (e.g. hammers, drills), continuous measurements (e.g. meters of cable, kilograms of nails), and currency:

- **Monetary Values (`precio_actual`)**: Must **always** use `DECIMAL(12,2)`.
- **Stock Quantities (`cantidad`, `cantidad_sistema`, `cantidad_contada`, `diferencia`)**: Must **always** use `DECIMAL(12,3)`.
- **STRICT PROHIBITION**:
  - **NEVER** cast database numbers to PHP `float` or `double` for calculation.
  - Floating-point arithmetic produces precision drift (e.g. `0.1 + 0.2 = 0.30000000000000004`), which corrupts stock audits and accounting.
  - Pass decimals as strings or use exact string-based / BCMath operations if needed.

---

## 4. The Observational Count Invariant

The table `conteo_inventario` serves a strict architectural purpose:

> **Invariant**: Records inserted into `conteo_inventario` are purely **observational audits**.
> Submitting a physical count **MUST NEVER** update, overwrite, or mutate the `cantidad` column in `inventario_stock`.

Stock reconciliation (adjusting system quantity to match physical count) is a separate business event requiring explicit administrative authorization, which will be implemented in a future release.

### Persisted Variance Column (`diferencia`)
In the schema (`0006_create_conteo_inventario.up.sql`), `diferencia` is defined as a standard persisted column: `diferencia DECIMAL(12,3) NOT NULL`. It is **NOT** a database-level generated or virtual column (`GENERATED ALWAYS AS`). The variance calculation is executed by `App\Modules\Inventory\CountCommand` during the atomic `INSERT INTO ... SELECT` query:
```sql
SELECT s.id_stock, s.cantidad, :qty_val, (:qty_calc - s.cantidad), :notas, UTC_TIMESTAMP()
FROM inventario_stock s WHERE s.id_stock = :id_stock
```
This guarantees that `diferencia = cantidad_contada - cantidad_sistema` is calculated against the exact snapshot of `cantidad_sistema` at the moment of insertion and stored durably.

---

## 5. Test Isolation & Database Separation

Testing must never mutate development or production data:

- **Development Database**: `ferreto` (configured via `DB_NAME` or default).
- **Test Database**: `ferreto_test` (configured via `TEST_DB_NAME`).
- **Enforcement Rules**:
  1. `TEST_DB_NAME` **must** end with `_test`. Any test attempting to run against a database not ending with `_test` will abort immediately with an exception.
  2. Tests run inside `Tests\Support\DatabaseIsolationTrait`, which verifies the development database before and after each test suite to prove zero data leakage or mutation.
  3. Migrations are executed dynamically in the test database during `setUpBeforeClass()`.

---

## 6. User Provisioning & Account Creation

To maintain strict security and reproducibility:
- **No User Seeds in Migrations**: Default accounts or hardcoded credentials are **strictly prohibited** in SQL migrations or `database/seeds/`.
- **CLI Provisioning**: Accounts are created exclusively via the console CLI tool:
  ```powershell
  php scripts/console.php create-user <username> <rol>
  ```
  The CLI interactively prompts for a password conforming to the security policy (15+ chars, $\le 72$ bytes), validates it, hashes it with bcrypt cost 10, and inserts the user into `usuario`.
- **Administrative Account Unlocking**:
  ```powershell
  php scripts/console.php unlock-user <username>
  ```
  Resets `failed_attempt_count = 0`, sets `estado = 'activo'`, and logs the unlock event.
