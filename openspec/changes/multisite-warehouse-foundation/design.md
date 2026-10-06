# Technical Design: Multisite Warehouse Foundation

## 1. System Architecture & Relational Hierarchy

This design establishes the core physical and organizational facility hierarchy for **Ferreterías El Constructor**, defining commercial branches (`sucursales`) and storage warehouses (`almacenes`), and associating physical storage locations (`ubicaciones`) with specific warehouses under an **Expand → Adapt → Contract** architectural lifecycle structured across **two deployable releases (PRs)**.

```
┌─────────────────────────────────────────────────────────────┐
│                          SUCURSAL                           │
│  (Commercial Branch: code, name, city, address, phone)      │
└──────────────────────────────┬──────────────────────────────┘
                               │ 1
                               │ has many
                               ▼ N
┌─────────────────────────────────────────────────────────────┐
│                           ALMACEN                           │
│  (Warehouse Storage Facility: code, name, type, branch FK)   │
└──────────────────────────────┬──────────────────────────────┘
                               │ 1
                               │ has many
                               ▼ N
┌─────────────────────────────────────────────────────────────┐
│                          UBICACION                          │
│  (Physical Coordinate: global unique code, warehouse FK)    │
└──────────────────────────────┬──────────────────────────────┘
                               │ 1
                               │ has many
                               ▼ N
┌─────────────────────────────────────────────────────────────┐
│                      INVENTARIO_STOCK                       │
│  (Stock Position: product FK, location FK, decimal quantity)│
└──────────────────────────────┬──────────────────────────────┘
                               │ 1
                               │ audited by
                               ▼ N
┌─────────────────────────────────────────────────────────────┐
│                      CONTEO_INVENTARIO                      │
│  (Observational Count Snapshot: stock FK, count, variance)  │
└─────────────────────────────────────────────────────────────┘
```

### 1.1 Strict 3NF Data Model Proof

Stock in Sistema Ferreto represents the physical quantity of a specific product stored at a specific physical storage coordinate.

- **Candidate Key of `inventario_stock`**: `(id_producto, id_ubicacion)` (enforced via `UNIQUE KEY uq_stock_producto_ubicacion`).
- **Functional Dependencies**:
  1. `id_stock -> id_producto, id_ubicacion, cantidad`
  2. `id_ubicacion -> id_almacen` (A physical location belongs to exactly one warehouse).
  3. `id_almacen -> id_sucursal` (A warehouse belongs to exactly one commercial branch).
- **Proof Against Denormalization**:
  - If `id_almacen` or `id_sucursal` were added directly to `inventario_stock`, we would introduce the transitive functional dependency:
    $$\text{id\_stock} \longrightarrow \text{id\_ubicacion} \longrightarrow \text{id\_almacen} \longrightarrow \text{id\_sucursal}$$
  - This violates **Third Normal Form (3NF)** ($X \to Y$ where $Y$ is not part of a candidate key and $X$ is not a superkey).
  - Storing `id_almacen` on `inventario_stock` would also create an update anomaly: if data were inserted or modified directly, `inventario_stock.id_almacen` could conflict with `ubicacion.id_almacen`, corrupting warehouse stock reporting.
- **Dynamic Roll-Up Computation**:
  - Warehouse stock is computed dynamically from canonical location stock:
    ```sql
    SELECT s.id_producto, SUM(s.cantidad) AS stock_almacen
    FROM inventario_stock s
    JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion
    WHERE u.id_almacen = :id_almacen AND s.id_producto = :id_producto
    GROUP BY s.id_producto;
    ```
  - Branch stock is computed dynamically:
    ```sql
    SELECT s.id_producto, SUM(s.cantidad) AS stock_sucursal
    FROM inventario_stock s
    JOIN ubicacion u ON u.id_ubicacion = s.id_ubicacion
    JOIN almacen a ON a.id_almacen = u.id_almacen
    WHERE a.id_sucursal = :id_sucursal AND s.id_producto = :id_producto
    GROUP BY s.id_producto;
    ```
  - Both queries leverage primary key and foreign key B-tree indexes, ensuring efficient execution without redundant storage.

