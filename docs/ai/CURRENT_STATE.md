# Current System State — Sistema Ferreto

> **Audience**: AI Coding Agents & Engineering Team
> **Status**: Production-Ready Release 1 (R1) Baseline
> **Verified Quality**: 382 tests | 2581 assertions | PHPStan Level Max | 6 Canonical OpenSpec Specs

---

## 1. Golden Rule for AI Agents

> [!CAUTION]
> **DO NOT INFER IMPLEMENTATION FROM DOCUMENTED FUTURE REQUIREMENTS.**
> If a concept (such as POS, sales orders, purchase orders, suppliers, SKU/barcode identifiers, or multi-branch transfers) is mentioned in business documentation or roadmaps, **it does NOT exist in code today**. Check this document and the codebase before making any assumption.

---

## 2. Exhaustive Audit: What IS Implemented

### A. Infrastructure & Foundation (`src/Foundation/`)
- [x] **Kernel**: Synchronous pipeline coordinating route matching, health checks, auth guards, CSRF validation, handler dispatch, and error mapping.
- [x] **Router**: Parameterized path matcher (`/products/{id}/price`) with pre-dispatch guard hook, path normalization, 400 Bad Request on traversal, and 405 Method Not Allowed fallback.
- [x] **HTTP Abstraction**: Immutable `Request` (method, path, headers, query, body, cookies) and `Response` (status, headers, body, redirect factory).
- [x] **Session**: `NativeSession` wrapper with deterministic start, regeneration, destruction, and key-value storage.
- [x] **CSRF Protection**: Cryptographic token generation via `random_bytes(32)`, session binding, and validation on all unsafe methods.
- [x] **Database & Transactions**: Native PDO wrapper with strict type handling, positional parameter binding, and atomic `Transaction` wrapper.
- [x] **Renderer & ViewContext**: Pure PHP view renderer with layout wrapping, fragment rendering, and shared `ViewContext` encapsulation.
- [x] **Health Boundary**: `GET /health` public liveness check; diagnostic `POST /health` strictly gated to `development` and `test` environments via `HealthAccessPolicy`.
- [x] **Console CLI**: `scripts/console.php` with commands for `migrate`, `seed`, `create-user`, `unlock-user`, `verify-assets`, and `serve`.

### B. Access Control & Security (`src/Modules/Access/`)
- [x] **Authentication Engine**: Native bcrypt password hashing (cost 10), timing-attack mitigated dummy hash evaluation for non-existent users, lockout after 6 failed attempts in a 10-minute window.
- [x] **Session Inactivity Management**: Distinct timeouts per role: **20 minutes** for `cajero`, **30 minutes** for `administrador`, `bodeguero`, and `compras`. Stale sessions are destroyed on next request.
- [x] **Single Authorization Authority**: `RouteAccessPolicy` is the authoritative canonical matrix mapping HTTP methods and paths to allowed roles. All 17 current R1 routes are explicitly covered (exempt or mapped).
- [x] **Guards**: `AuthGuard` (redirects anonymous users to `/login`) and `RoleGuard` (structurally fail-closed: returns 403 Forbidden for unauthorized roles AND denies any registered protected route missing from `RouteAccessPolicy`).
- [x] **Presentation Permissions**: `ViewPermissions` wrapping `RouteAccessPolicy`. Templates evaluate `$can($method, $path)` and fail closed if permissions are missing or route is unmapped.
- [x] **Login & Logout**: Full HTTP flow with CSRF protection, secure redirection validation (preventing open redirects), and session regeneration upon login.

### C. Product Catalog Module (`src/Modules/Inventory/`)
- [x] **Categories**: Category creation via modal, uniqueness validation on name (`uk_categoria_nombre`), unclassified category fallback.
- [x] **Products**: Product registration (name, optional description, optional category, selling price `DECIMAL(12,2)` stored in `precio_actual`, active/inactive state toggle). Per canonical specification, SKU and barcode identifiers are explicitly NOT required or implemented. Product name is not uniquely constrained.
- [x] **Real-time Search**: HTMX-powered live search by product name with 300ms debounce. (`ProductQuery` supports optional category and status filtering at the query layer, but `CatalogHandler` and `products.php` currently wire and render text search only).
- [x] **Price Updates**: Dedicated endpoint `POST /products/{id}/price` with strict decimal formatting. Updates current selling price (`precio_actual`) and `updated_at`. Historical price tracking / price audit history is explicitly not implemented per canonical spec.

