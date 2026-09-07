# Product & Inventory Core Exploration

## Exploration: product-inventory-core

### Current State
The project has completed its foundation bootstrap (`project-foundation-bootstrap`). The infrastructure includes:
- A reproducible runtime (PHP 8.5.10, Composer 2.10.3) with PHPUnit and PHPStan (Level max clean).
- Secure HTTP delivery via a single front controller (`public/index.php`), route dispatcher (`Router`), native session handling, CSRF verification, and layout/fragment rendering (`Renderer`).
- A transactional data foundation using MariaDB via PDO with explicit transaction control (`Transaction`), serialized ordered migration engine (`MigrationRunner`), and idempotent development seeder (`SeedRunner`).
- No business domain modules, tables, or seed data currently exist in `src/Modules/`, `database/migrations/`, or `database/seeds/`.

The official requirements for the business domain are governed by the canonical statement (R1â€“R10).
This exploration assesses requirement **R1**:
> **R1:** Products, categories, prices, stock, warehouse locations, and periodic inventory counts/controls.

This updated exploration incorporates the final maintainer decisions to produce a minimal, strictly bounded core:
1. **Optional Category Assignment**: `PRODUCTO.id_categoria` is nullable (`NULL`), permitting creation of unclassified products without imposing an unsupported restriction.
2. **Total Removal of SKU and Barcode**: Neither SKU nor barcode exist in the R1 relational schema (barcode deferred to **R5**; SKU remains outside official scope). Product technical identity relies exclusively on the database primary key (`id_producto`).
3. **Exclusion of Stock Adjustments**: Stock adjustment/reconciliation is explicitly **OUT OF SCOPE**. Recording an inventory count captures observational data and variance, but **must not mutate recorded stock**. No "Apply Adjustment" workflow or approval states exist in R1.
4. **Simplified Physical Locations**: Location is modeled as a flexible, generic storage position (`UBICACION` with `codigo`, `descripcion`, `estado_activo`), without warehouse-specific coordinates (aisle/shelf/level) or coupling to **R9** branches/warehouses.
5. **Current Selling Price Only**: The product table stores only `precio_actual`. Price history and temporal pricing are out of scope.
6. **Relational Integrity via `id_stock`**: `CONTEO_INVENTARIO` references `INVENTARIO_STOCK.id_stock` directly, eliminating redundant foreign keys and guaranteeing that counts attach to valid product/location stock positions. Duplicate product/location positions are prohibited by a uniqueness invariant.
7. **Decimal Stock Quantities**: Stock quantities and count figures use decimal representation (`DECIMAL(12,3)`), preventing truncation of fractional goods without introducing a units-of-measure subsystem.

---

### Affected Areas
- `database/migrations/` â€” ordered migrations defining core tables (`categoria`, `producto`, `ubicacion`, `inventario_stock`, and `conteo_inventario`).
- `database/seeds/development.php` â€” deterministic development fixtures for sample categories, products, locations, and initial stock quantities.
- `src/Modules/Inventory/` â€” new module directory following the project's module-owned query/command architecture (no generic repository or ORM):
  - Handlers for catalog viewing/editing, location listing, stock observation, and count recording.
  - Module-owned SQL queries and commands using PDO parameterization and explicit transactions.
  - Domain validation rules for prices, decimal quantities, and observational count capture.
- `templates/pages/` and `templates/fragments/` â€” Bulma + HTMX views:
  - Product catalog table with asynchronous search.
  - Product and category creation/edit forms.
  - Location and stock overview.
  - Observational inventory count sheet displaying recorded stock, counted input, and live variance calculation.
- `config/routes.php` â€” registration of inventory and catalog routes.
- `tests/Integration/` and `tests/Unit/` â€” integration tests verifying schema migrations, non-negative decimal stock invariants, observational count persistence, variance calculation, and server-rendered HTML/HTMX responses.

---

### Approaches

