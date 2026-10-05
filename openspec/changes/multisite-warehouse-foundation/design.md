# Technical Design: Multisite Warehouse Foundation

## 1. System Architecture & Relational Hierarchy

This design establishes the core physical and organizational facility hierarchy for **Ferreterías El Constructor**, defining commercial branches (`sucursales`) and storage warehouses (`almacenes`), and associating physical storage locations (`ubicaciones`) with specific warehouses under an **Expand → Adapt → Contract** architectural lifecycle.

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
  - Storing `id_almacen` on `inventario_stock` would also create a serious update anomaly: if data were inserted or modified directly, `inventario_stock.id_almacen` could conflict with `ubicacion.id_almacen`, corrupting warehouse stock reporting.
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

To maintain architectural transparency, every table, column, and constraint is explicitly classified:
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
| `id_almacen` | `INT UNSIGNED NULL` (during EXPAND) / `NOT NULL` (after CONTRACT) | SUPPORTED | Foreign key to `almacen(id_almacen)` with `ON DELETE RESTRICT`. |

---

## 3. Migration Runner Compatibility & MariaDB Implicit Commits

The implementation strictly obeys the design contracts of `src/Foundation/MigrationRunner.php` and `src/Foundation/Database.php`:
1. **Single-Statement Rule**: Exactly **one executable DDL statement** per `.up.sql` and `.down.sql` file. `Pdo\Mysql::ATTR_MULTI_STATEMENTS` is disabled (`false`). Combining multiple DDL statements causes execution to throw a fatal error.
2. **MariaDB Implicit Commits**: In MariaDB, DDL statements (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) trigger an implicit transaction commit. The migration runner is therefore **fail-stop and non-transactional**. History (`schema_migrations`) is recorded ONLY after the statement executes successfully.
3. **Reversibility**: Every `.down.sql` file is a compensating reversal executing a single DDL statement.
4. **Checksum Drift**: Existing migrations (`0001` through `0007`) are immutable. Their checksums must not change.

---

## 4. Expand → Adapt → Contract Migration Strategy

### 4.1 Current Development Database Evidence
Inspection of the active development database confirms:
- Location 1: `id_ubicacion = 1`, `codigo = 'CENTRAL'`, `descripcion = 'Bodega Central'`, referenced by `inventario_stock` row 1 (`id_stock = 1`, `id_producto = 2`, `cantidad = 2000.000`), with zero count records.
- Location 2: `id_ubicacion = 2`, `codigo = 'bod-a2'`, `descripcion = 'Bodega calle 2'`, referenced by `inventario_stock` row 2 (`id_stock = 2`, `id_producto = 2`, `cantidad = 300.000`), with observational count record 1 (`id_conteo = 1`, `cantidad_sistema = 300.000`, `cantidad_contada = 250.000`, `diferencia = -50.000`).

These facts prove existing relational dependencies. They do **not** prove business warehouse ownership. Therefore, **no artificial branches or warehouses will be seeded in migrations**, and no speculative mapping will be performed.

### 4.2 Three-Stage Migration Sequencing

#### Stage 1: EXPAND (Schema Additions)
Executes additive, non-breaking schema definitions compatible with existing code and tests:

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

3. `database/migrations/0010_add_ubicacion_almacen.up.sql`:
   ```sql
   ALTER TABLE ubicacion
       ADD COLUMN id_almacen INT UNSIGNED NULL AFTER id_ubicacion,
       ADD CONSTRAINT fk_ubicacion_almacen FOREIGN KEY (id_almacen) REFERENCES almacen (id_almacen) ON DELETE RESTRICT;
   ```
   *Reversal (`0010_add_ubicacion_almacen.down.sql`)*:
   ```sql
   ALTER TABLE ubicacion DROP FOREIGN KEY fk_ubicacion_almacen, DROP COLUMN id_almacen;
   ```

