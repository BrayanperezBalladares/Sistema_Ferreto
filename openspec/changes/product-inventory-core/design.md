# Design: Product & Inventory Core

## Technical Approach

Implement the core product catalog and multi-location decimal inventory established in **R1** and the approved specifications (`product-catalog`, `inventory-locations-stock`). The solution introduces a cohesive `Inventory` module inside `src/Modules/Inventory/` adhering to the foundation's modular monolith architecture:
- Schema migrations define 5 tables with explicit engine constraints.
- Module-owned queries (read-only) and commands (parameterized mutations) isolate PDO.
- Observational inventory counts capture snapshots and variances atomically using MariaDB decimal arithmetic without updating stock balances.
- Server-rendered Bulma pages and HTMX partials deliver catalog search, modal forms, and count entry with CSRF protection.

---

## Architecture Decisions

| Decision | Option Chosen | Alternatives Considered | Rationale |
|---|---|---|---|
| **Module Structure** | Single `src/Modules/Inventory/` namespace | Separate `Catalog` and `Inventory` modules | Keeps transaction boundaries and queries cohesive without inter-module overhead for R1. |
| **Stock Association** | Associative `inventario_stock` table | Column on `producto` | Supports multi-location storage per spec without schema restructuring later. |
| **Decimal Arithmetic** | MariaDB `DECIMAL(12,3)` + PHP string validation | PHP `float` or BCMath extension | Floats produce binary rounding drift; DB decimal operations guarantee exact precision. |
| **Count Capture** | Atomic `INSERT ... SELECT` from `inventario_stock` | Multi-query read-then-insert | Eliminates race conditions; calculates `diferencia` and captures `cantidad_sistema` in one atomic step. |
| **Count Immutability** | Append-only (no UPDATE/DELETE routes) | Status flag with soft delete | Specs mandate immutable observation records; preventing mutation eliminates audit tampering. |
| **Lifecycle & Deactivation Policy** | Reversible `estado_activo` flag (`ACTIVE <-> INACTIVE`) | Physical SQL `DELETE` or trash lifecycle | Preserves referential integrity for historical stock/count records and allows reactivation without duplicate product entities. |

---

## Data Flow & Atomic Count Architecture

```
[Operator Input: id_stock, cantidad_contada]
                     │
                     ▼
          [CSRF & Format Validation]
                     │
                     ▼
           [CountCommand::record]
                     │
                     ▼
    INSERT INTO conteo_inventario (
        id_stock, cantidad_sistema, cantidad_contada, diferencia, notas, created_at
    )
    SELECT
        s.id_stock,
        s.cantidad,
        :cantidad_contada,
        (:cantidad_contada - s.cantidad),
        :notas,
        UTC_TIMESTAMP()
    FROM inventario_stock s
    WHERE s.id_stock = :id_stock;
                     │
                     ▼
    [INVENTARIO_STOCK remains unchanged]
```

---

## Relational Schema & Migration Sequence

MariaDB DDL causes implicit commits. Each migration file contains exactly one executable DDL statement:

| Migration File | Statement Type | Table / Constraint Target | Compensating Reversal (.down.sql) |
|---|---|---|---|
| `0002_create_categoria.up.sql` | `CREATE TABLE` | `categoria` (`id_categoria` PK, `nombre` UNIQUE) | `DROP TABLE categoria` |
| `0003_create_producto.up.sql` | `CREATE TABLE` | `producto` (`id_producto` PK, nullable FK `categoria` RESTRICT, `precio_actual`, `estado_activo`) | `DROP TABLE producto` |
| `0004_create_ubicacion.up.sql` | `CREATE TABLE` | `ubicacion` (`id_ubicacion` PK, `codigo` UNIQUE, `estado_activo`) | `DROP TABLE ubicacion` |
| `0005_create_inventario_stock.up.sql` | `CREATE TABLE` | `inventario_stock` (FK `producto`, FK `ubicacion`, UNIQUE pair, `cantidad >= 0`) | `DROP TABLE inventario_stock` |
| `0006_create_conteo_inventario.up.sql` | `CREATE TABLE` | `conteo_inventario` (FK `id_stock`, `cantidad_sistema`, `cantidad_contada`, `diferencia`) | `DROP TABLE conteo_inventario` |

