# Implementation Progress: product-inventory-core

## Status Summary

- **Change**: `product-inventory-core`
- **Phase**: Phase 3 — Observational Inventory Counts (Slice 3)
- **Completed Tasks**: Tasks 1.1 through 1.6, Tasks 2.1 through 2.5, and Tasks 3.1 through 3.4
- **Scope Audit & Review Budget Management**:
  - The original combined Slice 2 implementation reached ~487 authored changed lines across 11 files, triggering the mandatory review budget stop gate ($\le$ 400 lines).
  - The maintainer declined a size exception and approved non-destructive subdivision into two autonomous, reviewable units:
    - **Slice 2A — Physical Locations Foundation** (commit `6ac0872`, 217 changed authored lines)
    - **Slice 2B — Associative Stock Foundation** (commit `61b0bca`, 304 changed authored lines)
  - Mandatory Scope Audit of Slice 2 confirmed `updateQuantity()` was out of approved scope (reconciliations/adjustments are explicitly out of scope for R1). The method and its dedicated assertions were excised under commit `f454a68` (`fix(inventory): keep stock core within approved scope`).
  - **Slice 3 — Observational Inventory Counts** delivered in a single reviewable commit (`5b9acff`, 343 changed authored lines $\le$ 400).

---

## Slices Delivered

### Slice 1A: Category Foundation
- **Commit**: `a1549a6` (`feat(inventory): establish category foundation`)
- **Diff Stat**: 5 files changed, 227 insertions(+), 0 deletions(-)
- **Scope**:
  - `database/migrations/0002_create_categoria.up.sql` (`categoria` table: `id_categoria` INT PK, `nombre` UNIQUE, `descripcion`, timestamps)
  - `database/migrations/0002_create_categoria.down.sql` (reversal)
  - `src/Modules/Inventory/CategoryCommand.php` (transactional creation)
  - `src/Modules/Inventory/CategoryQuery.php` (read queries with type-safe row mapping)
  - `tests/Integration/CatalogTest.php` (category tests, isolation, migration reversal/re-run)
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/worktree_1a`) at `a1549a6`.
  - `composer setup`: 28 packages installed from lockfile, 0 warnings.
  - `composer test`: 72 tests, 150 assertions (100% green).
  - `composer analyse`: 28/28 files, 0 errors at PHPStan Level Max (Level 10).

### Slice 1B: Product Catalog Core
- **Commit**: `cf439e3` (`feat(inventory): establish product catalog core`)
- **Diff Stat**: 6 files changed, 311 insertions(+), 7 deletions(-)
- **Scope**:
  - `database/migrations/0003_create_producto.up.sql` (`producto` table: `id_producto` PK, nullable `id_categoria` FK RESTRICT, `precio_actual` DECIMAL(12,2) with CHECK >= 0, `estado_activo`, timestamps)
  - `database/migrations/0003_create_producto.down.sql` (reversal)
  - `src/Modules/Inventory/CatalogValidator.php` (exact string decimal regex `/^\d+(\.\d{1,2})?$/`, zero PHP float math)
  - `src/Modules/Inventory/ProductCommand.php` (registration, price update, soft deactivation)
  - `src/Modules/Inventory/ProductQuery.php` (findById, search with text/category/active filters, type-safe mapping)
  - `tests/Integration/CatalogTest.php` (restored complete test suite covering products and categories)
- **Verification**:
  - `composer setup`: Verified lockfile dependencies.
  - `composer test`: 80 tests, 179 assertions (100% green).
  - `composer analyse`: 31/31 files, 0 errors at PHPStan Level Max (Level 10).

### Slice 2A: Physical Locations Foundation
- **Commit**: `6ac0872` (`feat(inventory): establish physical locations`)
- **Diff Stat**: 5 files changed, 217 insertions(+), 0 deletions(-) (217 changed authored lines $\le$ 400)
- **Scope**:
  - `database/migrations/0004_create_ubicacion.up.sql` (`ubicacion` table: `id_ubicacion` INT UNSIGNED AUTO_INCREMENT PK, `codigo` VARCHAR(50) UNIQUE, `descripcion` TEXT, `estado_activo` TINYINT(1) DEFAULT 1, timestamps UTC)
  - `database/migrations/0004_create_ubicacion.down.sql` (reversal)
  - `src/Modules/Inventory/LocationCommand.php` (transactional creation, trimmed uppercase code, duplicate validation)
  - `src/Modules/Inventory/LocationQuery.php` (strict typed queries: `all`, `findById`, `findByCode`)
  - `tests/Integration/StockTest.php` (location creation, duplicate code rejection, migration reversal/re-run)
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/worktree-2a`) at `6ac0872`.
  - `composer setup`: Verified dependencies and autoloading.
  - Targeted location test (`./vendor/bin/phpunit tests/Integration/StockTest.php`): 4 tests, 12 assertions (100% green).
  - `composer test`: 84 tests, 191 assertions (100% green).
  - `composer analyse`: 33/33 files, 0 errors at PHPStan Level Max (Level 10).
  - Development DB (`sistema_ferreto`): 0 tables (untouched).