---

## 2. Schema Definition & Traceability Analysis

Every table, column, and constraint is explicitly classified:
- **SUPPORTED**: Directly mandated by original business requirements (`especificacion_requerimientos_sistema_ferretero.md`, `mod_cuentas_accesos.md`, `mod_inventarios_catalogo.md`).
- **DERIVED**: Logically inferred from operational workflows described in requirements.
- **AMBIGUOUS**: Underspecified in original documents, resolved by explicit maintainer decision.
- **TECHNICAL DESIGN**: Engineering decisions of Sistema Ferreto (surrogate PKs, alphanumeric codes, timestamps, CHECK constraints).

### 2.1 Table `sucursal`

| Column | Type | Classification | Rationale & Evidence |
|---|---|---|---|
| `id_sucursal` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | TECHNICAL DESIGN | Surrogate primary key standard across all tables in Sistema Ferreto. |
| `codigo` | `VARCHAR(30) NOT NULL UNIQUE` | TECHNICAL DESIGN / BUSINESS IDENTIFIER | Alphanumeric unique identifier (e.g. `SUC-01`) for deterministic referencing, slugs, and export tags. |
| `nombre` | `VARCHAR(100) NOT NULL` | SUPPORTED | Mandated in SRS and `mod_cuentas_accesos.md` (e.g. "Sucursal Central", "Sucursal San Jerónimo"). |
| `ciudad` | `VARCHAR(100) NOT NULL` | SUPPORTED | Explicitly mandated in SRS ("Campos: id_sucursal, nombre, direccion, ciudad") and `mod_cuentas_accesos.md`. |
| `direccion` | `VARCHAR(255) NULL` | SUPPORTED | Physical street address. Nullable because some facilities may be registered before street assignment. |
| `telefono` | `VARCHAR(30) NULL` | SUPPORTED | Contact phone number. Nullable. |
| `estado_activo` | `TINYINT(1) NOT NULL DEFAULT 1` | SUPPORTED / TECHNICAL DESIGN | Boolean operational lifecycle state (1: Active, 0: Inactive) with CHECK constraint `IN (0, 1)`. |
| `created_at` | `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` | TECHNICAL DESIGN | Framework timestamp for audit and synchronization. |
| `updated_at` | `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` | TECHNICAL DESIGN | Framework timestamp for audit and synchronization. |

### 2.2 Table `almacen`

| Column | Type | Classification | Rationale & Evidence |
|---|---|---|---|
| `id_almacen` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | TECHNICAL DESIGN | Surrogate primary key. |
| `id_sucursal` | `INT UNSIGNED NOT NULL` | SUPPORTED | Foreign key to `sucursal(id_sucursal)` with `ON DELETE RESTRICT`. |
| `codigo` | `VARCHAR(30) NOT NULL UNIQUE` | TECHNICAL DESIGN / BUSINESS IDENTIFIER | Alphanumeric unique identifier (e.g. `ALM-CENTRAL`, `ALM-01`). |
| `nombre` | `VARCHAR(100) NOT NULL` | SUPPORTED | Mandated in `mod_inventarios_catalogo.md` (e.g. "Almacén Principal", "Patio de Fierros", "Bodega Merma"). |
| `tipo` | `VARCHAR(20) NOT NULL DEFAULT 'bodega'` | DERIVED / TECHNICAL DESIGN | Functional storage area type. CHECK constraint restricts to `('bodega', 'mostrador', 'patio', 'merma')`. |
| `estado_activo` | `TINYINT(1) NOT NULL DEFAULT 1` | TECHNICAL DESIGN | Boolean operational lifecycle state with CHECK constraint `IN (0, 1)`. |
| `created_at` | `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` | TECHNICAL DESIGN | Framework timestamp. |
| `updated_at` | `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` | TECHNICAL DESIGN | Framework timestamp. |

