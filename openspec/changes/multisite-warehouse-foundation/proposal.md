# Proposal: Multisite Warehouse Foundation

## Intent

Establish the core multisite physical hierarchy for **Ferreterías El Constructor**, modeling commercial branches (`sucursales`) and storage warehouses (`almacenes`), and associating physical storage locations (`ubicaciones`) with specific warehouses. Currently, storage locations and stock positions exist in an unpartitioned space independent of company facilities. This change introduces explicit facility boundaries (`Sucursal -> Almacen -> Ubicacion`), while strictly preserving the relational 3NF stock grain `(id_producto, id_ubicacion)` and deterministic audit trails.

## Why

In the operational reality of **Ferreterías El Constructor**, goods, counters, and stock positions do not exist in an abstract space; they reside in distinct physical facilities (such as Central, branch stores, yard storage, or damage quarantine). 

Before implementing Point of Sale (which sells from branch-specific counters), Purchasing (which receives into specific warehouses), or Logistics Transfers (which moves inventory between branches and warehouses), the software must model the physical organizational hierarchy. Furthermore, transitioning from independent locations to warehouse-owned locations is an architectural foundation: delaying it increases the cost of retrofitting inventory queries, transactional boundaries, and multi-location visibility.

## Scope

### In Scope
- **Branch Entity (`sucursal`)**: Relational model for physical enterprise branches, including unique `codigo`, `nombre`, optional `direccion` and `telefono`, and boolean/enum lifecycle `estado_activo`.
- **Warehouse Entity (`almacen`)**: Relational model for storage areas inside a branch, including unique `codigo`, `nombre`, functional `tipo` (`bodega`, `mostrador`, `patio`, `merma`), branch reference `id_sucursal`, and `estado_activo`.
- **Location Reparenting to Warehouse**: Update `ubicacion` schema to enforce warehouse ownership via `id_almacen` (Foreign Key `RESTRICT`).
- **Global Location Code Invariant**: Retention of global uniqueness on `ubicacion.codigo` across the entire enterprise to avoid scanning ambiguity.
- **Location Integrity Guards**: Strict deletion restriction when stock or historical count records reference a location; prohibition or strict constraints on location reparenting to protect audit trail integrity.
- **Stock Roll-Up Queries**: Read-only queries computing aggregate stock per warehouse (`SUM(cantidad) GROUP BY id_almacen, id_producto`) and per branch (`SUM(cantidad) GROUP BY id_sucursal, id_producto`) derived from canonical location stock without data duplication.
- **Database Migration & Backfill Strategy**: Additive migration introducing `sucursal` and `almacen`, establishing a canonical initial branch/warehouse to backfill existing development/production locations, and establishing foreign key integrity.
- **CQS Persistence**: Dedicated query and command classes for `Sucursal` and `Almacen` in `App\Modules\Inventory` (or dedicated multisite sub-namespace).
- **Route Authorization & Access Policy**: Register branch and warehouse management routes in `RouteAccessPolicy` adhering to fail-closed `RoleGuard` (administrador full access, bodeguero read/operational access where appropriate, cajero/compras restricted).
- **Server-Rendered UI**: Responsive Bulma 1.0.4 management interfaces for branches and warehouses, with HTMX partials and location creation forms tied to active warehouses.

### Out of Scope
- **User-Branch Scoping (`usuario.id_sucursal`)**: Assigning users or cashiers to specific branches is deferred to a future access-control / POS change.
- **Transit Warehouses (`tipo = 'transito'`)**: In-transit stock semantics are deferred to the inter-branch transfer specification (Logistics / Transfers change), as goods in transit are governed by transfer order state rather than physical locations.
- **Point of Sale (POS) / Sales Orders**: Checkout and counter sales remain in R2.
- **Direct Stock Grain Changes**: Stock quantity remains strictly at `(id_producto, id_ubicacion)`. No redundant `id_almacen` or `id_sucursal` columns will be added to `inventario_stock`.
- **Automated Rebalancing or Transfers**: Moving stock between warehouses or branches remains part of the transfer module.

## Capabilities

### New Capabilities
- `multisite-foundation`: Commercial branch and warehouse relational management, lifecycle toggles, and hierarchical validation.

### Modified Capabilities
- `inventory-locations-stock`: Physical storage locations require warehouse association (`id_almacen`), and queries provide aggregated stock views per warehouse and branch while maintaining base position grain.

## Approach

1. **Schema & Migration**:
   - Create table `sucursal` with `id_sucursal`, `codigo` (UNIQUE), `nombre`, `direccion`, `telefono`, `estado_activo`, timestamps.
   - Create table `almacen` with `id_almacen`, `id_sucursal` (FK), `codigo` (UNIQUE), `nombre`, `tipo` (CHECK: `bodega`, `mostrador`, `patio`, `merma`), `estado_activo`, timestamps.
   - Migration for existing databases: insert canonical initial branch (`SUC-01`, "Sucursal Central") and warehouse (`ALM-CENTRAL`, "Bodega Central", tipo `bodega`), update existing `ubicacion` rows setting `id_almacen = 1`, then alter `ubicacion` to add `id_almacen INT UNSIGNED NOT NULL` and FK constraint `FOREIGN KEY (id_almacen) REFERENCES almacen(id_almacen) ON DELETE RESTRICT`.
2. **CQS Domain Operations**:
   - Implement `SucursalQuery`, `SucursalCommand`, `AlmacenQuery`, `AlmacenCommand`.
   - Update `LocationQuery` and `LocationCommand` to require and filter by `id_almacen`.
   - Add aggregation queries in `StockQuery` for warehouse and branch totals.
3. **Route Policy & Guards**:
   - Register `/branches` and `/warehouses` in `config/routes.php` and `App\Modules\Access\RouteAccessPolicy::MATRIX`.
   - Ensure `RoleGuard` fail-closed compliance.
4. **UI Presentation**:
   - Bulma 1.0.4 views for branch and warehouse catalogs and modals.
   - Update location management view to group/filter by branch and warehouse.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/` | New | Additive migrations for `sucursal`, `almacen`, and `ubicacion` foreign key backfill. |
| `src/Modules/Inventory/` | New/Modified | New `Sucursal*` and `Almacen*` CQS classes; update `Location*` and `StockQuery`. |
| `src/Modules/Access/RouteAccessPolicy.php` | Modified | Add route policy entries for branches and warehouses. |
| `config/routes.php` | Modified | Register HTTP routes for branch and warehouse handlers. |
| `templates/pages/`, `templates/fragments/` | New/Modified | Templates for branches, warehouses, and updated location selector. |
| `tests/Integration/` | New/Modified | Integration tests for multisite persistence, warehouse hierarchy, and stock rollups. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Existing location data orphaned or broken during migration | Medium | Migration script automatically creates default branch and warehouse to backfill existing records before applying NOT NULL constraint. |
| Denormalization temptation (adding `id_almacen` to `inventario_stock`) | High | Enforce 3NF strictly: stock exists only at `(id_producto, id_ubicacion)`; warehouse stock is computed dynamically via indexed JOIN. |
| Location reparenting corrupting physical count audit trails | Medium | Prohibit reparenting location to another warehouse when stock or counts exist; enforce validation in `LocationCommand`. |
| Route authorization bypass on new routes | Low | `RoleGuard` is structurally fail-closed; test suite mandates explicit policy in `RouteAccessPolicy`. |
