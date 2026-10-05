# Tasks: Multisite Warehouse Foundation

## Phase A: Database Migrations & Backfill Foundation

- [ ] A.1 Create additive migration `database/migrations/0008_create_sucursal.up.sql` (`sucursal` table: `id_sucursal` PK, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `direccion` VARCHAR(255) NULL, `telefono` VARCHAR(30) NULL, `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `estado_activo`, and initial canonical seed `SUC-01` if table is empty) and reversal `0008_create_sucursal.down.sql`.
- [ ] A.2 Create additive migration `database/migrations/0009_create_almacen.up.sql` (`almacen` table: `id_almacen` PK, `id_sucursal` FK REFERENCES `sucursal`, `codigo` VARCHAR(30) UNIQUE, `nombre` VARCHAR(100), `tipo` VARCHAR(20) DEFAULT 'bodega', `estado_activo` TINYINT(1) DEFAULT 1, timestamps, CHECK constraint for `tipo` IN ('bodega', 'mostrador', 'patio', 'merma'), CHECK constraint for `estado_activo`, and initial canonical seed `ALM-CENTRAL` if table is empty) and reversal `0009_create_almacen.down.sql`.
- [ ] A.3 Create additive migration `database/migrations/0010_reparent_ubicacion_almacen.up.sql` adding nullable `id_almacen` column to `ubicacion`, backfilling existing rows to initial warehouse, altering to `NOT NULL`, and adding foreign key `fk_ubicacion_almacen` with `ON DELETE RESTRICT`, along with reversal `0010_reparent_ubicacion_almacen.down.sql`.
- [ ] A.4 Add migration integration test `tests/Integration/MultisiteMigrationTest.php` verifying migration up/down cycles and backfill integrity on existing location records.

## Phase B: Domain Persistence & CQS (Slice B1 & B2)

- [ ] B.1 Implement `src/Modules/Inventory/SucursalQuery.php` (`findAll`, `findActive`, `findById`, `findByCode`) using prepared PDO statements returning associative arrays.
- [ ] B.2 Implement `src/Modules/Inventory/SucursalCommand.php` (`create`, `update`, `toggleActive`) validating unique code and required attributes.
- [ ] B.3 Implement `src/Modules/Inventory/AlmacenQuery.php` (`findAll`, `findActive`, `findById`, `findByBranch`, `findByCode`) returning associative arrays.
- [ ] B.4 Implement `src/Modules/Inventory/AlmacenCommand.php` (`create`, `update`, `toggleActive`) validating branch existence, code uniqueness, and allowed type values (`bodega`, `mostrador`, `patio`, `merma`).
- [ ] B.5 Update `src/Modules/Inventory/LocationQuery.php` to join warehouse and branch data (`almacen_nombre`, `sucursal_nombre`, `almacen_tipo`) and support warehouse filtering.
- [ ] B.6 Update `src/Modules/Inventory/LocationCommand.php` to require valid `id_almacen` on creation, and enforce the reparenting guard (prohibiting modifying `id_almacen` if stock or count records exist).
- [ ] B.7 Extend `src/Modules/Inventory/StockQuery.php` with aggregation queries (`getWarehouseStock`, `getBranchStock`, `getStockBreakdownByWarehouse`) calculating roll-up stock sums on-the-fly without denormalization.
- [ ] B.8 Add persistence integration tests `tests/Integration/SucursalPersistenceTest.php`, `tests/Integration/AlmacenPersistenceTest.php`, and `tests/Integration/StockRollupTest.php`.

## Phase C: Access Control & Route Authorization Policy (Slice C1)

- [ ] C.1 Update `src/Modules/Access/RouteAccessPolicy.php` registering new route policies: `/branches` (GET, POST), `/branches/toggle-active` (POST), `/warehouses` (GET, POST), `/warehouses/toggle-active` (POST).
- [ ] C.2 Update `src/Modules/Access/ViewPermissions.php` providing permission helpers (`canManageBranches()`, `canManageWarehouses()`).
- [ ] C.3 Add integration test `tests/Integration/MultisiteRouteAccessTest.php` verifying `RoleGuard` fail-closed enforcement and exact role permissions across all new endpoints.

## Phase D: HTTP Handlers & Application Wiring (Slice D1 & D2)

- [ ] D.1 Implement `src/Modules/Inventory/BranchHandler.php` handling `GET /branches`, `POST /branches`, and `POST /branches/toggle-active` with CSRF validation, sanitization, and structured redirect/error responses.
- [ ] D.2 Implement `src/Modules/Inventory/WarehouseHandler.php` handling `GET /warehouses`, `POST /warehouses`, and `POST /warehouses/toggle-active` with CSRF validation and input parsing.
- [ ] D.3 Update `src/Modules/Inventory/LocationHandler.php` handling `GET /locations` and `POST /locations` to process warehouse selection and validation.
- [ ] D.4 Register routes in `config/routes.php` and wire handlers in `public/index.php`.
- [ ] D.5 Add HTTP integration tests `tests/Integration/BranchHttpTest.php` and `tests/Integration/WarehouseHttpTest.php`.

## Phase E: Templates & User Interface (Slice E1 & E2)

- [ ] E.1 Create `templates/pages/branches.php` following Bulma 1.0.4 design tokens (`docs/ui/DESIGN.md`), with branch table, "Nueva Sucursal" modal, toggle actions, and touch-target minimums (>=44px).
- [ ] E.2 Create `templates/pages/warehouses.php` with branch filter selector, warehouse table, functional type badges (`bodega`, `mostrador`, `patio`, `merma`), and creation modal.
- [ ] E.3 Update `templates/pages/locations.php` displaying branch and warehouse context in the table and providing an active warehouse dropdown in the creation form.
- [ ] E.4 Update `templates/layout.php` navigation drawer and topbar to conditionally display "Sucursales" and "Almacenes" links using `ViewPermissions`.
- [ ] E.5 Add UI integration tests `tests/Integration/MultisiteUiTest.php` asserting rendered HTML structure and role-based action button suppression.

## Phase F: Verification & Quality Gates

- [ ] F.1 Run full regression test suite (`composer test`) ensuring all 382+ tests pass deterministically.
- [ ] F.2 Run static analysis (`composer analyse`) verifying PHPStan Level `max` with zero errors.
- [ ] F.3 Run whitespace and line ending check (`git diff --check`).
- [ ] F.4 Validate canonical and delta specifications (`openspec validate --specs` and `openspec validate multisite-warehouse-foundation`).