### Slice 2B: Associative Stock Foundation
- **Commit**: `61b0bca` (`feat(inventory): establish associative stock`)
- **Diff Stat**: 7 files changed, 285 insertions(+), 19 deletions(-) (304 changed authored lines $\le$ 400)
- **Scope**:
  - `database/migrations/0005_create_inventario_stock.up.sql` (`inventario_stock` table: `id_stock` INT UNSIGNED AUTO_INCREMENT PK, `id_producto` INT UNSIGNED FK RESTRICT, `id_ubicacion` INT UNSIGNED FK RESTRICT, UNIQUE pair, `cantidad` DECIMAL(12,3) CHECK >= 0, timestamps UTC)
  - `database/migrations/0005_create_inventario_stock.down.sql` (reversal)
  - `src/Modules/Inventory/StockValidator.php` (exact string decimal regex `/^\d+(\.\d{1,3})?$/`, zero PHP float math, rejects negatives and >3 fractional digits)
  - `src/Modules/Inventory/StockCommand.php` (transactional position creation with FK checks and validation)
  - `src/Modules/Inventory/StockQuery.php` (module queries with DRY `BASE_SELECT`: `getPosition`, `findById`, `listByProduct`, `listByLocation`)
  - `tests/Integration/StockTest.php` (extended with 6 stock position scenarios, zero/fractional quantities, FK integrity, negative/precision rejection, parent deletion restriction, migration rollback)
  - `tests/Integration/CatalogTest.php` (updated table cleanup to preserve schema definitions and handle downstream migration reversal ordering for 0005)
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/worktree-2b`) at `61b0bca`.
  - `composer setup`: Verified dependencies and autoloading.
  - Targeted stock test (`./vendor/bin/phpunit tests/Integration/StockTest.php`): 10 tests, 32 assertions (100% green).
  - `composer test`: 90 tests, 211 assertions (100% green).
  - `composer analyse`: 36/36 files, 0 errors at PHPStan Level Max (Level 10).
  - Development DB (`sistema_ferreto`): 0 tables (untouched).

### Slice 3: Observational Inventory Counts
- **Commit**: `5b9acff` (`feat(inventory): establish observational inventory counts`)
- **Diff Stat**: 6 files changed, 343 insertions(+), 0 deletions(-) (343 changed authored lines $\le$ 400)
- **Scope**:
  - `database/migrations/0006_create_conteo_inventario.up.sql` (`conteo_inventario` table: `id_conteo` INT UNSIGNED AUTO_INCREMENT PK, `id_stock` INT UNSIGNED FK RESTRICT, `cantidad_sistema` DECIMAL(12,3) CHECK >= 0, `cantidad_contada` DECIMAL(12,3) CHECK >= 0, `diferencia` DECIMAL(12,3), `notas` TEXT NULL, `created_at` DATETIME UTC)
  - `database/migrations/0006_create_conteo_inventario.down.sql` (reversal)
  - `src/Modules/Inventory/CountCommand.php` (atomic `INSERT ... SELECT` from `inventario_stock`, distinct parameters `:qty_val` and `:qty_calc`, exact MariaDB decimal arithmetic, non-existent stock position guard, non-mutating)
  - `src/Modules/Inventory/CountQuery.php` (read-only queries `findById`, `listByStock`, strictly typed list row mapping)
  - `tests/Integration/CountTest.php` (11 scenarios: discrepancy, zero discrepancy, fractional quantities, negative/precision validation rejection, nonexistent stock position, non-mutation of stock, append-only history, immutability check, migration reversal/re-run)
  - `tests/Integration/StockTest.php` (downstream 0006 reversal check in migration test)
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/worktree-3`) at `5b9acff`.
  - `composer setup`: Verified dependencies and autoloading.
  - Targeted count test (`./vendor/bin/phpunit tests/Integration/CountTest.php`): 11 tests, 42 assertions (100% green).
  - `composer test`: 101 tests, 253 assertions (100% green).
  - `composer analyse`: 38/38 files, 0 errors at PHPStan Level Max (Level 10).
  - Development DB (`sistema_ferreto`): 0 tables (untouched).

