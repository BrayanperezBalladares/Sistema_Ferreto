# Proposal: Multisite Warehouse Foundation

## Intent

Establish the core physical and organizational facility hierarchy for **Ferreterías El Constructor**, defining commercial branches (`sucursales`) and storage warehouses (`almacenes`), and associating physical storage locations (`ubicaciones`) with specific warehouses. This change introduces explicit facility boundaries (`Sucursal -> Almacen -> Ubicacion`) structured across **two distinct deployable releases (PRs)** separated by an **operator transition boundary**:

1. **Release 1 (EXPAND + ADAPT)**: Delivers schema additions (`sucursal`, `almacen`, and transitional nullable `ubicacion.id_almacen`), full administrative management for branches and warehouses, application-level warehouse requirement for newly created locations, nullable-safe visibility for existing unmapped locations, and controlled CLI mapping tools. Migration `0011` is **intentionally withheld** from Release 1.
2. **Operator Transition Boundary**: A human operator provisions legitimate real branches and warehouses, executes one-time atomic location mappings via CLI, and verifies that zero unmapped locations remain.
3. **Release 2 (CONTRACT)**: Once verification succeeds, Release 2 introduces migration `0011` enforcing `NOT NULL` on `ubicacion.id_almacen` and validates final invariants.

This rollout guarantees that legacy development and production data are preserved without fabricating artificial placeholder entities and without risking premature contract enforcement by the single-statement `MigrationRunner`.

## Why

In the operational reality of **Ferreterías El Constructor**, inventory items and physical storage locations reside within concrete physical facilities (commercial branches, general warehouses, sales counters, yard storage, or damage quarantine areas).

Establishing facility boundaries is an architectural prerequisite before introducing:
1. Point of Sale (POS), which sells items directly from branch-specific counter storage.
2. Purchasing, which receives goods from vendors into specific receiving warehouses.
3. Logistics Transfers, which coordinates the movement of goods between branches and warehouses.

Because `MigrationRunner` executes all pending `*.up.sql` migrations in sorted order without pausing, including migration `0011` in the initial package would cause `MigrationRunner` to attempt `NOT NULL` enforcement before legacy locations are mapped, aborting execution on populated databases. Splitting the implementation into Release 1 (EXPAND + ADAPT) and Release 2 (CONTRACT) provides an executable, fail-safe deployment pipeline.

## Scope

### In Scope

#### Release 1: EXPAND + ADAPT (PR 1)
- **Commercial Branch Entity (`sucursal`)**: Relational model for physical enterprise branches, including surrogate primary key `id_sucursal`, unique alphanumeric business code `codigo`, required `nombre`, city `ciudad`, optional street address `direccion`, optional telephone `telefono`, and independent lifecycle status `estado_activo`.
- **Warehouse Storage Facility Entity (`almacen`)**: Relational model for storage areas inside a branch, including surrogate primary key `id_almacen`, parent branch foreign key `id_sucursal`, unique alphanumeric business code `codigo`, required `nombre`, functional storage type `tipo` restricted to `('bodega', 'mostrador', 'patio', 'merma')`, and independent lifecycle status `estado_activo`.
- **Transitional Schema (EXPAND)**:
  - Single-statement migrations: `0008_create_sucursal.up.sql`, `0009_create_almacen.up.sql`, and `0010_add_ubicacion_almacen_nullable.up.sql` (adding nullable `id_almacen` with FK `ON DELETE RESTRICT`).
  - Migration `0011` is strictly **withheld** from Release 1.
- **Transitional Application Runtime (ADAPT)**:
  - CQS domain layer for branches and warehouses (`SucursalQuery`, `SucursalCommand`, `AlmacenQuery`, `AlmacenCommand`).
  - Update `LocationCommand` so that **all newly created locations require a valid, active warehouse at the application/domain layer**, preventing any new NULL-owned rows from ever being inserted.
  - Update `LocationQuery` and inventory/count views with **nullable-safe query behavior** (`LEFT JOIN` instead of `INNER JOIN`), ensuring legacy unmapped locations remain fully visible without fabricating fake branch or warehouse names (displaying "Sin asignar" / "Pendiente de mapeo").
  - Dynamic stock roll-up aggregations for warehouse and branch totals strictly in Third Normal Form (3NF), without adding redundant foreign keys to `inventario_stock`.