> [!NOTE]
> **Transit Warehouse Scope Note**: Transit warehouse semantics are intentionally deferred to the future transfer capability. The functional storage types above represent the physical storage domains needed for this foundation.

### 2.3 Table `ubicacion` (Modification)

| Column | Type | Classification | Rationale & Evidence |
|---|---|---|---|
| `id_almacen` | `INT UNSIGNED NULL` (Release 1) / `NOT NULL` (Release 2) | SUPPORTED | Foreign key to `almacen(id_almacen)` with `ON DELETE RESTRICT`. |

---

## 3. MigrationRunner Contract & MariaDB Implicit Commits

The implementation strictly obeys the design contracts of `src/Foundation/MigrationRunner.php` and `src/Foundation/Database.php`:
1. **Single-Statement Rule**: Exactly **one executable DDL statement** per `.up.sql` and `.down.sql` file. `Pdo\Mysql::ATTR_MULTI_STATEMENTS` is disabled (`false`). Combining multiple DDL statements causes execution to throw a fatal error.
2. **MariaDB Implicit Commits**: In MariaDB, DDL statements (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) trigger an implicit transaction commit. The migration runner is non-transactional. Execution of `$this->pdo->exec($sql)` throws on failure; `MigrationRunner` releases its advisory lock in `finally`, and the caller (`Console` or test runner) catches the exception and returns failure.
3. **Sequential Execution Without Pauses**: `MigrationRunner` discovers and executes **all pending `*.up.sql` migrations in sorted order**. It has no supported mechanism to stop midway. Therefore, a contract enforcement migration (`0011`) **cannot coexist in the migration folder** with the expand migrations (`0008`..`0010`) on a populated database awaiting operator mapping. Fail-stop is not a valid deployment boundary; the boundary must be established across separate releases.

---

## 4. Two-Release Deployment Architecture & Operator Boundary

### 4.1 Release 1: EXPAND + ADAPT (PR 1)

Release 1 introduces the schema additions and runtime tooling while intentionally withholding migration `0011`.

#### Migrations Included:
1. `database/migrations/0008_create_sucursal.up.sql`:
   ```sql
   CREATE TABLE sucursal (
       id_sucursal   INT UNSIGNED NOT NULL AUTO_INCREMENT,
       codigo        VARCHAR(30)  NOT NULL,
       nombre        VARCHAR(100) NOT NULL,
       ciudad        VARCHAR(100) NOT NULL,
       direccion     VARCHAR(255) NULL,
       telefono      VARCHAR(30)  NULL,
       estado_activo TINYINT(1)   NOT NULL DEFAULT 1,
       created_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
       updated_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
       PRIMARY KEY (id_sucursal),
       CONSTRAINT uq_sucursal_codigo UNIQUE (codigo),
       CONSTRAINT chk_sucursal_activo CHECK (estado_activo IN (0, 1))
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```
   *Reversal (`0008_create_sucursal.down.sql`)*: `DROP TABLE sucursal;`

2. `database/migrations/0009_create_almacen.up.sql`:
   ```sql
   CREATE TABLE almacen (
       id_almacen    INT UNSIGNED NOT NULL AUTO_INCREMENT,
       id_sucursal   INT UNSIGNED NOT NULL,
       codigo        VARCHAR(30)  NOT NULL,
       nombre        VARCHAR(100) NOT NULL,
       tipo          VARCHAR(20)  NOT NULL DEFAULT 'bodega',
       estado_activo TINYINT(1)   NOT NULL DEFAULT 1,
       created_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
       updated_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
       PRIMARY KEY (id_almacen),
       CONSTRAINT uq_almacen_codigo UNIQUE (codigo),
       CONSTRAINT fk_almacen_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal (id_sucursal) ON DELETE RESTRICT,
       CONSTRAINT chk_almacen_tipo CHECK (tipo IN ('bodega', 'mostrador', 'patio', 'merma')),
       CONSTRAINT chk_almacen_activo CHECK (estado_activo IN (0, 1))
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```
   *Reversal (`0009_create_almacen.down.sql`)*: `DROP TABLE almacen;`

