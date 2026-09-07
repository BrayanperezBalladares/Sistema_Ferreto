# Implementation Progress: product-inventory-core

## Status Summary

- **Change**: `product-inventory-core`
- **Phase**: Phase 2 — Storage Locations & Associative Stock (Slice 2)
- **Completed Tasks**: Tasks 1.1 through 1.6, and Tasks 2.1 through 2.5
- **Review Budget Management**:
  - The original combined Slice 2 implementation reached ~487 authored changed lines across 11 files, triggering the mandatory review budget stop gate ($\le$ 400 lines).
  - The maintainer declined a size exception and approved non-destructive subdivision into two autonomous, reviewable units:
    - **Slice 2A — Physical Locations Foundation** (commit `6ac0872`, 217 changed authored lines)
    - **Slice 2B — Associative Stock Foundation** (commit `61b0bca`, 304 changed authored lines)

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
  - `src/Modules/Inventory/StockCommand.php` (transactional position creation with FK checks and validation, transactional quantity update)
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

---

## Database Invariant Audit
- Local environment audited against maintainer-approved configuration:
  - Development (`sistema_ferreto` on `127.0.0.1:3309` as `ferreto_app@127.0.0.1`): 0 tables, completely untouched.
  - Test (`sistema_ferreto_test` on `127.0.0.1:3309` as `ferreto_test@127.0.0.1`): verified ending in `_test`, isolated execution.

---

## Remaining Tasks
- **Phase 3: Observational Inventory Counts (Slice 3 — Tasks 3.1–3.4)**: Pending
- **Phase 4: Server-Rendered Catalog UI (Slice 4A — Tasks 4.1–4.4)**: Pending
- **Phase 5: Server-Rendered Inventory & Counts UI (Slice 4B — Tasks 5.1–5.4)**: Pending
- **Phase 6: Development Seeds & Regression Verification (Tasks 6.1–6.2)**: Pending