Reversal order is strictly `0006 -> 0005 -> 0004 -> 0003 -> 0002`, dropping child foreign key dependents before parent tables.

---

## Component Interfaces & Data Access

Handlers never access `PDO`. They consume module-owned query and command objects:

```php
namespace App\Modules\Inventory;

interface CategoryCommand {
    public function create(string $nombre, ?string $descripcion = null): int;
}

interface CategoryQuery {
    public function findAll(): array;
    public function findByName(string $name): ?array;
    public function findById(int $id): ?array;
}

interface ProductCommand {
    public function register(string $nombre, string $precioActual, ?int $idCategoria = null, ?string $desc = null): int;
    public function updatePrice(int $idProducto, string $nuevoPrecio): bool;
    public function deactivate(int $idProducto): bool;
    public function activate(int $idProducto): bool;
}

interface ProductQuery {
    public function search(string $query = '', ?int $categoryId = null, bool $activeOnly = false): array;
    public function findById(int $id): ?array;
}

interface StockCommand {
    public function registerPosition(int $idProducto, int $idUbicacion, string $cantidadInicial = '0.000'): int;
}

interface StockQuery {
    public function findPosition(int $idProducto, int $idUbicacion): ?array;
    public function listOverview(): array;
}

interface CountCommand {
    public function recordCount(int $idStock, string $cantidadContada, ?string $notas = null): int;
}

interface CountQuery {
    public function listByStock(int $idStock): array;
}
```

---

## HTTP Routes & Server-Rendered HTMX Delivery

All mutating requests require valid CSRF tokens (`Csrf::validateToken()`). All outputs escape HTML via `Renderer`:

| Method | URI | Purpose | View / Fragment Rendered |
|---|---|---|---|
| `GET` | `/products` | Catalog listing & live search | `page.catalog` (full) or `fragment.product_table` (HTMX) |
| `POST` | `/products` | Register new product | Redirect `/products` or `fragment.product_row` |
| `POST` | `/products/{id}/price` | Update current selling price | `fragment.product_row` |
| `POST` | `/products/{id}/deactivate` | Deactivate product | `fragment.product_row` |
| `POST` | `/products/{id}/activate` | Reactivate inactive product | `fragment.product_row` |
| `POST` | `/categories` | Create product category | Redirect `/products` or modal swap |
| `GET` | `/locations` | Physical locations listing & empty state | `page.locations` |
| `POST` | `/locations` | Register new physical storage location | Redirect `/locations` or modal response |
| `GET` | `/inventory` | Multi-location stock overview (Phase 5B) | `page.inventory` |
| `POST` | `/inventory/stock` | Establish stock position (Phase 5B) | `fragment.stock_row` |
| `POST` | `/inventory/counts` | Record observational count (Phase 5B) | `fragment.count_row` |

### Locations UI Decomposition (Approved Phase 5A)
To decouple physical storage location management from inventory stock and counts:
- **`LocationHandler`**: A module-owned HTTP handler (`src/Modules/Inventory/LocationHandler.php`) consuming existing `LocationQuery` and `LocationCommand`. Handles `GET /locations` (page listing with empty state) and `POST /locations` (creation modal mutation with CSRF and duplicate-code validation). It does NOT introduce repositories, generic CRUD handlers, or new DI containers.
- **Production Wiring**: Wired explicitly in `public/index.php` alongside `CatalogHandler` and `HealthHandler` to guarantee production front-controller availability and avoid missing-entrypoint defects.
- **Authorized Routes**: `GET /locations` and `POST /locations`. Physical deletion (`DELETE /locations/{id}`), modification (`PUT`/`PATCH`), lifecycle toggles (`POST /locations/{id}/deactivate`, `POST /locations/{id}/activate`), and restore actions remain strictly unsupported.
- **Functional Scope**: Supports listing physical locations, creating locations with unique code, optional description, default active status, Spanish validation feedback, CSRF protection, and XSS escaping. Explicitly excludes stock quantity editing, product assignments, counts, branch modeling, warehouse modeling, and bin/aisle hierarchy.
- **Template Architecture**: Delivered via `templates/pages/locations.php` inheriting the canonical visual shell from `docs/ui/DESIGN.md` (charcoal sidebar, light workspace, header, primary action *Nueva ubicación*, and accessible modal).
- **Table Contract**: Server-rendered table with columns `Código`, `Descripción`, and `Estado` (`Activo` soft green badge). Acciones column is omitted because no row actions are supported.
- **Modal Contract**: Focused modal `Nueva ubicación` with fields for `Código` (mandatory) and `Descripción` (optional). Default active status remains implicit. Actions: `Cancelar` and `Guardar ubicación`.
- **Navigation**: Sidebar under `Catálogo` exposes `Productos` and `Ubicaciones`. Stock and Counts navigation links remain omitted until Phase 5B.
- **Phase 5B Scope**: `GET /inventory`, `POST /inventory/stock`, and `POST /inventory/counts` remain reserved for Phase 5B. Phase 5B will utilize existing locations created via `/locations` without duplicating location-management responsibilities.