3. `database/migrations/0010_add_ubicacion_almacen_nullable.up.sql`:
   ```sql
   ALTER TABLE ubicacion
       ADD COLUMN id_almacen INT UNSIGNED NULL AFTER id_ubicacion,
       ADD CONSTRAINT fk_ubicacion_almacen FOREIGN KEY (id_almacen) REFERENCES almacen (id_almacen) ON DELETE RESTRICT;
   ```
   *Reversal (`0010_add_ubicacion_almacen_nullable.down.sql`)*:
   ```sql
   ALTER TABLE ubicacion DROP FOREIGN KEY fk_ubicacion_almacen, DROP COLUMN id_almacen;
   ```

#### Application Runtime Behavior (ADAPT):
- **Domain Guard on Location Creation**: Even though `ubicacion.id_almacen` is nullable in the database, `LocationCommand::create` strictly requires a valid, active warehouse. No newly created location can have `id_almacen = NULL`.
- **Nullable-Safe Visibility**: `LocationQuery` and inventory views use `LEFT JOIN` on `almacen` and `sucursal`. Existing unmapped rows (`id_almacen IS NULL`) remain visible and are displayed with warehouse/branch status "Sin asignar" / "Pendiente de mapeo". No rows are silently dropped by `INNER JOIN`.
- **Template Registration**: Register `page.branches` and `page.warehouses` in `Renderer::TEMPLATES`.

### 4.2 Operator Transition Boundary (Between Releases)

After deploying Release 1:
1. Operator creates legitimate real commercial branches via the UI (`POST /branches`).
2. Operator creates legitimate real storage warehouses via the UI (`POST /warehouses`).
3. Operator maps each existing legacy location using the atomic CLI command:
   ```powershell
   php scripts/console.php map-location <location-identifier> <warehouse-identifier>
   ```
   **Atomic Contract**:
   ```sql
   UPDATE ubicacion
   SET id_almacen = :warehouse_id
   WHERE id_ubicacion = :location_id
     AND id_almacen IS NULL;
   ```
   - Must affect exactly 1 row (`rowCount() === 1`).
   - If the location already has an assigned warehouse, or if the warehouse is inactive/missing, the command throws and aborts without mutating data.
   - Preserves `id_ubicacion`, `codigo`, `descripcion`, associated `inventario_stock` positions/quantities, and `conteo_inventario` records without alteration.
4. Operator verifies mapping completeness:
   ```powershell
   php scripts/console.php verify-locations-mapped
   ```
   - Checks `SELECT COUNT(*) FROM ubicacion WHERE id_almacen IS NULL`.
   - Exits with status 0 only when the count is exactly 0.
   - Exits with non-zero status if any unmapped rows remain.

### 4.3 Release 2: CONTRACT (PR 2)

Deployed ONLY after `verify-locations-mapped` confirms 0 unmapped rows remain:

1. `database/migrations/0011_enforce_ubicacion_almacen_not_null.up.sql`:
   ```sql
   ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL;
   ```
   *Reversal (`0011_enforce_ubicacion_almacen_not_null.down.sql`)*:
   ```sql
   ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL;
   ```

---

## 5. Dual Database Execution Paths

### 5.1 Fresh Database Path (CI / Fresh Install)
- Release 1 executes migrations `0008`, `0009`, `0010`. `ubicacion` starts empty.
- Tests/fixtures create branches, warehouses, and locations with explicit warehouse IDs.
- Release 2 executes migration `0011`. Since no NULL rows exist, `0011` succeeds immediately.