| Approach | Pros | Cons | Complexity |
|---|---|---|---|
| **1. Strictly Minimal Decoupled Core (Recommended)**: Implement R1 with products (current price, optional category), generic locations, associative decimal stock (`id_stock`), and observational count logs inside `src/Modules/Inventory/`. | Exact fit for R1. Eliminates all premature policies (no branch coupling, no barcodes, no adjustment workflows, no UOM bloat). Adheres cleanly to the 400 authored-line review budget. | Stock adjustment remains a manual/deferred process until a later requirement defines adjustment policies. | Low-Medium |
| **2. Single Flat Product Entity**: Attach `stock_actual` and location text directly to `PRODUCTO`. | Minimal initial table count. | Violates multi-location requirement and relational normalization; would require destructive schema rewrite when multi-bin storage or R9 arrives. | Low |
| **3. Expanded Inventory Engine (With Adjustments & Barcodes)**: Include automatic adjustment workflows, approval states, aisle/shelf/level coordinates, and barcode fields. | Provides an end-to-end adjustment pipeline immediately. | Massive scope creep; violates maintainer decisions #2, #3, and #4; blows past line budget. | High |

---

### Recommendation
Adopt **Approach 1 (Strictly Minimal Decoupled Core)**.
Build a clean `Inventory` module in `src/Modules/Inventory/` strictly scoped to R1:
- Products have an optional category, name, description, active status, and current selling price (`precio_actual`).
- Locations represent generic physical storage positions (`codigo`, `descripcion`, `estado_activo`).
- Stock is held associatively in `INVENTARIO_STOCK (id_producto, id_ubicacion, cantidad)` with decimal precision and non-negative constraints.
- Inventory counts record observational audits (`cantidad_sistema`, `cantidad_contada`, `diferencia`) referencing `id_stock`, without mutating stock balances.

---

### Detailed Analysis by Exploration Goals

#### 1. Minimum Domain Concepts for R1
- **Category (`Categoria`)**: High-level merchandise grouping (e.g., "Herramientas Manuales", "Pinturas").
- **Product (`Producto`)**: Central catalog definition with technical ID (`id_producto`), name, description, active status, current price (`precio_actual`), and optional category reference.
- **Location (`Ubicacion`)**: Generic physical storage position (`codigo`, `descripcion`, `estado_activo`).
- **Inventory Stock (`InventarioStock`)**: Associative position recording the current decimal quantity of a product at a specific location.
- **Inventory Count (`ConteoInventario`)**: Observational audit record capturing a physical verification against a stock position: system recorded quantity, counted quantity, and calculated discrepancy.

#### 2. Relationships Among Domain Entities
```
+---------------+        1:N (optional)        +---------------+
|   CATEGORIA   | ---------------------------< |   PRODUCTO    |
+---------------+                              +---------------+
                                                       | 1
                                                       |
                                                       | N
+---------------+             1:N              +---------------+
|   UBICACION   | ---------------------------< |INVENTARIO_STOCK|
+---------------+                              +---------------+
                                                       | 1
                                                       |
                                                       | N
                                              +-----------------+
                                              |CONTEO_INVENTARIO|
                                              +-----------------+
```
- `CATEGORIA` -> `PRODUCTO`: 1 to N. A product may optionally reference a category (`id_categoria NULL` allowed).
- `PRODUCTO` & `UBICACION` -> `INVENTARIO_STOCK`: Associative relationship. A product can reside in zero, one, or more locations. A location can hold multiple products. Uniqueness on `(id_producto, id_ubicacion)` ensures a single stock position per product-location pair.
- `INVENTARIO_STOCK` -> `CONTEO_INVENTARIO`: 1 to N. Each count record references an exact `id_stock` position, guaranteeing referential integrity without duplicating foreign keys.

