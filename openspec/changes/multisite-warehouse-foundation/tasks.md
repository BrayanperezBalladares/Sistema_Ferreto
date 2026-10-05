# Tasks: Multisite Warehouse Foundation

## PART I: IMPLEMENTATION RELEASE 1 (EXPAND + ADAPT)

### Phase A: Database Schema & Expand Migrations (Release 1)

- [x] A.1 Create additive migration `database/migrations/0008_create_sucursal.up.sql` executing a single DDL statement (`sucursal` table: `id_sucursal` PK, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `ciudad` VARCHAR(100), `direccion` VARCHAR(255) NULL, `telefono` VARCHAR(30) NULL, `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `estado_activo` in 0/1) and single-statement reversal `0008_create_sucursal.down.sql`.
- [x] A.2 Create additive migration `database/migrations/0009_create_almacen.up.sql` executing a single DDL statement (`almacen` table: `id_almacen` PK, `id_sucursal` FK REFERENCES `sucursal(id_sucursal)` ON DELETE RESTRICT, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `tipo` VARCHAR(20) DEFAULT 'bodega', `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `tipo` IN ('bodega', 'mostrador', 'patio', 'merma'), CHECK constraint for `estado_activo` in 0/1) and single-statement reversal `0009_create_almacen.down.sql`.
- [x] A.3 Create additive migration `database/migrations/0010_add_ubicacion_almacen_nullable.up.sql` executing a single DDL statement adding nullable `id_almacen` column to `ubicacion` with foreign key `fk_ubicacion_almacen` REFERENCES `almacen(id_almacen)` ON DELETE RESTRICT, and single-statement reversal `0010_add_ubicacion_almacen_nullable.down.sql`.
- [x] A.4 (*Note on Migration 0011*): Do NOT include `0011` in Release 1. Migration `0011` is strictly withheld until Release 2.

### Phase B: Domain Persistence & CQS Layer (Release 1)

- [x] B.1 Implement `src/Modules/Inventory/SucursalQuery.php` (`findAll`, `findActive`, `findById`, `findByCode`) using prepared PDO statements returning associative arrays.
- [x] B.2 Implement `src/Modules/Inventory/SucursalCommand.php`:
  - `create`: Insert branch with validated unique code, required name, required city, optional address/phone, active status.
  - `update`: Update name, city, address, phone.
  - `toggleActive`: Toggle `estado_activo` between 1 and 0.
- [x] B.3 Implement `src/Modules/Inventory/AlmacenQuery.php` (`findAll`, `findActive`, `findById`, `findByBranch`, `findByCode`) returning associative arrays.
- [x] B.4 Implement `src/Modules/Inventory/AlmacenCommand.php`:
  - `create`: Insert warehouse with validated unique code, name, type in allowed set (`bodega`, `mostrador`, `patio`, `merma`), and branch FK. Enforce structural creation guard (reject if parent branch is inactive).
  - `update`: Update name and type.
  - `toggleActive`: Toggle `estado_activo`, rejecting activation if parent branch is inactive.
  - Enforce warehouse parent immutability: prohibit updating `id_sucursal` after creation.
- [x] B.5 Update `src/Modules/Inventory/LocationQuery.php`:
  - Implement nullable-safe queries (`LEFT JOIN almacen` and `LEFT JOIN sucursal`), ensuring legacy unmapped locations (`id_almacen IS NULL`) remain visible.
  - For unmapped rows, return warehouse/branch status as unassigned / pending mapping without fabricating placeholder labels.
  - Support warehouse filtering when specified.
- [x] B.6 Update `src/Modules/Inventory/LocationCommand.php`:
  - `create`: Require valid, active `id_almacen` at application/domain layer (rejecting null/missing warehouse), preventing any new NULL rows from being created even while the column is temporarily nullable in the database.
  - Enforce structural creation guard: reject location creation if parent warehouse is inactive.
  - Enforce location parent immutability: prohibit updating `id_almacen` on existing locations.
  - Enforce referential deletion guard: prohibit deletion if any stock records or count history reference this location.