- **One-Time Atomic Mapping CLI Tool**:
  - `scripts/console.php map-location <location-identifier> <warehouse-identifier>`:
    - Atomically assigns `ubicacion.id_almacen = :warehouse_id` WHERE `id_ubicacion = :location_id AND id_almacen IS NULL`.
    - Requires exactly 1 affected row.
    - Rejects already-mapped locations, repeated mappings, nonexistent locations, and inactive warehouses.
    - Strictly preserves location IDs, codes, descriptions, stock IDs, stock quantities, count IDs, count values, and FK relationships.
- **Completeness Verification CLI Tool**:
  - `scripts/console.php verify-locations-mapped`:
    - Checks `SELECT COUNT(*) FROM ubicacion WHERE id_almacen IS NULL`.
    - Returns exit code 0 when count is 0; returns exit code 1 with unmapped details when unmapped rows remain.
- **Location & Warehouse Parent Immutability**:
  - Once a location has been mapped or created with an `id_almacen`, changing `id_almacen` through normal application operations is unsupported.
  - Likewise, a warehouse's parent branch (`id_sucursal`) is immutable after creation.
- **Global Location Code Invariant**: Retention of global uniqueness on `ubicacion.codigo` across the entire enterprise (`UNIQUE(codigo)`).
- **Referential Integrity & Deletion Guards**:
  - `ON DELETE RESTRICT` protects `sucursal -> almacen`, `almacen -> ubicacion`, and `ubicacion -> inventario_stock`.
  - Physical deletion of locations holding any stock position records (even with zero balance) or historical count records is prohibited.
- **Independent Lifecycle & Structural Creation Guards**:
  - Deactivating a branch does not cascade status to warehouses or locations.
  - Deactivating a warehouse does not cascade status to locations.
  - Structural guards: creating or reactivating a warehouse requires an active parent branch; creating a location requires an active parent warehouse.
  - Historical visibility: stock and observational count records under inactive facilities remain visible and included in aggregate queries.
- **Route Authorization Policy**:
  - Register `/branches` and `/warehouses` in `RouteAccessPolicy` and `config/routes.php`.
  - Enforce fail-closed authorization via `RoleGuard` (`administrador` holds mutation rights; `bodeguero` holds warehouse read access; `cajero` and `compras` are denied access).
- **Server-Rendered UI**:
  - Register `pages/branches.php` and `pages/warehouses.php` in `Renderer::TEMPLATES`.
  - Pure PHP templates with Bulma 1.0.4 design tokens (`docs/ui/DESIGN.md`) and HTMX interactions.

#### Operator Transition Boundary (Between Releases)
1. Operator deploys Release 1 and runs migrations `0008`..`0010`.
2. Operator creates legitimate real branches via UI/CLI.
3. Operator creates legitimate real warehouses via UI/CLI.
4. Operator executes `php scripts/console.php map-location` for every legacy unmapped location.
5. Operator executes `php scripts/console.php verify-locations-mapped` and confirms exit code 0.

#### Release 2: CONTRACT (PR 2)
- Introduce single-statement migration `0011_enforce_ubicacion_almacen_not_null.up.sql` (`ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL;`).
- Reversal: `0011_enforce_ubicacion_almacen_not_null.down.sql` (`ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL;`).
- Validate final schema invariants, verify zero NULL rows remain, and confirm end-to-end regression pass.