#### 3. Final Business Invariants Supported by R1
- **Non-Negative Stock**: `cantidad >= 0.000` at all times, enforced via database `CHECK` constraint and domain validation.
- **Unique Product-Location Position**: `UNIQUE (id_producto, id_ubicacion)` on `INVENTARIO_STOCK` ensures no duplicate stock entries for the same product in the same location.
- **Non-Negative Current Price**: `precio_actual >= 0.00`, stored as `DECIMAL(12,2)`.
- **Decimal Quantity Precision**: Quantities are stored as `DECIMAL(12,3)` to preserve fractional units without floating-point errors.
- **Count Immutability**: Count records are purely observational append-only snapshots. Once written, they cannot be updated or deleted.
- **Count Isolation (No Mutation)**: Creating a count record **never updates** `INVENTARIO_STOCK.cantidad`.

#### 4. Product Attributes vs. Inventory/Location Attributes
- **Product Entity (`PRODUCTO`)**: Global catalog facts:
  - Technical ID: `id_producto` (primary key).
  - Classification: `id_categoria` (optional foreign key).
  - Identity & Description: `nombre`, `descripcion`.
  - Commercial: `precio_actual` (current selling price; no history).
  - Lifecycle: `estado_activo` (boolean, defaults to active).
  - Timestamps: `created_at`, `updated_at`.
- **Location Entity (`UBICACION`)**: Storage facts:
  - Identifier: `id_ubicacion` (primary key).
  - Labeling: `codigo` (unique, e.g. "LOC-A1"), `descripcion`.
  - Lifecycle: `estado_activo` (boolean).
  - Timestamps: `created_at`, `updated_at`.
- **Inventory Stock (`INVENTARIO_STOCK`)**: Localized quantity facts:
  - Position: `id_stock`, `id_producto`, `id_ubicacion`.
  - Quantity: `cantidad` (`DECIMAL(12,3)`).
  - Timestamps: `created_at`, `updated_at`.

#### 5. Stock Representation & Multi-Location Design
- Stock is held exclusively in `INVENTARIO_STOCK`, never directly on `PRODUCTO`.
- A product can be associated with multiple locations.
- For UI simplicity in initial slices, views may display a primary/first location or a simple position list.
- Without sales (R2), purchases (R3), or transfers (R9), stock levels in R1 remain fixed once initialized via development seeds/fixtures.

#### 6. Observational Inventory Counts
- An inventory count represents a physical check at a point in time.
- Workflow:
  1. Operator selects a stock position (`id_stock`).
  2. System captures current recorded quantity (`cantidad_sistema`).
  3. Operator enters the counted quantity (`cantidad_contada`).
  4. System calculates variance: `diferencia = cantidad_contada - cantidad_sistema`.
  5. System records an immutable row in `CONTEO_INVENTARIO`.
  6. **`INVENTARIO_STOCK.cantidad` remains unchanged.** Stock adjustment is deferred to a future requirement.

#### 7. Simplified Location Semantics (Independent from R9)
- Locations are generic storage identifiers (`UBICACION`).
- No physical coordinate axes (no aisle, shelf, level columns).
- No foreign key to branches (`SUCURSAL`) or warehouses (`ALMACEN`). Central branch management belongs exclusively to **R9**.

#### 8. Final Relational Model