---

## Database Invariant Audit
- Local environment audited against maintainer-approved configuration:
  - Development (`sistema_ferreto` on `127.0.0.1:3309` as `ferreto_app@127.0.0.1`): 0 tables, completely untouched.
  - Test (`sistema_ferreto_test` on `127.0.0.1:3309` as `ferreto_test@127.0.0.1`): verified ending in `_test`, isolated execution.

---

### Slice 4A: Server-Rendered Catalog UI
- **Phase Intent**: Complete Tasks 4.1 through 4.4 implementing catalog browsing, live HTMX search, category creation, product registration, price updating, and product deactivation with CSRF protection and output escaping.
- **Review Budget & Subdivision Strategy**:
  - Proactively subdivided into **4A1** (Catalog Browse & Search) and **4A2** (Catalog Mutations) to satisfy the $\le 400$ changed authored lines budget limit.
  - When combined 4A2 produced 575 insertions, the review-budget stop gate was triggered and the decision made to further subdivide into **4A2a** (Category & Product Creation) and **4A2b** (Price Update & Product Deactivation) without size exceptions.

#### Slice 4A1: Catalog Browse & Search
- **Commit**: `f31eb95` (`feat(inventory): add catalog browsing interface`)
- **Diff Stat**: 6 files changed, 318 insertions(+), 0 deletions(-) (318 changed authored lines $\le$ 400)
- **Deliverables**:
  - `src/Foundation/Renderer.php`: Registered `page.products` and `fragment.product_table` in allowlist.
  - `config/routes.php`: Registered `GET /products` route.
  - `src/Modules/Inventory/CatalogHandler.php`: Handled `GET /products` using `ProductQuery::search()`, returning `page.products` for standard navigation and `fragment.product_table` for HTMX with `Vary: HX-Request`.
  - `templates/fragments/product_table.php`: Rendered product table displaying product name, category name (or explicit `Unclassified`), price, and status, with empty-state handling.
  - `templates/pages/products.php`: Server-rendered Bulma page layout with search bar supporting live search via `hx-get="/products"`, `hx-target="#product-table-container"`, `hx-swap="outerHTML"`.
  - `tests/Integration/CatalogHttpTest.php`: 6 tests verifying full HTML page navigation, matching keyword search, non-matching empty state, HTMX fragment swapping, XSS escaping, and active/inactive/unclassified display.
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/wt-4a1`) at `f31eb95`.
  - Targeted tests: 6 tests, 32 assertions (100% green).
  - Full suite: 107 tests, 285 assertions (100% green).
  - PHPStan: 39/39 files, 0 errors at Level Max.
  - Development DB: 0 tables (untouched).

#### Slice 4A2a: Category & Product Creation
- **Commit**: `0f6aa88` (`feat(inventory): add category and product creation`)
- **Diff Stat**: 5 files changed, 385 insertions(+), 61 deletions(-) (385 changed authored lines $\le$ 400)
- **Deliverables**:
  - `config/routes.php`: Registered `POST /categories` and `POST /products`.
  - `src/Modules/Inventory/CatalogHandler.php`: Added `createCategory` (unique name enforcement, optional description, 422 validation response) and `createProduct` (required name, decimal price, optional category verification, default active status, 422 validation response).
  - `templates/pages/products.php`: Added CSRF-protected Bulma creation forms for category and product.
  - `templates/fragments/product_table.php`: Added validation error display.
  - `tests/Integration/CatalogHttpTest.php`: 20 tests verifying category creation, duplicate category error handling, product registration with/without category, default active status, 0.00 price acceptance, negative/overprecision/malformed price rejection, CSRF 403 enforcement, XSS escaping, and absence of initial-status controls.
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/wt-4a2a`) at `0f6aa88`.
  - Targeted tests: 20 tests, 75 assertions (100% green).
  - Full suite: 121 tests, 328 assertions (100% green).
  - PHPStan: 39/39 files, 0 errors at Level Max.
  - Development DB: 0 tables (untouched).

