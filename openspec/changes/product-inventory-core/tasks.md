# Tasks: Product & Inventory Core

## Phase 1: Catalog Foundation (Slice 1 — `product-catalog`)

- [x] 1.1 Create migration `database/migrations/0002_create_categoria.up.sql` (`categoria` table: `id_categoria` PK, `nombre` UNIQUE, `descripcion`, timestamps) and compensating reversal `0002_create_categoria.down.sql`.
- [x] 1.2 Create migration `database/migrations/0003_create_producto.up.sql` (`producto` table: `id_producto` PK, nullable `id_categoria` FK RESTRICT, `nombre`, `descripcion`, `precio_actual` DECIMAL(12,2) with CHECK >= 0, `estado_activo`, timestamps) and reversal `0003_create_producto.down.sql`.
- [x] 1.3 Implement `src/Modules/Inventory/CategoryQuery.php` and `CategoryCommand.php` for unique category registration and listing via PDO prepared statements.
- [x] 1.4 Implement `src/Modules/Inventory/ProductQuery.php` and `ProductCommand.php` for product registration, price update, and soft deactivation (`estado_activo = 0`).
- [x] 1.5 Implement `src/Modules/Inventory/CatalogValidator.php` for exact string decimal price validation (accepting at most 2 fractional digits via `/^\d+(\.\d{1,2})?$/`, rejecting negative values, without PHP float arithmetic).
- [x] 1.6 Add integration test `tests/Integration/CatalogTest.php` verifying category uniqueness, product CRUD, price non-negativity, nullable category assignment, and deactivation.

## Phase 2: Storage Locations & Associative Stock (Slice 2 — `inventory-locations-stock`)

- [x] 2.1 Create migration `database/migrations/0004_create_ubicacion.up.sql` (`ubicacion` table: `id_ubicacion` PK, `codigo` UNIQUE, `descripcion`, `estado_activo`, timestamps) and reversal `0004_create_ubicacion.down.sql`.
- [x] 2.2 Create migration `database/migrations/0005_create_inventario_stock.up.sql` (`inventario_stock` table: `id_stock` PK, FK `producto` RESTRICT, FK `ubicacion` RESTRICT, UNIQUE pair, `cantidad` DECIMAL(12,3) CHECK >= 0, timestamps) and reversal `0005_create_inventario_stock.down.sql`.
- [x] 2.3 Implement `src/Modules/Inventory/LocationQuery.php` and `LocationCommand.php` for registering and querying physical storage locations.
- [x] 2.4 Implement `src/Modules/Inventory/StockQuery.php` and `StockCommand.php` for establishing associative stock positions with exact decimal string validation (accepting at most 3 fractional digits via `/^\d+(\.\d{1,3})?$/`, rejecting negative values, without PHP float arithmetic).
- [x] 2.5 Add integration test `tests/Integration/StockTest.php` verifying location code uniqueness, stock position creation, duplicate pair rejection, and decimal constraints.

## Phase 3: Observational Inventory Counts (Slice 3 — `inventory-locations-stock`)

- [x] 3.1 Create migration `database/migrations/0006_create_conteo_inventario.up.sql` (`conteo_inventario` table: `id_conteo` PK, FK `id_stock` RESTRICT, `cantidad_sistema`, `cantidad_contada`, `diferencia`, `notas`, `created_at`) and reversal `0006_create_conteo_inventario.down.sql`.
- [x] 3.2 Implement `src/Modules/Inventory/CountQuery.php` for read-only retrieval of count records by stock position.
- [x] 3.3 Implement `src/Modules/Inventory/CountCommand.php` executing atomic `INSERT ... SELECT` from `inventario_stock` with exact decimal string validation for counted quantity (at most 3 fractional digits via `/^\d+(\.\d{1,3})?$/`, rejecting negative values, without PHP float arithmetic) to capture snapshot and MariaDB decimal variance without mutating stock.
- [x] 3.4 Add integration test `tests/Integration/CountTest.php` verifying count recording, atomic variance math, append-only immutability, and stock non-mutation.

## Phase 4: Server-Rendered Catalog UI (Slice 4A — `product-catalog`)

- [x] 4.1 Implement `src/Modules/Inventory/CatalogHandler.php` handling catalog listing, live search, product creation, price updating, and category creation.
- [x] 4.2 Create Bulma templates `templates/pages/products.php` and `templates/fragments/product_table.php` with HTMX live search and CSRF-protected modal forms.
- [x] 4.3 Register catalog routes in `config/routes.php` (`GET /products`, `POST /products`, `POST /products/{id}/price`, `POST /products/{id}/deactivate`, `POST /categories`).
- [x] 4.4 Add HTTP integration test `tests/Integration/CatalogHttpTest.php` verifying 200/422 status, HTMX partial rendering, and CSRF token enforcement.