```sql
CREATE TABLE categoria (
    id_categoria INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE producto (
    id_producto INT AUTO_INCREMENT PRIMARY KEY,
    id_categoria INT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    precio_actual DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado_activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_producto_categoria FOREIGN KEY (id_categoria)
        REFERENCES categoria(id_categoria) ON DELETE SET NULL,
    CONSTRAINT chk_producto_precio CHECK (precio_actual >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ubicacion (
    id_ubicacion INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(150) NULL,
    estado_activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventario_stock (
    id_stock INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    id_ubicacion INT NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT uk_stock_producto_ubicacion UNIQUE (id_producto, id_ubicacion),
    CONSTRAINT fk_stock_producto FOREIGN KEY (id_producto)
        REFERENCES producto(id_producto) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_ubicacion FOREIGN KEY (id_ubicacion)
        REFERENCES ubicacion(id_ubicacion) ON DELETE RESTRICT,
    CONSTRAINT chk_stock_cantidad CHECK (cantidad >= 0.000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE conteo_inventario (
    id_conteo INT AUTO_INCREMENT PRIMARY KEY,
    id_stock INT NOT NULL,
    cantidad_sistema DECIMAL(12,3) NOT NULL,
    cantidad_contada DECIMAL(12,3) NOT NULL,
    diferencia DECIMAL(12,3) NOT NULL,
    notas TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_conteo_stock FOREIGN KEY (id_stock)
        REFERENCES inventario_stock(id_stock) ON DELETE RESTRICT,
    CONSTRAINT chk_conteo_contada CHECK (cantidad_contada >= 0.000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 9. Server-Rendered HTMX Interactions
- **Product Catalog Listing (`/products`)**:
  - Full page (`templates/pages/products.php`) with search bar (`hx-get="/products"`, `hx-trigger="keyup changed delay:300ms, search"`, `hx-target="#product-table-body"`).
  - Displays product name, category name (or "Sin categorÃ­a"), current price, and status.
- **Product Creation/Edit Form (`/products/new`, `/products/{id}/edit`)**:
  - Modal/card form with category dropdown (including "None" option), price input, and CSRF token.
- **Stock by Location View (`/inventory`)**:
  - List of stock positions displaying product, location code, and current quantity.
- **Observational Count Entry (`/inventory/counts/new`)**:
  - Selection of stock position, displaying current system recorded stock.
  - Counted quantity input; on submit, HTMX inserts the new count record showing calculated discrepancy badge.
  - Explicit confirmation that stock balance is not modified.

#### 10. Concept Classification Table (Scope Rule)

| Concept | Classification | Justification |
|---|---|---|
| Products (`nombre`, `descripcion`, `precio_actual`) | **Official requirement** | Core element of R1. |
| Categories (`nombre`, `descripcion`) | **Official requirement** | Core grouping element of R1. |
| Physical locations (`codigo`, `descripcion`) | **Official requirement** | Explicit in R1 ("ubicaciÃ³n de almacÃ©n"). |
| Associative multi-location stock (`inventario_stock`) | **Necessary derived design decision** | Required by maintainer decision to associate products with locations rather than a single field. |
| Decimal quantity representation (`DECIMAL(12,3)`) | **Necessary derived technical requirement** | Required by maintainer decision to accommodate fractional quantities without precluding them. |
| Periodic inventory count recording (`conteo_inventario`) | **Official requirement** | Explicit in R1 ("controles periÃ³dicos"). |
| Relational integrity via `id_stock` | **Necessary derived design decision** | Simpler normalization: links count directly to stock position without duplicating product/location FKs. |
| Optional category assignment (`id_categoria` nullable) | **Necessary derived policy** | Avoids blocking creation of unclassified items (maintainer decision #1). |
| Product & location active status (`estado_activo`) | **Necessary derived requirement** | Enables soft-deactivation instead of destructive SQL `DELETE`. |
| Stock adjustment / reconciliation workflows | **Deferred / Unsupported for this change** | Excluded by maintainer decision #3; deferred until a future requirement defines adjustment policies. |
| SKU & Barcode fields | **Deferred / Unsupported for this change** | Excluded by maintainer decision #2; barcode deferred to R5; SKU deferred until officially required. |
| Price history / temporal pricing | **Deferred / Unsupported for this change** | Excluded by maintainer decision #5; current selling price only. |
| Branch and warehouse management (`SUCURSAL`, `ALMACEN`) | **Deferred / Unsupported for this change** | Excluded by maintainer decision #4; belongs to R9. |
| Units of measure subsystem (conversions, UoM table) | **Deferred / Unsupported for this change** | Excluded by maintainer decision #7. |
| Inter-branch transfers & waybills | **Deferred / Unsupported for this change** | Belongs to R9. |
| Sales POS stock deduction & recommendations | **Deferred / Unsupported for this change** | Belongs to R2. |
| Automated purchasing & supplier negotiation | **Deferred / Unsupported for this change** | Belongs to R3. |
| Obsolescence alerts, lots, & expiration tracking | **Deferred / Unsupported for this change** | Belongs to R5. |
| Barcode scanner hardware integration | **Deferred / Unsupported for this change** | Belongs to R5. |
| User action audit log in JSON | **Deferred / Unsupported for this change** | Belongs to R6. |
| Data Warehouse ETL & BI dashboards | **Deferred / Unsupported for this change** | Belongs to R7 / R4. |

#### 10.1 Decision Amendment: Reversible Product Lifecycle (Approved Post-4A)
- **Original Baseline Decision:** Products followed an `ACTIVE -> INACTIVE` one-way deactivation rule with no reactivation workflow.
- **Approved Decision Amendment (2026-09-07):** The maintainer explicitly approved changing the product lifecycle to a reversible `ACTIVE <-> INACTIVE` state transition.
- **Rationale:** Products subject to temporary discontinuation, seasonal supplier availability changes, or accidental deactivations must be able to return to active status in the catalog without requiring duplicate product records or fracturing relational continuity.
- **Core Invariants Preserved:**
  1. Reactivation operates strictly on the existing database row with the same `id_producto`.
  2. No duplicate or replacement product record is created.
  3. All historical, inventory stock (`inventario_stock`), and count (`conteo_inventario`) references remain intact.
  4. Physical SQL `DELETE` remains strictly prohibited for end-to-end traceability.

---

### Potential Implementation Slices (Review Budget <= 400 lines)
The simplified domain guarantees that each slice remains safely under the 400 authored-line review budget:

1. **Slice 1: Catalog Foundation (Categories & Products)**
   - Schema migrations for `categoria` and `producto`.
   - Domain validation, queries, and commands for categories and products (nullable category, non-negative `precio_actual`).
   - Integration tests verifying catalog CRUD, active status, and price non-negativity.
   - *Estimated lines: ~290.*

2. **Slice 2: Storage Locations & Associative Stock**
   - Schema migrations for `ubicacion` and `inventario_stock` (`DECIMAL(12,3)`).
   - Module queries and commands for creating locations and establishing stock positions.
   - Integration tests verifying `UNIQUE(id_producto, id_ubicacion)` and `CHECK(cantidad >= 0.000)`.
   - *Estimated lines: ~300.*

3. **Slice 3: Observational Inventory Counts**
   - Schema migration for `conteo_inventario` (FK `id_stock`).
   - Query and command for recording physical counts and calculating variance without mutating stock.
   - Integration tests verifying count immutability, variance calculations, and non-mutation of stock.
   - *Estimated lines: ~260.*

4. **Slice 4: Server-Rendered UI & HTMX Interactions**
   - Product catalog listing with live search (`templates/pages/products.php`).
   - Product and category forms with server-authoritative validation and CSRF protection.
   - Stock list and observational count entry sheet fragment.
   - HTTP tests verifying full-page vs. partial fragment rendering and CSRF enforcement.
   - *Estimated lines: ~350.*

---

### Risks
1. **Operator Expectation of Auto-Adjustment**: Operators might assume recording a count immediately updates stock balance. The UI must explicitly indicate that counts are audit records and do not change inventory balances.
2. **Category Deletion Behavior**: Deleting a category sets `id_categoria = NULL` on associated products (`ON DELETE SET NULL`), preserving product integrity.

---

### Ready for Proposal
Yes â€” the exploration is fully stabilized, stripped of all out-of-scope policies and premature extensions, and adheres strictly to all maintainer decisions.

The change is ready for `/sdd-propose product-inventory-core`.