#### Slice 4A2b: Price Update & Product Deactivation
- **Commit**: `bc40dcc` (`feat(inventory): add price update and product deactivation`)
- **Diff Stat**: 6 files changed, 215 insertions(+), 3 deletions(-) (215 changed authored lines $\le$ 400)
- **Deliverables**:
  - `src/Foundation/Router.php`: Added minimal, generic parameterized route matching (`matchPath`) supporting `{param}` pattern without product-specific coupling, preserving exact route performance, traversal checks, and 404/405 semantics.
  - `config/routes.php`: Registered `POST /products/{id}/price` and `POST /products/{id}/deactivate`.
  - `src/Modules/Inventory/CatalogHandler.php`: Added `updatePrice` (exact decimal price validation, zero-price support, 404 on missing product, 422 on invalid price) and `deactivate` (soft deactivation preserving rows and references, 404 on missing product).
  - `templates/fragments/product_table.php`: Added inline price update form with CSRF and deactivation button for active products; inactive products render inactive tag without reactivation action.
  - `tests/Integration/HttpTest.php`: Added parameterized route tests verifying exact match, parameter match, 405 Method Not Allowed, and 404 Not Found.
  - `tests/Integration/CatalogHttpTest.php`: 32 tests verifying valid price update, zero price update, negative/overprecision/malformed price rejection, price-update CSRF 403, active product deactivation, deactivation CSRF 403, storage retention after deactivation, inactive state, absence of physical DELETE routes, absence of reactivation routes, and inactive display without activate button.
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`scratch/wt-4a2b`) at `bc40dcc`.
  - Targeted tests: 32 tests, 107 assertions (100% green); `HttpTest`: 23 tests, 67 assertions (100% green).
  - Full suite: 134 tests, 367 assertions (100% green).
  - PHPStan: 39/39 files, 0 errors at Level Max.
  - Development DB: 0 tables (untouched).

#### Post-4A Correction: Catalog Entrypoint Integration Fix
- **Commit**: `b56cb40` (`fix(inventory): wire catalog into application entrypoint`)
- **Discovery**: Browser smoke testing revealed `GET /health` returned 200 OK while `GET /products` returned 500 Internal Server Error (`Undefined array key "catalog"` in `public/index.php:31`). `CatalogHttpTest` had previously constructed `CatalogHandler` directly in test isolation, masking the omitted production composition wiring in the front controller.
- **Root Cause & Fix**: `public/index.php` registered only the `'health'` handler. Fixed by instantiating `Database` (with `APP_ENV === 'test'` isolation check), `Transaction`, `ProductQuery`, `CategoryQuery`, `CategoryCommand`, `ProductCommand`, and `CatalogHandler`, registering `'catalog' => $catalog->handle(...)` in the front controller handler map.
- **Regression Test**: Added `CatalogHttpTest::testProductionEntrypointServesCatalog` executing a subprocess front-controller run (`public/index.php`) with `REQUEST_URI=/products` under `APP_ENV=test`. Proved fail-on-unwired (catches missing handler key, stderr warning, error logging, and `Request failed` 500 output) and pass-on-wired (clean exit code 0, empty error log, valid Bulma catalog HTML output containing `Product Catalog` and `id="product-table-container"`).
- **Test Isolation Harmonization**: Harmonized `testDevelopmentDatabaseRemainsUntouched` across `CatalogTest`, `CountTest`, `MigrationTest`, `SeedTest`, and `StockTest` to support both unmigrated and legitimately migrated development database states while strictly asserting zero test rows in application tables.
- **Real Localhost Verification**:
  - `GET http://127.0.0.1:8000/health` → `200 OK`
  - `GET http://127.0.0.1:8000/products` → `200 OK` (4,175 bytes, Bulma catalog layout, empty state `No products found`, category creation form, product registration form).
