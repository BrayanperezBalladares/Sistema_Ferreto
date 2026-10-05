# Proposal: Multisite Warehouse Foundation

## Intent

Establish the core physical and organizational facility hierarchy for **Ferreterías El Constructor**, defining commercial branches (`sucursales`) and storage warehouses (`almacenes`), and associating physical storage locations (`ubicaciones`) with specific warehouses. This change introduces explicit facility boundaries (`Sucursal -> Almacen -> Ubicacion`) governed by an **Expand → Adapt → Contract** migration lifecycle that strictly preserves all existing development and production data without fabricating artificial business entities.

## Why

In the operational reality of **Ferreterías El Constructor**, inventory items and physical storage locations do not exist in an unpartitioned space; they reside within specific physical facilities (commercial branches, general warehouses, sales counters, yard storage, or damage quarantine areas).

Establishing facility boundaries is an architectural prerequisite before introducing:
1. Point of Sale (POS), which sells items directly from branch-specific counter storage.
2. Purchasing, which receives goods from vendors into specific receiving warehouses.
3. Logistics Transfers, which coordinates the movement of goods between branches and warehouses.

Transitioning physical locations from unpartitioned entities to warehouse-owned entities under an Expand → Adapt → Contract strategy ensures that existing stock positions and observational physical-count history remain completely intact with zero data loss or speculative assignments.

## Scope

### In Scope
- **Commercial Branch Entity (`sucursal`)**: Relational model for physical enterprise branches, including surrogate primary key `id_sucursal`, unique alphanumeric business code `codigo`, required `nombre`, city `ciudad`, optional street address `direccion`, optional telephone `telefono`, and independent lifecycle status `estado_activo`.
- **Warehouse Storage Facility Entity (`almacen`)**: Relational model for storage areas inside a branch, including surrogate primary key `id_almacen`, parent branch foreign key `id_sucursal`, unique alphanumeric business code `codigo`, required `nombre`, functional storage type `tipo` restricted to `('bodega', 'mostrador', 'patio', 'merma')`, and independent lifecycle status `estado_activo`.
- **Expand → Adapt → Contract Migration Architecture**:
  - **EXPAND**: Additive schema migrations executed by `MigrationRunner` (one DDL statement per file, no multi-statement files) creating `sucursal`, `almacen`, and adding nullable `ubicacion.id_almacen` with foreign key constraint (`ON DELETE RESTRICT`).
  - **ADAPT**: Runtime capabilities for creating branches and warehouses, coupled with a controlled administrative CLI tool (`scripts/console.php map-location`) to explicitly assign existing legacy locations to verified real warehouses without inventing placeholder entities.
  - **CONTRACT**: Final migration enforcing `NOT NULL` on `ubicacion.id_almacen` once all locations have been explicitly assigned and verified via preflight checks (`scripts/console.php verify-locations-mapped`).
- **Data & Historical Integrity Preservation**:
  - Existing `ubicacion` IDs and codes (`codigo`) are preserved.
  - Existing `inventario_stock` positions, IDs, and quantities are preserved.
  - Existing `conteo_inventario` observational count records, system quantities, counted quantities, and discrepancies are preserved.
  - No default, placeholder, or legacy branches or warehouses are fabricated.
- **Location Parent Immutability**:
  - Once a location is created or assigned to a warehouse under the final contract, its parent warehouse (`id_almacen`) is immutable through normal application operations. Changing `id_almacen` via HTTP/UI is not supported to protect the integrity of historical stock and observational count attributions.
  - Likewise, a warehouse's parent branch (`id_sucursal`) is immutable after creation.
- **Global Location Code Invariant**: Retention of global uniqueness on `ubicacion.codigo` across the entire enterprise (`UNIQUE(codigo)`).
- **Referential Integrity & Deletion Guards**:
  - `ON DELETE RESTRICT` protects `sucursal -> almacen`, `almacen -> ubicacion`, and `ubicacion -> inventario_stock`.
  - Physical deletion of locations holding any stock position records (even with zero balance) or historical count records is prohibited.
  - Lifecycle management uses explicit logical deactivation (`estado_activo = 0`).
- **Independent Lifecycle & Structural Creation Guards**:
  - Inactivating a parent branch does not automatically cascade to child warehouses or locations.
  - Inactivating a parent warehouse does not automatically cascade to child locations.
  - Structural creation guard: new warehouses cannot be created under an inactive branch; new locations cannot be created under an inactive warehouse.
  - Historical visibility: existing stock positions and observational counts under inactive facilities remain fully visible and included in aggregate queries.
- **Dynamic Stock Roll-Up Aggregations (Strict 3NF)**:
  - Aggregate warehouse stock and branch stock are computed dynamically via SQL queries joining `inventario_stock` with `ubicacion` and `almacen`.
  - Stock quantity remains strictly at the position grain `(id_producto, id_ubicacion)`. No redundant `id_almacen` or `id_sucursal` columns are added to `inventario_stock`.