### 5.2 Populated Database Path (Existing Development / Production)
- Database has existing locations (e.g. `id_ubicacion = 1` "CENTRAL", `id_ubicacion = 2` "bod-a2").
- Release 1 executes `0008`, `0009`, `0010`. Existing locations acquire `id_almacen = NULL`.
- Existing locations remain fully visible and operational via nullable-safe queries.
- Operator explicitly maps locations 1 and 2 to verified real warehouses via `map-location`.
- Operator runs `verify-locations-mapped` (exits 0).
- Release 2 is deployed and executes `0011`, locking in the `NOT NULL` constraint without failure.

---

## 6. Lifecycle & Parent Immutability Contracts

### 6.1 Operational Lifecycle Rules

| Entity | Active State (`estado_activo = 1`) | Inactive State (`estado_activo = 0`) |
|---|---|---|
| `sucursal` | Normal operation. Permitted to create child warehouses. | Closed branch. **Prohibits** creating new warehouses under this branch. Does **not** cascade inactive status to child warehouses. |
| `almacen` | Normal operation. Permitted to create child locations. | Closed warehouse. **Prohibits** creating new physical locations under this warehouse. Prohibits reactivation if parent branch is inactive. Does **not** cascade inactive status to child locations. |
| `ubicacion` | Normal operation. Available for establishing new stock positions. | Closed location. Prohibits selecting this location when establishing new stock positions. |

- **Creation & Reactivation Guards**: Requiring an active parent is a check performed during creation and reactivation operations, not a continuous invariant that would invalidate independent statuses if a parent is deactivated later.
- **Historical Visibility**: Deactivating a branch or warehouse does not hide or delete historical stock positions or observational counts. Aggregate stock rollups continue to accurately reflect existing stock.

### 6.2 Parent Immutability Invariant

To preserve the auditability of physical counts and stock history:
1. **Location Warehouse Immutability**: Once a location is created or assigned to a warehouse, its `id_almacen` **cannot be modified** through normal application operations. Changing `id_almacen` via HTTP/UI is rejected with a validation error (`422 Unprocessable Entity`).
2. **Warehouse Branch Immutability**: Once a warehouse is created, its parent branch reference (`id_sucursal`) **cannot be modified** through normal application operations.
3. **No Reparenting Operation**: No HTTP endpoint or UI form will expose a reparenting action in this capability. `map-location` only updates rows where `id_almacen IS NULL`.
4. **Physical Deletion Prohibition**: Physical deletion of locations holding any stock records (even with zero quantity) is prohibited by foreign key `RESTRICT` and domain pre-checks.

---

## 7. Access Control & Route Authorization Policy

### 7.1 Route Access Matrix
In accordance with `AGENTS.md` and `RouteAccessPolicy`, all new routes are explicitly mapped with conservative authorization:

| HTTP Method | Route Path | Handler & Action | Allowed Roles | Exemption |
|---|---|---|---|---|
| `GET` | `/branches` | `BranchHandler::index` | `administrador` | No |
| `POST` | `/branches` | `BranchHandler::create` | `administrador` | No |
| `POST` | `/branches/toggle-active` | `BranchHandler::toggleActive` | `administrador` | No |
| `GET` | `/warehouses` | `WarehouseHandler::index` | `administrador`, `bodeguero` | No |
| `POST` | `/warehouses` | `WarehouseHandler::create` | `administrador` | No |
| `POST` | `/warehouses/toggle-active` | `WarehouseHandler::toggleActive` | `administrador` | No |
| `GET` | `/locations` | `LocationHandler::index` | `administrador`, `bodeguero` | No (Existing) |
| `POST` | `/locations` | `LocationHandler::create` | `administrador`, `bodeguero` | No (Existing) |

> [!NOTE]
> `POST /locations/toggle-active` is excluded from the scope of this change to maintain bounded scope.