At the completion of EXPAND:
- The database schema supports branches, warehouses, and location warehouse foreign keys.
- Existing location rows remain completely untouched with `id_almacen IS NULL`.
- All existing queries and tests continue functioning without interruption.

#### Stage 2: ADAPT (Runtime Enablement & Explicit Mapping)
1. **Application Deployment**: The application is deployed with support for Branch and Warehouse management (`BranchHandler`, `WarehouseHandler`), and new location creation forms require selecting an active warehouse.
2. **Explicit Administrative Mapping**:
   - An administrator provisions legitimate commercial branches and storage warehouses using the web UI or database console.
   - An administrative CLI tool is provided to map legacy locations explicitly:
     ```powershell
     php scripts/console.php map-location <location-id-or-code> <warehouse-id-or-code>
     ```
     This command:
     - Verifies the location exists.
     - Verifies the warehouse exists and is active.
     - Sets `ubicacion.id_almacen = :warehouse_id`.
     - Preserves `id_ubicacion`, `codigo`, `descripcion`, and all associated stock and count records.
3. **Completeness Verification Tool**:
   - An automated preflight command verifies readiness:
     ```powershell
     php scripts/console.php verify-locations-mapped
     ```
     This executes:
     ```sql
     SELECT COUNT(*) FROM ubicacion WHERE id_almacen IS NULL;
     ```
     If the count is greater than 0, it outputs the unmapped location IDs and codes and exits with status 1.

#### Stage 3: CONTRACT (Enforcement)
Once all locations in the environment have been mapped:

1. `database/migrations/0011_enforce_ubicacion_almacen_not_null.up.sql`:
   ```sql
   ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL;
   ```
   *Reversal (`0011_enforce_ubicacion_almacen_not_null.down.sql`)*:
   ```sql
   ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL;
   ```

#### Fail-Stop & Deployment Boundary Invariants:
- If `0011` is attempted on a database where unmapped locations exist, MariaDB rejects the DDL with `Error 1138: Invalid use of NULL value`.
- `MigrationRunner` catches the exception and halts immediately without recording `0011` in `schema_migrations`.
- In fresh test environments (`*_test`), `0008` through `0011` execute sequentially in automated test runs because `ubicacion` starts empty, satisfying the `NOT NULL` constraint immediately.

---

## 5. Lifecycle & Parent Immutability Contracts

### 5.1 Operational Lifecycle Rules

| Entity | Active State (`estado_activo = 1`) | Inactive State (`estado_activo = 0`) |
|---|---|---|
| `sucursal` | Normal operation. Permitted to create child warehouses. | Closed branch. **Prohibits** creating new warehouses under this branch. Does **not** cascade inactive status to child warehouses. |
| `almacen` | Normal operation. Permitted to create child locations. | Closed warehouse. **Prohibits** creating new physical locations under this warehouse. Does **not** cascade inactive status to child locations. |
| `ubicacion` | Normal operation. Available for establishing new stock positions. | Closed location. Prohibits selecting this location when establishing new stock positions. |

- **Historical Visibility**: Deactivating a branch or warehouse does **not** hide or delete historical stock positions or observational counts. Aggregate stock rollups continue to accurately reflect existing stock until future business workflows (e.g. transfers) relocate items.
- **Reactivation**: An administrator may toggle an inactive branch or warehouse back to active status at any time, provided the parent entity is active.

### 5.2 Parent Immutability Invariant

To preserve the auditability of physical counts and stock history:
1. **Location Warehouse Immutability**: Once a location is created or assigned to a warehouse under the final contract, its `id_almacen` **cannot be modified** through normal application operations. Changing `id_almacen` via HTTP/UI is rejected with a validation error (`422 Unprocessable Entity`).
2. **Warehouse Branch Immutability**: Once a warehouse is created, its parent branch reference (`id_sucursal`) **cannot be modified** through normal application operations.
3. **No Reparenting Operation**: No HTTP endpoint or UI form will expose a reparenting action in this capability.
4. **Physical Deletion Prohibition**: Physical deletion of locations holding any stock records (even with zero quantity) is prohibited by foreign key `RESTRICT` and domain pre-checks.

