# Database Architecture — Sistema Ferreto

> **Audience**: AI Coding Agents & Database Engineers
> **Engine**: MariaDB 10.4.19+ | Storage Engine: InnoDB | Collation: `utf8mb4_unicode_ci`

---

## 1. Migration History & Schema Definition

Database schema changes are strictly versioned, deterministic, and executed sequentially from `database/migrations/`:

| Migration File | Primary Table | Purpose / Architectural Invariants |
|---|---|---|
| `0001_infrastructure_probe.sql` | `infrastructure_probe` | Diagnostic connectivity table used to verify database connectivity. |
| `0002_producto_categoria.sql` | `producto_categoria` | Catalog categorization. Uniqueness on `nombre`. Self-healing default fallback. |
| `0003_producto.sql` | `producto` | Core product catalog. `precio_venta` uses `DECIMAL(12,2)`. Foreign key to `producto_categoria(id_categoria)`. Uniqueness on SKU / name. |
| `0004_inventario_ubicacion.sql` | `inventario_ubicacion` | Physical warehouse locations. Uniqueness on `codigo` (e.g. `PAS-01-A`). `estado_activo` flag. |
| `0005_inventario_stock.sql` | `inventario_stock` | Product-to-location mapping. `cantidad` uses `DECIMAL(12,3)`. Composite uniqueness on `(id_producto, id_ubicacion)`. |
| `0006_conteo_inventario.sql` | `conteo_inventario` | Observational physical inventory audits. Stores `cantidad_sistema` and `cantidad_contada` (`DECIMAL(12,3)`), plus computed `diferencia`. |
| `0007_usuario.sql` | `usuario` | User accounts, credentials, and roles. Password hash (bcrypt cost 10), failed login attempts, lockout state (`bloqueado`), and timestamps. |

---

## 2. Entity-Relationship Model (ASCII Diagram)

```
       +-----------------------+
       |  producto_categoria   |
       +-----------------------+
       | PK  id_categoria      |
       |     nombre (UNIQUE)   |
       |     descripcion       |
       |     estado_activo     |
       +-----------+-----------+
                   | 1
                   |
                   | N
       +-----------v-----------+                       +------------------------+
       |       producto        |                       |  inventario_ubicacion  |
       +-----------------------+                       +------------------------+
       | PK  id_producto       |                       | PK  id_ubicacion       |
       | FK  id_categoria      |                       |     codigo (UNIQUE)    |
       |     sku (UNIQUE)      |                       |     descripcion        |
       |     nombre            |                       |     estado_activo      |
       |     precio_venta      |                       +-----------+------------+
       |     estado_activo     |                                   | 1
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

       +-----------------------+
       |        usuario        |
       +-----------------------+
       | PK  id_usuario        |
       |     username (UNIQUE) |
       |     password_hash     |
       |     rol (ENUM)        |  --> ('administrador', 'bodeguero', 'cajero', 'compras')
       |     estado (ENUM)     |  --> ('activo', 'inactivo', 'bloqueado')
       |     failed_attempts   |
       |     last_failed_at    |
       |     created_at        |
       +-----------------------+
```

---

## 3. Decimal Precision Constraints & Float Prohibition

Hardware store items involve discrete units (e.g. hammers, drills), continuous measurements (e.g. meters of cable, kilograms of nails), and currency:

- **Monetary Values (`precio_venta`)**: Must **always** use `DECIMAL(12,2)`.
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