### Out of Scope
To ensure strict boundary discipline, this foundation change explicitly does **NOT** implement:
- User-to-branch assignment (`usuario.id_sucursal` or `usuario_sucursal` persistence)
- Object-level or branch-scoped user authorization matrices
- Multibranch user membership or switching
- Point of Sale (POS) checkout, sales, or cash register operations
- Customer accounts or credit management
- Payment processing or fiscal invoicing
- Supplier catalog, terms, or contact management
- Purchase orders, purchasing workflows, or vendor receiving
- Inter-branch transfer requests, approvals, dispatch, or receipts
- Virtual transit inventory or in-transit warehouse tracking (transit warehouse semantics are intentionally deferred to the future transfer capability)
- Product batch tracking, lot numbers, or expiration dates
- First-In, First-Out (FIFO) consumption engines
- Automated replenishment or minimum stock reorder workflows
- Barcode scanner hardware integration or continuous keyboard-wedge listeners
- Product selling price audit logs or price history tables
- General transactional audit subsystem (`REGISTRO_ACCION_LOG`)
- Analytics, Data Warehouse schemas, or ETL synchronization routines

## Capabilities

### New Capabilities
- `multisite-foundation`: Commercial branch and warehouse storage facility entity management, operational lifecycles, structural creation/reactivation guards, and parent immutability.

### Modified Capabilities
- `inventory-locations-stock`: Physical storage locations require warehouse association (`id_almacen`), enforce parent immutability and referential deletion guards, and provide dynamic warehouse and branch stock rollups while supporting nullable-safe transitional visibility during ADAPT.

## Approach & Dual Database Execution Paths

### Fresh Database Path (e.g. CI / New Installations)
1. Release 1 migrations execute `0008`, `0009`, `0010`.
2. Application runtime enforces that every newly created location requires an active warehouse (no NULL rows created).
3. When Release 2 is deployed, `0011` succeeds immediately because `ubicacion` contains zero NULL rows.

### Populated Database Path (e.g. Existing Development / Production)
1. Release 1 migrations execute `0008`, `0009`, `0010`.
2. Existing legacy locations (such as `CENTRAL` and `bod-a2` in local development) remain unmodified with `id_almacen IS NULL`.
3. Application queries use nullable-safe `LEFT JOIN`s, displaying unmapped locations as "Sin asignar" / "Pendiente de mapeo".
4. Operator provisions real branches and warehouses, then maps legacy locations using `map-location`.
5. Operator runs `verify-locations-mapped` to confirm 100% mapping completeness.
6. Release 2 is deployed, executing `0011` to lock in the `NOT NULL` constraint.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/` | New | Release 1: `0008`, `0009`, `0010` (with `.down` reversals). Release 2: `0011` (with `.down` reversal). Single-statement DDL only. |
| `src/Foundation/Renderer.php` | Modified | Register new templates `page.branches` and `page.warehouses` in `Renderer::TEMPLATES`. |
| `src/Modules/Inventory/` | New/Modified | New `Sucursal*` and `Almacen*` CQS classes; update `Location*` (nullable-safe reads, mandatory warehouse on create) and `StockQuery`. |
| `src/Modules/Access/RouteAccessPolicy.php` | Modified | Declare exact route authorization rules for branches and warehouses. |
| `config/routes.php` | Modified | Register HTTP routes for branch and warehouse endpoints. |
| `scripts/console.php` | Modified | Add `map-location` and `verify-locations-mapped` CLI commands. |
| `templates/pages/` | New/Modified | New templates `branches.php` and `warehouses.php`; update `locations.php`. |
| `tests/Integration/` | New/Modified | Integration suites for Release 1 migrations, CLI mapping, parent immutability, rollups, route guards, fixture reset consistency, and Release 2 contract enforcement. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Premature CONTRACT execution on unmapped database | Low | Strictly withheld from Release 1; Release 2 is gated by `verify-locations-mapped` check returning exit code 0. |
| Multi-statement migration rejection | High | Strictly author exactly one DDL statement per migration file, validated by automated migration tests. |
| Unmapped legacy locations hidden during ADAPT | Medium | Mandatory use of `LEFT JOIN` in `LocationQuery` and views; mixed-state integration tests asserting visibility of unmapped rows. |
| Reparenting corrupting observational count history | Medium | Enforce parent immutability: `map-location` only updates `id_almacen IS NULL` rows; HTTP/UI updates reject parent modification. |
| Fabricating placeholder business data | High | Prohibit automatic migration seeds; require human operators to provision real branches and map locations explicitly. |