- [x] B.7 Extend `src/Modules/Inventory/StockQuery.php` with dynamic roll-up queries:
  - `getWarehouseStock(int $productId, int $warehouseId): string`
  - `getBranchStock(int $productId, int $branchId): string`
  - `getStockBreakdownByWarehouse(int $productId): array`
  - Strictly preserving 3NF without adding warehouse or branch columns to `inventario_stock`.

### Phase C: Administrative CLI Tooling for Legacy Mapping (Release 1)

- [x] C.1 Wire CLI commands `map-location` and `verify-locations-mapped` into `src/Foundation/Console.php`.
- [x] C.2 Implement `scripts/console.php map-location <location> <warehouse>`:
  - Accept ID or unique code for location and warehouse.
  - Validate location exists and warehouse exists and is active.
  - Execute atomic assignment: `UPDATE ubicacion SET id_almacen = :warehouse_id WHERE id_ubicacion = :location_id AND id_almacen IS NULL`.
  - Assert exactly 1 row affected; throw and abort if location already has an assigned warehouse.
  - Reject repeated mapping or remapping to another warehouse.
  - Strictly preserve `id_ubicacion`, `codigo`, `descripcion`, associated `inventario_stock` positions/quantities, and `conteo_inventario` records without alteration.
- [x] C.3 Implement `scripts/console.php verify-locations-mapped`:
  - Execute `SELECT COUNT(*) FROM ubicacion WHERE id_almacen IS NULL`.
  - If count > 0, output unmapped location details and exit with status 1.
  - If count === 0, output verification confirmation and exit with status 0.

### Phase D: Access Control & Route Authorization Policy (Release 1)

- [x] D.1 Update `src/Modules/Access/RouteAccessPolicy.php` registering exact route policies:
  - `/branches` [GET] -> `['administrador']`
  - `/branches` [POST] -> `['administrador']`
  - `/branches/toggle-active` [POST] -> `['administrador']`
  - `/warehouses` [GET] -> `['administrador', 'bodeguero']`
  - `/warehouses` [POST] -> `['administrador']`
  - `/warehouses/toggle-active` [POST] -> `['administrador']`
  - `/locations` [GET] -> `['administrador', 'bodeguero']`
  - `/locations` [POST] -> `['administrador', 'bodeguero']`
- [x] D.2 Update `src/Modules/Access/ViewPermissions.php` adding permission helpers `canManageBranches(): bool` and `canManageWarehouses(): bool`.

### Phase E: HTTP Handlers, Application Wiring & Renderer Registration (Release 1)

- [x] E.1 Register new template views in `src/Foundation/Renderer.php` (`Renderer::TEMPLATES` allowlist):
  - `'page.branches' => 'pages/branches.php'`
  - `'page.warehouses' => 'pages/warehouses.php'`
- [x] E.2 Implement `src/Modules/Inventory/BranchHandler.php` (`index`, `create`, `toggleActive`) with CSRF protection, input validation, and redirect/error responses.
- [x] E.3 Implement `src/Modules/Inventory/WarehouseHandler.php` (`index`, `create`, `toggleActive`) with CSRF protection and type validation.
- [x] E.4 Update `src/Modules/Inventory/LocationHandler.php` to require and process warehouse selection on creation.
- [x] E.5 Register routes in `config/routes.php` and wire handler dependencies in `public/index.php`.

### Phase F: Templates & User Interface (Release 1)

- [x] F.1 Create `templates/pages/branches.php` conforming to Bulma 1.0.4 design tokens (`docs/ui/DESIGN.md`):
  - Responsive table (Code, Name, City, Address, Phone, Warehouses count, Status badge).
  - "Nueva Sucursal" modal with required code, name, city, optional address, phone, CSRF token, and minimum 44px touch targets.
  - Active/Inactive toggle buttons.