### Product Reactivation Extension (Approved Post-4A)
To implement the reversible `ACTIVE <-> INACTIVE` lifecycle:
- **`ProductCommand::activate(int $id): bool`**: Executes parameterized `UPDATE producto SET estado_activo = 1, updated_at = UTC_TIMESTAMP() WHERE id_producto = :id` within the active transaction boundary. Operates strictly on the existing `id_producto`; creates no replacement or duplicate product row.
- **`CatalogHandler::activate(Request $request, int $id): Response`**: Handles `POST /products/{id}/activate`. Enforces CSRF token validation, verifies that the product exists (returns 404 if not found), calls `ProductCommand::activate($id)`, and returns an HTMX swap or 303 redirect with a success notification.
- **Route**: Registered as `POST /products/{id}/activate` in `config/routes.php`. Physical deletion (`DELETE /products/{id}`) remains strictly unsupported.

---

## Implementation Slicing Strategy (<= 400 Lines Budget)

| Slice | Scope & Deliverables | Estimated Lines |
|---|---|---|
| **1. Catalog Core** | Migrations 0002/0003, `Category` & `Product` queries/commands, validation, and integration tests. | ~300 lines |
| **2. Storage Locations & Stock** | Migrations 0004/0005, `Location` & `Stock` queries/commands, decimal constraints, and integration tests. | ~310 lines |
| **3. Observational Counts** | Migration 0006, `Count` query/atomic command, variance math, and immutability tests. | ~260 lines |
| **4. Server-Rendered Catalog UI** | Route wiring, Bulma templates (`pages/`, `fragments/`), and full-page/HTMX integration tests. | ~350 lines |
| **5A. Server-Rendered Locations UI** | Standalone `LocationHandler`, `locations.php` template, `GET/POST /locations` routes, production wiring, and HTTP tests. | ~300 lines |
| **5B. Server-Rendered Stock & Counts UI** | `InventoryHandler`, `inventory.php` template, `GET /inventory`, `POST /inventory/stock`, `POST /inventory/counts`, and HTTP tests. | ~350 lines |

---

## Testing Strategy

| Layer | Target | Verification Approach |
|---|---|---|
| **Unit** | Domain validation & math | Test price format (`regex`), decimal string format, and variance signs. |
| **Integration (DB)** | Migrations & queries/commands | Run against isolated `*_test` DB; verify FK restrictions, `CHECK` constraints, and atomic count calculations. |
| **Integration (HTTP)** | Handlers & HTMX | Verify 200/422 status codes, CSRF rejection (403), full-page vs fragment headers (`Vary: HX-Request`). |

---

## Rollback & Safety

1. **Reversal Plan**: Execute `MigrationRunner::revert()` down to 0001, removing tables in reverse FK order. Remove `src/Modules/Inventory/` and related templates.
2. **Safety Invariants**:
   - MariaDB `CHECK (cantidad >= 0.000)` and `CHECK (precio_actual >= 0.00)` block corrupt data at storage level.
   - `UNIQUE (id_producto, id_ubicacion)` prevents duplicate inventory records.
   - Count operations execute read-only queries against `inventario_stock`, guaranteeing zero stock mutation.
