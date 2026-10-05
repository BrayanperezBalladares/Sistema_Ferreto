# Tasks: Multisite Warehouse Foundation

## Phase A: Database Schema & Expand Migrations

- [ ] A.1 Create additive migration `database/migrations/0008_create_sucursal.up.sql` executing a single DDL statement (`sucursal` table: `id_sucursal` PK, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `ciudad` VARCHAR(100), `direccion` VARCHAR(255) NULL, `telefono` VARCHAR(30) NULL, `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `estado_activo` in 0/1) and single-statement reversal `0008_create_sucursal.down.sql`.
- [ ] A.2 Create additive migration `database/migrations/0009_create_almacen.up.sql` executing a single DDL statement (`almacen` table: `id_almacen` PK, `id_sucursal` FK REFERENCES `sucursal(id_sucursal)` ON DELETE RESTRICT, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `tipo` VARCHAR(20) DEFAULT 'bodega', `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `tipo` IN ('bodega', 'mostrador', 'patio', 'merma'), CHECK constraint for `estado_activo` in 0/1) and single-statement reversal `0009_create_almacen.down.sql`.
- [ ] A.3 Create additive migration `database/migrations/0010_add_ubicacion_almacen.up.sql` executing a single DDL statement adding nullable `id_almacen` column to `ubicacion` with foreign key `fk_ubicacion_almacen` REFERENCES `almacen(id_almacen)` ON DELETE RESTRICT, and single-statement reversal `0010_add_ubicacion_almacen.down.sql`.
- [ ] A.4 Add migration isolation test `tests/Integration/MultisiteMigrationExpandTest.php` verifying:
  - Fresh `*_test` execution of `0008`, `0009`, `0010` succeeds without multi-statement errors.
  - Idempotent re-run leaves schema intact.
  - Reversals `0010.down`, `0009.down`, `0008.down` cleanly remove columns and tables in dependency-aware order.
  - Checksum drift detection across applied migrations.
  - Existing `ubicacion`, `inventario_stock`, and `conteo_inventario` records remain untouched with `id_almacen IS NULL`.

## Phase B: Administrative CLI Tooling & Legacy Location Mapping

- [ ] B.1 Wire CLI commands `map-location` and `verify-locations-mapped` into `src/Foundation/Console.php`.
- [ ] B.2 Implement `scripts/console.php map-location <location> <warehouse>`:
  - Accept ID or unique code for location and warehouse.
  - Validate location exists and warehouse exists and is active.
  - Update `ubicacion.id_almacen = :warehouse_id`.
  - Strictly preserve `id_ubicacion`, `codigo`, `descripcion`, associated `inventario_stock` positions/quantities, and `conteo_inventario` records without alteration.
- [ ] B.3 Implement `scripts/console.php verify-locations-mapped`:
  - Execute `SELECT COUNT(*) FROM ubicacion WHERE id_almacen IS NULL`.
  - Output unmapped location details and exit with status 1 if any unmapped locations exist; exit status 0 when mapping is complete.
- [ ] B.4 Add integration tests `tests/Integration/ConsoleMultisiteMappingTest.php` verifying:
  - `map-location` assigns valid warehouse and preserves existing stock and count records.
  - Rejection of nonexistent location or inactive warehouse.
  - `verify-locations-mapped` correctly reports unmapped count and returns appropriate exit codes.

## Phase C: Contract Enforcement Migration

- [ ] C.1 Create contract migration `database/migrations/0011_enforce_ubicacion_almacen_not_null.up.sql` executing a single DDL statement (`ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NOT NULL;`) and reversal `0011_enforce_ubicacion_almacen_not_null.down.sql` (`ALTER TABLE ubicacion MODIFY COLUMN id_almacen INT UNSIGNED NULL;`).
- [ ] C.2 Add contract migration integration test in `tests/Integration/MultisiteMigrationContractTest.php`:
  - Verify that `0011.up.sql` fails stop and leaves `schema_migrations` intact when unmapped locations exist.
  - Verify that after explicit mapping, `0011.up.sql` executes successfully and enforces `NOT NULL`.
  - Verify fresh test database execution (empty `ubicacion`) completes all migrations `0008`..`0011` seamlessly.

## Phase D: Domain Persistence & CQS Layer

- [ ] D.1 Implement `src/Modules/Inventory/SucursalQuery.php` (`findAll`, `findActive`, `findById`, `findByCode`) using prepared PDO statements returning associative arrays.
- [ ] D.2 Implement `src/Modules/Inventory/SucursalCommand.php`:
  - `create`: Insert branch with validated unique code, required name, required city, optional address/phone, active status.
  - `update`: Update name, city, address, phone.
  - `toggleActive`: Toggle `estado_activo` between 1 and 0.
- [ ] D.3 Implement `src/Modules/Inventory/AlmacenQuery.php` (`findAll`, `findActive`, `findById`, `findByBranch`, `findByCode`) returning associative arrays.
- [ ] D.4 Implement `src/Modules/Inventory/AlmacenCommand.php`:
  - `create`: Insert warehouse with validated unique code, name, type in allowed set (`bodega`, `mostrador`, `patio`, `merma`), and branch FK. Enforce structural creation guard (reject if parent branch is inactive).
  - `update`: Update name and type.
  - `toggleActive`: Toggle `estado_activo`, rejecting activation if parent branch is inactive.
  - Enforce parent immutability (prohibit updating `id_sucursal` after creation).