### 7.2 Fail-Closed Authorization Semantics
- **Authenticated requests** to registered non-exempt routes lacking an explicit entry in `RouteAccessPolicy::MATRIX` return **HTTP 403 Forbidden**.
- **Unauthenticated requests** to protected routes are redirected by `AuthGuard` to `/login` (HTTP 303 for standard browser navigation, HTTP 200 with `HX-Redirect: /login` and empty body for HTMX requests).
- **Unregistered routes** return **HTTP 404 Not Found** from `Router`.
- **Unsupported HTTP methods** return **HTTP 405 Method Not Allowed** from `Router`.

---

## 8. UI, Templates & Renderer Allowlist

### 8.1 Template Allowlist Registration
`src/Foundation/Renderer.php` enforces an explicit template allowlist in `Renderer::TEMPLATES`. The new views must be registered:
```php
'page.branches' => 'pages/branches.php',
'page.warehouses' => 'pages/warehouses.php',
```

### 8.2 UI Design System Conformance
All templates conform strictly to `docs/ui/DESIGN.md`:
- Pure PHP templates, Bulma 1.0.4 CSS tokens, and HTMX 2.0.10 interactions.
- Responsive layout supporting viewports down to 360px without horizontal clipping.
- Minimum touch target sizing of $\ge 44 \times 44\text{ px}$ for all buttons and interactive controls.
- Mandatory CSRF protection (`Csrf::TOKEN_KEY`) on all POST forms.
- Mandatory HTML escaping via `Renderer::escape()`.
- Navigation drawer and topbar links in `templates/layout.php` evaluate permissions via `ViewPermissions` (`canManageBranches()`, `canManageWarehouses()`).

---

## 9. Verification & Testing Strategy

The test plan exercises every architectural boundary:
1. **Migration Isolation & Lifecycle (`tests/Integration/MultisiteMigrationTest.php`)**:
   - Fresh DB: single-statement execution of `0008`, `0009`, `0010`.
   - Populated DB upgrade: existing rows acquire NULL.
   - Repeated migration execution leaves schema intact.
   - Partial failure / retry behavior.
   - Checksum drift validation across migrations.
   - Dependency-aware reversal `0010.down`, `0009.down`, `0008.down`.
   - Release 2: verify `0011` fails if unmapped rows exist, and succeeds when fully mapped.
2. **Administrative Mapping CLI (`tests/Integration/ConsoleMultisiteTest.php`)**:
   - Exercise `map-location` atomically updating `id_almacen IS NULL` rows.
   - Verify rejection of already-mapped locations, repeated mappings, and nonexistent entities.
   - Verify `verify-locations-mapped` returns non-zero when unmapped rows exist, and 0 when clean.
   - Verify existing stock and count records remain unchanged after mapping.
3. **CQS Persistence & Immutability (`tests/Integration/MultisitePersistenceTest.php`)**:
   - Branch and warehouse CRUD, code uniqueness, and active toggles.
   - Structural creation guards (inactive branch rejects warehouse; inactive warehouse rejects location; inactive branch rejects warehouse reactivation).
   - Parent immutability (prohibit changing location's `id_almacen` and warehouse's `id_sucursal`).
   - Location deletion restriction with stock positions.
   - Dynamic stock rollups matching exact expected sums.
   - Mixed-state visibility: unmapped legacy locations remain visible alongside mapped locations.
4. **HTTP & Authorization (`tests/Integration/MultisiteHttpTest.php`)**:
   - RoleGuard matrix verification: `administrador` full access, `bodeguero` warehouse read-only, `cajero` and `compras` 403 Forbidden.
   - Fail-closed regression for missing policy entries.
   - CSRF protection: valid token succeeds, missing/invalid token rejected.
   - Unauthenticated browser 303 redirect vs HTMX `HX-Redirect` behavior.
   - Public entrypoint GET route testing (`tests/Integration/ProductionEntrypointTest.php`).
5. **Fixture Adaptation & Reset Consistency**:
   - Update `StockTest`, `CountTest`, `LocationHttpTest`, and migration reset helpers to create parent warehouses/branches.
   - Prove reset -> migration history consistent -> rerun -> correct final schema.