### D. Inventory & Locations Module (`src/Modules/Inventory/`)
- [x] **Warehouse Locations**: Registration of physical storage spaces (`codigo` with unique constraint `uk_ubicacion_codigo`, `descripcion`, `estado_activo`).
- [x] **Stock Placement**: Mapping products to locations with quantity tracking (`DECIMAL(12,3)` in `cantidad`, unique per product/location).
- [x] **Physical Counts (`conteo_inventario`)**: Observational count registration (`cantidad_sistema`, `cantidad_contada`, `diferencia`, `notas`).
- [x] **Observational Invariant**: Physical counts **NEVER** overwrite or mutate system stock balances.

### E. Authenticated UI / Presentation
- [x] **Industrial Precision Workspace**: Unified design system using Bulma 1.0.4 + custom `ferreto.css`.
- [x] **Responsive Shell**: Collapsible sidebar, mobile hamburger drawer, active navigation indicators (`aria-current="page"`).
- [x] **Role-Aware UI**: Operational buttons and navigation links are automatically suppressed for roles lacking permission.
- [x] **WCAG 2.1 AA Accessibility**: Proper label associations, skip-links, 44px touch targets, `:focus-visible` styling, and contrast compliance.

---

## 3. Currently Registered HTTP Routes

| Method | Path | Allowed Roles | Description |
|---|---|---|---|
| `GET` | `/health` | *Public* | Liveness probe (HTTP 200) |
| `POST` | `/health` | *Dev/Test Only* | Diagnostic probe test |
| `GET` | `/login` | *Public* (Redirects if auth) | Login form |
| `POST` | `/login` | *Public* | Authenticate credentials |
| `POST` | `/logout` | `administrador`, `bodeguero`, `cajero`, `compras` | Terminate session |
| `GET` | `/products` | `administrador`, `bodeguero`, `cajero`, `compras` | Product catalog & search |
| `POST` | `/categories` | `administrador` | Create category |
| `POST` | `/products` | `administrador` | Create product |
| `POST` | `/products/{id}/price` | `administrador` | Update sale price |
| `POST` | `/products/{id}/deactivate` | `administrador` | Deactivate product |
| `POST` | `/products/{id}/activate` | `administrador` | Activate product |
| `GET` | `/locations` | `administrador`, `bodeguero` | View warehouse locations |
| `POST` | `/locations` | `administrador`, `bodeguero` | Create warehouse location |
| `GET` | `/inventory` | `administrador`, `bodeguero` | View stock per location |
| `POST` | `/inventory/stock` | `administrador`, `bodeguero` | Register stock placement |
| `GET` | `/inventory/counts` | `administrador`, `bodeguero` | View & record physical counts |
| `POST` | `/inventory/counts` | `administrador`, `bodeguero` | Submit physical count observation |

---

## 4. Exhaustive Audit: What IS NOT Implemented

The following modules, tables, endpoints, and behaviors **DO NOT EXIST**:

| Domain | What Does NOT Exist Today |
|---|---|
| **Product Identifiers** | No SKU or barcode columns or validation in database or application (per canonical spec, SKU and barcode are explicitly not required for R1). |
| **Product Name Uniqueness** | Product names are not uniquely constrained in schema or validator (unlike category names, which have `uk_categoria_nombre`). |
| **Catalog UI Filters** | No category dropdown or status filter controls are wired in `CatalogHandler` or rendered in `products.php` (live search by product name only). |
| **Stock Adjustments & Reconciliation** | No administrative write-off, shrinkage adjustment, or stock reconciliation command (counts in `conteo_inventario` are purely observational). |
| **Point of Sale (POS)** | No cashier shift opening/closing, no sales orders, no shopping cart, no payment recording, no receipt or invoice printing. |
| **Purchasing & Suppliers** | No supplier entity or table, no purchase orders (PO), no receiving dock validation against POs. |
| **Logistics & Multi-Store** | No branch/store entity, no inter-warehouse transfers, no in-transit stock status. |
| **System Administration UI** | No user management web screens. User creation and unlocking exist **exclusively** via the CLI tool `scripts/console.php`. |
| **Audit Log Subsystem** | No centralized `audit_log` table tracking arbitrary user events (mutations rely on table-specific audit columns like `created_at`, `updated_at`). |
| **Analytics & BI** | No reporting dashboards, no PDF export, no Excel/CSV downloads, no scheduled ETL jobs. |