---

## 6. Access Control & Route Authorization Policy

### 6.1 Route Access Matrix
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
> `POST /locations/toggle-active` was removed from the scope of this change to keep the capability tightly focused on the facility foundation.

### 6.2 Fail-Closed Authorization Semantics
- **Authenticated requests** to registered non-exempt routes lacking an explicit entry in `RouteAccessPolicy::MATRIX` return **HTTP 403 Forbidden**.
- **Unauthenticated requests** to protected routes are redirected by `AuthGuard` to `/login` (HTTP 303 for standard browser navigation, HTTP 200 with `HX-Redirect: /login` and empty body for HTMX requests).
- **Unregistered routes** return **HTTP 404 Not Found** from `Router`.
- **Unsupported HTTP methods** return **HTTP 405 Method Not Allowed** from `Router`.

---

## 7. UI, Templates & Renderer Allowlist

### 7.1 Template Allowlist Registration
`src/Foundation/Renderer.php` enforces a strict allowlist in `Renderer::TEMPLATES`. The new management views must be registered:
```php
'page.branches' => 'pages/branches.php',
'page.warehouses' => 'pages/warehouses.php',
```

### 7.2 UI Design System Conformance
All templates conform strictly to `docs/ui/DESIGN.md`:
- Pure PHP templates, Bulma 1.0.4 CSS tokens, and HTMX 2.0.10 interactions.
- Responsive layout supporting viewports down to 360px without horizontal clipping.
- Minimum touch target sizing of $\ge 44 \times 44\text{ px}$ for all buttons and interactive controls.
- Mandatory CSRF protection (`Csrf::TOKEN_KEY`) on all POST forms.
- Mandatory HTML escaping via `Renderer::escape()`.
- Navigation drawer and topbar links in `templates/layout.php` evaluate permissions via `ViewPermissions` (`canManageBranches()`, `canManageWarehouses()`).

---

## 8. Verification & Testing Strategy

The test plan exercises every architectural boundary:
1. **Migration Isolation & Lifecycle (`tests/Integration/MultisiteMigrationTest.php`)**:
   - Single-statement execution of `0008`, `0009`, `0010`, `0011`.
   - Compensating reversals `0011.down` through `0008.down`.
   - Checksum drift validation across migrations.
   - Populated test DB: verify that `0011` fails stop when unmapped locations exist, and succeeds once mapped.
2. **Administrative Mapping CLI (`tests/Integration/ConsoleMultisiteTest.php`)**:
   - Exercise `map-location` with valid and invalid IDs.
   - Verify `verify-locations-mapped` returns non-zero when unmapped rows exist.
   - Verify existing stock and count records remain unchanged after mapping.
3. **CQS Persistence & Immutability (`tests/Integration/MultisitePersistenceTest.php`)**:
   - Branch and warehouse CRUD, code uniqueness, and active toggles.
   - Structural creation guards (inactive branch rejects warehouse; inactive warehouse rejects location).
   - Parent immutability (prohibit changing location's `id_almacen` and warehouse's `id_sucursal`).
   - Location deletion restriction with stock positions.
   - Dynamic stock rollups matching exact expected sums.
4. **HTTP & Authorization (`tests/Integration/MultisiteHttpTest.php`)**:
   - RoleGuard matrix verification: `administrador` full access, `bodeguero` warehouse read-only, `cajero` and `compras` 403 Forbidden.
   - Fail-closed regression for missing policy entries.
   - Public entrypoint GET route testing (`tests/Integration/ProductionEntrypointTest.php`).
5. **UI & Template Verification (`tests/Integration/MultisiteUiTest.php`)**:
   - Rendered HTML structure, touch target sizing, CSRF token presence, and role control suppression.