## Phase 5A: Server-Rendered Locations UI (Slice 4B.1 — `inventory-locations`)

- [x] 5A.1 Implement a module-owned `LocationHandler` in `src/Modules/Inventory/LocationHandler.php` for location listing and creation using existing `LocationQuery` and `LocationCommand`.
- [x] 5A.2 Create Bulma templates `templates/pages/locations.php` with canonical locations table, empty state, and CSRF-protected location creation modal following `docs/ui/DESIGN.md`.
- [x] 5A.3 Register routes `GET /locations` and `POST /locations` in `config/routes.php` and wire `LocationHandler` into the production front controller `public/index.php`.
- [x] 5A.4 Add HTTP integration test `tests/Integration/LocationHttpTest.php` verifying 200/422 status, listing, empty state, creation, duplicate-code validation, CSRF enforcement, HTML escaping, production composition, and absence of unsupported CRUD actions.

## Phase 5B.1: Server-Rendered Stock by Location UI (Slice 4B.2 — `inventory-stock-by-location`)

- [x] 5B.1.1 Implement module-owned `InventoryHandler` in `src/Modules/Inventory/InventoryHandler.php` handling stock position overview and new position registration using `StockQuery`, `StockCommand`, `ProductQuery`, `LocationQuery`, and `StockValidator`.
- [x] 5B.1.2 Create Bulma template `templates/pages/inventory.php` with canonical stock table (`Producto`, `Ubicación`, `Cantidad`), empty state, and CSRF-protected position creation modal following `docs/ui/DESIGN.md`.
- [x] 5B.1.3 Register routes `GET /inventory` and `POST /inventory/stock` in `config/routes.php` and wire `InventoryHandler` into the production front controller `public/index.php`.
- [x] 5B.1.4 Add HTTP integration test `tests/Integration/InventoryHttpTest.php` verifying 200/422 status, listing, empty state, position creation, decimal validation, duplicate pair rejection, CSRF enforcement, HTML escaping, production composition, and absence of unsupported quantity-edit/adjustment actions.

## Phase 5B.2: Observational Inventory Counts UI (Slice 4B.3 — `inventory-counts`)

- [x] 5B.2.1 Extend `InventoryHandler` in `src/Modules/Inventory/InventoryHandler.php` to handle count page browsing (`GET /inventory/counts`) and observational count submission (`POST /inventory/counts`) using `CountCommand`, `CountQuery`, `StockQuery`, and `StockValidator`.
- [x] 5B.2.2 Create Bulma templates `templates/pages/counts.php` and `templates/fragments/count_history.php` with stock position selector, system quantity reference, count registration modal `#modal-count`, non-mutation notice, and immutable count history following `docs/ui/DESIGN.md`.
- [x] 5B.2.3 Register routes `GET /inventory/counts` and `POST /inventory/counts` in `config/routes.php` and wire `CountCommand` and `CountQuery` into `InventoryHandler` in `public/index.php`.
- [x] 5B.2.4 Add HTTP integration tests `tests/Integration/CountHttpTest.php` verifying 200/422 status, stock position selection, count submission, server-authoritative snapshot/variance, negative variance support, append-only immutability, stock non-mutation, CSRF enforcement, HTML escaping, production composition, and absence of edit/reconciliation actions.

## Phase 6: Development Seeds & Regression Verification

- [x] 6.1 Review development fixture strategy and intentionally omit domain fixtures because product identity lacks an approved natural unique key and development already contains maintainer-created validation data.
- [ ] 6.2 Execute canonical regression suite (`composer setup`, `composer test`, `composer analyse`) verifying zero regressions and full spec compliance.

## Phase 7: Product Reactivation

- [x] 7.1 Extend product-catalog specification and design for reversible active/inactive lifecycle (`spec.md`, `design.md`, `proposal.md`, `exploration.md`).
- [x] 7.2 Implement `ProductCommand::activate` using the existing product record and `id_producto`.
- [x] 7.3 Register CSRF-protected `POST /products/{id}/activate` and `CatalogHandler::activate` support.
- [x] 7.4 Render `Activar` only for inactive products and preserve `Desactivar` only for active products.
- [x] 7.5 Add domain and HTTP regression tests proving same-id reactivation, CSRF enforcement, reference preservation, and absence of physical deletion.