- [ ] D.5 Update `src/Modules/Inventory/LocationQuery.php` joining warehouse and branch data (`almacen_nombre`, `almacen_codigo`, `almacen_tipo`, `sucursal_nombre`, `id_sucursal`) and supporting warehouse filtering.
- [ ] D.6 Update `src/Modules/Inventory/LocationCommand.php`:
  - `create`: Require valid, active `id_almacen`. Enforce structural creation guard (reject if parent warehouse is inactive).
  - Enforce location parent immutability: prohibit updating `id_almacen` on existing locations.
  - Enforce referential deletion guard: prohibit deletion if any stock records or count history reference this location.
- [ ] D.7 Extend `src/Modules/Inventory/StockQuery.php` with aggregation queries:
  - `getWarehouseStock(int $productId, int $warehouseId): string`
  - `getBranchStock(int $productId, int $branchId): string`
  - `getStockBreakdownByWarehouse(int $productId): array`
  - Calculating dynamic stock sums on-the-fly without denormalization, preserving strict 3NF.
- [ ] D.8 Add persistence integration tests `tests/Integration/MultisitePersistenceTest.php` verifying:
  - Branch CRUD, unique code, active toggle, city persistence.
  - Warehouse CRUD, type constraints, unique code, parent immutability (`id_sucursal`).
  - Structural creation guards: inactive branch rejects warehouse; inactive warehouse rejects location.
  - Location parent immutability: updating `id_almacen` is rejected.
  - Location deletion restriction with stock positions.
  - Stock roll-up queries returning exact expected sums for warehouse and branch.
  - Historical visibility: stock under inactive facilities remains visible in rollups.

## Phase E: Access Control & Route Authorization Policy

- [ ] E.1 Update `src/Modules/Access/RouteAccessPolicy.php` registering exact route policies:
  - `/branches` [GET] -> `['administrador']`
  - `/branches` [POST] -> `['administrador']`
  - `/branches/toggle-active` [POST] -> `['administrador']`
  - `/warehouses` [GET] -> `['administrador', 'bodeguero']`
  - `/warehouses` [POST] -> `['administrador']`
  - `/warehouses/toggle-active` [POST] -> `['administrador']`
- [ ] E.2 Update `src/Modules/Access/ViewPermissions.php` adding permission helpers `canManageBranches(): bool` and `canManageWarehouses(): bool`.
- [ ] E.3 Add integration test `tests/Integration/MultisiteRouteAccessTest.php` verifying:
  - Full access for `administrador` on all branch and warehouse routes.
  - Warehouse read-only access for `bodeguero` (mutation routes return 403 Forbidden).
  - Complete 403 Forbidden denial for `cajero` and `compras` across all new routes.
  - Structural fail-closed regression: authenticated requests to any registered route missing from policy return 403.

## Phase F: HTTP Handlers, Application Wiring & Renderer Registration

- [ ] F.1 Register new template views in `src/Foundation/Renderer.php`:
  - `'page.branches' => 'pages/branches.php'`
  - `'page.warehouses' => 'pages/warehouses.php'`
- [ ] F.2 Implement `src/Modules/Inventory/BranchHandler.php` (`index`, `create`, `toggleActive`) with CSRF protection, input validation, and redirect/error responses.
- [ ] F.3 Implement `src/Modules/Inventory/WarehouseHandler.php` (`index`, `create`, `toggleActive`) with CSRF protection and type validation.
- [ ] F.4 Update `src/Modules/Inventory/LocationHandler.php` to require and process warehouse selection on creation.
- [ ] F.5 Register routes in `config/routes.php` and wire handler dependencies in `public/index.php`.
- [ ] F.6 Add HTTP integration tests `tests/Integration/MultisiteHttpTest.php` and update `tests/Integration/ProductionEntrypointTest.php` ensuring all GET routes return HTTP 200 for authorized sessions.

## Phase G: Templates & User Interface

- [ ] G.1 Create `templates/pages/branches.php` conforming to Bulma 1.0.4 design tokens (`docs/ui/DESIGN.md`):
  - Responsive table (Code, Name, City, Address, Phone, Warehouses count, Status badge).
  - "Nueva Sucursal" modal with required code, name, city, optional address, phone, CSRF token, and minimum 44px touch targets.
  - Active/Inactive toggle buttons.
- [ ] G.2 Create `templates/pages/warehouses.php`:
  - Branch filter dropdown.
  - Responsive table (Branch, Code, Name, Type badge, Locations count, Status badge).
  - "Nuevo Almacén" modal with branch selector, code, name, type selector (`bodega`, `mostrador`, `patio`, `merma`), and CSRF token.
  - Active/Inactive toggle buttons.
- [ ] G.3 Update `templates/pages/locations.php` displaying Branch and Warehouse columns and updating the creation modal with a required active warehouse selector.
- [ ] G.4 Update `templates/layout.php` navigation drawer and topbar to render "Sucursales" and "Almacenes" links gated by `ViewPermissions`.
- [ ] G.5 Add UI integration tests `tests/Integration/MultisiteUiTest.php` verifying:
  - HTML rendering and table content for branches and warehouses.
  - CSRF token presence and escaping (`Renderer::escape`).
  - Viewport responsiveness down to 360px without horizontal overflow.
  - Role-based button suppression (mutation buttons hidden from `bodeguero`).

## Phase H: Verification & Quality Gates

- [ ] H.1 Run full regression test suite (`composer test`) ensuring all 382+ existing tests plus new multisite tests pass green.
- [ ] H.2 Run static analysis (`composer analyse`) at PHPStan level `max` with zero errors.
- [ ] H.3 Verify whitespace and line-endings (`git diff --check`).
- [ ] H.4 Validate OpenSpec specifications (`openspec validate --specs` and `openspec validate multisite-warehouse-foundation`).
