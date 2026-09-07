# Proposal: Product & Inventory Core

## Intent

Establish the baseline product catalog, storage locations, and multi-location inventory stock required by **R1**. The system currently has an infrastructure foundation but no business domain entities or data access layer. This change delivers the minimum reliable data and presentation core to track goods and observational counts without premature dependencies on sales, logistics, or purchasing.

## Scope

### In Scope
- **Catalog Master**: Categories and Products with current selling price (`precio_actual`), optional category assignment (`id_categoria` nullable), and reversible active/inactive lifecycle (`estado_activo`).
- **Physical Locations**: Generic storage positions (`codigo`, `descripcion`, `estado_activo`) independent from branches or warehouses.
- **Associative Stock**: Decimal stock quantities (`DECIMAL(12,3)`) linked associatively via `INVENTARIO_STOCK (id_producto, id_ubicacion)` with `CHECK (cantidad >= 0.000)` and `UNIQUE(id_producto, id_ubicacion)`.
- **Observational Counts**: Append-only audit records (`CONTEO_INVENTARIO`) capturing system snapshot, physical count, and variance linked to `id_stock`. Zero mutation of recorded stock.
- **Server-Rendered UI**: Bulma + HTMX views for catalog search, product/category modal forms, stock list, and observational count entry.
- **Data Access & Tests**: Module-owned PDO queries/commands in `src/Modules/Inventory/`, schema migrations, development seeds, and integration tests.

> [!NOTE]
> **Product Lifecycle Amendment**:
> While initially scoped as active -> inactive only, the maintainer subsequently approved a reversible `ACTIVE <-> INACTIVE` lifecycle allowing reactivation of inactive products using the same `id_producto` and preserving all references. Physical deletion remains strictly out of scope.

### Out of Scope
- SKU and barcode tracking (deferred to R5/future).
- Branch and warehouse hierarchies (`SUCURSAL`, `ALMACEN`) (deferred to R9).
- Stock adjustments, reconciliation workflows, or balance mutations from counts.
- Price history, temporal pricing, discounts, or taxes.
- Units-of-measure subsystem and conversion engines.
- Reorder points, minimum/maximum stock thresholds, lots, FIFO, and expirations (R3/R5).
- Sales POS (R2), purchases (R3), transfers (R9), DW/ETL (R7), and full audit logging (R6).

## Capabilities

### New Capabilities
- `product-catalog`: Product definitions, optional category classification, current selling price, and server-rendered catalog browsing/search.
- `inventory-locations-stock`: Generic physical storage positions, associative decimal stock balances, and observational inventory count recording with variance tracking.

### Modified Capabilities
None. (Foundation specs remain intact).

## Approach

Implement a cohesive `Inventory` module in `src/Modules/Inventory/` adhering to the foundation's modular monolith architecture:
- Schema migrations define `categoria`, `producto`, `ubicacion`, `inventario_stock`, and `conteo_inventario`.
- Module-owned SQL queries and commands encapsulate persistence using prepared PDO statements; handlers never call PDO directly.
- Server-rendered HTML pages and HTMX partials deliver interactive catalog search and observational count entry with Bulma styling and CSRF validation.
- Counts record observational verification against `id_stock`; stock adjustment logic is strictly excluded.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/` | New | Ordered migrations for core tables and constraints. |
| `database/seeds/development.php` | Modified | Sample categories, products, locations, and stock fixtures. |
| `src/Modules/Inventory/` | New | Handlers, domain validation, module-owned queries and commands. |
| `templates/pages/`, `templates/fragments/` | New | Server-rendered Bulma/HTMX catalog and inventory views. |
| `config/routes.php` | Modified | Registration of catalog and inventory routes. |
| `tests/Integration/` | New | Integration tests verifying CRUD, constraints, and HTMX rendering. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Expectation of count-driven stock adjustment | Medium | Explicit UI copy stating counts are audit records and do not mutate stock balances. |
| Branch/warehouse abstraction leakage | Low | Keep `UBICACION` strictly a local storage coordinate without foreign keys to branches or multi-tier warehouses. |
| Fractional quantity rounding drift | Low | Enforce database `DECIMAL(12,3)` column types and string/decimal PHP formatting. |

## Rollback Plan

Revert migrations via the canonical runner (`MigrationRunner`), dropping `conteo_inventario`, `inventario_stock`, `ubicacion`, `producto`, and `categoria`. Remove `src/Modules/Inventory/`, associated templates, and route entries. Foundation remains operational.

## Dependencies

Existing foundation:
- `src/Foundation/{Database, Transaction, Router, Renderer, Csrf, ValidationResult}`.
- MariaDB with `*_test` database isolation and canonical `composer test` workflow.

## Success Criteria

- [ ] Migrations create all 5 tables with explicit non-negative and uniqueness constraints.
- [ ] Catalog UI allows creating and searching products with optional categories and decimal prices.
- [ ] Multi-location stock positions track non-negative decimal quantities.
- [ ] Recording an inventory count calculates variance and saves an immutable record without mutating stock balances.
- [ ] PHPUnit test suite passes cleanly with 0 failures and PHPStan level max clean.
- [ ] Total implementation planned across reviewable slices under 400 authored lines each.
