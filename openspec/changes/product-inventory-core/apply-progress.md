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

---

## Database Invariant Audit
- Local environment audited against maintainer-approved configuration:
  - Development (`sistema_ferreto` on `127.0.0.1:3309` as `ferreto_app@127.0.0.1`): 0 tables, completely untouched.
  - Test (`sistema_ferreto_test` on `127.0.0.1:3309` as `ferreto_test@127.0.0.1`): verified ending in `_test`, isolated execution.

---

## Remaining Tasks
- **Phase 5: Server-Rendered Inventory & Counts UI (Slice 4B — Tasks 5.1–5.4)**: Pending
- **Phase 6: Development Seeds & Regression Verification (Tasks 6.1–6.2)**: Pending