- **Verification Evidence**:
  - Targeted test (`CatalogHttpTest`): 33 tests, 114 assertions (100% green).
  - Full test suite (`composer test`): 135 tests, 382 assertions (100% green).
  - Static analysis (`composer analyse`): 39/39 files, 0 errors at PHPStan Level Max (Level 10).
  - Development DB (`sistema_ferreto`): 7 schema tables, exactly 0 application rows (untouched by tests).

---

## Database Invariant Audit
- Local environment audited against maintainer-approved configuration:
  - Development (`sistema_ferreto` on `127.0.0.1:3309` as `ferreto_app@127.0.0.1`): 7 tables (migrated 0001-0006), 0 application rows.
  - Test (`sistema_ferreto_test` on `127.0.0.1:3309` as `ferreto_test@127.0.0.1`): verified ending in `_test`, isolated execution.

---

## Remaining Tasks
- **Phase 5A: Server-Rendered Locations UI (Slice 4B.1 — Tasks 5A.1–5A.4)**: Pending
- **Phase 5B: Server-Rendered Stock & Counts UI (Slice 4B.2 — Tasks 5B.1–5B.4)**: Pending
- **Phase 6: Development Seeds & Regression Verification (Tasks 6.1–6.2)**: Pending
- **Phase 7: Product Reactivation (Tasks 7.1–7.5)**: Complete

---

## Phase 7: Product Reactivation Implementation (Slices 7A & 7B)

- **Phase Intent**: Complete Tasks 7.1 through 7.5 implementing domain command activation, parameterized HTTP route with CSRF, UI row actions, confirmation modal, and regression verification proving same-ID identity and reference preservation without physical deletion.
- **Review Budget & Subdivision Strategy**:
  - Subdivided into **Slice 7A** (Domain + HTTP Activation, 142 lines $\le$ 400) and **Slice 7B** (Activation UI & Modal Interaction, 109 lines $\le$ 400). Both slices strictly satisfied the $\le 400$ changed authored lines budget limit.

### Slice 7A: Domain Command & HTTP Activation
- **Commit**: `85929e1` (`feat(inventory): add product reactivation`)
- **Diff Stat**: 5 files changed, 139 insertions(+), 3 deletions(-) (142 changed authored lines $\le$ 400)
- **Deliverables**:
  - `src/Modules/Inventory/ProductCommand.php`: Implemented `activate(int $idProducto): bool`, running `UPDATE producto SET estado_activo = 1, updated_at = UTC_TIMESTAMP() WHERE id_producto = :id` within transaction boundaries. Symmetrical with `deactivate(...)`.
  - `config/routes.php`: Registered `POST /products/{id}/activate` mapped to `catalog`.
  - `src/Modules/Inventory/CatalogHandler.php`: Handled `POST /products/{id}/activate`, verifying product existence (404 on missing), calling `ProductCommand::activate($id)`, and returning mutation success.
  - `tests/Integration/CatalogTest.php`: Added `testProductActivation` and `testActivationPreservesSameProductIdentityAndReferences` verifying activation, same `id_producto`, and preservation of `inventario_stock` reference.
  - `tests/Integration/CatalogHttpTest.php`: Added `testInactiveProductCanBeActivated`, `testActivationCsrfFailureProduces403`, `testActivationNonexistentProductReturns404`, and `testActivationPreservesStockReferenceAndCreatesNoDuplicate`.
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`.worktree-7a`) at `85929e1`.
  - Targeted tests: `CatalogTest` (15 tests, 71 assertions), `CatalogHttpTest` (37 tests, 162 assertions) — 100% green.
  - Full suite: 141 tests, 506 assertions (100% green).
  - PHPStan: 39/39 files, 0 errors at Level Max.
  - Development DB: 0 test rows created, complete isolation verified.