- **Route Authorization Policy**:
  - Register `/branches` and `/warehouses` in `RouteAccessPolicy` and `config/routes.php`.
  - Enforce fail-closed authorization via `RoleGuard` (authenticated requests to registered non-exempt routes lacking an explicit policy return HTTP 403 Forbidden; unauthenticated requests redirect to `/login`; unknown routes return 404; unsupported methods return 405).
  - Conservative role grants: `administrador` holds mutation rights; `bodeguero` holds read access to warehouses; `cajero` and `compras` are denied access.
- **Server-Rendered UI**:
  - Register `pages/branches.php` and `pages/warehouses.php` in `Renderer::ALLOWED_TEMPLATES`.
  - Pure PHP templates with Bulma 1.0.4 design tokens (`docs/ui/DESIGN.md`) and HTMX partials.

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
- `multisite-foundation`: Commercial branch and warehouse storage facility entity management, independent operational lifecycles, and structural creation guards.

### Modified Capabilities
- `inventory-locations-stock`: Physical storage locations require warehouse association (`id_almacen`), enforce parent immutability and referential deletion guards, and provide dynamic warehouse and branch stock rollups.

## Approach

The delivery follows a disciplined three-stage rollout:

```
EXPAND: Additive single-statement DDL migrations
        (create sucursal, create almacen, add nullable ubicacion.id_almacen)
   │
   ▼
ADAPT:  Deploy runtime management (Branches, Warehouses, updated Locations)
        + Run controlled mapping CLI (scripts/console.php map-location)
        + Run completeness verification (scripts/console.php verify-locations-mapped)
   │
   ▼
CONTRACT: Final single-statement migration enforcing NOT NULL on ubicacion.id_almacen
```

> [!IMPORTANT]
> The Expand → Adapt → Contract sequence represents distinct deployment and operational boundaries. In production and persistent development environments, the CONTRACT migration cannot execute until an operator explicitly provisions legitimate branches/warehouses and maps all existing locations. The migration runner is single-statement and fail-stop: if CONTRACT runs while unmapped locations exist, the database engine aborts execution and the runner halts without recording the migration.

1. **Migration Runner Compatibility**:
   - Every `.up.sql` and `.down.sql` file contains exactly **one executable DDL statement**, honoring `MigrationRunner`'s single-statement contract and MariaDB's `Mysql::ATTR_MULTI_STATEMENTS = false`.
   - Applied migration checksums and history are strictly preserved.
2. **Explicit Mapping Workflow**:
   - Existing locations (such as `CENTRAL` and `bod-a2` in local development) remain unmodified during EXPAND.
   - An administrator executes `scripts/console.php map-location <location-identifier> <warehouse-identifier>` to explicitly establish ownership.
   - The verification command `scripts/console.php verify-locations-mapped` confirms that zero unmapped locations remain before applying CONTRACT.
3. **Domain & Route Access**:
   - `SucursalQuery`, `SucursalCommand`, `AlmacenQuery`, and `AlmacenCommand` encapsulate CQS domain logic.
   - Routes are mapped in `RouteAccessPolicy` conforming to fail-closed authorization.
4. **Renderer Integration**:
   - New templates are registered in `Renderer::ALLOWED_TEMPLATES` to satisfy the strict template allowlist.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/` | New | Single-statement migrations for EXPAND (`0008`, `0009`, `0010`) and CONTRACT (`0011`), with compensating `.down.sql` reversals. |
| `src/Foundation/Renderer.php` | Modified | Register new template names in `Renderer::TEMPLATES` allowlist. |
| `src/Modules/Inventory/` | New/Modified | New `Sucursal*` and `Almacen*` CQS classes; update `Location*` and `StockQuery`. |
| `src/Modules/Access/RouteAccessPolicy.php` | Modified | Declare exact route authorization rules for branches and warehouses. |
| `config/routes.php` | Modified | Register HTTP routes for branch and warehouse endpoints. |
| `scripts/console.php` | Modified | Add `map-location` and `verify-locations-mapped` CLI commands. |
| `templates/pages/` | New/Modified | New templates `branches.php` and `warehouses.php`; update `locations.php`. |
| `tests/Integration/` | New/Modified | Integration suites for single-statement migrations, mapping CLI, parent immutability, rollups, and route guards. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Premature CONTRACT execution on unmapped database | Medium | Preflight check in deployment and MariaDB `MODIFY COLUMN ... NOT NULL` failure aborts migration cleanly without corrupting schema history. |
| Multi-statement migration rejection | High | Strictly author exactly one DDL statement per migration file, validated by automated migration tests. |
| Arbitrary location reparenting corrupting observational count history | Medium | Enforce parent immutability at the domain layer: reject any HTTP/UI request attempting to modify `id_almacen` on existing locations. |
| Route authorization bypass on new routes | Low | `RoleGuard` is structurally fail-closed; integration tests mandate explicit declarations in `RouteAccessPolicy`. |
| Fabricating placeholder business data | High | Prohibit automatic migration seeds; require human operators to provision real branches and map locations explicitly. |