- [x] F.2 Create `templates/pages/warehouses.php`:
  - Branch filter dropdown.
  - Responsive table (Branch, Code, Name, Type badge, Locations count, Status badge).
  - "Nuevo Almacén" modal with branch selector, code, name, type selector (`bodega`, `mostrador`, `patio`, `merma`), and CSRF token.
  - Active/Inactive toggle buttons.
- [x] F.3 Update `templates/pages/locations.php`:
  - Display Branch and Warehouse columns using nullable-safe rendering (showing "Sin asignar" / "Pendiente de mapeo" for unmapped legacy rows).
  - Update creation modal with required active warehouse dropdown.
- [x] F.4 Update `templates/layout.php` navigation drawer and topbar to render "Sucursales" and "Almacenes" links gated by `ViewPermissions`.

### Phase G: Test Suite & Fixture Adaptation (Release 1)

- [x] G.1 Inspect and adapt all affected test fixtures and reset helpers to establish parent branch and warehouse records before inserting locations:
  - Update `StockTest.php` fixtures.
  - Update `CountTest.php` fixtures.
  - Update `LocationHttpTest.php` fixtures.
  - Update any catalog/migration reset tests performing raw `INSERT INTO ubicacion`.
  - Update any test reversing `0004`/`0005`/`0006` to honor dependency-aware order (reversing `0010` before `0004`).
  - Prove: reset -> migration history consistent -> rerun -> correct final schema.
- [ ] G.2 Add comprehensive migration lifecycle tests (`tests/Integration/MultisiteMigrationTest.php`) covering:
  - Test A: Fresh database execution of Release 1 migrations (`0008`..`0010`).
  - Test B: Populated database upgrade to Release 1 (existing locations acquire `id_almacen = NULL`).
  - Test C: Repeat migration execution (idempotent; no duplicate execution or drift).
  - Test D: Partial migration failure and retry behavior.
  - Test E: Mixed mapped/unmapped ADAPT state (nullable-safe queries return all rows).
  - Test F: `verify-locations-mapped` failure when unmapped rows exist (exit code 1).
  - Test G: `verify-locations-mapped` success when all rows are mapped (exit code 0).
  - Test J: Migration reset and history consistency.
  - Test K: Reverse dependency behavior (`0010.down`, `0009.down`, `0008.down`).
- [ ] G.3 Add persistence & CLI integration tests (`tests/Integration/MultisitePersistenceTest.php`, `tests/Integration/ConsoleMultisiteMappingTest.php`) verifying:
  - Branch and warehouse CRUD, code uniqueness, city persistence.
  - Structural creation guards: inactive branch rejects warehouse; inactive warehouse rejects location.
  - Reactivation guard: inactive branch prohibits warehouse reactivation.
  - Inactive parent does not cascade status to children.
  - Parent immutability: updating `id_almacen` on existing location or `id_sucursal` on existing warehouse is rejected.
  - Location deletion restriction with stock positions.
  - One-time atomic mapping: `map-location` updates `id_almacen IS NULL` row; rejects already-mapped location; rejects repeated mapping.
  - Preservation of all IDs, codes, quantities, and count records during mapping.
  - Stock roll-up queries returning exact expected sums; historical stock under inactive facilities remains visible.