### Slice 7B: Activation UI & Modal Interaction
- **Commit**: `7fe9ddc` (`feat(ui): add product activation interaction`)
- **Diff Stat**: 5 files changed, 102 insertions(+), 7 deletions(-) (109 changed authored lines $\le$ 400)
- **Deliverables**:
  - `templates/fragments/product_table.php`: Inactive rows render `Actualizar precio` and `Activar` (`btn-secondary`, amber tone, `data-modal-open="modal-activate"`). Active rows continue to render `Actualizar precio` and `Desactivar`.
  - `templates/pages/products.php`: Added accessible confirmation modal `modal-activate` with Spanish copy: *"¿Estás seguro de que deseas activar el producto...?"*, reassurance of reference preservation, CSRF token, and non-destructive `Activar` submit button (`btn-primary`).
  - `public/assets/app.js`: Wired `modal-activate` in `openModal()` to populate product name and target action URL `/products/{id}/activate`.
  - `assets/provenance.json`: Updated `app.js` SHA-256 hash (`a4bbef3bde86a16721f5fef17493a8856e773e8c12a223d1b547f80e853af55e`), verified by `composer setup`.
  - `tests/Integration/CatalogHttpTest.php`: Added tests verifying inactive row action rendering, modal Spanish copy, CSRF token presence, after-activation status transition, and absence of forbidden terms (*Restaurar*, *Recuperar*, *Reactivar*, *Eliminar*).
- **Independent Verification**:
  - Verified in isolated detached Git worktree (`.worktree-7b`) at `7fe9ddc`.
  - Targeted tests: `CatalogHttpTest` (39 tests, 186 assertions) — 100% green.
  - Full suite: 143 tests, 530 assertions (100% green).
  - PHPStan: 39/39 files, 0 errors at Level Max.
  - Development DB: Untouched, isolation verified.

---

## Phase 5 Decomposition Amendment: Locations UI vs Stock & Counts UI

- **Planning Decision**: Following completion of the Product Catalog and Reactivation lifecycle, the maintainer approved decomposing the bundled Phase 5 UI into two autonomous, reviewable slices:
  - **Phase 5A: Server-Rendered Locations UI (Slice 4B.1 — Tasks 5A.1–5A.4)**: Standalone `LocationHandler`, `templates/pages/locations.php`, routes `GET /locations` and `POST /locations`, production front-controller wiring, and comprehensive HTTP integration tests. Supports listing and creation with unique code validation and default active status. Strictly excludes edit, delete, deactivate, activate, restore, stock quantity editing, product assignment, or branch/warehouse modeling.
  - **Phase 5B: Server-Rendered Stock & Counts UI (Slice 4B.2 — Tasks 5B.1–5B.4)**: Retains `InventoryHandler`, `templates/pages/inventory.php`, `GET /inventory`, `POST /inventory/stock`, and `POST /inventory/counts` for subsequent implementation.
- **Rationale**:
  - Clearer page responsibilities: Locations are a master data catalog concept (matching `Catálogo -> Ubicaciones` in `docs/ui/DESIGN.md`), distinct from operational stock balances and physical count audits.
  - Review-budget control: Separating locations from stock and count workflows prevents oversized pull requests and preserves the $\le 400$ changed authored lines budget limit.
  - Incremental verification: Enables independent browser smoke testing and verification of physical locations before introducing multi-location stock positions.
- **Status**: Planning amendment complete. Phase 5A and 5B implementations remain pending. No domain or functional requirements were removed.