- [ ] G.4 Add HTTP, security, and UI integration tests (`tests/Integration/MultisiteHttpTest.php`, `tests/Integration/MultisiteUiTest.php`):
  - Route matrix verification: `administrador` full access, `bodeguero` warehouse read-only, `cajero` and `compras` 403 Forbidden across all facility endpoints.
  - Structural fail-closed regression: authenticated requests to any registered route lacking explicit policy return 403 Forbidden.
  - CSRF validation: valid token succeeds; missing or invalid token returns 403 Forbidden without mutating state.
  - Output escaping (`Renderer::escape`) preventing XSS in rendered tables and modal inputs; viewport responsiveness down to 360px without horizontal overflow.
  - Production entrypoint GET route testing (`tests/Integration/ProductionEntrypointTest.php`) verifying all facility routes return HTTP 200 for authorized sessions.
  - **Forged Parent-Change HTTP Regression Tests**:
    - Location parent immutability: Given an existing location assigned to warehouse A (with stock and observational count records), test that submitting or forging `id_almacen = warehouse B` in any location endpoint (e.g. `POST /locations`) is rejected or ignored according to endpoint validation without side effects; assert `ubicacion.id_almacen` remains warehouse A, with zero reparenting, and location ID, stock IDs, stock quantities, count IDs, and count payloads remain completely unchanged.
    - Warehouse parent immutability: Given an existing warehouse assigned to branch A, test that submitting or forging `id_sucursal = branch B` in any warehouse endpoint (e.g. `POST /warehouses`, `POST /warehouses/toggle-active`) is rejected or ignored according to endpoint validation without side effects; assert `almacen.id_sucursal` remains branch A, with zero reparenting, and descendant location relationships, stock, and count attribution remain completely unchanged.
    - Invariant: Do NOT introduce new edit/reparent endpoints to test this; verify that existing endpoints reject or ignore unexpected parent fields without persistence side effects.
  - **Authenticated Full-Page vs HTMX Response Tests**:
    - Explicit contract: Endpoints `/branches`, `/warehouses`, and `/locations` intentionally have NO special authenticated fragment responses by design; creation forms are modals rendered directly on their full pages (`page.branches`, `page.warehouses`, `page.locations`).
    - Ordinary full-page requests:
      - Successful mutation: returns HTTP 303 redirect with `Location` header to the respective catalog path (`/branches`, `/warehouses`, `/locations`).
      - Validation failure: returns HTTP 422 Unprocessable Entity with full-page HTML re-rendered displaying field validation feedback; verify zero unintended persistence side effects (relationships, stock, and counts remain unchanged).
    - Authenticated HTMX requests (`HX-Request: true`):
      - Successful mutation: returns HTTP 200 OK with `HX-Redirect` header pointing to the respective catalog path and `HX-Trigger` containing a JSON success notification payload.
      - Validation failure: returns HTTP 422 Unprocessable Entity with full-page HTML re-rendered displaying field validation feedback; verify that endpoints do not invent fragment responses; verify zero unintended persistence side effects (relationships, stock, and counts remain unchanged).
- [ ] G.5 Run full regression suite (`composer test`, `composer analyse`, `git diff --check`, `openspec validate`).

---

## PART II: OPERATOR TRANSITION BOUNDARY (BETWEEN RELEASES)

- [ ] O.1 Deploy Release 1 to target environment and run migrations (`0008`..`0010`).
- [ ] O.2 Operator provisions legitimate commercial branches via web UI (`POST /branches`).
- [ ] O.3 Operator provisions legitimate storage warehouses via web UI (`POST /warehouses`).
- [ ] O.4 Operator executes one-time mapping for each legacy unmapped location:
  `php scripts/console.php map-location <location-identifier> <warehouse-identifier>`
- [ ] O.5 Operator executes verification check:
  `php scripts/console.php verify-locations-mapped`
  Confirm command exits with status 0 (zero unmapped locations remain).

---

## PART III: IMPLEMENTATION RELEASE 2 (CONTRACT)

- [ ] C.1 Create contract migration `database/migrations/0011_enforce_ubicacion_almacen_not_null.up.sql` executing a single DDL statement:
  `ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL;`
  and single-statement reversal `0011_enforce_ubicacion_almacen_not_null.down.sql`:
  `ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL;`
- [ ] C.2 Add contract migration integration tests in `tests/Integration/MultisiteMigrationContractTest.php`:
  - Test H: Release 2 CONTRACT succeeds after complete mapping.
  - Test I: Release 2 CONTRACT rejection if unmapped rows exist (fail-stop prevents false history).
  - Verify fresh test database execution (all migrations `0008`..`0011` run seamlessly).
- [ ] C.3 Validate full regression suite for Release 2 (`composer test`, `composer analyse`, `openspec validate`).
